<?php

namespace App\Export;

use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use App\Database\CrawlStore;
use App\Database\ChPdo;
use App\Database\ClickHouseDatabase;
use App\Job\JobManager;
use App\AI\SqlExecutor;
use App\AI\ClickHouseSqlExecutor;
use App\Storage\Storage;
use PDO;

/**
 * Asynchronous CSV exports → blob store.
 *
 * Every "Export" click (SQL / URL / Link / Redirect explorers) enqueues an
 * `export:<id>` job instead of streaming a download. The worker calls
 * {@see ExportService::run}, which regenerates the CSV server-side, uploads it
 * gzip-free under `export/<id>/…` and flips the row to `ready`. The header's
 * downloads center (downloads.js) polls `/api/exports` and offers a 24h link.
 *
 * Access is enforced at CREATE time (the controller checks crawl access); the
 * worker trusts the stored crawl_id. The download is limited to 24h via
 * `expires_at`, and {@see ExportService::pruneExpired} deletes the object + row.
 *
 * @package    Scouter
 * @subpackage Export
 */
class ExportService
{
    /** Exports stay downloadable for this long, then they're swept. */
    public const TTL_SECONDS = 86400; // 24h

    private const TYPES = ['urls', 'links', 'redirects', 'sql'];

    private PDO $db;

    public function __construct()
    {
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Create a pending export and enqueue its worker job. $crawl is the resolved
     * (already access-checked) crawl record. Returns the new export row.
     *
     * @param array<string,mixed> $params  type-specific query params (stored as JSON)
     * @return array<string,mixed>
     */
    public function create(int $userId, object $crawl, string $type, array $params): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown export type: {$type}");
        }

        $domain = (string)($crawl->domain ?? 'export');
        $filename = $this->safeName($domain) . '_' . $type . '_' . date('Y-m-d_His') . '.csv';
        $label = $domain . ' - ' . $type;

        $stmt = $this->db->prepare("
            INSERT INTO exports (user_id, project_id, crawl_id, type, label, params, status, filename, created_at, expires_at)
            VALUES (:uid, :pid, :cid, :type, :label, :params, 'pending', :filename, NOW(), NOW() + INTERVAL '" . self::TTL_SECONDS . " seconds')
            RETURNING *
        ");
        $stmt->execute([
            ':uid'      => $userId,
            ':pid'      => $crawl->project_id ?? null,
            ':cid'      => $crawl->id,
            ':type'     => $type,
            ':label'    => $label,
            ':params'   => json_encode($params),
            ':filename' => $filename,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Enqueue the worker job. project_dir = crawl path so logs/route are scoped.
        $jm = new JobManager();
        $jobId = $jm->createJob((string)($crawl->path ?? ''), 'Export ' . $type, "export:{$row['id']}");
        $jm->updateJobStatus($jobId, 'queued');
        $jm->addLog($jobId, "Queued {$type} export #{$row['id']} for {$domain}", 'info');

        $upd = $this->db->prepare("UPDATE exports SET job_id = :jid WHERE id = :id");
        $upd->execute([':jid' => $jobId, ':id' => $row['id']]);
        $row['job_id'] = $jobId;

        return $row;
    }

    /**
     * Worker side: regenerate the CSV for export #$id, upload it, mark it ready.
     * Throws on failure (the caller marks the export + job failed).
     */
    public function run(int $id): void
    {
        $stmt = $this->db->prepare("SELECT * FROM exports WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $export = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$export) {
            throw new \RuntimeException("Export #{$id} not found");
        }

        $this->db->prepare("UPDATE exports SET status = 'running' WHERE id = :id")
            ->execute([':id' => $id]);

        $crawlId = (int)$export['crawl_id'];
        $type = $export['type'];
        $params = json_decode($export['params'] ?? '{}', true) ?: [];
        $useCh = CrawlStore::usesClickHouse($crawlId);
        $dataDb = $useCh ? new ChPdo($crawlId) : $this->db;

        // ClickHouse crawls can hold tens of millions of rows: EVERY type STREAMS
        // ClickHouse's own CSV output straight to disk instead of buffering rows
        // in PHP (which OOM-killed the worker — the 'sql' path especially, as the
        // executor materialised the whole result set in an array before writing).
        // PG crawls are legacy/small → keep the simple buffered writers.
        $streamable = $useCh;

        $tmp = tempnam(sys_get_temp_dir(), 'scouter-export-');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create temp file for export');
        }

        try {
            // 'w+b' for the streaming path: streamSelectToFile reads the handle
            // back to recover an error body on a failed query.
            $fh = fopen($tmp, $streamable ? 'w+b' : 'w');
            fwrite($fh, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM (Excel)

            $rowCount = null; // streamed rows aren't counted (avoids a 2nd heavy query)
            if ($streamable) {
                switch ($type) {
                    case 'urls':      $this->streamUrls($fh, $crawlId, $params, $dataDb); break;
                    case 'links':     $this->streamLinks($fh, $crawlId, $params, $dataDb); break;
                    case 'redirects': $this->streamRedirects($fh, $crawlId, $dataDb); break;
                    case 'sql':       $this->streamSql($fh, $crawlId, (string)($params['sql'] ?? '')); break;
                    default:          throw new \RuntimeException("Unknown export type: {$type}");
                }
            } else {
                $rowCount = match ($type) {
                    'urls'      => $this->writeUrls($fh, $crawlId, (int)$export['project_id'], $params, $useCh, $dataDb),
                    'links'     => $this->writeLinks($fh, $crawlId, $params, $dataDb),
                    'redirects' => $this->writeRedirects($fh, $crawlId, $dataDb),
                    'sql'       => $this->writeSql($fh, $crawlId, (string)($params['sql'] ?? ''), $useCh),
                    default     => throw new \RuntimeException("Unknown export type: {$type}"),
                };
            }
            fclose($fh);

            // Size BEFORE upload: putFile may move the temp file (local backend
            // renames it into place), leaving nothing to stat afterwards.
            $size = filesize($tmp) ?: 0;

            $key = "export/{$id}/" . $export['filename'];
            if (!Storage::instance()->putFile($key, $tmp, 'text/csv; charset=utf-8')) {
                throw new \RuntimeException('Failed to upload export to storage');
            }
            $this->db->prepare("
                UPDATE exports
                SET status = 'ready', object_key = :key, row_count = :rc, size_bytes = :sz, ready_at = NOW()
                WHERE id = :id
            ")->execute([':key' => $key, ':rc' => $rowCount, ':sz' => $size, ':id' => $id]);
        } finally {
            @unlink($tmp);
        }
    }

    /** Mark an export failed with a message. */
    public function fail(int $id, string $error): void
    {
        $this->db->prepare("UPDATE exports SET status = 'failed', error = :e WHERE id = :id")
            ->execute([':e' => mb_substr($error, 0, 1000), ':id' => $id]);
    }

    /**
     * Reconcile exports for a job that died WITHOUT self-reporting (e.g. the
     * subprocess was OOM-killed by SIGKILL → its catch block never ran, leaving
     * the export stuck in 'running' and spinning forever in the UI). The parent
     * worker calls this when an export job exits non-zero. Idempotent: it only
     * touches non-terminal rows, so a clean failure that already set 'failed'
     * (or a success that set 'ready') is left untouched.
     *
     * @return int number of export rows flipped to failed
     */
    public function failByJob(int $jobId, string $error): int
    {
        $stmt = $this->db->prepare(
            "UPDATE exports SET status = 'failed', error = :e
             WHERE job_id = :jid AND status NOT IN ('ready', 'failed')"
        );
        $stmt->execute([':e' => mb_substr($error, 0, 1000), ':jid' => $jobId]);
        return $stmt->rowCount();
    }

    /**
     * Delete every export past its 24h TTL: remove the blob then the row.
     * Returns the number of exports swept. Safe to call repeatedly.
     */
    public function pruneExpired(): int
    {
        $stmt = $this->db->query("SELECT id, object_key FROM exports WHERE expires_at IS NOT NULL AND expires_at < NOW()");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $store = Storage::instance();
        $n = 0;
        foreach ($rows as $row) {
            try {
                // The whole export lives under export/<id>/ → one prefix delete.
                $store->deletePrefix("export/{$row['id']}/");
            } catch (\Throwable $e) {
                // Object may already be gone; still drop the row.
            }
            $this->db->prepare("DELETE FROM exports WHERE id = :id")->execute([':id' => $row['id']]);
            $n++;
        }
        return $n;
    }

    // -------------------------------------------------------------------------
    // CSV generators (stream row-by-row into the open file handle)
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $params */
    private function writeUrls($fh, int $crawlId, int $projectId, array $params, bool $useCh, $dataDb): int
    {
        $search = (string)($params['search'] ?? '');
        $filters = $params['filters'] ?? [];
        if (is_string($filters)) {
            $filters = json_decode($filters, true) ?: [];
        }
        $columns = $params['columns'] ?? ['url'];
        if (is_string($columns)) {
            $columns = json_decode($columns, true) ?: ['url'];
        }

        // Project categories (id → name) for the legacy PG path.
        $categoriesMap = [];
        $catStmt = $this->db->prepare("SELECT id, cat FROM crawl_categories WHERE project_id = :pid");
        $catStmt->execute([':pid' => $projectId]);
        while ($c = $catStmt->fetch(PDO::FETCH_ASSOC)) {
            $categoriesMap[$c['id']] = $c['cat'];
        }

        $where = ["c.crawl_id = " . (int)$crawlId, "c.crawled = true", "c.in_crawl = TRUE"];
        $sqlParams = [];
        if ($search !== '') {
            $where[] = "c.url LIKE :search";
            $sqlParams[':search'] = '%' . $search . '%';
        }
        if (!empty($filters) && isset($filters['items'])) {
            $conds = $this->buildFilterConditions($filters['items'], $sqlParams);
            if (!empty($conds)) {
                $logic = strtoupper($filters['logic'] ?? 'AND');
                if (!in_array($logic, ['AND', 'OR'], true)) $logic = 'AND';
                $where[] = '(' . implode(' ' . $logic . ' ', $conds) . ')';
            }
        }
        // Report scope — SELECT-safe boolean conditions only (same guard as before).
        $reportWhere = preg_replace('/^\s*WHERE\s+/i', '', trim((string)($params['report_where'] ?? '')));
        if ($reportWhere !== ''
            && !preg_match('/[;]|--|\/\*|\*\/|\b(union|select|insert|update|delete|drop|alter|create|grant|truncate|into|information_schema|pg_catalog|system)\b/i', $reportWhere)) {
            $where[] = '(' . $reportWhere . ')';
        }

        $catSelect = $useCh ? ", c.category AS _category" : "";
        $query = "SELECT c.*{$catSelect} FROM pages c WHERE " . implode(' AND ', $where) . " ORDER BY c.pri DESC";
        $stmt = $dataDb->prepare($query);
        $stmt->execute($sqlParams);

        fputcsv($fh, $columns, ';');
        $n = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $line = [];
            foreach ($columns as $col) {
                if ($col === 'category') {
                    $line[] = $useCh
                        ? ((($row['_category'] ?? '') !== '') ? $row['_category'] : 'Non catégorisé')
                        : ($categoriesMap[$row['cat_id']] ?? 'Non catégorisé');
                } else {
                    $line[] = $row[$col] ?? '';
                }
            }
            fputcsv($fh, $line, ';');
            $n++;
        }
        return $n;
    }

    /** @param array<string,mixed> $params */
    private function writeLinks($fh, int $crawlId, array $params, $dataDb): int
    {
        $columns = $params['columns'] ?? ['source_url', 'target_url'];
        if (is_string($columns)) {
            $columns = json_decode($columns, true) ?: ['source_url', 'target_url'];
        }

        $query = "
            SELECT cs.url as source_url, ct.url as target_url, l.anchor, l.type, l.nofollow
            FROM links l
            JOIN pages cs ON l.src = cs.id AND cs.crawl_id = :crawl_id AND cs.in_crawl = TRUE
            JOIN pages ct ON l.target = ct.id AND ct.crawl_id = :crawl_id2 AND ct.in_crawl = TRUE
            WHERE l.crawl_id = :crawl_id3
            ORDER BY cs.url
        ";
        $stmt = $dataDb->prepare($query);
        $stmt->execute([':crawl_id' => $crawlId, ':crawl_id2' => $crawlId, ':crawl_id3' => $crawlId]);

        fputcsv($fh, $columns, ';');
        $n = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($fh, $line, ';');
            $n++;
        }
        return $n;
    }

    private function writeRedirects($fh, int $crawlId, $dataDb): int
    {
        $query = "
            SELECT source_url, hops, is_loop, final_url, final_code, final_compliant
            FROM redirect_chains
            WHERE crawl_id = :crawl_id
            ORDER BY is_loop DESC, hops DESC
        ";
        $stmt = $dataDb->prepare($query);
        $stmt->execute([':crawl_id' => $crawlId]);

        fputcsv($fh, ['source_url', 'hops', 'is_loop', 'final_url', 'final_code', 'indexable'], ';');
        $n = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($fh, [
                $row['source_url'] ?? '',
                $row['is_loop'] ? 'loop' : (int)$row['hops'],
                $row['is_loop'] ? 'yes' : 'no',
                $row['is_loop'] ? '' : ($row['final_url'] ?? ''),
                $row['is_loop'] ? '' : ($row['final_code'] ?? ''),
                $row['is_loop'] ? 'no' : ($row['final_compliant'] ? 'yes' : 'no'),
            ], ';');
            $n++;
        }
        return $n;
    }

    private function writeSql($fh, int $crawlId, string $sql, bool $useCh): int
    {
        if (trim($sql) === '') {
            throw new \RuntimeException('Empty SQL query');
        }
        // Same validated executors as the SQL Explorer (crawl_id forced, SELECT-only).
        // CH: rowLimit 0 = open bar; PG: large cap (SqlExecutor clamps to HARD_ROW_CAP).
        if ($useCh) {
            $res = (new ClickHouseSqlExecutor())->execute($sql, $crawlId, 0);
        } else {
            $res = (new SqlExecutor())->execute($sql, $crawlId, PHP_INT_MAX);
        }
        if (empty($res['ok'])) {
            throw new \RuntimeException($res['error'] ?? 'SQL query failed');
        }

        $rows = $res['rows'] ?? [];
        $columns = array_values(array_filter($res['columns'] ?? [], fn($c) => $c !== 'crawl_id'));
        if (empty($columns) && !empty($rows)) {
            $columns = array_values(array_filter(array_keys($rows[0]), fn($c) => $c !== 'crawl_id'));
        }

        fputcsv($fh, $columns, ';');
        $n = 0;
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $v = $row[$col] ?? '';
                $line[] = is_scalar($v) ? $v : json_encode($v);
            }
            fputcsv($fh, $line, ';');
            $n++;
        }
        return $n;
    }

    // -------------------------------------------------------------------------
    // ClickHouse streaming generators (no PHP row buffering)
    // -------------------------------------------------------------------------

    /**
     * Stream a PG-style SELECT through ChPdo's translation + ClickHouse's own
     * CSVWithNames output, straight into $fh. Params are inlined BEFORE translate
     * (same order ChPdo uses) so the category regex it injects isn't clobbered.
     *
     * @param array<string,mixed> $params
     */
    private function streamPgSqlToFile($fh, ChPdo $chPdo, string $pgSql, array $params): void
    {
        $inlined = $this->inlineParams($pgSql, $params, $chPdo);
        $chSql = $chPdo->translate($inlined);
        ClickHouseDatabase::getInstance()->streamSelectToFile(
            $chSql . "\nFORMAT CSVWithNames",
            $fh,
            ['format_csv_delimiter' => ';']
        );
    }

    /** Inline :named params into the SQL (string values quoted), longest first. */
    private function inlineParams(string $sql, array $params, ChPdo $chPdo): string
    {
        if (empty($params)) {
            return $sql;
        }
        uksort($params, fn($a, $b) => strlen((string)$b) - strlen((string)$a));
        foreach ($params as $name => $val) {
            $ph = ':' . ltrim((string)$name, ':');
            if (is_int($val) || is_float($val)) {
                $lit = (string)$val;
            } elseif (is_bool($val)) {
                $lit = $val ? '1' : '0';
            } else {
                $lit = $chPdo->quote((string)$val);
            }
            $sql = str_replace($ph, $lit, $sql);
        }
        return $sql;
    }

    /** @param array<string,mixed> $params */
    private function streamUrls($fh, int $crawlId, array $params, ChPdo $chPdo): void
    {
        [$sql, $sqlParams] = $this->buildUrlsSelect($crawlId, $params);
        $this->streamPgSqlToFile($fh, $chPdo, $sql, $sqlParams);
    }

    /** @param array<string,mixed> $params */
    private function streamLinks($fh, int $crawlId, array $params, ChPdo $chPdo): void
    {
        $columns = $this->decodeColumns($params['columns'] ?? '', ['source_url', 'target_url']);
        $select = $this->buildLinkSelectList($columns);
        $cid = (int)$crawlId;
        // crawl_id MUST be on the joined pages too. ChPdo::translate scopes each
        // `pages` reference to a per-crawl virtual subquery ONLY when it can read a
        // crawl_id predicate for that alias; without `cs.crawl_id`/`ct.crawl_id`
        // the join hash table is built over EVERY crawl's pages (millions of rows)
        // → MEMORY_LIMIT_EXCEEDED on big sites. Scoping each side keeps it small
        // (~1 GiB, ~1s vs OOM). (in_crawl is synthesised as a constant 1 in CH, so
        // there's no point filtering on it here.) No ORDER BY: sorting tens of
        // millions of joined rows in CH would blow the memory limit anyway.
        $sql = "SELECT {$select} FROM links l "
            . "JOIN pages cs ON l.src = cs.id AND cs.crawl_id = {$cid} "
            . "JOIN pages ct ON l.target = ct.id AND ct.crawl_id = {$cid} "
            . "WHERE l.crawl_id = {$cid}";
        $this->streamPgSqlToFile($fh, $chPdo, $sql, []);
    }

    /**
     * Stream the redirect-chains export (CH) to disk. The cosmetic transforms the
     * buffered writeRedirects did in PHP (loop label, yes/no, blanking the final
     * columns on loops) are expressed in SQL here so nothing is buffered. Column
     * aliases become the CSVWithNames header (same headers as writeRedirects).
     */
    private function streamRedirects($fh, int $crawlId, ChPdo $chPdo): void
    {
        $cid = (int)$crawlId;
        $sql = "SELECT source_url AS source_url, "
            . "CASE WHEN is_loop THEN 'loop' ELSE CAST(hops AS String) END AS hops, "
            . "CASE WHEN is_loop THEN 'yes' ELSE 'no' END AS is_loop, "
            . "CASE WHEN is_loop THEN '' ELSE final_url END AS final_url, "
            . "CASE WHEN is_loop THEN '' ELSE CAST(final_code AS String) END AS final_code, "
            . "CASE WHEN is_loop THEN 'no' WHEN final_compliant THEN 'yes' ELSE 'no' END AS indexable "
            . "FROM redirect_chains WHERE crawl_id = {$cid} ORDER BY is_loop DESC, hops DESC";
        $this->streamPgSqlToFile($fh, $chPdo, $sql, []);
    }

    /**
     * Stream a SQL-explorer export (CH) to disk via the validated executor, with
     * NO row cap and zero PHP buffering. Replaces the writeSql path for CH, which
     * pulled the entire result set into a PHP array (OOM on multi-million rows).
     */
    private function streamSql($fh, int $crawlId, string $sql): void
    {
        if (trim($sql) === '') {
            throw new \RuntimeException('Empty SQL query');
        }
        (new ClickHouseSqlExecutor())->streamToFile($sql, $crawlId, $fh);
    }

    /**
     * Build the urls SELECT (PG-style; translated to CH by the caller). Mirrors
     * the buffered writeUrls WHERE logic so streamed and buffered exports match.
     *
     * @param array<string,mixed> $params
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildUrlsSelect(int $crawlId, array $params): array
    {
        $columns = $this->decodeColumns($params['columns'] ?? '', ['url']);
        $search = (string)($params['search'] ?? '');
        $filters = $params['filters'] ?? [];
        if (is_string($filters)) {
            $filters = json_decode($filters, true) ?: [];
        }

        $where = ["c.crawl_id = " . (int)$crawlId, "c.crawled = true", "c.in_crawl = TRUE"];
        $sqlParams = [];
        if ($search !== '') {
            $where[] = "c.url LIKE :search";
            $sqlParams[':search'] = '%' . $search . '%';
        }
        if (!empty($filters) && isset($filters['items'])) {
            $conds = $this->buildFilterConditions($filters['items'], $sqlParams);
            if (!empty($conds)) {
                $logic = strtoupper($filters['logic'] ?? 'AND');
                if (!in_array($logic, ['AND', 'OR'], true)) $logic = 'AND';
                $where[] = '(' . implode(' ' . $logic . ' ', $conds) . ')';
            }
        }
        $reportWhere = preg_replace('/^\s*WHERE\s+/i', '', trim((string)($params['report_where'] ?? '')));
        if ($reportWhere !== ''
            && !preg_match('/[;]|--|\/\*|\*\/|\b(union|select|insert|update|delete|drop|alter|create|grant|truncate|into|information_schema|pg_catalog|system)\b/i', $reportWhere)) {
            $where[] = '(' . $reportWhere . ')';
        }

        // Whitelisted column list → "c.<col> AS <col>". `category` resolves to the
        // live category once ChPdo.translate runs.
        $select = [];
        foreach ($columns as $col) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/i', $col)) {
                $select[] = "c.{$col} AS {$col}";
            }
        }
        if (empty($select)) {
            $select[] = "c.url AS url";
        }

        $sql = "SELECT " . implode(', ', $select) . " FROM pages c WHERE " . implode(' AND ', $where) . " ORDER BY c.pri DESC";
        return [$sql, $sqlParams];
    }

    /**
     * Expand the explorer's base column keys into source_/target_ + link columns
     * (mirrors web/components/link-table.php) and map each to its SQL expression
     * aliased to the expected CSV header.
     *
     * @param string[] $base
     */
    private function buildLinkSelectList(array $base): string
    {
        // Page-level columns → cs.<expr> / ct.<expr>.
        $page = [
            'url' => 'url', 'domain' => 'domain', 'depth' => 'depth', 'code' => 'code',
            'category' => 'category', 'inlinks' => 'inlinks', 'outlinks' => 'outlinks',
            'response_time' => 'response_time', 'schemas' => 'array_length(%s.schemas, 1)',
            'compliant' => 'compliant', 'canonical' => 'canonical', 'canonical_value' => 'canonical_value',
            'noindex' => 'noindex', 'blocked' => 'blocked', 'crawled' => 'crawled',
            'out_of_scope' => '(%s.external = false AND %s.blocked = false AND %s.crawled = false)',
            'in_sitemap' => 'in_sitemap', 'is_html' => 'is_html', 'redirect_to' => 'redirect_to',
            'content_type' => 'content_type', 'pri' => 'pri', 'title_status' => 'title_status',
            'title' => 'title', 'h1_status' => 'h1_status', 'h1' => 'h1',
            'metadesc_status' => 'metadesc_status', 'metadesc' => 'metadesc',
            'h1_multiple' => 'h1_multiple', 'headings_missing' => 'headings_missing', 'word_count' => 'word_count',
        ];
        // Link-level columns (emitted once, in the middle).
        $link = ['anchor' => 'l.anchor', 'external' => 'l.external', 'nofollow' => 'l.nofollow',
                 'type' => 'l.type', 'position' => 'l.position', 'xpath' => 'l.xpath'];

        $urlCols = [];
        $linkCols = [];
        foreach ($base as $col) {
            if (isset($page[$col]) || str_starts_with($col, 'extract_')) {
                $urlCols[] = $col;
            } elseif (isset($link[$col])) {
                $linkCols[] = $col;
            }
        }

        $exprFor = function (string $alias, string $col) use ($page): ?string {
            if (str_starts_with($col, 'extract_')) {
                $name = substr($col, strlen('extract_'));
                if (!preg_match('/^[a-z0-9_]+$/i', $name)) return null;
                return "{$alias}.extracts['{$name}']";
            }
            $tpl = $page[$col] ?? null;
            if ($tpl === null) return null;
            // Templated exprs (schemas/out_of_scope) take the alias; plain ones get "alias.col".
            if (str_contains($tpl, '%s')) {
                return vsprintf($tpl, array_fill(0, substr_count($tpl, '%s'), $alias));
            }
            return "{$alias}.{$tpl}";
        };

        $select = [];
        foreach ($urlCols as $col) {
            if ($e = $exprFor('cs', $col)) $select[] = "{$e} AS source_{$col}";
        }
        foreach ($linkCols as $col) {
            $select[] = "{$link[$col]} AS {$col}";
        }
        foreach ($urlCols as $col) {
            if ($e = $exprFor('ct', $col)) $select[] = "{$e} AS target_{$col}";
        }
        if (empty($select)) {
            $select = ['cs.url AS source_url', 'ct.url AS target_url'];
        }
        return implode(', ', $select);
    }

    /**
     * Decode a columns param (a JSON array string or already an array) to a
     * clean string list, falling back to $default when empty.
     *
     * @param mixed    $raw
     * @param string[] $default
     * @return string[]
     */
    private function decodeColumns($raw, array $default): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || empty($raw)) {
            return $default;
        }
        return array_values(array_filter($raw, 'is_string'));
    }

    // -------------------------------------------------------------------------

    /** Sanitize a domain into a filename-safe token. */
    private function safeName(string $s): string
    {
        $s = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $s);
        return trim((string)$s, '-') ?: 'export';
    }

    /**
     * Build parameterized WHERE conditions from the explorer's filter tree.
     * Column names are whitelisted; values are bound. (Ported from the former
     * synchronous ExportController.)
     *
     * @param array<int,array> $items
     * @param array            $params (by ref)
     * @return array<int,string>
     */
    private function buildFilterConditions(array $items, array &$params): array
    {
        static $counter = 0;
        $conditions = [];
        foreach ($items as $item) {
            if (isset($item['type']) && $item['type'] === 'group') {
                $sub = $this->buildFilterConditions($item['items'] ?? [], $params);
                if (!empty($sub)) {
                    $gl = strtoupper($item['logic'] ?? 'AND');
                    if (!in_array($gl, ['AND', 'OR'], true)) $gl = 'AND';
                    $conditions[] = '(' . implode(' ' . $gl . ' ', $sub) . ')';
                }
                continue;
            }
            $field = $item['field'] ?? '';
            $operator = $item['operator'] ?? '=';
            $value = $item['value'] ?? '';
            if (empty($field) || !preg_match('/^[a-z_][a-z0-9_]*$/i', $field)) continue;

            $counter++;
            $p = ':p' . $counter;
            switch ($operator) {
                case 'contains':      $conditions[] = "c.$field LIKE $p";     $params[$p] = '%' . $value . '%'; break;
                case 'not_contains':  $conditions[] = "c.$field NOT LIKE $p"; $params[$p] = '%' . $value . '%'; break;
                case 'starts_with':   $conditions[] = "c.$field LIKE $p";     $params[$p] = $value . '%'; break;
                case 'ends_with':     $conditions[] = "c.$field LIKE $p";     $params[$p] = '%' . $value; break;
                case 'is_empty':      $conditions[] = "(c.$field IS NULL OR c.$field = '')"; break;
                case 'is_not_empty':  $conditions[] = "(c.$field IS NOT NULL AND c.$field != '')"; break;
                case '>': case '<': case '>=': case '<=': case '=': case '!=':
                    $conditions[] = "c.$field $operator $p"; $params[$p] = $value; break;
            }
        }
        return $conditions;
    }
}
