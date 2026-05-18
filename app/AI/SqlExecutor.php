<?php

namespace App\AI;

use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use PDO;

/**
 * Safe SQL executor for the AI assistant (Dr. Brief).
 *
 * Mirrors the security rules of QueryController::execute() — exactly the same
 * whitelist, the same forbidden keywords, the same transformation of
 * `pages` → `pages_<crawl_id>`. On top of that:
 *
 *   - Forces a READ ONLY transaction (defense in depth) — even if a future bug
 *     in the validator let a write keyword through, PostgreSQL itself would
 *     reject it.
 *   - Sets statement_timeout to 10s so Dr. Brief can't hang the server with
 *     a runaway query.
 *   - Returns a structured result instead of HTTP responses (the controller
 *     emits SSE events, not JSON).
 *
 * The code is largely COPIED from QueryController on purpose : the AI agent
 * needs identical guarantees, and we'd rather duplicate a hundred lines once
 * than risk subtle divergence over time. To be DRY'd up later if both paths
 * accumulate drift.
 *
 * @package    Scouter
 * @subpackage AI
 */
class SqlExecutor
{
    private const FORBIDDEN_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
        'TRUNCATE', 'REPLACE', 'RENAME', 'ATTACH', 'DETACH',
        'VACUUM', 'REINDEX', 'GRANT', 'REVOKE', 'COPY',
    ];

    private const FORBIDDEN_FUNCTIONS = [
        'pg_sleep', 'pg_read_file', 'pg_read_binary_file', 'pg_ls_dir',
        'pg_stat_file', 'lo_import', 'lo_export', 'dblink',
        'pg_advisory_lock', 'pg_terminate_backend', 'pg_cancel_backend',
        'set_config', 'current_setting',
    ];

    private const ALLOWED_BASE_TABLES = [
        'crawl_categories',
        'pages', 'links',
        'duplicate_clusters', 'page_schemas', 'redirect_chains',
    ];

    private const HARD_ROW_CAP   = 10000;
    // Preview cap for chat tool calls. Raised from 10 → 100 so the model has
    // enough rows to spot patterns (per-bucket samples, distributions, lists
    // long enough to be representative). The UI still tells the user it's a
    // capped sample when truncated=true, and the deeplink-to-SQL-Explorer
    // button lets them see the full result.
    private const PREVIEW_ROWS   = 100;
    private const TIMEOUT_SECONDS = 10;

    /**
     * Validate + transform + execute a SQL query against a specific crawl.
     *
     * @param string $query     raw SQL from the AI
     * @param int    $crawlId   target crawl (used to expand `pages` → `pages_<id>`)
     * @param int    $rowLimit  max rows to return in the preview (kept low for chat;
     *                          full results require the deeplink to SQL Explorer)
     * @return array{
     *     ok: bool,
     *     rows?: array<int, array>,
     *     columns?: string[],
     *     total_rows?: int,
     *     truncated?: bool,
     *     transformed_sql?: string,
     *     deeplink_sql?: string,
     *     error?: string
     * }
     */
    public function execute(string $query, int $crawlId, int $rowLimit = self::PREVIEW_ROWS): array
    {
        try {
            // === Strip comments before keyword scan ===
            $clean = preg_replace('/\/\*.*?\*\//s', ' ', $query);
            $clean = preg_replace('/--.*$/m', ' ', $clean);
            $cleanUpper = strtoupper(trim($clean));

            // Accept SELECT or WITH (CTE) at the start. WITH RECURSIVE stays
            // blocked further down — that's the actual runaway-loop risk.
            // Write CTEs like `WITH x AS (INSERT ...) ...` are caught by the
            // FORBIDDEN_KEYWORDS scan below + the READ ONLY transaction.
            if (strpos($cleanUpper, 'SELECT') !== 0 && strpos($cleanUpper, 'WITH') !== 0) {
                return ['ok' => false, 'error' => 'Only SELECT or WITH … SELECT statements are allowed.'];
            }

            // === Multi-statement attack ===
            if (preg_match('/;\s*(INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|GRANT|REVOKE|COPY|SET|DO|CALL)/i', $clean)) {
                return ['ok' => false, 'error' => 'Multi-statement queries are not allowed.'];
            }

            // === Forbidden keywords (write ops) ===
            foreach (self::FORBIDDEN_KEYWORDS as $kw) {
                if (preg_match('/\b' . $kw . '\b/i', $clean)) {
                    return ['ok' => false, 'error' => "Forbidden keyword: {$kw}"];
                }
            }

            // === Dangerous PostgreSQL functions ===
            foreach (self::FORBIDDEN_FUNCTIONS as $fn) {
                if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $clean)) {
                    return ['ok' => false, 'error' => "Forbidden function: {$fn}"];
                }
            }

            if (preg_match('/\b(PREPARE|EXECUTE|EXPLAIN|WITH\s+RECURSIVE)\b/i', $clean)) {
                return ['ok' => false, 'error' => 'PREPARE / EXECUTE / EXPLAIN / WITH RECURSIVE not allowed.'];
            }
            if (preg_match('/COPY\s+.*\s+TO\s+PROGRAM/i', $clean)) {
                return ['ok' => false, 'error' => 'COPY TO PROGRAM not allowed.'];
            }

            // === Multi-crawl @id references — must stay inside the same project ===
            $crawlRecord = CrawlDatabase::getCrawlById($crawlId);
            if (!$crawlRecord) {
                return ['ok' => false, 'error' => 'Crawl not found.'];
            }

            $referencedCrawlIds = [];
            $transformed = preg_replace_callback(
                '/\b(pages|links|duplicate_clusters|page_schemas|redirect_chains|html)@(\d+)\b/i',
                function ($m) use (&$referencedCrawlIds) {
                    $referencedCrawlIds[] = (int)$m[2];
                    return $m[1] . '_' . $m[2];
                },
                $clean
            );
            $transformed = preg_replace('/\bcategories@\d+\b/i', 'crawl_categories', $transformed);

            foreach (array_unique($referencedCrawlIds) as $refId) {
                $ref = CrawlDatabase::getCrawlById($refId);
                if (!$ref || $ref->project_id !== $crawlRecord->project_id) {
                    return ['ok' => false, 'error' => "Cannot query crawl {$refId}: not in the same project."];
                }
            }

            // === Substitute virtual table names with the current crawl partition ===
            $transformed = preg_replace('/\bpages\b(?!_\d)/i',              "pages_{$crawlId}",              $transformed);
            $transformed = preg_replace('/\blinks\b(?!_\d)/i',              "links_{$crawlId}",              $transformed);
            $transformed = preg_replace('/(?<!crawl_)\bcategories\b(?!_\d)/i', "crawl_categories",           $transformed);
            $transformed = preg_replace('/\bduplicate_clusters\b(?!_\d)/i', "duplicate_clusters_{$crawlId}", $transformed);
            $transformed = preg_replace('/\bpage_schemas\b(?!_\d)/i',       "page_schemas_{$crawlId}",       $transformed);
            $transformed = preg_replace('/\bredirect_chains\b(?!_\d)/i',    "redirect_chains_{$crawlId}",    $transformed);

            // === Extract CTE names so the whitelist doesn't flag them ===
            // A query like `WITH ranked AS (SELECT ...) SELECT * FROM ranked`
            // would otherwise fail the whitelist on `ranked` because it appears
            // after FROM. We collect all CTE identifiers and treat them as
            // valid local table names for this query only.
            $cteNames = [];
            if (preg_match_all(
                '/(?:\bWITH\s+|,\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s+AS\s*\(/i',
                $transformed,
                $cteMatches
            )) {
                foreach ($cteMatches[1] as $name) {
                    $cteNames[] = strtolower($name);
                }
            }

            // === Whitelist check on the final table names ===
            preg_match_all(
                '/\b(?:FROM|JOIN)\s+(?:"?[a-zA-Z_][a-zA-Z0-9_]*"?\s*\.\s*)?"?([a-zA-Z_][a-zA-Z0-9_]*)"?/i',
                $transformed,
                $tableMatches
            );
            foreach (array_unique($tableMatches[1] ?? []) as $tableName) {
                $low = strtolower($tableName);
                // `html_<id>` is whitelisted in suffix form only — there's no
                // unqualified `html` table at the project level (and `.html`
                // would clash with URL patterns in regex literals if we did
                // a bare-name substitution).
                $allowed = in_array($low, self::ALLOWED_BASE_TABLES, true)
                    || in_array($low, $cteNames, true)
                    || preg_match('/^(pages|links|duplicate_clusters|page_schemas|redirect_chains|html)_\d+$/i', $low);
                if (!$allowed) {
                    return [
                        'ok' => false,
                        'error' => "Table '{$tableName}' is not whitelisted. Allowed: "
                            . implode(', ', self::ALLOWED_BASE_TABLES),
                    ];
                }
            }

            // === Project-scope guard on crawl_categories ===
            //
            // crawl_categories is a shared, project-level table (no partition
            // per crawl_id like pages/links/...). Without a WHERE clause, a
            // SELECT against it leaks rows from every project on the instance.
            // We shadow the real table with a same-named CTE that pre-filters
            // on the current project — PostgreSQL resolves every reference
            // to `crawl_categories` (FROM, JOIN, subquery) against the CTE
            // instead of the underlying table, so it's impossible for the
            // model to fetch categories from another project, regardless of
            // how the query is phrased.
            //
            // Same idea (and same fix) as in QueryController::execute().
            $pid = (int)($crawlRecord->project_id ?? 0);
            if ($pid > 0) {
                $catScope = "crawl_categories AS (SELECT * FROM crawl_categories WHERE project_id = {$pid})";
                if (preg_match('/^\s*WITH\s+/i', $transformed)) {
                    $transformed = preg_replace(
                        '/^\s*WITH\s+/i', "WITH {$catScope}, ", $transformed, 1
                    );
                } else {
                    $transformed = "WITH {$catScope} " . ltrim($transformed);
                }
            }

            // === Cap rows ===
            // The "preview" cap is the chat row limit; the absolute hard cap
            // prevents accidental gigabyte selects. We always rewrite LIMIT
            // when present to enforce the preview, and append one if missing.
            //
            // First strip trailing semicolons + whitespace — the AI sometimes
            // ends its statement with `;` which would cause
            //   "... WHERE x = 1; LIMIT 10"
            // → syntax error. Strip them defensively so the AI doesn't have
            // to be perfect.
            $transformed = rtrim($transformed);
            $transformed = preg_replace('/;+\s*$/', '', $transformed);
            $transformed = rtrim($transformed);

            $cap = max(1, min($rowLimit, self::HARD_ROW_CAP));
            if (preg_match('/\bLIMIT\s+(\d+)/i', $transformed, $lm)) {
                $existing = (int)$lm[1];
                $newLimit = min($existing, $cap);
                $finalSql = preg_replace('/\bLIMIT\s+\d+/i', "LIMIT {$newLimit}", $transformed, 1);
            } else {
                $finalSql = $transformed . " LIMIT {$cap}";
            }

            // The SQL that the deeplink should preserve = the transformed query
            // WITHOUT the preview LIMIT, but with the partition substitutions
            // reverted to the virtual names (so the user sees `pages` not
            // `pages_42` in the SQL Explorer editor — friendlier).
            $deeplinkSql = preg_replace('/\bLIMIT\s+\d+\s*$/i', '', trim($transformed));
            $deeplinkSql = preg_replace('/_' . $crawlId . '\b/', '', $deeplinkSql);

            // === Execute, hard read-only, with timeout ===
            $db = PostgresDatabase::getInstance()->getConnection();
            $db->exec("SET statement_timeout = '" . self::TIMEOUT_SECONDS . "s'");
            $db->exec("BEGIN; SET TRANSACTION READ ONLY");
            try {
                $stmt = $db->query($finalSql);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $db->exec("COMMIT");
                $db->exec("SET statement_timeout = '0'");
            }

            $columns = !empty($rows) ? array_keys($rows[0]) : [];

            // total_rows = number of rows we got. truncated = true if user might
            // have wanted more. We can't know the "true" total without re-running
            // a COUNT, so we just signal that hitting exactly the cap likely means
            // there's more.
            $totalRows = count($rows);
            $truncated = $totalRows >= $cap;

            return [
                'ok'              => true,
                'rows'            => $rows,
                'columns'         => $columns,
                'total_rows'      => $totalRows,
                'truncated'       => $truncated,
                'transformed_sql' => $finalSql,
                'deeplink_sql'    => trim($deeplinkSql),
            ];

        } catch (\Throwable $e) {
            // Defensive cleanup if we threw mid-transaction.
            try {
                PostgresDatabase::getInstance()->getConnection()->exec("ROLLBACK");
                PostgresDatabase::getInstance()->getConnection()->exec("SET statement_timeout = '0'");
            } catch (\Throwable $ignored) {}

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
