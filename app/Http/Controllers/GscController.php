<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Google\GoogleOAuthClient;
use App\Gsc\ConnectorRepository;
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
