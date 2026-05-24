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
    /** @var int[] the crawl(s) bare `pages`/`links` cover (current [+ compare]). */
    private array $crawlIds;
    /** @var array<int,array{0:string,1:string}> per-crawl {catIdExpr, catNameExpr} cache. */
    private array $catCache = [];
    private string $crawlCategoriesSource;

    public function __construct(int $crawlId, ?int $compareId = null)
    {
        $this->ch = ClickHouseDatabase::getInstance();
        $this->db = $this->ch->getDatabase();
        $this->crawlId = $crawlId;
        $this->crawlIds = $compareId ? [$crawlId, $compareId] : [$crawlId];

        $pg = PostgresDatabase::getInstance()->getConnection();
        $stmt = $pg->prepare("SELECT project_id FROM crawls WHERE id = :id");
        $stmt->execute([':id' => $crawlId]);
        $this->projectId = (int) $stmt->fetchColumn();

        $rules = (new CategoryExpr($pg))->rulesForCrawl($crawlId);
        $this->crawlCategoriesSource = $this->buildCrawlCategoriesSource($rules);
    }

    /** Cached {cat_id expr, category-name expr} for one crawl's YAML rules. */
    private function catExprs(int $id): array
    {
        if (!isset($this->catCache[$id])) {
            $ce = new CategoryExpr(PostgresDatabase::getInstance()->getConnection());
            $rules = $ce->rulesForCrawl($id);
            $this->catCache[$id] = [$ce->buildIdExpr($rules), $ce->build($rules)];
        }
        return $this->catCache[$id];
    }

    private function inList(array $ids): string
    {
        return implode(',', array_map('intval', $ids));
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

    /**
     * The pages source over a set of crawl_ids, with category computed from
     * $rulesId's rules. Dedup on read (CH is append-only): LIMIT 1 BY (crawl_id,id).
     * Joined subqueries rename their keys (_mcid/_mid …) so only `p` exposes
     * crawl_id — CH's new analyzer can't resolve a shared column name across joins.
     */
    /**
     * @param array{cat:bool,metrics:bool,gen:bool} $feat which derived bits the
     *   query actually needs — we only compute/join those (huge perf win on big
     *   crawls: e.g. the category RE2 regex isn't evaluated when no query
     *   references `category`/`cat_id`).
     */
    private function pagesSourceFor(array $ids, int $rulesId, array $feat): string
    {
        $in = $this->inList($ids);
        // Alias every column so output names are unqualified (`crawl_id`, not `p.crawl_id`).
        $cols = array_map(fn($c) => "p.{$c} AS {$c}", self::PAGE_COLS);
        $joins = '';
        if ($feat['cat']) {
            [$catId, $catName] = $this->catExprs($rulesId);
            $cols[] = "{$catId} AS cat_id";
            $cols[] = "({$catName}) AS category";
        }
        if ($feat['metrics']) {
            $cols[] = "m.inlinks AS inlinks";
            $cols[] = "m.pri AS pri";
            $cols[] = "m.title_status AS title_status";
            $cols[] = "m.h1_status AS h1_status";
            $cols[] = "m.metadesc_status AS metadesc_status";
            $cols[] = "m.in_sitemap AS in_sitemap";
            $joins .= " LEFT JOIN (SELECT crawl_id AS _mcid, id AS _mid, inlinks, pri, title_status, h1_status, metadesc_status, in_sitemap "
                . "FROM {$this->db}.page_metrics WHERE crawl_id IN ({$in}) LIMIT 1 BY (crawl_id, id)) m ON m._mcid = p.crawl_id AND m._mid = p.id";
        }
        if ($feat['gen']) {
            $cols[] = "g.generation AS generation";
            $joins .= " LEFT JOIN (SELECT crawl_id AS _gcid, id AS _gid, generation "
                . "FROM {$this->db}.page_generation WHERE crawl_id IN ({$in}) LIMIT 1 BY (crawl_id, id)) g ON g._gcid = p.crawl_id AND g._gid = p.id";
        }
        $cols[] = "toUInt8(1) AS in_crawl";
        return "(SELECT " . implode(', ', $cols)
            . " FROM (SELECT * FROM {$this->db}.pages WHERE crawl_id IN ({$in}) LIMIT 1 BY (crawl_id, id)) p"
            . $joins . ")";
    }

    private function simpleSourceFor(string $table, array $ids): string
    {
        $in = $this->inList($ids);
        if ($table === 'page_schemas') {
            return "(SELECT * FROM {$this->db}.page_schemas WHERE crawl_id IN ({$in}) LIMIT 1 BY (crawl_id, page_id, schema_type))";
        }
        if ($table === 'html') {
            return "(SELECT * FROM {$this->db}.html WHERE crawl_id IN ({$in}) LIMIT 1 BY (crawl_id, id))";
        }
        // duplicate_clusters / redirect_chains: PG exposed a SERIAL `id`; CH names
        // it cluster_id / chain_id. Alias it so report SQL referencing `id` works.
        if ($table === 'duplicate_clusters') {
            return "(SELECT *, cluster_id AS id FROM {$this->db}.duplicate_clusters WHERE crawl_id IN ({$in}))";
        }
        if ($table === 'redirect_chains') {
            return "(SELECT *, chain_id AS id FROM {$this->db}.redirect_chains WHERE crawl_id IN ({$in}))";
        }
        // links = intentional multi-row.
        return "(SELECT * FROM {$this->db}.{$table} WHERE crawl_id IN ({$in}))";
    }

    /** @param array{cat:bool,metrics:bool,gen:bool} $feat */
    private function sourceFor(string $table, array $ids, int $rulesId, array $feat): string
    {
        return $table === 'pages' ? $this->pagesSourceFor($ids, $rulesId, $feat) : $this->simpleSourceFor($table, $ids);
    }

    private const ALL_FEAT = ['cat' => true, 'metrics' => true, 'gen' => true];

    /**
     * The crawl-scoped virtual source for one table+crawl (no alias). Public so
     * the SQL Explorer's ClickHouseSqlExecutor reuses the SAME `pages` shape as
     * the report shim — they must never drift (e.g. on `in_crawl`/`cat_id`).
     */
    public function virtualSource(string $table, int $id): string
    {
        // Ad-hoc SQL Explorer: expose everything (can't know what the user wants).
        return $this->sourceFor($table, [$id], $id, self::ALL_FEAT);
    }

    /** Which derived columns a query references (so we only build those). */
    private function featuresOf(string $sql): array
    {
        return [
            'cat'     => (bool) preg_match('/\b(category|cat_id)\b/i', $sql),
            'metrics' => (bool) preg_match('/\b(inlinks|pri|title_status|h1_status|metadesc_status|in_sitemap)\b/i', $sql),
            'gen'     => (bool) preg_match('/\bgeneration\b/i', $sql),
        ];
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

    private const VTABLES = 'pages|links|page_schemas|duplicate_clusters|redirect_chains|html';

    private function rewriteTables(string $sql): string
    {
        // Only build the derived bits the query actually uses (perf).
        $feat = $this->featuresOf($sql);

        // 0) Normalise PG partition names (pages_123) to the @id form so the
        //    multi-crawl rule below handles them (used by new-urls/lost-urls).
        $sql = preg_replace('/\b(' . self::VTABLES . ')_(\d+)\b/i', '$1@$2', $sql);

        // 1) Multi-crawl `table@<id>` (comparison reports) → that crawl's source,
        //    with that crawl's own category rules.
        $sql = preg_replace_callback(
            '/\b(FROM|JOIN)\s+(' . self::VTABLES . ')@(\d+)\b(\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*))?/i',
            function ($m) use ($feat) {
                $table = strtolower($m[2]);
                $id = (int) $m[3];
                $src = $this->sourceFor($table, [$id], $id, $feat);
                return $m[1] . ' ' . $src . ' AS ' . $this->aliasFor($m[5] ?? '', $table, $m[4] ?? '');
            },
            $sql
        );
        // categories@<id> was a project-level table → crawl_categories (scoped).
        $sql = preg_replace('/\bcategories@\d+\b/i', 'crawl_categories', $sql);

        // 2) Bare virtual tables → the crawl set (current [+ compare]), current rules.
        $sources = [
            'pages'              => $this->sourceFor('pages', $this->crawlIds, $this->crawlId, $feat),
            'links'              => $this->simpleSourceFor('links', $this->crawlIds),
            'page_schemas'       => $this->simpleSourceFor('page_schemas', $this->crawlIds),
            'duplicate_clusters' => $this->simpleSourceFor('duplicate_clusters', $this->crawlIds),
            'redirect_chains'    => $this->simpleSourceFor('redirect_chains', $this->crawlIds),
            'html'               => $this->simpleSourceFor('html', $this->crawlIds),
            'crawl_categories'   => $this->crawlCategoriesSource,
        ];
        foreach ($sources as $name => $src) {
            $sql = preg_replace_callback(
                '/\b(FROM|JOIN)\s+' . $name . '\b(\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*))?/i',
                fn($m) => $m[1] . ' ' . $src . ' AS ' . $this->aliasFor($m[3] ?? '', $name, $m[2] ?? ''),
                $sql
            );
        }
        return $sql;
    }

    /** Resolve the alias to keep after a rewritten table (real alias, else the
     *  virtual name + the swallowed trailing keyword like WHERE). */
    private function aliasFor(string $captured, string $name, string $tail): string
    {
        if ($captured !== '' && !in_array(strtolower($captured), self::NOT_ALIAS, true)) {
            return $captured;
        }
        return $name . $tail;
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
