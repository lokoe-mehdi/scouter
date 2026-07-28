<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Google\GoogleOAuthClient;
use App\Gsc\ConnectorRepository;
use App\Gsc\EventRepository;
use App\Gsc\GscQueryService;

/**
 * JSON endpoints for the Search Analytics view (project-scoped GSC data).
 *
 * Routed under /api/gsc/* (see web/api/index.php). The browser OAuth flow lives
 * separately in web/gsc.php.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class GscController extends Controller
{
    private ConnectorRepository $repo;

    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->repo = new ConnectorRepository();
    }

    /** Connector state for a project (drives the view's header/banner). */
    public function status(Request $request): void
    {
        $projectId = (int) $request->get('project', 0);
        $this->guard($projectId);

        $c = $this->repo->getByProject($projectId);
        if (!$c) {
            $this->json([
                'connected'  => false,
                'configured' => GoogleOAuthClient::isConfigured(),
            ]);
            return;
        }

        $done  = (int) ($c->backfill_days_done ?? 0);
        $total = (int) ($c->backfill_days_total ?? 0);

        $this->json([
            'connected'        => true,
            'configured'       => GoogleOAuthClient::isConfigured(),
            'status'           => $c->status,
            'site_url'         => $c->site_url,
            'property_type'    => $c->property_type,
            'google_email'     => $c->google_email,
            'last_synced_date' => $c->last_synced_date,
            'backfill_cursor'  => $c->backfill_cursor,
            'backfill_months'  => (int) $c->backfill_months,
            'last_error'       => $c->last_error,
            // Liveness + progress: a "backfilling" badge alone can't be told apart
            // from a dead import, which is the whole point of surfacing these.
            'backfill_done'    => $done,
            'backfill_total'   => $total,
            'backfill_pct'     => $total > 0 ? (int) round($done * 100 / $total) : null,
            'heartbeat_at'     => $c->heartbeat_at ?? null,
            'last_attempt_at'  => $c->last_attempt_at ?? null,
            'failures'         => (int) ($c->consecutive_failures ?? 0),
            'next_retry_at'    => $c->next_retry_at ?? null,
        ]);
    }

    /** Paginated table rows + headline KPIs. */
    public function query(Request $request): void
    {
        $projectId = (int) $request->json('project', $request->get('project', 0));
        $this->guard($projectId);

        [$mode, $from, $to, $filters, $includeAnon] = $this->commonParams($request);
        $sort    = (string) $request->json('sort', 'clicks');
        $dir     = (string) $request->json('dir', 'desc');
        $page    = (int) $request->json('page', 1);
        $perPage = (int) $request->json('per_page', 50);

        // Comparison mode: the grid shows both periods + row-by-row diff.
        $compare = (string) $request->json('compare', 'none');
        $cfrom   = (string) $request->json('cfrom', '');
        $cto     = (string) $request->json('cto', '');
        $comparing = $compare !== 'none' && $cfrom !== '' && $cto !== '';

        $svc = new GscQueryService($projectId);
        $table = $comparing
            ? $svc->tableCompare($mode, $from, $to, $cfrom, $cto, $filters, $sort, $dir, $page, $perPage, $includeAnon)
            : $svc->table($mode, $from, $to, $filters, $sort, $dir, $page, $perPage, $includeAnon);
        $kpis  = $svc->kpis($mode, $from, $to, $filters, $includeAnon);

        $this->json([
            'rows'      => $table['rows'],
            'total'     => $table['total'],
            'kpis'      => $kpis,
            'page'      => $page,
            'per_page'  => $perPage,
            'mode'      => $mode,
            'comparing' => $comparing,
        ]);
    }

    /** Daily clicks/impressions series for the chart. */
    public function timeseries(Request $request): void
    {
        $projectId = (int) $request->json('project', $request->get('project', 0));
        $this->guard($projectId);

        [$mode, $from, $to, $filters, $includeAnon] = $this->commonParams($request);
        $svc = new GscQueryService($projectId);
        $this->json(['series' => $svc->timeseries($mode, $from, $to, $filters, $includeAnon)]);
    }

    /** Distinct country codes present over the range — for the country filter dropdown. */
    public function countries(Request $request): void
    {
        $projectId = (int) $request->get('project', 0);
        $this->guard($projectId);

        $from = (string) $request->get('from', date('Y-m-d', strtotime('-90 days')));
        $to   = (string) $request->get('to', date('Y-m-d'));
        $svc  = new GscQueryService($projectId);
        $this->json(['countries' => $svc->countries($from, $to)]);
    }

    // -- Custom timeline events -----------------------------------------------

    /** List a project's timeline events (optionally within a from/to window). */
    public function events(Request $request): void
    {
        $projectId = (int) $request->get('project', 0);
        $this->guard($projectId);

        $from = (string) $request->get('from', '');
        $to   = (string) $request->get('to', '');
        $events = (new EventRepository())->listByProject(
            $projectId,
            $from !== '' ? $from : null,
            $to !== '' ? $to : null
        );
        $this->json(['events' => $events]);
    }

    /** Create a timeline event ({date, title, optional description}). */
    public function createEvent(Request $request): void
    {
        $projectId = (int) $request->json('project', 0);
        $this->guard($projectId);

        try {
            $clean = EventRepository::sanitize(
                (string) $request->json('date', ''),
                (string) $request->json('title', ''),
                $request->json('description', null) !== null ? (string) $request->json('description', '') : null
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($this->eventErrorMessage($e->getMessage()), 422);
            return;
        }

        $event = (new EventRepository())->create($projectId, $clean, $this->userId);
        $this->json(['event' => $event]);
    }

    /** Update an existing timeline event. */
    public function updateEvent(Request $request): void
    {
        $projectId = (int) $request->json('project', 0);
        $this->guard($projectId);
        $id = (int) $request->json('id', 0);
        if ($id <= 0) {
            $this->error('Missing event id', 422);
            return;
        }

        try {
            $clean = EventRepository::sanitize(
                (string) $request->json('date', ''),
                (string) $request->json('title', ''),
                $request->json('description', null) !== null ? (string) $request->json('description', '') : null
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($this->eventErrorMessage($e->getMessage()), 422);
            return;
        }

        $ok = (new EventRepository())->update($projectId, $id, $clean);
        $this->json(['ok' => $ok, 'event' => array_merge(['id' => $id], $clean)]);
    }

    /** Delete a timeline event (scoped to its project). */
    public function deleteEvent(Request $request): void
    {
        $projectId = (int) $request->json('project', 0);
        $this->guard($projectId);
        $id = (int) $request->json('id', 0);
        if ($id <= 0) {
            $this->error('Missing event id', 422);
            return;
        }
        $ok = (new EventRepository())->delete($projectId, $id);
        $this->json(['ok' => $ok]);
    }

    /** Map a sanitize() error code to a translated, user-facing message. */
    private function eventErrorMessage(string $code): string
    {
        $key = $code === 'invalid_date' ? 'gsc.event_err_date'
             : ($code === 'empty_title' ? 'gsc.event_err_title' : 'gsc.event_err_generic');
        return function_exists('__') ? __($key) : $code;
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{0:string,1:string,2:string,3:array<int,mixed>,4:bool}
     */
    private function commonParams(Request $request): array
    {
        $mode = GscQueryService::normalizeMode((string) $request->json('mode', 'keywords'));
        $to   = (string) $request->json('to', date('Y-m-d', strtotime('-2 days')));
        $from = (string) $request->json('from', date('Y-m-d', strtotime('-30 days')));
        $filters = $request->json('filters', []);
        if (!is_array($filters)) {
            $filters = [];
        }
        $includeAnon = (bool) $request->json('include_anon', true);
        return [$mode, $from, $to, $filters, $includeAnon];
    }

    private function guard(int $projectId): void
    {
        if (!$projectId) {
            $this->error('Missing project id');
        }
        $this->auth->requireProjectAccess($projectId);
    }
}
