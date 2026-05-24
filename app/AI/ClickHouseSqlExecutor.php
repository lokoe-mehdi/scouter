<?php

namespace App\AI;

use App\Database\ClickHouseDatabase;
use App\Database\CrawlDatabase;
use App\Database\PostgresDatabase;
use App\Analysis\CategoryExpr;

/**
 * Safe SQL executor for the ClickHouse data store (SQL Explorer / API v1 / MCP).
 *
 * Same contract as {@see SqlExecutor} (execute / executePaginated) but targets
 * ClickHouse. The key isolation change vs PostgreSQL: there are no per-crawl
 * partition tables (`pages_<id>`); instead every virtual table name is rewritten
 * to a `crawl_id`-filtered subquery, which *forces* crawl isolation no matter
 * what the query does. The `pages` subquery also:
 *
 *   - LEFT JOINs page_metrics so inlinks / pri / *_status / in_sitemap are
 *     available as columns (they are derived, not stored on pages), and
 *   - exposes a live `category` column computed from the project's YAML rules
 *     ({@see CategoryExpr}) — there is no stored cat_id anymore.
 *
 * Multi-crawl `table@<id>` references are allowed only within the same project.
 *
 * @package    Scouter
 * @subpackage AI
 */
class ClickHouseSqlExecutor
{
    private const FORBIDDEN_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
        'TRUNCATE', 'RENAME', 'ATTACH', 'DETACH', 'OPTIMIZE',
        'GRANT', 'REVOKE', 'SYSTEM', 'KILL', 'SET',
    ];

    // ClickHouse table/dictionary functions that read external resources or the
    // filesystem — never allowed from user/AI SQL.
    private const FORBIDDEN_FUNCTIONS = [
        'file', 'url', 'remote', 'remotesecure', 's3', 's3cluster', 'hdfs',
        'jdbc', 'odbc', 'mysql', 'postgresql', 'mongodb', 'executable',
        'cluster', 'clusterallreplicas', 'dictionary', 'sqlite', 'azureblobstorage',
    ];

    private const ALLOWED_BASE_TABLES = [
        'pages', 'links', 'duplicate_clusters', 'page_schemas', 'redirect_chains', 'html',
    ];

    private const HARD_ROW_CAP    = 10000;
    private const PREVIEW_ROWS    = 100;
    private const TIMEOUT_SECONDS = 15;

    private ClickHouseDatabase $ch;
    private string $db;

    public function __construct()
    {
        $this->ch = ClickHouseDatabase::getInstance();
        $this->db = $this->ch->getDatabase();
    }

    /** @return array see SqlExecutor::execute() */
    public function execute(string $query, int $crawlId, int $rowLimit = self::PREVIEW_ROWS): array
    {
        $prep = $this->prepareSafeSql($query, $crawlId);
        if (!$prep['ok']) {
            return $prep;
        }
        $cap = max(1, min($rowLimit, self::HARD_ROW_CAP));
        $transformed = $prep['transformed'];
        if (preg_match('/\bLIMIT\s+(\d+)/i', $transformed, $lm)) {
            $newLimit = min((int)$lm[1], $cap);
            $finalSql = preg_replace('/\bLIMIT\s+\d+/i', "LIMIT {$newLimit}", $transformed, 1);
        } else {
            $finalSql = $transformed . " LIMIT {$cap}";
        }

        try {
            $rows = $this->ch->select($this->withSettings($finalSql));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $total = count($rows);
        return [
            'ok'              => true,
            'rows'            => $rows,
            'columns'         => !empty($rows) ? array_keys($rows[0]) : [],
            'total_rows'      => $total,
            'truncated'       => $total >= $cap,
            'transformed_sql' => $finalSql,
            'deeplink_sql'    => $prep['deeplink_sql'],
        ];
    }

    /** @return array see SqlExecutor::executePaginated() */
    public function executePaginated(string $query, int $crawlId, int $pageSize, int $offset, bool $withCount = true): array
    {
        $pageSize = max(1, min($pageSize, self::HARD_ROW_CAP));
        $offset   = max(0, $offset);

        $prep = $this->prepareSafeSql($query, $crawlId);
        if (!$prep['ok']) {
            return $prep;
        }
        $sub = '(' . $prep['transformed'] . ') AS _scouter_q';

        try {
            $total = null;
            if ($withCount) {
                $total = (int) $this->ch->selectValue($this->withSettings("SELECT count() FROM {$sub}"));
            }
            $rows = $this->ch->select($this->withSettings("SELECT * FROM {$sub} LIMIT {$pageSize} OFFSET {$offset}"));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return [
            'ok'        => true,
            'rows'      => $rows,
            'columns'   => !empty($rows) ? array_keys($rows[0]) : [],
            'total'     => $total,
            'page_size' => $pageSize,
            'offset'    => $offset,
        ];
    }

    /**
     * Validate + rewrite into safe, crawl-scoped ClickHouse SQL.
     *
     * @return array{ok:true,transformed:string,deeplink_sql:string}|array{ok:false,error:string}
     */
    private function prepareSafeSql(string $query, int $crawlId): array
    {
        try {
            $clean = preg_replace('/\/\*.*?\*\//s', ' ', $query);
            $clean = preg_replace('/--.*$/m', ' ', $clean);
            $cleanUpper = strtoupper(trim($clean));

            if (strpos($cleanUpper, 'SELECT') !== 0 && strpos($cleanUpper, 'WITH') !== 0) {
                return ['ok' => false, 'error' => 'Only SELECT or WITH … SELECT statements are allowed.'];
            }
            if (preg_match('/;\s*(SELECT|WITH|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|GRANT|REVOKE|SYSTEM|SET|OPTIMIZE|RENAME)\b/i', $clean)) {
                return ['ok' => false, 'error' => 'Multi-statement queries are not allowed.'];
            }
            foreach (self::FORBIDDEN_KEYWORDS as $kw) {
                if (preg_match('/\b' . $kw . '\b/i', $clean)) {
                    return ['ok' => false, 'error' => "Forbidden keyword: {$kw}"];
                }
            }
            foreach (self::FORBIDDEN_FUNCTIONS as $fn) {
                if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $clean)) {
                    return ['ok' => false, 'error' => "Forbidden function: {$fn}"];
                }
            }
            // Block access to ClickHouse system / information databases.
            if (preg_match('/\b(system|information_schema|INFORMATION_SCHEMA)\s*\./i', $clean)) {
                return ['ok' => false, 'error' => 'System databases are not accessible.'];
            }

            $crawlRecord = CrawlDatabase::getCrawlById($crawlId);
            if (!$crawlRecord) {
                return ['ok' => false, 'error' => 'Crawl not found.'];
            }
            $projectId = (int) ($crawlRecord->project_id ?? 0);

            // Multi-crawl table@<id> — validate same project, replace with that
            // crawl's filtered subquery.
            $transformed = preg_replace_callback(
                '/\b(pages|links|duplicate_clusters|page_schemas|redirect_chains|html)@(\d+)\b/i',
                function ($m) use ($projectId, &$error) {
                    $refId = (int) $m[2];
                    $ref = CrawlDatabase::getCrawlById($refId);
                    if (!$ref || (int) ($ref->project_id ?? -1) !== $projectId) {
                        $error = "Cannot query crawl {$refId}: not in the same project.";
                        return $m[0];
                    }
                    return $this->tableSub(strtolower($m[1]), $refId);
                },
                $clean
            );
            if (!empty($error)) {
                return ['ok' => false, 'error' => $error];
            }

            // CTE names are allowed table references (extract before whitelist).
            $cteNames = [];
            if (preg_match_all('/(?:\bWITH\s+|,\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s+AS\s*\(/i', $transformed, $cteMatches)) {
                foreach ($cteMatches[1] as $name) {
                    $cteNames[] = strtolower($name);
                }
            }

            // Whitelist check on the bare virtual names still present (those not
            // already rewritten to subqueries via @id). Reserved-word table names
            // following FROM/JOIN must be whitelisted base tables or CTEs.
            preg_match_all('/\b(?:FROM|JOIN)\s+([a-zA-Z_][a-zA-Z0-9_]*)\b/i', $transformed, $tableMatches);
            foreach (array_unique($tableMatches[1] ?? []) as $tableName) {
                $low = strtolower($tableName);
                if (in_array($low, self::ALLOWED_BASE_TABLES, true) || in_array($low, $cteNames, true)) {
                    continue;
                }
                return [
                    'ok' => false,
                    'error' => "Table '{$tableName}' is not whitelisted. Allowed: " . implode(', ', self::ALLOWED_BASE_TABLES),
                ];
            }

            // Replace the current-crawl virtual base names with filtered subqueries.
            foreach (self::ALLOWED_BASE_TABLES as $tbl) {
                $transformed = preg_replace_callback(
                    '/\b(FROM|JOIN)\s+' . $tbl . '\b/i',
                    fn($m) => $m[1] . ' ' . $this->tableSub($tbl, $crawlId),
                    $transformed
                );
            }

            $transformed = preg_replace('/;+\s*$/', '', rtrim($transformed));
            $deeplinkSql = preg_replace('/\bLIMIT\s+\d+\s*$/i', '', trim($clean));

            return ['ok' => true, 'transformed' => trim($transformed), 'deeplink_sql' => trim($deeplinkSql)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * The crawl_id-filtered subquery that backs a virtual table name. Reuses the
     * EXACT same source builder as the report shim (ChPdo) so the `pages` shape —
     * cat_id, category, inlinks/pri/*_status/in_sitemap, generation, in_crawl —
     * never drifts between the reports and the SQL Explorer.
     */
    private function tableSub(string $table, int $crawlId): string
    {
        $src = (new \App\Database\ChPdo((int) $crawlId))->virtualSource($table, (int) $crawlId);
        return $src . ' AS ' . $table;
    }

    /** Append the read guardrails as a ClickHouse SETTINGS clause. */
    private function withSettings(string $sql): string
    {
        return $sql . " SETTINGS max_execution_time = " . self::TIMEOUT_SECONDS
            . ", max_result_rows = " . (self::HARD_ROW_CAP + 1000)
            . ", max_result_bytes = 268435456, result_overflow_mode = 'throw'";
    }
}
