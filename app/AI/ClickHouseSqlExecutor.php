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

    // NB : `html` (le HTML brut des pages, ~centaines de Ko/ligne) est VOLONTAIREMENT
    // exclu — non requêtable depuis l'explorer SQL. C'est la seule colonne « lourde » ;
    // l'exclure permet de lever tout plafond de lignes (open bar / export complet) sans
    // risque mémoire. `SELECT * FROM pages` ne la contient pas (table séparée).
    private const ALLOWED_BASE_TABLES = [
        'pages', 'links', 'duplicate_clusters', 'page_schemas', 'redirect_chains',
        // Project-level category values table (id/cat/color) built live from the
        // YAML rules — lets PG-style `JOIN crawl_categories ON p.cat_id = c.id` run.
        'crawl_categories',
    ];

    private const HARD_ROW_CAP    = 10000;
    // Plafond de l'explorer SQL (rowLimit <= 0) : haut pour permettre un gros export,
    // mais borné pour ne pas saturer le navigateur / la réponse HTTP sur un crawl à
    // plusieurs millions de lignes (ex. infinite). Au-delà → message "export CSV".
    private const EXPLORER_ROW_CAP = 500000;
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
        // Open bar quand rowLimit <= 0 (l'explorer) : la colonne lourde `html` étant
        // bloquée (cf. ALLOWED_BASE_TABLES), aucune bombe mémoire → on ne plafonne PAS
        // le nombre de lignes, pour permettre un export CSV complet. Sinon (AI/aperçu,
        // rowLimit > 0) on wrappe + plafonne. NB : on ne bricole PAS le LIMIT au regex —
        // le SQL réécrit contient `LIMIT 1 BY id` (dédup) qu'un regex prendrait pour un
        // vrai LIMIT.
        // L'explorer (rowLimit <= 0) plafonne haut (EXPLORER_ROW_CAP) pour permettre un
        // gros export ; les autres usages (AI/aperçu) à HARD_ROW_CAP. `html` étant bloqué,
        // les lignes sont légères. Toujours wrappé + LIMIT (jamais de bricolage regex du
        // `LIMIT 1 BY` de dédup). Au-delà du plafond, le front affiche déjà « X lignes sur
        // :total — utilisez l'export CSV » (sql_explorer.display_limited_detail).
        $cap = ($rowLimit <= 0) ? self::EXPLORER_ROW_CAP : max(1, min($rowLimit, self::HARD_ROW_CAP));
        $finalSql = "SELECT * FROM (" . $prep['transformed'] . ") AS _scouter_q LIMIT {$cap}";
        $big = ($cap > self::HARD_ROW_CAP);

        try {
            $rows = $this->ch->select($this->withSettings($finalSql, $big));
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
     * Stream a validated, crawl-scoped SELECT straight to $fh as CSVWithNames
     * (ClickHouse → disk, NO PHP row buffering). Used by the async CSV export so
     * a multi-million-row SQL export never materialises its rows in PHP memory
     * (that path OOM-killed the worker before writing a single line). Open bar:
     * no row cap (the heavy `html` column is blocked → rows stay light), only a
     * generous execution-time guard is kept.
     *
     * @throws \RuntimeException on validation failure or ClickHouse error
     */
    public function streamToFile(string $query, int $crawlId, $fh): void
    {
        $prep = $this->prepareSafeSql($query, $crawlId);
        if (!$prep['ok']) {
            throw new \RuntimeException($prep['error'] ?? 'Invalid SQL');
        }
        $sql = "SELECT * FROM (" . $prep['transformed'] . ") AS _scouter_q\nFORMAT CSVWithNames";
        $this->ch->streamSelectToFile($sql, $fh, [
            'format_csv_delimiter' => ';',
            'max_execution_time'   => '600',
        ]);
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

            // Accept PostgreSQL-dialect SQL, not just ClickHouse-native: translate
            // the PG-isms the report shim also normalises (::casts, ->>/JSON,
            // COUNT(*) FILTER, ~*/~ POSIX regex, unnest, …) to ClickHouse, reusing
            // the SAME translator (ChPdo) as the reports. So Dr Brief (whose prompt
            // is PG-flavoured), the SQL Explorer and the MCP all run identically and
            // a user typing either dialect just works. CH-native SQL is untouched —
            // the rules only match PG patterns. Done AFTER the SELECT-only/forbidden
            // checks (validated on the user's original input) and BEFORE table
            // substitution (translation never touches FROM/JOIN names or `@id`).
            $chpdo = new \App\Database\ChPdo($crawlId);
            $clean = $chpdo->translateDialectOnly($clean);

            // Multi-crawl `table@<id>`: SECURITY — only validate the referenced
            // crawl is in the same project (the actual rewrite is delegated to
            // ChPdo below, which also handles aliases + crawl_categories). PG-style
            // `pages_<id>` is normalised to `@id` first so the check covers it.
            $clean = preg_replace('/\b(pages|links|duplicate_clusters|page_schemas|redirect_chains|html)_(\d+)\b/i', '$1@$2', $clean);
            if (preg_match_all('/\b(?:pages|links|duplicate_clusters|page_schemas|redirect_chains|html)@(\d+)\b/i', $clean, $atMatches)) {
                foreach (array_unique($atMatches[1]) as $refId) {
                    $ref = CrawlDatabase::getCrawlById((int) $refId);
                    if (!$ref || (int) ($ref->project_id ?? -1) !== $projectId) {
                        return ['ok' => false, 'error' => "Cannot query crawl {$refId}: not in the same project."];
                    }
                }
            }

            // CTE names are allowed table references (extract before whitelist).
            $cteNames = [];
            if (preg_match_all('/(?:\bWITH\s+|,\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s+AS\s*\(/i', $clean, $cteMatches)) {
                foreach ($cteMatches[1] as $name) {
                    $cteNames[] = strtolower($name);
                }
            }

            // Whitelist check: every FROM/JOIN target must be a whitelisted virtual
            // table (optionally `@id`) or a CTE. Done on the user input, before the
            // rewrite turns the names into subqueries.
            preg_match_all('/\b(?:FROM|JOIN)\s+([a-zA-Z_][a-zA-Z0-9_]*)\b/i', $clean, $tableMatches);
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

            // Delegate the table rewriting to ChPdo — the SAME engine the reports
            // use — so virtual names (bare + `@id`), aliases (`pages p`) and
            // crawl_categories all resolve exactly like in the dashboard, with no
            // duplicated/ad-hoc substitution drifting out of sync.
            $transformed = $chpdo->translateTablesOnly($clean);

            $transformed = preg_replace('/;+\s*$/', '', rtrim($transformed));
            $deeplinkSql = preg_replace('/\bLIMIT\s+\d+\s*$/i', '', trim($clean));

            return ['ok' => true, 'transformed' => trim($transformed), 'deeplink_sql' => trim($deeplinkSql)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Append the read guardrails as a ClickHouse SETTINGS clause.
     *
     * $unlimited (l'explorer, html bloqué) : pas de plafond de lignes — export complet.
     * Garde-fous conservés : temps d'exécution + un plafond mémoire généreux (1 Go) pour
     * ne pas faire fondre le serveur sur un crawl démesuré.
     */
    private function withSettings(string $sql, bool $big = false): string
    {
        if ($big) {
            // Explorer : jusqu'à EXPLORER_ROW_CAP lignes (le LIMIT externe borne déjà) ;
            // plafond mémoire généreux (1 Go) + temps élargi comme garde-fous.
            return $sql . " SETTINGS max_execution_time = 60, max_result_rows = " . (self::EXPLORER_ROW_CAP + 1000)
                . ", max_result_bytes = 1073741824, result_overflow_mode = 'throw'";
        }
        return $sql . " SETTINGS max_execution_time = " . self::TIMEOUT_SECONDS
            . ", max_result_rows = " . (self::HARD_ROW_CAP + 1000)
            . ", max_result_bytes = 268435456, result_overflow_mode = 'throw'";
    }
}
