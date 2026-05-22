<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\PostgresDatabase;
use App\Database\ProjectRepository;
use App\Database\CrawlRepository;
use App\AI\SqlExecutor;
use App\Api\PageContent;
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
    // GET /api/v1/crawls/{id}/content?url=…  — readable content of one page
    // -------------------------------------------------------------------------
    public function content(Request $request): void
    {
        $crawl = $this->resolveAccessibleCrawl($request);
        if ($crawl === null) return;
        $cid = (int)$crawl->id;

        $url = trim((string)$request->get('url', ''));
        if ($url === '') { Response::error('`url` is required', 400); return; }

        // Resolve the URL to a page in this crawl.
        $stmt = $this->db->prepare("SELECT id, url, title FROM pages WHERE crawl_id = :cid AND url = :url LIMIT 1");
        $stmt->execute([':cid' => $cid, ':url' => $url]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$page) { Response::notFound('URL not found in this crawl'); return; }

        $base = ['url' => $page['url'], 'title' => $page['title'], 'has_html' => false, 'headings' => [], 'text' => '', 'word_count' => 0];

        // Fetch the stored HTML blob (may be absent if the crawl didn't keep HTML).
        $stmt = $this->db->prepare("SELECT html FROM html WHERE crawl_id = :cid AND id = :id LIMIT 1");
        $stmt->execute([':cid' => $cid, ':id' => $page['id']]);
        $stored = $stmt->fetchColumn();
        if (!$stored) {
            $base['note'] = 'No HTML stored for this URL (the crawl did not keep raw HTML).';
            Response::json(['data' => $base, 'meta' => ['crawl_id' => $cid]]);
            return;
        }

        $raw = PageContent::decode($stored);
        if ($raw === null) {
            $base['note'] = 'Stored HTML could not be decoded.';
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

        $stmt = $this->db->prepare("SELECT id, url FROM pages WHERE crawl_id = :cid AND url = :url LIMIT 1");
        $stmt->execute([':cid' => $cid, ':url' => $url]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$page) { Response::notFound('URL not found in this crawl'); return; }

        $base = ['url' => $page['url'], 'has_html' => false, 'html' => '', 'length' => 0, 'truncated' => false];

        $stmt = $this->db->prepare("SELECT html FROM html WHERE crawl_id = :cid AND id = :id LIMIT 1");
        $stmt->execute([':cid' => $cid, ':id' => $page['id']]);
        $stored = $stmt->fetchColumn();
        if (!$stored) {
            $base['note'] = 'No HTML stored for this URL (the crawl did not keep raw HTML).';
            Response::json(['data' => $base, 'meta' => ['crawl_id' => $cid]]);
            return;
        }

        $raw = PageContent::decode($stored);
        if ($raw === null) {
            $base['note'] = 'Stored HTML could not be decoded.';
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
    // Helpers
    // -------------------------------------------------------------------------

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
