<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\PostgresDatabase;
use App\Database\ProjectRepository;
use App\Database\CrawlRepository;
use App\AI\SqlExecutor;
use PDO;

/**
 * Public REST API (v1). Token-authenticated (Bearer) — the router has already
 * set the auth context to the API key's owner before any method here runs, so
 * `$this->userId` / role checks all act AS that user.
 *
 * Envelope: success = { "data": …, "meta": … } (Response::json); errors keep the
 * internal { "success": false, "error": … } shape (Response::error/forbidden/notFound).
 *
 * See API.md for the full contract.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class ApiV1Controller extends Controller
{
    private const PAGE_SIZE_DEFAULT = 50;
    private const PAGE_SIZE_MAX     = 200;
    private const QUERY_PAGE_DEFAULT = 100;
    private const QUERY_PAGE_MAX     = 1000;

    private ProjectRepository $projects;
    private CrawlRepository $crawls;
    private PDO $db;

    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->projects = new ProjectRepository();
        $this->crawls   = new CrawlRepository();
        $this->db       = PostgresDatabase::getInstance()->getConnection();
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/projects
    // -------------------------------------------------------------------------
    public function projects(Request $request): void
    {
        // Role-based visibility (same rule as the dashboard): admin → all,
        // editor → owned + shared, viewer → shared.
        $all = [];
        if ($this->auth->isAdmin()) {
            $all = $this->projects->getAllWithOwner();
        } elseif ($this->auth->hasRole('user')) {
            $all = array_merge(
                $this->projects->getForUser($this->userId),
                $this->projects->getSharedForUser($this->userId)
            );
        } else {
            $all = $this->projects->getSharedForUser($this->userId);
        }

        [$limit, $offset] = $this->pageParams($request, self::PAGE_SIZE_DEFAULT, self::PAGE_SIZE_MAX);
        $total = count($all);
        $slice = array_slice($all, $offset, $limit);

        $data = array_map(fn($p) => [
            'id'            => (int)($p->id ?? 0),
            'name'          => $p->name ?? null,
            'crawl_count'   => isset($p->crawl_count) ? (int)$p->crawl_count : null,
            'last_crawl_at' => $p->last_crawl_at ?? null,
            'owner_email'   => $p->owner_email ?? null,
        ], $slice);

        Response::json(['data' => $data, 'meta' => compact('limit', 'offset', 'total')]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/projects/{id}/crawls
    // -------------------------------------------------------------------------
    public function crawls(Request $request): void
    {
        $projectId = (int)$request->param('id', 0);
        $project = $projectId > 0 ? $this->projects->getById($projectId) : null;
        if (!$project) { Response::notFound('Project not found'); return; }
        $this->auth->requireProjectAccess($projectId); // 403 if no access

        $allCrawls = $this->crawls->getByProjectId($projectId);
        [$limit, $offset] = $this->pageParams($request, self::PAGE_SIZE_DEFAULT, self::PAGE_SIZE_MAX);
        $total = count($allCrawls);
        $slice = array_slice($allCrawls, $offset, $limit);

        $data = array_map(fn($c) => $this->crawlPayload($c), $slice);
        Response::json([
            'data' => $data,
            'meta' => ['limit' => $limit, 'offset' => $offset, 'total' => $total, 'project_id' => $projectId],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/crawls/{id}
    // -------------------------------------------------------------------------
    public function crawl(Request $request): void
    {
        $crawl = $this->resolveAccessibleCrawl($request);
        if ($crawl === null) return; // response already sent
        Response::json(['data' => $this->crawlPayload($crawl)]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/crawls/{id}/schema
    // -------------------------------------------------------------------------
    public function schema(Request $request): void
    {
        $crawl = $this->resolveAccessibleCrawl($request);
        if ($crawl === null) return;
        $cid = (int)$crawl->id;

        // Virtual name → physical table (partitioned ones carry the crawl id).
        $map = [
            'pages'              => "pages_{$cid}",
            'links'              => "links_{$cid}",
            'duplicate_clusters' => "duplicate_clusters_{$cid}",
            'page_schemas'       => "page_schemas_{$cid}",
            'redirect_chains'    => "redirect_chains_{$cid}",
            'crawl_categories'   => 'crawl_categories',
        ];
        $tables = [];
        $colStmt = $this->db->prepare("
            SELECT column_name, data_type
            FROM information_schema.columns
            WHERE table_name = :t
            ORDER BY ordinal_position
        ");
        foreach ($map as $virtual => $physical) {
            $colStmt->execute([':t' => $physical]);
            $cols = [];
            foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                // Hide the partition key from the public schema (always = crawl id).
                if ($col['column_name'] === 'crawl_id') continue;
                $cols[] = ['name' => $col['column_name'], 'type' => $col['data_type']];
            }
            if ($cols) $tables[$virtual] = $cols;
        }

        Response::json([
            'data' => [
                'tables' => $tables,
                'notes'  => 'Use the virtual names above (e.g. `pages`, `links`). '
                          . 'Query other crawls of the SAME project with `pages@<id>`. '
                          . 'SELECT / WITH … SELECT only.',
            ],
            'meta' => ['crawl_id' => $cid],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/crawls/{id}/query  — paginated read-only SQL
    // -------------------------------------------------------------------------
    public function query(Request $request): void
    {
        $crawl = $this->resolveAccessibleCrawl($request);
        if ($crawl === null) return;
        $cid = (int)$crawl->id;

        $sql = trim((string)$request->get('query', ''));
        if ($sql === '') { Response::error('`query` is required', 400); return; }

        $page     = max(1, (int)$request->get('page', 1));
        $pageSize = (int)$request->get('page_size', self::QUERY_PAGE_DEFAULT);
        $pageSize = max(1, min($pageSize, self::QUERY_PAGE_MAX));
        $withCount = $request->get('count', true) !== false;
        $offset   = ($page - 1) * $pageSize;

        $exec = (new SqlExecutor())->executePaginated($sql, $cid, $pageSize, $offset, $withCount);
        if (!$exec['ok']) {
            Response::error($exec['error'] ?? 'Query failed', 422);
            return;
        }

        $rows     = $exec['rows'];
        $returned = count($rows);
        $total    = $exec['total']; // null when count=false
        $totalPages = $total !== null ? (int)ceil($total / $pageSize) : null;
        $hasMore  = $total !== null ? ($offset + $returned < $total) : ($returned === $pageSize);

        Response::json([
            'data' => ['columns' => $exec['columns'], 'rows' => $rows],
            'meta' => [
                'crawl_id'    => $cid,
                'page'        => $page,
                'page_size'   => $pageSize,
                'returned'    => $returned,
                'total'       => $total,
                'total_pages' => $totalPages,
                'has_more'    => $hasMore,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Resolve {id} → an accessible crawl object, or send 404/403 and return null. */
    private function resolveAccessibleCrawl(Request $request): ?object
    {
        $crawlId = (int)$request->param('id', 0);
        $crawl = $crawlId > 0 ? $this->crawls->getById($crawlId) : null;
        if (!$crawl) { Response::notFound('Crawl not found'); return null; }
        $this->auth->requireProjectAccess((int)$crawl->project_id); // 403 if no access
        return $crawl;
    }

    private function crawlPayload(object $c): array
    {
        return [
            'id'          => (int)($c->id ?? 0),
            'project_id'  => isset($c->project_id) ? (int)$c->project_id : null,
            'domain'      => $c->domain ?? null,
            'status'      => $c->status ?? null,
            'crawl_type'  => $c->crawl_type ?? null,
            'urls'        => isset($c->urls) ? (int)$c->urls : null,
            'crawled'     => isset($c->crawled) ? (int)$c->crawled : null,
            'compliant'   => isset($c->compliant) ? (int)$c->compliant : null,
            'started_at'  => $c->started_at ?? null,
            'finished_at' => $c->finished_at ?? null,
        ];
    }

    /** @return array{0:int,1:int} [limit, offset] from ?limit&offset. */
    private function pageParams(Request $request, int $default, int $max): array
    {
        $limit  = (int)$request->get('limit', $default);
        $limit  = max(1, min($limit, $max));
        $offset = max(0, (int)$request->get('offset', 0));
        return [$limit, $offset];
    }
}
