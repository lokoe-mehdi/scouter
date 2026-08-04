<?php

namespace App\Export;

use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use App\Database\CrawlStore;
use App\Database\ChPdo;
use App\Database\PgReportPdo;
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

    private const TYPES = ['urls', 'links', 'redirects', 'sql', 'gsc'];

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
     * Create a project-scoped GSC (Search Analytics) export — no crawl involved.
     * $params: mode, from, to, filters (JSON groups), include_anon.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function createGsc(int $userId, int $projectId, string $domain, array $params): array
    {
        $mode = (string)($params['mode'] ?? 'keywords');
        $filename = $this->safeName($domain) . '_gsc-' . $mode . '_' . date('Y-m-d_His') . '.csv';
        $label = $domain . ' - Search Analytics';

        $stmt = $this->db->prepare("
            INSERT INTO exports (user_id, project_id, crawl_id, type, label, params, status, filename, created_at, expires_at)
            VALUES (:uid, :pid, NULL, 'gsc', :label, :params, 'pending', :filename, NOW(), NOW() + INTERVAL '" . self::TTL_SECONDS . " seconds')
            RETURNING *
        ");
        $stmt->execute([
            ':uid'      => $userId,
            ':pid'      => $projectId,
            ':label'    => $label,
            ':params'   => json_encode($params),
            ':filename' => $filename,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $jm = new JobManager();
        $jobId = $jm->createJob('gsc-export-' . $projectId, 'Export Search Analytics', "export:{$row['id']}");
        $jm->updateJobStatus($jobId, 'queued');
        $jm->addLog($jobId, "Queued GSC export #{$row['id']} for {$domain}", 'info');

        $this->db->prepare("UPDATE exports SET job_id = :jid WHERE id = :id")
            ->execute([':jid' => $jobId, ':id' => $row['id']]);
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

        // GSC exports are project-scoped (no crawl): stream ClickHouse's CSV output
        // for the built Search-Analytics query straight to disk.
        if (($export['type'] ?? '') === 'gsc') {
            $this->runGsc($id, $export);
            return;
        }

        $crawlId = (int)$export['crawl_id'];
        $type = $export['type'];
        $params = json_decode($export['params'] ?? '{}', true) ?: [];
        $useCh = CrawlStore::usesClickHouse($crawlId);
        // PgReportPdo (not the bare connection): legacy PG crawls have no stored
        // category either — the shim injects the same live `category` column the
        // explorers read, so `category` exports a real name instead of blowing up
        // on an unknown column.
        $dataDb = $useCh ? new ChPdo($crawlId) : new PgReportPdo($crawlId);

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
                    'urls'      => $this->writeUrls($fh, $crawlId, $params, $dataDb),
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

    /**
     * Worker side of a GSC export: build the Search-Analytics SELECT for the
     * stored (project, mode, date-range, filters) and stream ClickHouse's
     * CSVWithNames output to disk, then upload — zero PHP row buffering.
     *
     * @param array<string,mixed> $export the exports row
     */
    private function runGsc(int $id, array $export): void
    {
        $projectId = (int)$export['project_id'];
        $params = json_decode($export['params'] ?? '{}', true) ?: [];
        $mode = (string)($params['mode'] ?? 'keywords');
        $from = (string)($params['from'] ?? date('Y-m-d', strtotime('-30 days')));
        $to   = (string)($params['to'] ?? date('Y-m-d', strtotime('-2 days')));
        $includeAnon = (bool)($params['include_anon'] ?? true);
        $filters = $params['filters'] ?? [];
        if (is_string($filters)) {
            $filters = json_decode($filters, true) ?: [];
        }
        // Comparison + visible-metrics, so the CSV mirrors the on-screen grid.
        $metrics = $params['metrics'] ?? ['clicks', 'impressions', 'ctr', 'position'];
        if (is_string($metrics)) {
            $metrics = json_decode($metrics, true) ?: ['clicks', 'impressions', 'ctr', 'position'];
        }
        $comparing = ($params['compare'] ?? 'none') !== 'none';
        $cfrom = $comparing ? (string)($params['cfrom'] ?? '') : null;
        $cto   = $comparing ? (string)($params['cto'] ?? '') : null;

        $built = (new \App\Gsc\GscQueryService($projectId))
            ->exportSelect($mode, $from, $to, is_array($filters) ? $filters : [], $includeAnon,
                is_array($metrics) ? $metrics : ['clicks', 'impressions', 'ctr', 'position'], $cfrom, $cto);

        $tmp = tempnam(sys_get_temp_dir(), 'scouter-export-');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create temp file for export');
        }
        try {
            $fh = fopen($tmp, 'w+b');
            fwrite($fh, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM (Excel)
            ClickHouseDatabase::getInstance()->streamSelectToFile(
                $built['sql'] . "\nFORMAT CSVWithNames",
                $fh,
                ['format_csv_delimiter' => ';'],
                $built['params']
            );
            fclose($fh);

            $size = filesize($tmp) ?: 0;
            $key = "export/{$id}/" . $export['filename'];
            if (!Storage::instance()->putFile($key, $tmp, 'text/csv; charset=utf-8')) {
                throw new \RuntimeException('Failed to upload export to storage');
            }
            $this->db->prepare("
                UPDATE exports
                SET status = 'ready', object_key = :key, size_bytes = :sz, ready_at = NOW()
                WHERE id = :id
            ")->execute([':key' => $key, ':sz' => $size, ':id' => $id]);
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
    private function writeUrls($fh, int $crawlId, array $params, $dataDb): int
    {
        $columns = $this->decodeColumns($params['columns'] ?? '', ['url']);
        [$sql, $sqlParams] = $this->buildUrlsSelect($crawlId, $params);

        $stmt = $dataDb->prepare($sql);
        $stmt->execute($this->usedParams($sql, $sqlParams));

        // The SELECT is built from the same column list, so the CSV header and the
        // row keys line up 1:1 (unknown/unavailable keys are dropped from both).
        $emitted = $this->urlExportColumns($columns);
        fputcsv($fh, $emitted, ';');
        $n = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $line = [];
            foreach ($emitted as $col) {
                $v = $row[$col] ?? '';
                $line[] = ($col === 'category' && ($v === '' || $v === null)) ? 'Non catégorisé' : $v;
            }
            fputcsv($fh, $line, ';');
            $n++;
        }
        return $n;
    }

    /** @param array<string,mixed> $params */
    private function writeLinks($fh, int $crawlId, array $params, $dataDb): int
    {
        $columns = $this->decodeColumns($params['columns'] ?? '', ['source_url', 'target_url']);
        $select = $this->buildLinkSelectList($columns);
        $headers = $this->linkExportColumns($columns);

        $sqlParams = [];
        $scope = $this->reportScope($params, $sqlParams);
        // Crawl scoping last: it must never be shadowed by a posted param name.
        $sqlParams = array_merge($sqlParams, [
            ':crawl_id' => $crawlId, ':crawl_id2' => $crawlId, ':crawl_id3' => $crawlId,
        ]);

        $query = "
            SELECT {$select}
            FROM links l
            JOIN pages cs ON l.src = cs.id AND cs.crawl_id = :crawl_id AND cs.in_crawl = TRUE
            JOIN pages ct ON l.target = ct.id AND ct.crawl_id = :crawl_id2 AND ct.in_crawl = TRUE
            WHERE l.crawl_id = :crawl_id3" . ($scope !== '' ? " AND ({$scope})" : '') . "
            ORDER BY cs.url
        ";
        $stmt = $dataDb->prepare($query);
        $stmt->execute($this->usedParams($query, $sqlParams));

        fputcsv($fh, $headers, ';');
        $n = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $line = [];
            foreach ($headers as $col) {
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
        // The link explorer's own WHERE (its filter chips, on cs./ct./l. aliases),
        // replayed with its params bound — without it the CSV was the whole crawl's
        // links whatever the on-screen filters said.
        $sqlParams = [];
        $scope = $this->reportScope($params, $sqlParams);
        // crawl_id MUST be on the joined pages too. ChPdo::translate scopes each
        // `pages` reference to a per-crawl virtual subquery ONLY when it can read a
        // crawl_id predicate for that alias; without `cs.crawl_id`/`ct.crawl_id`
        // the join hash table is built over EVERY crawl's pages (millions of rows)
        // → MEMORY_LIMIT_EXCEEDED on big sites. Scoping each side keeps it small
        // (~1 GiB, ~1s vs OOM). (No in_crawl filter: sitemap-only placeholders have
        // no links, so they never reach this src/target join anyway.) No ORDER BY: sorting tens of
        // millions of joined rows in CH would blow the memory limit anyway.
        $sql = "SELECT {$select} FROM links l "
            . "JOIN pages cs ON l.src = cs.id AND cs.crawl_id = {$cid} "
            . "JOIN pages ct ON l.target = ct.id AND ct.crawl_id = {$cid} "
            . "WHERE l.crawl_id = {$cid}"
            . ($scope !== '' ? " AND ({$scope})" : '');
        $this->streamPgSqlToFile($fh, $chPdo, $sql, $sqlParams);
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

        $where = ["c.crawl_id = " . (int)$crawlId];
        $sqlParams = [];

        // The table's own WHERE (filters + search, already turned into SQL by the
        // page that rendered the table) — the export then matches the screen by
        // construction. Its values travel as BOUND params, never inlined.
        $scope = $this->reportScope($params, $sqlParams);
        if ($scope !== '') {
            // The scope IS the table's row set: adding anything on top (the old
            // hardcoded `crawled = true AND in_crawl = TRUE`) silently dropped rows
            // the user could see on screen — uncrawled and sitemap-only URLs.
            $where[] = '(' . $scope . ')';
        } else {
            // No table scope (API caller / legacy payload): keep the historical
            // default row set and rebuild the WHERE from the raw search + filters.
            $where[] = 'c.crawled = true';
            $where[] = 'c.in_crawl = TRUE';
            $search = (string)($params['search'] ?? '');
            if ($search !== '') {
                $where[] = "c.url LIKE :search";
                $sqlParams[':search'] = '%' . $search . '%';
            }
            $conds = $this->buildFilterGroups($params['filters'] ?? [], $sqlParams);
            if ($conds !== '') {
                $where[] = $conds;
            }
        }

        $select = $this->buildUrlSelectList($columns);
        $sql = "SELECT " . $select . " FROM pages c WHERE " . implode(' AND ', $where) . " ORDER BY c.pri DESC";
        return [$sql, $sqlParams];
    }

    /**
     * The page columns an urls export can actually produce, mapped to their SQL
     * expression (`%s` = the `pages` alias). Mirrors the SELECT built by
     * web/components/url-table.php so the CSV matches the on-screen table.
     *
     * Anything NOT listed here is dropped instead of being emitted blindly as
     * `c.<key>`: keys like `out_of_scope` (a computed expression), `gsc_*` /
     * `cmp_*` (join-only columns the export has no join for) are not columns of
     * `pages`, and shipping them to the database killed the whole export with an
     * "Unknown identifier" error.
     */
    private const URL_COLS = [
        'url' => 'url', 'domain' => 'domain', 'depth' => 'depth', 'code' => 'code',
        'category' => 'category', 'inlinks' => 'inlinks', 'outlinks' => 'outlinks',
        'response_time' => 'response_time', 'schemas' => 'schemas',
        'compliant' => 'compliant', 'canonical' => 'canonical', 'canonical_value' => 'canonical_value',
        'noindex' => 'noindex', 'nofollow' => 'nofollow', 'blocked' => 'blocked',
        'external' => 'external', 'crawled' => 'crawled',
        'out_of_scope' => '(%s.external = false AND %s.blocked = false AND %s.crawled = false)',
        'in_sitemap' => 'in_sitemap', 'is_html' => 'is_html', 'redirect_to' => 'redirect_to',
        'content_type' => 'content_type', 'pri' => 'pri',
        'title_status' => 'title_status', 'title' => 'title',
        'h1_status' => 'h1_status', 'h1' => 'h1',
        'metadesc_status' => 'metadesc_status', 'metadesc' => 'metadesc',
        'h1_multiple' => 'h1_multiple', 'headings_missing' => 'headings_missing',
        'word_count' => 'word_count',
    ];

    /**
     * SQL expression for one urls-export column key, or null when the key isn't
     * exportable. `extract_<k>` / `generation_<k>` read the JSONB maps the same
     * way url-table.php does (ChPdo rewrites `->>` to CH map access).
     */
    private function urlColumnExpr(string $alias, string $col): ?string
    {
        foreach (['extract_' => 'extracts', 'generation_' => 'generation'] as $prefix => $jsonCol) {
            if (str_starts_with($col, $prefix)) {
                $key = substr($col, strlen($prefix));
                if (!preg_match('/^[a-z0-9_]+$/i', $key)) {
                    return null;
                }
                return "{$alias}.{$jsonCol}->>'{$key}'";
            }
        }
        $tpl = self::URL_COLS[$col] ?? null;
        if ($tpl === null) {
            return null;
        }
        if (str_contains($tpl, '%s')) {
            return vsprintf($tpl, array_fill(0, substr_count($tpl, '%s'), $alias));
        }
        return "{$alias}.{$tpl}";
    }

    /**
     * The requested columns an urls export can actually emit, in order. Both the
     * CSV header and the SELECT are built from this list, so they can't drift.
     *
     * @param string[] $columns
     * @return string[]
     */
    private function urlExportColumns(array $columns): array
    {
        $kept = [];
        foreach ($columns as $col) {
            if ($this->urlColumnExpr('c', (string)$col) !== null) {
                $kept[] = (string)$col;
            }
        }
        return $kept ?: ['url'];
    }

    /** @param string[] $columns */
    private function buildUrlSelectList(array $columns): string
    {
        $select = [];
        foreach ($this->urlExportColumns($columns) as $col) {
            $select[] = $this->urlColumnExpr('c', $col) . " AS {$col}";
        }
        return implode(', ', $select);
    }

    /**
     * The table's own WHERE clause, replayed verbatim with its params BOUND.
     *
     * The explorers build their WHERE with PDO placeholders (`c.url LIKE :url_0`)
     * and keep the values in a separate array. Before this, only the clause was
     * posted: the placeholders reached the database unbound, which is a syntax
     * error — every export filtered on anything other than a boolean died there
     * ("Échec" in the download center, worker exit code 0). Now the values ride
     * along in `report_params` and are bound like everywhere else.
     *
     * The clause is trusted only when it carries this install's signature
     * ({@see ExportScope}); an unsigned one is still limited to plain boolean
     * conditions. User-supplied values live in the params, so they never reach
     * that check — and never reach the SQL text either.
     *
     * @param array<string,mixed> $params  the stored export params
     * @param array<string,mixed> $sqlParams (by ref) receives the bound values
     */
    private function reportScope(array $params, array &$sqlParams): string
    {
        $raw = trim((string)($params['report_where'] ?? ''));
        $where = preg_replace('/^\s*WHERE\s+/i', '', $raw);
        if ($where === '' || $where === '1=1') {
            return '';
        }
        // A signed clause is one this install rendered → replay it as-is, including
        // the sub-SELECT the lost-urls / new-urls reports need (their scope compares
        // against another crawl's partition; the old keyword filter silently dropped
        // it, so those exports quietly returned the WHOLE crawl).
        if (!ExportScope::verify($raw, (string)($params['report_sig'] ?? ''))) {
            // Unsigned (API caller, or a tampered payload): only plain boolean
            // conditions are allowed through.
            if (preg_match('/[;]|--|\/\*|\*\/|\b(union|select|insert|update|delete|drop|alter|create|grant|truncate|into|information_schema|pg_catalog|system)\b/i', $where)) {
                throw new \RuntimeException('Export scope rejected: unsigned report_where contains unsafe SQL');
            }
        }
        foreach ($this->decodeReportParams($params['report_params'] ?? []) as $name => $value) {
            $sqlParams[$name] = $value;
        }
        return $where;
    }

    /**
     * Normalize the posted report params to `:name => scalar`. Anything that
     * isn't a plain placeholder/scalar pair is dropped (it could not have come
     * from a table's PDO param array).
     *
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private function decodeReportParams($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $name => $value) {
            if (!is_string($name) || !preg_match('/^:?[a-z_][a-z0-9_]*$/i', $name)) {
                continue;
            }
            if ($value !== null && !is_scalar($value)) {
                continue;
            }
            $out[':' . ltrim($name, ':')] = $value;
        }
        return $out;
    }

    /**
     * Keep only the params the query actually references — PDO rejects an execute()
     * carrying a placeholder that isn't in the statement ("Invalid parameter number").
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function usedParams(string $sql, array $params): array
    {
        $used = [];
        foreach ($params as $name => $value) {
            if (preg_match('/' . preg_quote($name, '/') . '\b/', $sql)) {
                $used[$name] = $value;
            }
        }
        return $used;
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
        $map = $this->linkSelectMap($base);
        $select = [];
        foreach ($map as $alias => $expr) {
            $select[] = "{$expr} AS {$alias}";
        }
        return implode(', ', $select);
    }

    /**
     * The CSV header of a links export — the aliases {@see linkSelectMap} emits,
     * in the same order, so header and rows can't drift.
     *
     * @param string[] $base
     * @return string[]
     */
    private function linkExportColumns(array $base): array
    {
        return array_keys($this->linkSelectMap($base));
    }

    /**
     * alias => SQL expression for a links export, in output order.
     *
     * @param string[] $base
     * @return array<string,string>
     */
    private function linkSelectMap(array $base): array
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
                // PG-style JSONB access: ChPdo rewrites `->>` to CH map access, so
                // the one expression is valid on both stores (the buffered PG path
                // reuses this very list).
                return "{$alias}.extracts->>'{$name}'";
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
            if ($e = $exprFor('cs', $col)) $select["source_{$col}"] = $e;
        }
        foreach ($linkCols as $col) {
            $select[$col] = $link[$col];
        }
        foreach ($urlCols as $col) {
            if ($e = $exprFor('ct', $col)) $select["target_{$col}"] = $e;
        }
        if (empty($select)) {
            $select = ['source_url' => 'cs.url', 'target_url' => 'ct.url'];
        }
        return $select;
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

    /** Page columns the explorers expose as true/false chips (never as a value). */
    private const BOOL_FILTER_FIELDS = [
        'compliant', 'canonical', 'noindex', 'nofollow', 'blocked', 'h1_multiple',
        'headings_missing', 'external', 'in_sitemap', 'is_html', 'crawled',
    ];

    /** Page columns the explorers filter numerically. */
    private const NUM_FILTER_FIELDS = [
        'depth', 'code', 'inlinks', 'outlinks', 'response_time', 'word_count', 'pri',
    ];

    /**
     * Fallback WHERE builder for callers that post a raw filter tree instead of a
     * table scope (`report_where`) — i.e. the API, not the explorers, which now
     * replay their own already-built clause.
     *
     * Accepts the three shapes seen in the wild: a list of groups
     * (`[{type:'group',logic,items:[…]}]` — what filter-bar.js puts in the URL),
     * a single group (`{logic,items:[…]}`), or a bare list of chips. Before this,
     * only the middle one was understood, so the URL's own filters were silently
     * ignored and the CSV came back unfiltered.
     *
     * @param mixed $filters
     * @param array<string,mixed> $params (by ref) bound values
     */
    private function buildFilterGroups($filters, array &$params): string
    {
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
        }
        if (!is_array($filters) || empty($filters)) {
            return '';
        }
        // Single group → wrap it so the list handling below covers both shapes.
        $groups = isset($filters['items']) ? [$filters] : $filters;
        if (!array_is_list($groups)) {
            return '';
        }

        $out = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $items = $group['items'] ?? (isset($group['field']) ? [$group] : null);
            if (!is_array($items)) {
                continue;
            }
            $conds = $this->buildFilterConditions($items, $params);
            if (empty($conds)) {
                continue;
            }
            $logic = strtoupper((string)($group['logic'] ?? 'AND'));
            if (!in_array($logic, ['AND', 'OR'], true)) {
                $logic = 'AND';
            }
            $inter = strtoupper((string)($group['interGroupLogic'] ?? 'AND'));
            if (!in_array($inter, ['AND', 'OR'], true)) {
                $inter = 'AND';
            }
            $clause = '(' . implode(' ' . $logic . ' ', $conds) . ')';
            $out[] = empty($out) ? $clause : $inter . ' ' . $clause;
        }
        return empty($out) ? '' : '(' . implode(' ', $out) . ')';
    }

    /**
     * Build parameterized WHERE conditions from a filter tree. Column names are
     * whitelisted, values are bound, and a chip this builder can't express
     * EXACTLY like the explorer does is skipped rather than turned into
     * approximate SQL (a boolean chip used to become `c.external = 'false'`,
     * which ClickHouse rejects outright since the column is a UInt8).
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
            if (!is_array($item)) continue;
            if (isset($item['type']) && $item['type'] === 'group') {
                $sub = $this->buildFilterConditions($item['items'] ?? [], $params);
                if (!empty($sub)) {
                    $gl = strtoupper($item['logic'] ?? 'AND');
                    if (!in_array($gl, ['AND', 'OR'], true)) $gl = 'AND';
                    $conditions[] = '(' . implode(' ' . $gl . ' ', $sub) . ')';
                }
                continue;
            }
            $field = (string)($item['field'] ?? '');
            $operator = (string)($item['operator'] ?? '=');
            $value = $item['value'] ?? '';
            if ($field === '' || !preg_match('/^[a-z_][a-z0-9_]*$/i', $field)) continue;

            // Boolean chips carry no operator — `true`/`false` are SQL literals,
            // not bindable values (the column is a UInt8 on ClickHouse).
            if (in_array($field, self::BOOL_FILTER_FIELDS, true)) {
                $conditions[] = "c.{$field} = " . ($value === 'true' || $value === true ? 'true' : 'false');
                continue;
            }
            if ($field === 'out_of_scope') {
                $expr = '(c.external = false AND c.blocked = false AND c.crawled = false)';
                $conditions[] = ($value === 'true' || $value === true) ? $expr : "NOT {$expr}";
                continue;
            }
            // category: the chips carry names here (ids are a UI-only concern).
            if ($field === 'category' && in_array($operator, ['in', 'not_in'], true)) {
                $names = is_array($value) ? $value : [$value];
                $ph = [];
                foreach ($names as $name) {
                    if (!is_scalar($name)) continue;
                    $p = ':p' . (++$counter);
                    $ph[] = $p;
                    $params[$p] = (string)$name;
                }
                if (empty($ph)) continue;
                $conditions[] = $operator === 'not_in'
                    ? "(c.category NOT IN (" . implode(',', $ph) . ") OR c.category = '')"
                    : "c.category IN (" . implode(',', $ph) . ")";
                continue;
            }
            // Everything else is scalar-only: an array value (http-code groups, SEO
            // status sets, schema types…) has no faithful generic translation.
            if (!is_scalar($value)) continue;

            $isNum = in_array($field, self::NUM_FILTER_FIELDS, true);
            $p = ':p' . (++$counter);
            switch ($operator) {
                case 'contains':      $conditions[] = "c.{$field} ILIKE {$p}";  $params[$p] = '%' . $value . '%'; break;
                case 'not_contains':  $conditions[] = "(c.{$field} NOT ILIKE {$p} OR c.{$field} IS NULL)"; $params[$p] = '%' . $value . '%'; break;
                case 'starts_with':   $conditions[] = "c.{$field} ILIKE {$p}";  $params[$p] = $value . '%'; break;
                case 'ends_with':     $conditions[] = "c.{$field} ILIKE {$p}";  $params[$p] = '%' . $value; break;
                case 'regex':         $conditions[] = "c.{$field} ~* {$p}";     $params[$p] = (string)$value; break;
                case 'not_regex':     $conditions[] = "(c.{$field} !~* {$p} OR c.{$field} IS NULL)"; $params[$p] = (string)$value; break;
                case 'is_empty':      $conditions[] = "(c.{$field} IS NULL OR c.{$field} = '')"; break;
                case 'is_not_empty':  $conditions[] = "(c.{$field} IS NOT NULL AND c.{$field} != '')"; break;
                case '>': case '<': case '>=': case '<=': case '=': case '!=':
                    if (!$isNum) { $conditions[] = "c.{$field} {$operator} {$p}"; $params[$p] = (string)$value; break; }
                    $conditions[] = "c.{$field} {$operator} {$p}";
                    $params[$p] = ($field === 'pri' || $field === 'response_time') ? (float)$value : (int)$value;
                    break;
            }
        }
        return $conditions;
    }
}
