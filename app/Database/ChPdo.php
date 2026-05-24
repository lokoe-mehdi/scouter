<?php

namespace App\Database;

use App\Analysis\CategoryExpr;
use PDO;

/**
 * A PDO-compatible shim that lets the existing report pages run on ClickHouse
 * with (almost) no per-report changes.
 *
 * The reports were written for PostgreSQL: `$pdo->prepare($sql)->execute([':crawl_id'=>…])`
 * against the `pages` / `links` parent tables, using `cat_id`, `inlinks`, `pri`,
 * `*_status`, `generation`, etc. On ClickHouse those derived columns live in
 * `page_metrics` / `page_generation`, and categorization is computed live (no
 * cat_id stored). This shim bridges the gap centrally:
 *
 *   - rewrites `FROM/JOIN pages|links|…` into crawl_id-filtered subqueries that
 *     LEFT JOIN page_metrics + page_generation and expose a LIVE synthetic
 *     `cat_id` (the rule index) + `category` (the rule name) from the project's
 *     YAML — so `GROUP BY cat_id` + `$categoriesMap[cat_id]` keep working;
 *   - rewrites `crawl_categories` into an inline values table from the same rules;
 *   - translates the handful of PG-isms the reports use (`::numeric` casts,
 *     `COUNT(*) FILTER (WHERE …)`, JSONB `->>` / `jsonb_object_keys`).
 *
 * It implements just the slice of the PDO surface the reports touch
 * (prepare/query + execute/fetch/fetchAll/fetchColumn/rowCount), defaulting to
 * FETCH_OBJ like PostgresDatabase.
 *
 * @package    Scouter
 * @subpackage Database
 */
class ChPdo
{
    private ClickHouseDatabase $ch;
    private string $db;
    private int $crawlId;
    private int $projectId;
    private string $catIdExpr;
    private string $catNameExpr;
    private string $crawlCategoriesSource;

    public function __construct(int $crawlId)
    {
        $this->ch = ClickHouseDatabase::getInstance();
        $this->db = $this->ch->getDatabase();
        $this->crawlId = $crawlId;

        $pg = PostgresDatabase::getInstance()->getConnection();
        $stmt = $pg->prepare("SELECT project_id FROM crawls WHERE id = :id");
        $stmt->execute([':id' => $crawlId]);
        $this->projectId = (int) $stmt->fetchColumn();

        $ce = new CategoryExpr($pg);
        $rules = $ce->rulesForCrawl($crawlId);
        $this->catIdExpr = $ce->buildIdExpr($rules);
        $this->catNameExpr = $ce->build($rules);
        $this->crawlCategoriesSource = $this->buildCrawlCategoriesSource($rules);
    }

    /** The synthetic categoriesMap the dashboard exposes to reports (id => {cat,color}). */
    public static function categoriesMap(int $crawlId): array
    {
        $pg = PostgresDatabase::getInstance()->getConnection();
        $rules = (new CategoryExpr($pg))->rulesForCrawl($crawlId);
        $map = [];
        foreach ($rules as $i => $rule) {
            $map[$i + 1] = ['cat' => $rule['name'], 'color' => $rule['color']];
        }
        return $map;
    }

    public function prepare(string $sql): ChStmt
    {
        // Pass the ORIGINAL SQL: ChStmt binds the ?/:named placeholders first,
        // THEN calls translate(). Translating first would inject the category
        // regex (which contains literal '?' like '\?utm_source' and '(?i)') and
        // the positional-? binder would clobber it.
        return new ChStmt($this, $sql);
    }

    public function query(string $sql): ChStmt
    {
        $stmt = $this->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    /** No-op: reports occasionally call exec() for SET statements — ignore on CH. */
    public function exec(string $sql)
    {
        return 0;
    }

    /** PDO::quote() equivalent for ClickHouse string literals. */
    public function quote(string $value, int $type = 0): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
    }

    public function runSelect(string $sql, array $params): array
    {
        return $this->ch->select($sql, $params);
    }

    // -- SQL rewriting -------------------------------------------------------

    public function translate(string $sql): string
    {
        $sql = $this->rewriteTables($sql);
        $sql = $this->rewriteDialect($sql);
        return $sql;
    }

    /**
     * Dialect-only translation (keeps the virtual `pages`/`links` names): used to
     * render a chart's SQL-icon query so it is valid ClickHouse SQL the user can
     * run in the CH SQL Explorer (which applies its own crawl scoping).
     */
    public function translateDialectOnly(string $sql): string
    {
        return $this->rewriteDialect($sql);
    }

    /** The observed `pages` columns, enumerated explicitly (NOT `p.*`): CH's new
     *  analyzer fails to resolve a column from `t.*` through multiple joins, so we
     *  list them so every output column — crawl_id included — is unambiguous. */
    private const PAGE_COLS = [
        'crawl_id', 'id', 'date', 'domain', 'url', 'depth', 'code', 'response_time',
        'outlinks', 'content_type', 'redirect_to', 'crawled', 'compliant', 'noindex',
        'nofollow', 'canonical', 'canonical_value', 'external', 'blocked', 'title', 'h1',
        'metadesc', 'extracts', 'simhash', 'is_html', 'h1_multiple', 'headings_missing',
        'schemas', 'word_count',
    ];

    private function pagesSource(): string
    {
        $cid = $this->crawlId;
        // Alias every column so the output names are unqualified (`crawl_id`, not
        // `p.crawl_id`) — required for reports' WHERE/SELECT to resolve them.
        $pcols = implode(', ', array_map(fn($c) => "p.{$c} AS {$c}", self::PAGE_COLS));
        // Dedup on read: CH is append-only, so a page re-written during the crawl
        // (sitemap re-fetch, retries…) leaves >1 row per id. `LIMIT 1 BY id` keeps
        // one per id (engine-independent; cheaper than FINAL, scoped to the
        // crawl_id partition). Same for the derived/joined tables.
        return "(SELECT {$pcols}, "
            . "{$this->catIdExpr} AS cat_id, "
            . "({$this->catNameExpr}) AS category, "
            . "m.inlinks AS inlinks, m.pri AS pri, "
            . "m.title_status AS title_status, m.h1_status AS h1_status, "
            . "m.metadesc_status AS metadesc_status, m.in_sitemap AS in_sitemap, "
            . "g.generation AS generation, "
            // CH `pages` holds only crawled pages, which are all in-crawl. Expose the
            // frontier flag as a constant so reports' `AND in_crawl = TRUE` resolves.
            . "toUInt8(1) AS in_crawl "
            // The joined subqueries must NOT expose crawl_id: CH's new analyzer
            // can't resolve an unqualified outer column when several joined sources
            // share its name. Only `p` keeps crawl_id.
            . "FROM (SELECT * FROM {$this->db}.pages WHERE crawl_id = {$cid} LIMIT 1 BY id) p "
            . "LEFT JOIN (SELECT id, inlinks, pri, title_status, h1_status, metadesc_status, in_sitemap FROM {$this->db}.page_metrics WHERE crawl_id = {$cid} LIMIT 1 BY id) m ON m.id = p.id "
            . "LEFT JOIN (SELECT id, generation FROM {$this->db}.page_generation WHERE crawl_id = {$cid} LIMIT 1 BY id) g ON g.id = p.id)";
    }

    private function simpleSource(string $table): string
    {
        $cid = $this->crawlId;
        // page_schemas / html can also have append dups → dedup on read.
        if ($table === 'page_schemas') {
            return "(SELECT * FROM {$this->db}.page_schemas WHERE crawl_id = {$cid} LIMIT 1 BY (page_id, schema_type))";
        }
        if ($table === 'html') {
            return "(SELECT * FROM {$this->db}.html WHERE crawl_id = {$cid} LIMIT 1 BY id)";
        }
        // duplicate_clusters / redirect_chains: PG exposed a SERIAL `id`; CH names
        // it cluster_id / chain_id. Alias it so report SQL referencing `id` works.
        if ($table === 'duplicate_clusters') {
            return "(SELECT *, cluster_id AS id FROM {$this->db}.duplicate_clusters WHERE crawl_id = {$cid})";
        }
        if ($table === 'redirect_chains') {
            return "(SELECT *, chain_id AS id FROM {$this->db}.redirect_chains WHERE crawl_id = {$cid})";
        }
        // links = intentional multi-row.
        return "(SELECT * FROM {$this->db}.{$table} WHERE crawl_id = {$cid})";
    }

    private function buildCrawlCategoriesSource(array $rules): string
    {
        $pid = $this->projectId;
        if (empty($rules)) {
            // Empty typed table so JOINs resolve and match nothing.
            return "(SELECT toInt32(0) AS id, '' AS cat, '' AS color, toInt32({$pid}) AS project_id WHERE 0)";
        }
        $rows = [];
        foreach ($rules as $i => $rule) {
            $cat = "'" . str_replace("'", "''", $rule['name']) . "'";
            $color = "'" . str_replace("'", "''", $rule['color']) . "'";
            $rows[] = "SELECT toInt32(" . ($i + 1) . ") AS id, {$cat} AS cat, {$color} AS color, toInt32({$pid}) AS project_id";
        }
        return "(" . implode(" UNION ALL ", $rows) . ")";
    }

    /** Reserved words that can follow a table name (so they aren't read as an alias). */
    private const NOT_ALIAS = [
        'where','group','order','on','using','join','left','right','inner','cross','full',
        'union','limit','offset','having','and','or','as','set','returning','window',
    ];

    private function rewriteTables(string $sql): string
    {
        $sources = [
            'pages'              => $this->pagesSource(),
            'links'              => $this->simpleSource('links'),
            'page_schemas'       => $this->simpleSource('page_schemas'),
            'duplicate_clusters' => $this->simpleSource('duplicate_clusters'),
            'redirect_chains'    => $this->simpleSource('redirect_chains'),
            'html'               => $this->simpleSource('html'),
            'crawl_categories'   => $this->crawlCategoriesSource,
        ];
        foreach ($sources as $name => $src) {
            $sql = preg_replace_callback(
                '/\b(FROM|JOIN)\s+' . $name . '\b(\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*))?/i',
                function ($m) use ($src, $name) {
                    $alias = $m[3] ?? '';
                    if ($alias !== '' && !in_array(strtolower($alias), self::NOT_ALIAS, true)) {
                        return $m[1] . ' ' . $src . ' AS ' . $alias;
                    }
                    // No (real) alias: default to the virtual name + re-append the
                    // swallowed keyword (e.g. WHERE) if the regex captured one.
                    $tail = isset($m[2]) ? $m[2] : '';
                    return $m[1] . ' ' . $src . ' AS ' . $name . $tail;
                },
                $sql
            );
        }
        return $sql;
    }

    private function rewriteDialect(string $sql): string
    {
        // Strip PostgreSQL type casts CH doesn't understand (::numeric, ::int…).
        $sql = preg_replace('/::\s*(numeric|integer|int|bigint|smallint|float|double precision|real|text|varchar|jsonb|json)\b/i', '', $sql);

        // array_length(arr, 1) / array_length(arr) -> length(arr)
        $sql = preg_replace('/\barray_length\s*\(\s*([^(),]+?)\s*(?:,\s*\d+\s*)?\)/i', 'length($1)', $sql);

        // PERCENTILE_CONT(p) WITHIN GROUP (ORDER BY x) -> quantileExact(p)(x)
        // MEDIAN() WITHIN GROUP (ORDER BY x)           -> quantileExact(0.5)(x)
        $sql = preg_replace('/\bpercentile_cont\s*\(\s*([0-9.]+)\s*\)\s+within\s+group\s*\(\s*order\s+by\s+([^()]+?)\s*\)/i', 'quantileExact($1)($2)', $sql);
        $sql = preg_replace('/\bmedian\s*\(\s*\)\s+within\s+group\s*\(\s*order\s+by\s+([^()]+?)\s*\)/i', 'quantileExact(0.5)($1)', $sql);

        // COUNT(*) FILTER (WHERE c) -> countIf(c)
        $sql = preg_replace('/\bCOUNT\s*\(\s*\*\s*\)\s+FILTER\s*\(\s*WHERE\s+(.*?)\)/is', 'countIf($1)', $sql);
        // FUNC(arg) FILTER (WHERE c) -> FUNCIf(arg, c)
        $sql = preg_replace('/\b([A-Za-z_]+)\s*\(([^()]*)\)\s+FILTER\s*\(\s*WHERE\s+(.*?)\)/is', '$1If($2, $3)', $sql);

        // PG substring(x, 'regex') / substring(x FROM 'regex') -> CH extract().
        // (CH substring is positional; only rewrite the string-pattern forms.)
        $sql = preg_replace("/\\bsubstring\\s*\\(\\s*([^,()]+?)\\s*(?:,|\\bfrom\\b)\\s*('[^']*')\\s*\\)/i", 'extract($1, $2)', $sql);

        // PG unnest(arr) (row-producing) -> CH arrayJoin(arr)
        $sql = preg_replace('/\bunnest\s*\(/i', 'arrayJoin(', $sql);

        // JSONB -> Map: x ->> 'k' | x ->> :param | x ->> {p:T}  =>  x['k'] etc.
        $sql = preg_replace("/->>\\s*('[^']*'|:[A-Za-z_]\\w*|\\{[^}]*\\})/", "[$1]", $sql);
        // jsonb_object_keys(X) (row-producing) -> arrayJoin(mapKeys(X))
        $sql = preg_replace('/\bjsonb_object_keys\s*\(/i', 'arrayJoin(mapKeys(', $sql);
        $sql = preg_replace('/\barrayJoin\(mapKeys\(([^()]*)\)/i', 'arrayJoin(mapKeys($1))', $sql);
        // jsonb_typeof(X) = 'object'  -> 1  (generation is always a Map on CH)
        $sql = preg_replace("/\\bjsonb_typeof\\s*\\([^()]*\\)\\s*=\\s*'object'/i", '1', $sql);

        return $sql;
    }
}
