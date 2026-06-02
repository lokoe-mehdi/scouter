<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\PostgresDatabase;
use App\Database\ProjectRepository;
use App\Database\CrawlRepository;
use App\AI\SqlExecutor;
use App\AI\ClickHouseSqlExecutor;
use App\Database\CrawlStore;
use App\Analysis\CategorizationService;
use App\Api\PageContent;
use App\Storage\HtmlStore;
use App\Job\JobManager;
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

        // The single-crawl endpoint also exposes the full crawl configuration
        // (decoded from the stored JSON). Not included in the list endpoint to
        // keep it lean.
        $payload = $this->crawlPayload($crawl);
        $cfg = isset($crawl->config) && $crawl->config !== '' ? json_decode((string)$crawl->config, true) : null;
        $payload['config'] = is_array($cfg) ? $cfg : null;

        Response::json(['data' => $payload]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/crawls/{id}/schema
    // -------------------------------------------------------------------------
    public function schema(Request $request): void
    {
        $crawl = $this->resolveAccessibleCrawl($request);
        if ($crawl === null) return;
        $cid = (int)$crawl->id;

        if (CrawlStore::usesClickHouse($cid)) {
            Response::json([
                'data' => [
                    'tables' => self::clickHouseVirtualSchema(),
                    'notes'  => 'ClickHouse data store. Use virtual names (`pages`, `links`, '
                              . '`duplicate_clusters`, `page_schemas`, `redirect_chains`). '
                              . '`pages.category` is computed live from the project rules (no cat_id). '
                              . 'inlinks/pri/title_status/h1_status/metadesc_status/in_sitemap come from '
                              . 'post-processing. ClickHouse SQL dialect (RE2 regex via match(), '
                              . 'Map access extracts[\'k\']). Query other crawls of the SAME project with '
                              . '`pages@<id>`. SELECT / WITH … SELECT only.',
                ],
                'meta' => ['crawl_id' => $cid, 'data_store' => 'clickhouse'],
            ]);
            return;
        }

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

    /**
     * The virtual table schema exposed for ClickHouse crawls — read from the LIVE
     * ClickHouse schema (system.columns) so it never drifts from reality, then
     * augmented for `pages` with the columns the shim adds on top: the derived
     * post-processing fields (page_metrics: inlinks/pri/*_status/in_sitemap),
     * `generation` (page_generation Map) and the LIVE `category` (computed from the
     * project rules at query time). The synthetic `cat_id` is intentionally NOT
     * advertised — it isn't a real column (the shim still accepts it for legacy
     * queries, but `category` is the real thing). `crawl_id` is hidden (the server
     * scopes it automatically). Falls back to a static list if CH is unreachable.
     *
     * @return array<string,array<int,array{name:string,type:string}>>
     */
    public static function clickHouseVirtualSchema(): array
    {
        $col = fn(string $n, string $t) => ['name' => $n, 'type' => $t];
        $tables = ['pages', 'links', 'duplicate_clusters', 'page_schemas', 'redirect_chains'];
        $hidden = ['crawl_id'];

        try {
            $ch = ClickHouseDatabase::getInstance();
            $db = $ch->getDatabase();
            $out = [];
            foreach ($tables as $t) {
                $rows = $ch->select(
                    "SELECT name, type FROM system.columns WHERE database = {db:String} AND table = {tbl:String} ORDER BY position",
                    ['db' => $db, 'tbl' => $t]
                );
                $cols = [];
                foreach ($rows as $r) {
                    if (!in_array($r['name'], $hidden, true)) {
                        $cols[] = $col($r['name'], $r['type']);
                    }
                }
                if ($cols) {
                    $out[$t] = $cols;
                }
            }
            if (!empty($out['pages'])) {
                // Derived columns the shim joins onto `pages` from page_metrics.
                $pm = $ch->select(
                    "SELECT name, type FROM system.columns WHERE database = {db:String} AND table = 'page_metrics' ORDER BY position",
                    ['db' => $db]
                );
                foreach ($pm as $r) {
                    if (!in_array($r['name'], ['crawl_id', 'id'], true)) {
                        $out['pages'][] = $col($r['name'], $r['type']);
                    }
                }
                // Bulk-AI generation (page_generation Map) + the live category NAME.
                $out['pages'][] = $col('generation', 'Map(String, String)');
                $out['pages'][] = $col('category', 'String (live — computed from project rules)');
                return $out;
            }
        } catch (\Throwable $e) {
            // fall through to the static fallback below
        }

        // Static fallback (CH unreachable): mirrors docker/clickhouse/init.sql.
        return [
            'pages' => [
                $col('id', 'FixedString(8)'), $col('date', 'DateTime'), $col('domain', 'String'),
                $col('url', 'String'), $col('depth', 'Int32'), $col('code', 'Int32'),
                $col('response_time', 'Float64'), $col('outlinks', 'Int32'),
                $col('content_type', 'String'), $col('redirect_to', 'String'),
                $col('crawled', 'UInt8'), $col('compliant', 'UInt8'), $col('noindex', 'UInt8'),
                $col('nofollow', 'UInt8'), $col('canonical', 'UInt8'), $col('canonical_value', 'String'),
                $col('external', 'UInt8'), $col('blocked', 'UInt8'), $col('title', 'String'),
                $col('h1', 'String'), $col('metadesc', 'String'), $col('extracts', 'Map(String, String)'),
                $col('simhash', 'Nullable(Int64)'), $col('is_html', 'UInt8'), $col('h1_multiple', 'UInt8'),
                $col('headings_missing', 'UInt8'), $col('schemas', 'Array(String)'), $col('word_count', 'Int32'),
                $col('inlinks', 'Int32'), $col('pri', 'Float64'), $col('title_status', 'String'),
                $col('h1_status', 'String'), $col('metadesc_status', 'String'), $col('in_sitemap', 'UInt8'),
                $col('generation', 'Map(String, String)'), $col('category', 'String (live)'),
            ],
            'links' => [
                $col('src', 'FixedString(8)'), $col('target', 'FixedString(8)'), $col('anchor', 'String'),
                $col('external', 'UInt8'), $col('nofollow', 'UInt8'), $col('type', 'String'),
                $col('xpath', 'Nullable(String)'), $col('position', 'String'),
            ],
            'duplicate_clusters' => [
                $col('cluster_id', 'Int32'), $col('similarity', 'Int32'),
                $col('page_count', 'Int32'), $col('page_ids', 'Array(String)'),
            ],
            'page_schemas' => [
                $col('page_id', 'FixedString(8)'), $col('schema_type', 'String'),
            ],
            'redirect_chains' => [
                $col('chain_id', 'Int32'), $col('source_id', 'FixedString(8)'), $col('source_url', 'String'),
                $col('final_id', 'String'), $col('final_url', 'String'), $col('final_code', 'Int32'),
                $col('final_compliant', 'UInt8'), $col('hops', 'Int32'), $col('is_loop', 'UInt8'),
                $col('chain_ids', 'Array(String)'),
            ],
        ];
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

        $executor = CrawlStore::usesClickHouse($cid)
            ? new ClickHouseSqlExecutor()
            : new SqlExecutor();
        $exec = $executor->executePaginated($sql, $cid, $pageSize, $offset, $withCount);
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
    // GET /api/v1/crawls/{id}/content?url=…  — readable content of one page
    // -------------------------------------------------------------------------
    public function content(Request $request): void
    {
        $crawl = $this->resolveAccessibleCrawl($request);
        if ($crawl === null) return;
        $cid = (int)$crawl->id;

        $url = trim((string)$request->get('url', ''));
        if ($url === '') { Response::error('`url` is required', 400); return; }

        // Migrated crawl → PG partitions purged: read pages/html from ClickHouse
        // via the shim. CH stores RAW html (ZSTD codec); legacy PG stores it
        // base64+gzdeflate and needs PageContent::decode().
        $useCh = CrawlStore::usesClickHouse($cid);
        $dataDb = $useCh ? new \App\Database\ChPdo($cid) : $this->db;

        // Resolve the URL to a page in this crawl.
        $stmt = $dataDb->prepare("SELECT id, url, title FROM pages WHERE crawl_id = :cid AND url = :url LIMIT 1");
        $stmt->execute([':cid' => $cid, ':url' => $url]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$page) { Response::notFound('URL not found in this crawl'); return; }

        $base = ['url' => $page['url'], 'title' => $page['title'], 'has_html' => false, 'headings' => [], 'text' => '', 'word_count' => 0];

        // Raw HTML now lives in the blob store (S3/local); older crawls fall back
        // to the DB. HtmlStore returns it already decompressed.
        $raw = HtmlStore::fetch($cid, (string)$page['id'], $useCh, $dataDb);
        if ($raw === null || $raw === '') {
            $base['note'] = 'No HTML stored for this URL (the crawl did not keep raw HTML).';
            Response::json(['data' => $base, 'meta' => ['crawl_id' => $cid]]);
            return;
        }

        $ex = PageContent::extract($raw);
        Response::json([
            'data' => [
                'url'        => $page['url'],
                'title'      => $ex['title'] !== '' ? $ex['title'] : $page['title'],
                'has_html'   => true,
                'headings'   => $ex['headings'],
                'text'       => $ex['text'],
                'word_count' => $ex['word_count'],
                'truncated'  => $ex['truncated'],
            ],
            'meta' => ['crawl_id' => $cid],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/crawls/{id}/html?url=…[&max_chars=N]  — raw HTML of one page
    // -------------------------------------------------------------------------
    public function html(Request $request): void
    {
        $crawl = $this->resolveAccessibleCrawl($request);
        if ($crawl === null) return;
        $cid = (int)$crawl->id;

        $url = trim((string)$request->get('url', ''));
        if ($url === '') { Response::error('`url` is required', 400); return; }

        // Optional safety cap on the returned markup (raw HTML can be very large).
        $maxChars = (int)$request->get('max_chars', 1000000);
        $maxChars = max(1000, min($maxChars, 2000000));

        // Migrated crawl → read from ClickHouse (raw html); legacy PG → decode.
        $useCh = CrawlStore::usesClickHouse($cid);
        $dataDb = $useCh ? new \App\Database\ChPdo($cid) : $this->db;

        $stmt = $dataDb->prepare("SELECT id, url FROM pages WHERE crawl_id = :cid AND url = :url LIMIT 1");
        $stmt->execute([':cid' => $cid, ':url' => $url]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$page) { Response::notFound('URL not found in this crawl'); return; }

        $base = ['url' => $page['url'], 'has_html' => false, 'html' => '', 'length' => 0, 'truncated' => false];

        // Blob store first (new crawls), DB fallback for older ones — decompressed.
        $raw = HtmlStore::fetch($cid, (string)$page['id'], $useCh, $dataDb);
        if ($raw === null || $raw === '') {
            $base['note'] = 'No HTML stored for this URL (the crawl did not keep raw HTML).';
            Response::json(['data' => $base, 'meta' => ['crawl_id' => $cid]]);
            return;
        }

        $original = mb_strlen($raw);
        $truncated = false;
        if ($original > $maxChars) {
            $raw = mb_substr($raw, 0, $maxChars);
            $truncated = true;
        }

        Response::json([
            'data' => [
                'url'             => $page['url'],
                'has_html'        => true,
                'html'            => $raw,
                'length'          => mb_strlen($raw),
                'original_length' => $original,
                'truncated'       => $truncated,
            ],
            'meta' => ['crawl_id' => $cid],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/crawls/{id}/categorization  — current rules + applied categories
    // -------------------------------------------------------------------------
    public function getCategorization(Request $request): void
    {
        $crawl = $this->resolveAccessibleCrawl($request);
        if ($crawl === null) return;
        $cid = (int)$crawl->id;
        $projectId = (int)$crawl->project_id;

        // The YAML rules: prefer the crawl-level config (what was actually applied
        // to THIS crawl), fall back to the project-level source of truth.
        $stmt = $this->db->prepare("SELECT config FROM categorization_config WHERE crawl_id = :cid");
        $stmt->execute([':cid' => $cid]);
        $yaml = $stmt->fetchColumn();
        if ($yaml === false || $yaml === null || $yaml === '') {
            $yaml = $this->projects->getCategorizationConfig($projectId);
        }

        // Applied categories on THIS crawl — the proof the deploy reached it.
        // NULL cat = the "uncategorized" bucket (kept, with name=null).
        // Colours are project metadata (crawl_categories), keyed by NAME for both stores.
        $colors = [];
        $cstmt = $this->db->prepare("SELECT cat, color FROM crawl_categories WHERE project_id = :pid");
        $cstmt->execute([':pid' => $projectId]);
        while ($r = $cstmt->fetch(PDO::FETCH_ASSOC)) { $colors[$r['cat']] = $r['color']; }

        if (CrawlStore::usesClickHouse($cid)) {
            // CH: no stored cat_id — count live from the crawl's saved rules.
            $catExpr = (new \App\Analysis\CategoryExpr($this->db))->forCrawl($cid);
            $chdb = \App\Database\ClickHouseDatabase::getInstance();
            $db = $chdb->getDatabase();
            $rows = $chdb->select("SELECT {$catExpr} AS category, count() AS count
                FROM (SELECT url FROM {$db}.pages WHERE crawl_id = " . $cid . " AND external = 0 LIMIT 1 BY id)
                GROUP BY category ORDER BY count DESC");
            $categories = array_map(function ($r) use ($colors) {
                $name = (($r['category'] ?? '') !== '') ? $r['category'] : null;
                return [
                    'name'  => $name,
                    'color' => $name !== null ? ($colors[$name] ?? '#95a5a6') : null,
                    'count' => (int)$r['count'],
                ];
            }, $rows);
        } else {
            $stmt = $this->db->prepare("
                SELECT c.cat AS name, COUNT(p.id) AS count
                FROM pages p
                LEFT JOIN crawl_categories c ON p.cat_id = c.id
                WHERE p.crawl_id = :cid AND p.external = false
                GROUP BY c.cat
                ORDER BY count DESC
            ");
            $stmt->execute([':cid' => $cid]);
            $categories = array_map(fn($r) => [
                'name'  => $r['name'],
                'color' => $r['name'] !== null ? ($colors[$r['name']] ?? '#95a5a6') : null,
                'count' => (int)$r['count'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        Response::json([
            'data' => [
                'crawl_id'   => $cid,
                'project_id' => $projectId,
                'config'     => ($yaml !== false && $yaml !== null) ? (string)$yaml : null,
                'categories' => $categories,
                'deployment' => $this->categorizationDeployment($projectId),
            ],
            'meta' => ['crawl_id' => $cid],
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/crawls/{id}/categorization  — set rules, apply, deploy
    // -------------------------------------------------------------------------
    public function setCategorization(Request $request): void
    {
        $crawl = $this->resolveAccessibleCrawl($request);
        if ($crawl === null) return;
        // Writing categorization requires MANAGEMENT of the project, not just access.
        $this->auth->requireProjectManagement((int)$crawl->project_id);
        $cid       = (int)$crawl->id;
        $projectId = (int)$crawl->project_id;
        $domain    = (string)($crawl->domain ?? '');

        $yamlRaw = $request->get('yaml');
        if (!is_string($yamlRaw) || trim($yamlRaw) === '') {
            Response::error('`yaml` (the categorization rules) is required.', 400);
            return;
        }
        $deployToProject = $request->get('deploy_to_project', true) !== false;

        // Fill `dom` for any category that omitted it (or used the `{dom}`
        // placeholder) with the crawl's domain; an explicit dom is left as-is.
        // We normalise through the YAML parser so parseRules never skips a
        // dom-less category (it requires the key to be present).
        try {
            $yaml = $this->normalizeCategorizationYaml($yamlRaw, $domain);
        } catch (\Throwable $e) {
            Response::error('Invalid YAML: ' . $e->getMessage(), 400);
            return;
        }

        $categories = \Spyc::YAMLLoadString($yaml);
        if (!is_array($categories) || empty($categories)) {
            Response::error('YAML did not parse into any category.', 400);
            return;
        }

        // Validate regex patterns up-front (422 on a bad pattern) so the caller
        // gets a clear error instead of a half-applied config.
        $service = new CategorizationService($this->db);
        try {
            $service->parseRules($categories);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
            return;
        }

        // Persist at project level (source of truth) + crawl level.
        $this->projects->setCategorizationConfig($projectId, $yaml);
        $stmt = $this->db->prepare("
            INSERT INTO categorization_config (crawl_id, config)
            VALUES (:cid, :config)
            ON CONFLICT (crawl_id) DO UPDATE SET config = :config2
        ");
        $stmt->execute([':cid' => $cid, ':config' => $yaml, ':config2' => $yaml]);

        // Apply SYNCHRONOUSLY to THIS crawl so the result is immediately readable
        // (a few regex UPDATEs; same trade-off as the UI save flow).
        $categorizedCount = 0;
        $applyError = null;
        try {
            $categorizedCount = $service->applyCategorization($cid, $yaml, $projectId);
        } catch (\Throwable $e) {
            $applyError = $e->getMessage();
            error_log('[API categorization] sync apply on crawl ' . $cid . ' failed: ' . $e->getMessage());
        }

        // Deploy to the OTHER crawls of the project via an async batch job.
        $jobId = null;
        $otherCrawls = 0;
        if ($deployToProject) {
            $others = array_filter($this->crawls->getByProjectId($projectId), fn($c) => (int)$c->id !== $cid);
            $otherCrawls = count($others);
            if ($otherCrawls > 0) {
                $jm = new JobManager();
                $jobId = $jm->createJob((string)($crawl->path ?? ''), 'Batch Categorization', "batch-categorize-project:{$projectId}");
                $jm->updateJobStatus($jobId, 'queued');
                $jm->addLog($jobId, "Queued categorization for {$otherCrawls} other crawl(s) via API (crawl #{$cid} applied synchronously).", 'info');
            }
        }

        // Refresh the precomputed report fragments that depend on categories
        // (category distribution, Sankey flow, external links by category…). These
        // live in crawl_report_cache and are NOT recomputed by the categorization
        // apply itself — without this step the reports keep showing the OLD
        // categories after a save via the API/MCP, exactly as they do from the UI
        // save flow (CategorizationController::save). Mirror that routine here:
        //   - synchronous recompute of THIS crawl so the reports the caller looks
        //     at right after are fresh (reads the live category we just persisted),
        //   - an async project-wide job for the other crawls. It is queued AFTER
        //     the batch-categorize job above so that, by the time it runs, every
        //     crawl's per-crawl YAML snapshot has been refreshed (the CH live
        //     `category` reads that snapshot) — otherwise the others would be
        //     recomputed against their stale categories.
        $reportPrecomputeJobId = null;
        try {
            \App\Analysis\ReportPrecompute::recompute($cid, true); // category-dependent fragments only
        } catch (\Throwable $e) {
            error_log('[API categorization] report precompute on crawl ' . $cid . ' failed: ' . $e->getMessage());
        }
        if ($deployToProject && $otherCrawls > 0) {
            try {
                $pjm = new JobManager();
                $reportPrecomputeJobId = $pjm->createJob((string)($crawl->path ?? ''), 'Report Precompute', "precompute-reports-project:{$projectId}");
                $pjm->updateJobStatus($reportPrecomputeJobId, 'queued');
                $pjm->addLog($reportPrecomputeJobId, "Queued report recompute for the project after categorization via API (crawl #{$cid} recomputed synchronously).", 'info');
            } catch (\Throwable $e) {
                error_log('[API categorization] queue project report precompute failed: ' . $e->getMessage());
            }
        }

        $payload = [
            'crawl_id'          => $cid,
            'project_id'        => $projectId,
            'categorized_count' => $categorizedCount,
            'deploy_to_project' => $deployToProject,
            'deployment'        => [
                'status'       => $jobId !== null ? 'running' : 'completed',
                'job_id'       => $jobId !== null ? (int)$jobId : null,
                'progress'     => $jobId !== null ? 0 : 100,
                'other_crawls' => $otherCrawls,
            ],
            // Report fragments are refreshed so the reports immediately reflect the
            // new categories (this crawl synchronously, the rest in the background).
            'report_precompute' => [
                'current_crawl'  => 'completed',
                'project_job_id' => $reportPrecomputeJobId !== null ? (int)$reportPrecomputeJobId : null,
            ],
        ];
        // Config is persisted regardless; surface a sync-apply failure so the
        // caller knows THIS crawl's view won't reflect the rules until the
        // batch worker rescues it.
        if ($applyError !== null) {
            $payload['apply_error'] = $applyError;
        }
        Response::json(['data' => $payload]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/crawls/{id}/status  — live progress of a crawl
    // -------------------------------------------------------------------------
    public function crawlStatus(Request $request): void
    {
        $crawl = $this->resolveAccessibleCrawl($request);
        if ($crawl === null) return;
        $cid = (int)$crawl->id;

        $jm  = new JobManager();
        $job = $jm->getJobByProject((string)($crawl->path ?? ''));

        // Latest log lines (tail), returned oldest→newest.
        $recent = [];
        if ($job) {
            $stmt = $this->db->prepare("
                SELECT message, type, created_at FROM job_logs
                WHERE job_id = :jid ORDER BY created_at DESC LIMIT 15
            ");
            $stmt->execute([':jid' => (int)$job->id]);
            $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
            foreach ($rows as $r) {
                $recent[] = ['at' => $r['created_at'], 'type' => $r['type'], 'message' => $r['message']];
            }
        }

        Response::json([
            'data' => [
                'crawl_id'        => $cid,
                'status'          => $crawl->status ?? null,
                'crawl_type'      => $crawl->crawl_type ?? null,
                'scheduled'       => isset($crawl->scheduled) ? (bool)$crawl->scheduled : null,
                'in_progress'     => isset($crawl->in_progress) ? (int)$crawl->in_progress : null,
                'urls_discovered' => (int)($crawl->urls ?? 0),
                'urls_crawled'    => (int)($crawl->crawled ?? 0),
                'compliant'       => (int)($crawl->compliant ?? 0),
                'started_at'      => $crawl->started_at ?? null,
                'finished_at'     => $crawl->finished_at ?? null,
                'job'             => $job ? ['id' => (int)$job->id, 'status' => $job->status ?? null] : null,
                'recent_logs'     => $recent,
            ],
            'meta' => ['crawl_id' => $cid],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/crawls/{id}/stop  — stop / cancel a running or queued crawl
    // -------------------------------------------------------------------------
    public function stopCrawl(Request $request): void
    {
        $crawl = $this->resolveAccessibleCrawl($request);
        if ($crawl === null) return;
        // Controlling a crawl requires MANAGEMENT of its project, not just access.
        $this->auth->requireProjectManagement((int)$crawl->project_id);
        $cid = (int)$crawl->id;

        $jm  = new JobManager();
        $job = $jm->getJobByProject((string)($crawl->path ?? ''));
        if (!$job) {
            Response::error('No active job found for this crawl (it may already be finished).', 409);
            return;
        }

        $st = $job->status ?? '';
        if (!in_array($st, ['running', 'queued', 'pending', 'stopping'], true)) {
            Response::error('Crawl is not running or queued (status: ' . $st . ').', 409);
            return;
        }

        if (in_array($st, ['pending', 'queued'], true)) {
            $jm->updateJobStatus($job->id, 'stopped');
            $jm->addLog($job->id, 'Crawl cancelled via API.', 'warning');
            $this->crawls->update($cid, ['status' => 'stopped', 'in_progress' => 0]);
            Response::json(['data' => ['crawl_id' => $cid, 'status' => 'stopped', 'message' => 'Crawl cancelled (was queued).']]);
            return;
        }

        if ($st === 'stopping') {
            $jm->updateJobStatus($job->id, 'stopped');
            $jm->addLog($job->id, 'Crawl force-stopped via API.', 'warning');
            Response::json(['data' => ['crawl_id' => $cid, 'status' => 'stopped', 'message' => 'Crawl force-stopped.']]);
            return;
        }

        // running → graceful stop (worker finishes the current batch).
        $jm->updateJobStatus($job->id, 'stopping');
        $jm->addLog($job->id, 'Stop signal sent via API.', 'info');
        Response::json(['data' => ['crawl_id' => $cid, 'status' => 'stopping', 'message' => 'Stop signal sent; the crawl will finish the current batch and stop.']]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/crawls/{id}/start  — resume a fully-stopped crawl
    // -------------------------------------------------------------------------
    public function startCrawl(Request $request): void
    {
        $crawl = $this->resolveAccessibleCrawl($request);
        if ($crawl === null) return;
        // Controlling a crawl requires MANAGEMENT of its project, not just access.
        $this->auth->requireProjectManagement((int)$crawl->project_id);
        $cid = (int)$crawl->id;
        $status = (string)($crawl->status ?? '');

        // Only a FULLY stopped (or failed) crawl can be (re)started — never one
        // that is still running, queued, pending, or finishing its batch.
        if (!in_array($status, ['stopped', 'failed'], true)) {
            $msg = $status === 'stopping'
                ? 'Crawl is still finishing its current batch (status: stopping). Wait until it is fully stopped before starting it again.'
                : 'Only a stopped crawl can be started (current status: ' . $status . ').';
            Response::error($msg, 409);
            return;
        }

        $jm = new JobManager();
        // Extra guard: make sure no worker job is still active for this crawl.
        $existing = $jm->getJobByProject((string)($crawl->path ?? ''));
        if ($existing && in_array($existing->status ?? '', ['running', 'queued', 'pending', 'stopping'], true)) {
            Response::error('A worker job is still active for this crawl (status: ' . $existing->status . '). Wait until it is fully stopped.', 409);
            return;
        }

        // Resume = new job that continues where the crawl stopped (same as the UI).
        $projectDir  = (string)$crawl->path;
        $projectName = preg_replace('#-(\d{8})-(\d{6})$#', '', $projectDir);
        $jobId = $jm->createJob($projectDir, $projectName, 'crawl');
        $this->crawls->update($cid, ['status' => 'queued', 'in_progress' => 1]);
        $jm->updateJobStatus($jobId, 'queued');
        $jm->addLog($jobId, 'Crawl resumed via API. Waiting for worker...', 'info');

        Response::json(['data' => [
            'crawl_id' => $cid,
            'job_id'   => (int)$jobId,
            'status'   => 'queued',
            'message'  => 'Crawl resumed; waiting for a worker.',
        ]]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/crawls  — create + queue a new crawl from a config
    // -------------------------------------------------------------------------
    public function createCrawl(Request $request): void
    {
        // Write action: viewers can't launch crawls.
        if (!$this->auth->canCreate()) {
            Response::forbidden('You do not have permission to create crawls.');
            return;
        }

        $config = $request->get('config');
        if (!is_array($config)) {
            Response::error('`config` (a JSON object) is required.', 400);
            return;
        }

        $general  = is_array($config['general'] ?? null) ? $config['general'] : [];
        $advanced = is_array($config['advanced'] ?? null) ? $config['advanced'] : [];

        $crawlType = (($general['crawl_type'] ?? 'spider') === 'list') ? 'list' : 'spider';

        // Resolve the start URL (+ url_list for list mode).
        $urlList = [];
        if ($crawlType === 'list') {
            $urlList = array_values(array_filter(
                array_map('trim', (array)($general['url_list'] ?? [])),
                static fn($u) => is_string($u) && (str_starts_with($u, 'http://') || str_starts_with($u, 'https://'))
            ));
            if (empty($urlList)) {
                Response::error('List mode requires `config.general.url_list` with http(s) URLs.', 400);
                return;
            }
            $start = $urlList[0];
        } else {
            $start = trim((string)($general['start'] ?? ''));
        }

        if ($start === '' || !filter_var($start, FILTER_VALIDATE_URL)) {
            Response::error('A valid start URL is required (`config.general.start`, or `url_list` in list mode).', 400);
            return;
        }

        // Domain: explicit config.general.domains, else derived from the start URL.
        $domains = array_values(array_filter(array_map('trim', (array)($general['domains'] ?? [])), static fn($d) => $d !== ''));
        $startHost = parse_url($start, PHP_URL_HOST) ?: '';
        if ($startHost === '') {
            Response::error('Could not extract a domain from the start URL.', 400);
            return;
        }
        if (empty($domains)) {
            $domains = $crawlType === 'list'
                ? array_values(array_unique(array_filter(array_map(fn($u) => parse_url($u, PHP_URL_HOST) ?: '', $urlList))))
                : [$startHost];
        }
        $domain = $domains[0];

        $depthMax = (int)($general['depthMax'] ?? 30);

        // Merge the caller's config over the full default template, so a minimal
        // config (just a start URL) still produces a complete, valid crawl config.
        $finalConfig = $this->buildCrawlConfig($general, $advanced, $start, $crawlType, $domains, $depthMax, $urlList);

        // Project (one per domain for this user) + unique crawl path.
        $projectId  = $this->projects->getOrCreate($this->userId, $domain);
        $projectDir = $domain . '-' . date('Ymd') . '-' . date('His');

        $crawlId = $this->crawls->insert([
            'domain'      => $domain,
            'path'        => $projectDir,
            'status'      => 'pending',
            'config'      => $finalConfig,
            'depth_max'   => $depthMax,
            'crawl_type'  => $crawlType,
            'in_progress' => 0,
            'project_id'  => $projectId,
        ]);

        $this->applyDefaultCategorization($crawlId, $domain);

        // Queue the job (same path as the UI's /crawls/start).
        $jobManager = new JobManager();
        $jobId = $jobManager->createJob($projectDir, $domain, 'crawl');
        $jobManager->updateJobStatus($jobId, 'queued');
        $jobManager->addLog($jobId, 'Crawl queued via API. Waiting for worker...', 'info');
        $this->crawls->update($crawlId, ['status' => 'queued', 'in_progress' => 1]);

        Response::json([
            'data' => [
                'crawl_id'    => (int)$crawlId,
                'project_id'  => (int)$projectId,
                'job_id'      => (int)$jobId,
                'project_dir' => $projectDir,
                'domain'      => $domain,
                'crawl_type'  => $crawlType,
                'status'      => 'queued',
            ],
        ], 201);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/schedules  — all schedules across accessible projects
    // -------------------------------------------------------------------------
    public function schedules(Request $request): void
    {
        $where = '';
        $params = [];
        if (!$this->auth->isAdmin()) {
            $ids = [];
            foreach (array_merge($this->projects->getForUser($this->userId), $this->projects->getSharedForUser($this->userId)) as $p) {
                $ids[(int)$p->id] = true;
            }
            $ids = array_keys($ids);
            if (empty($ids)) { Response::json(['data' => [], 'meta' => ['total' => 0]]); return; }
            $ph = [];
            foreach ($ids as $i => $id) { $k = ':p' . $i; $ph[] = $k; $params[$k] = $id; }
            $where = 'WHERE cs.project_id IN (' . implode(',', $ph) . ')';
        }

        $stmt = $this->db->prepare("
            SELECT cs.project_id, p.name AS domain, cs.enabled, cs.frequency, cs.hour, cs.minute,
                   cs.days_of_week, cs.day_of_month, cs.crawl_type, cs.depth_max,
                   cs.next_run_at, cs.last_triggered_at, cs.updated_at
            FROM crawl_schedules cs
            JOIN projects p ON p.id = cs.project_id AND p.deleted_at IS NULL
            $where
            ORDER BY p.name
        ");
        $stmt->execute($params);
        $rows = array_map([$this, 'schedulePayload'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        Response::json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/projects/{id}/schedule  — one project's schedule (incl. disabled)
    // -------------------------------------------------------------------------
    public function getProjectSchedule(Request $request): void
    {
        $projectId = (int)$request->param('id', 0);
        if (!$this->projects->getById($projectId)) { Response::notFound('Project not found'); return; }
        $this->auth->requireProjectAccess($projectId);

        $row = $this->fetchSchedule($projectId);
        Response::json(['data' => $row ? $this->schedulePayload($row) : null, 'meta' => ['project_id' => $projectId]]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/projects/{id}/schedule  — create or replace the schedule (upsert)
    // -------------------------------------------------------------------------
    public function saveProjectSchedule(Request $request): void
    {
        $projectId = (int)$request->param('id', 0);
        if (!$this->projects->getById($projectId)) { Response::notFound('Project not found'); return; }
        $this->auth->requireProjectManagement($projectId);

        $frequency  = (string)$request->get('frequency', 'weekly');
        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            Response::error('Invalid frequency. Use one of: daily, weekly, monthly.', 400);
            return;
        }
        $daysOfWeek = (array)$request->get('days_of_week', ['mon']);
        $daysOfWeek = array_values(array_filter($daysOfWeek, fn($d) => in_array($d, ['mon','tue','wed','thu','fri','sat','sun'], true)));
        if (empty($daysOfWeek)) $daysOfWeek = ['mon'];
        $dayOfMonth = max(1, min(28, (int)$request->get('day_of_month', 1)));
        $hour       = max(0, min(23, (int)$request->get('hour', 8)));
        $minute     = max(0, min(59, (int)$request->get('minute', 0)));
        $enabled    = $request->get('enabled', true) !== false;
        $templateId = $request->get('template_crawl_id');

        $existing = $this->fetchSchedule($projectId);

        // Resolve the crawl template (what to crawl). Required to CREATE; on update
        // we keep the existing template if none is provided.
        if ($templateId !== null && $templateId !== '') {
            $stmt = $this->db->prepare("SELECT config, crawl_type, depth_max, project_id FROM crawls WHERE id = :id");
            $stmt->execute([':id' => (int)$templateId]);
            $tpl = $stmt->fetch(PDO::FETCH_OBJ);
            if (!$tpl || (int)$tpl->project_id !== $projectId) {
                Response::error('template_crawl_id not found or not in this project.', 400);
                return;
            }
            $crawlConfig = $tpl->config ?? '{}';
            $crawlType   = $tpl->crawl_type ?? 'spider';
            $depthMax    = (int)($tpl->depth_max ?? 30);
            $catStmt = $this->db->prepare("SELECT config FROM categorization_config WHERE crawl_id = :id");
            $catStmt->execute([':id' => (int)$templateId]);
            $catRow = $catStmt->fetch(PDO::FETCH_OBJ);
            $catConfig = $catRow ? $catRow->config : null;
        } elseif ($existing) {
            $crawlConfig = $existing['crawl_config'] ?? '{}';
            $crawlType   = $existing['crawl_type'] ?? 'spider';
            $depthMax    = (int)($existing['depth_max'] ?? 30);
            $catConfig   = $existing['categorization_config'] ?? null;
        } else {
            Response::error('template_crawl_id is required to create a schedule (it defines what to crawl).', 400);
            return;
        }

        $nextRun = $enabled ? $this->computeNextRun($frequency, $daysOfWeek, $dayOfMonth, $hour, $minute) : null;
        $pgDays  = '{' . implode(',', $daysOfWeek) . '}';

        $stmt = $this->db->prepare("
            INSERT INTO crawl_schedules (project_id, user_id, enabled, frequency, days_of_week, day_of_month, hour, minute, crawl_config, crawl_type, depth_max, categorization_config, next_run_at, updated_at)
            VALUES (:pid, :uid, :enabled, :frequency, :days, :dom, :hour, :minute, :cfg, :ctype, :depth, :cat, :next, NOW())
            ON CONFLICT (project_id) DO UPDATE SET
                user_id = EXCLUDED.user_id, enabled = EXCLUDED.enabled, frequency = EXCLUDED.frequency,
                days_of_week = EXCLUDED.days_of_week, day_of_month = EXCLUDED.day_of_month,
                hour = EXCLUDED.hour, minute = EXCLUDED.minute, crawl_config = EXCLUDED.crawl_config,
                crawl_type = EXCLUDED.crawl_type, depth_max = EXCLUDED.depth_max,
                categorization_config = EXCLUDED.categorization_config, next_run_at = EXCLUDED.next_run_at, updated_at = NOW()
        ");
        $stmt->execute([
            ':pid' => $projectId, ':uid' => $this->userId, ':enabled' => $enabled ? 'true' : 'false',
            ':frequency' => $frequency, ':days' => $pgDays, ':dom' => $dayOfMonth, ':hour' => $hour, ':minute' => $minute,
            ':cfg' => $crawlConfig, ':ctype' => $crawlType, ':depth' => $depthMax, ':cat' => $catConfig, ':next' => $nextRun,
        ]);

        Response::json(['data' => $this->schedulePayload($this->fetchSchedule($projectId)), 'meta' => ['project_id' => $projectId]]);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/projects/{id}/schedule  — enable / disable an existing schedule
    // -------------------------------------------------------------------------
    public function toggleProjectSchedule(Request $request): void
    {
        $projectId = (int)$request->param('id', 0);
        if (!$this->projects->getById($projectId)) { Response::notFound('Project not found'); return; }
        $this->auth->requireProjectManagement($projectId);

        $enabledRaw = $request->get('enabled', null);
        if ($enabledRaw === null) { Response::error('`enabled` (boolean) is required.', 400); return; }
        $enabled = $enabledRaw === true || $enabledRaw === 'true' || $enabledRaw === 1 || $enabledRaw === '1';

        $existing = $this->fetchSchedule($projectId);
        if (!$existing) { Response::error('No schedule exists for this project. Create one first (PUT).', 404); return; }

        if ($enabled) {
            $days = $this->parsePgDays($existing['days_of_week'] ?? null) ?: ['mon'];
            $nextRun = $this->computeNextRun(
                $existing['frequency'], $days, (int)$existing['day_of_month'], (int)$existing['hour'], (int)$existing['minute']
            );
            $this->db->prepare("UPDATE crawl_schedules SET enabled = true, next_run_at = :next, updated_at = NOW() WHERE project_id = :pid")
                ->execute([':next' => $nextRun, ':pid' => $projectId]);
        } else {
            $this->db->prepare("UPDATE crawl_schedules SET enabled = false, next_run_at = NULL, updated_at = NOW() WHERE project_id = :pid")
                ->execute([':pid' => $projectId]);
        }

        Response::json(['data' => $this->schedulePayload($this->fetchSchedule($projectId)), 'meta' => ['project_id' => $projectId]]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/projects/{id}/schedule  — remove the schedule
    // -------------------------------------------------------------------------
    public function deleteProjectSchedule(Request $request): void
    {
        $projectId = (int)$request->param('id', 0);
        if (!$this->projects->getById($projectId)) { Response::notFound('Project not found'); return; }
        $this->auth->requireProjectManagement($projectId);

        $stmt = $this->db->prepare("DELETE FROM crawl_schedules WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);
        Response::json(['data' => ['project_id' => $projectId, 'deleted' => $stmt->rowCount() > 0]]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse the caller's YAML, fill `dom` for any category that omitted it (or
     * used the `{dom}` placeholder) with the crawl's domain, and re-serialise.
     * An explicit, non-placeholder `dom` is left untouched. Re-serialising
     * guarantees CategorizationService::parseRules never skips a dom-less
     * category (it requires the key to be present).
     */
    private function normalizeCategorizationYaml(string $yaml, string $domain): string
    {
        $parsed = \Spyc::YAMLLoadString($yaml);
        if (!is_array($parsed) || empty($parsed)) {
            throw new \InvalidArgumentException('not a YAML mapping of categories');
        }
        foreach ($parsed as &$rule) {
            if (!is_array($rule)) continue;
            $dom = $rule['dom'] ?? null;
            if ($dom === null || $dom === '' || $dom === '{dom}') {
                $rule['dom'] = $domain;
            }
        }
        unset($rule);
        return \Spyc::YAMLDump($parsed, 2, 0, true);
    }

    /**
     * Latest project-wide categorization deployment status, as a public block.
     * Derived from the most recent `batch-categorize-project:<id>` job. No job
     * means the categorization was only ever applied synchronously (single-crawl
     * project), which we report as "idle" (nothing pending).
     *
     * @return array<string,mixed>
     */
    private function categorizationDeployment(int $projectId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, status, progress
            FROM jobs
            WHERE command = :cmd
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':cmd' => "batch-categorize-project:{$projectId}"]);
        $job = $stmt->fetch(PDO::FETCH_OBJ);

        if (!$job) {
            return ['status' => 'idle', 'job_id' => null, 'progress' => 100];
        }

        $status  = (string)($job->status ?? '');
        $running = in_array($status, ['pending', 'queued', 'running'], true);
        return [
            'status'   => $running ? 'running' : $status,
            'job_id'   => (int)$job->id,
            'progress' => $job->progress !== null ? (int)$job->progress : ($running ? 0 : 100),
        ];
    }

    /** @return array<string,mixed>|null raw crawl_schedules row for a project */
    private function fetchSchedule(int $projectId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM crawl_schedules WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<int,string> parse a PostgreSQL text array '{mon,wed}' → ['mon','wed'] */
    private function parsePgDays(?string $raw): array
    {
        if ($raw === null || $raw === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', trim($raw, '{}')))));
    }

    /** Shape a crawl_schedules row into the public payload. */
    private function schedulePayload(array $r): array
    {
        $en = $r['enabled'] ?? false;
        $enabled = $en === true || $en === 't' || $en === 'true' || $en === 1 || $en === '1';
        return [
            'project_id'        => (int)$r['project_id'],
            'domain'            => $r['domain'] ?? null,
            'enabled'           => $enabled,
            'frequency'         => $r['frequency'] ?? null,
            'hour'              => isset($r['hour']) ? (int)$r['hour'] : null,
            'minute'            => isset($r['minute']) ? (int)$r['minute'] : null,
            'days_of_week'      => $this->parsePgDays($r['days_of_week'] ?? null),
            'day_of_month'      => isset($r['day_of_month']) ? (int)$r['day_of_month'] : null,
            'crawl_type'        => $r['crawl_type'] ?? null,
            'depth_max'         => isset($r['depth_max']) ? (int)$r['depth_max'] : null,
            'next_run_at'       => $r['next_run_at'] ?? null,
            'last_triggered_at' => $r['last_triggered_at'] ?? null,
            'updated_at'        => $r['updated_at'] ?? null,
        ];
    }

    /** Next run timestamp from the structured frequency (mirror of the scheduler). */
    private function computeNextRun(string $freq, array $days, int $dayOfMonth, int $hour, int $minute): string
    {
        $now = new \DateTime('now');
        if ($freq === 'daily') {
            $next = clone $now; $next->setTime($hour, $minute, 0);
            if ($next <= $now) $next->modify('+1 day');
            return $next->format('Y-m-d H:i:00');
        }
        if ($freq === 'weekly') {
            $map = ['mon'=>'Monday','tue'=>'Tuesday','wed'=>'Wednesday','thu'=>'Thursday','fri'=>'Friday','sat'=>'Saturday','sun'=>'Sunday'];
            $cands = [];
            foreach ($days as $d) {
                $name = $map[$d] ?? null; if (!$name) continue;
                $c = new \DateTime("this week {$name}"); $c->setTime($hour, $minute, 0);
                if ($c <= $now) { $c = new \DateTime("next {$name}"); $c->setTime($hour, $minute, 0); }
                $cands[] = $c;
            }
            if (empty($cands)) { $c = new \DateTime('next Monday'); $c->setTime($hour, $minute, 0); return $c->format('Y-m-d H:i:00'); }
            usort($cands, fn($a, $b) => $a <=> $b);
            return $cands[0]->format('Y-m-d H:i:00');
        }
        if ($freq === 'monthly') {
            $dom = max(1, min(28, $dayOfMonth));
            $next = clone $now; $next->setDate((int)$next->format('Y'), (int)$next->format('m'), $dom); $next->setTime($hour, $minute, 0);
            if ($next <= $now) { $next->modify('+1 month'); $next->setDate((int)$next->format('Y'), (int)$next->format('m'), $dom); }
            return $next->format('Y-m-d H:i:00');
        }
        return (clone $now)->modify('+1 day')->format('Y-m-d H:i:00');
    }

    /** Merge caller-supplied config over the full default template (same shape as the UI). */
    private function buildCrawlConfig(array $general, array $advanced, string $start, string $crawlType, array $domains, int $depthMax, array $urlList): array
    {
        $generalOut = array_merge([
            'start'       => $start,
            'depthMax'    => $depthMax,
            'domains'     => $domains,
            'crawl_speed' => 'fast',
            'crawl_mode'  => 'classic',
            'crawl_type'  => $crawlType,
            'user-agent'  => 'Scouter/0.6 (Crawler developed by Lokoe SASU; +https://lokoe.fr/scouter-crawler)',
        ], $general);
        // Enforce the computed/validated values (caller can't override these).
        $generalOut['start']      = $start;
        $generalOut['domains']    = $domains;
        $generalOut['crawl_type'] = $crawlType;
        $generalOut['depthMax']   = $depthMax;
        if ($crawlType === 'list') {
            $generalOut['url_list'] = $urlList;
        } else {
            unset($generalOut['url_list']);
        }

        $advancedOut = array_merge([
            'respect_robots'    => true,
            'respect_nofollow'  => true,
            'respect_canonical' => true,
            'follow_redirects'  => true,
            'retry_failed_urls' => true,
            'store_html'        => true,
            'sitemap_urls'      => [],
            'custom_headers'    => [],
            'http_auth'         => null,
            'xPathExtractors'   => [],
            'regexExtractors'   => [],
        ], $advanced);

        return ['general' => $generalOut, 'advanced' => $advancedOut];
    }

    /** Apply the default categorization template (cat.yml) to a new crawl, same as the UI. */
    private function applyDefaultCategorization(int $crawlId, string $domain): void
    {
        $catYmlPath = dirname(__DIR__, 3) . '/cat.yml';
        if (!file_exists($catYmlPath)) return;
        $catYaml = file_get_contents($catYmlPath);
        if (!$catYaml) return;
        $catYaml = str_replace('{dom}', $domain, $catYaml);
        $stmt = $this->db->prepare("
            INSERT INTO categorization_config (crawl_id, config)
            VALUES (:crawl_id, :config)
            ON CONFLICT (crawl_id) DO UPDATE SET config = :config2
        ");
        $stmt->execute([':crawl_id' => $crawlId, ':config' => $catYaml, ':config2' => $catYaml]);
    }

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
            'scheduled'   => isset($c->scheduled) ? (bool)$c->scheduled : null,
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
