<?php

namespace App\Gsc;

use App\Database\ClickHouseDatabase;

/**
 * Builds and runs the ClickHouse queries behind the Search Analytics view.
 *
 * GSC data is project-scoped (not crawl-scoped), so we bypass ChPdo (which forces
 * a crawl_id) and query the gsc_* tables directly with server-side bound params,
 * always filtered by project_id + date range.
 *
 * SOURCE-TABLE SELECTION is dynamic (this is the crux): the "mode" gives the
 * grouping dimension, but the FILTERS decide the table. When a filter references
 * the OTHER dimension, we must read the JOINT table (gsc_page_query_daily) — the
 * only one holding both page AND query — otherwise the filter has no column to
 * apply to and would be silently dropped (returning global, wrong numbers).
 *
 *   keywords, no url filter        → gsc_query_daily        GROUP BY query
 *   keywords, url filter present   → gsc_page_query_daily   GROUP BY query   (keywords OF that URL)
 *   urls, no query filter          → gsc_page_daily         GROUP BY page
 *   urls, query filter present     → gsc_page_query_daily   GROUP BY page    (URLs FOR that keyword)
 *   both                           → gsc_page_query_daily   GROUP BY page,query
 *
 * This matches Google Search Console: filtering by a query then looking at pages
 * shows the (non-anonymized) per-page numbers FOR that query.
 *
 * Aggregation: clicks/impressions are additive → SUM; CTR = ΣclicksΣimpr; avg
 * position is impression-weighted over NAMED rows only (is_anon=0). FINAL dedups
 * the ReplacingMergeTree (a re-synced day supersedes by version).
 *
 * @package    Scouter
 * @subpackage Gsc
 */
class GscQueryService
{
    public const MODES = ['keywords', 'urls', 'both', 'country', 'device'];

    /** Tables that carry the is_anon column (for position weighting / anon toggle). */
    private const ANON_TABLES = ['gsc_query_daily', 'gsc_page_query_daily'];

    private int $projectId;

    public function __construct(int $projectId)
    {
        $this->projectId = $projectId;
    }

    public static function normalizeMode(string $mode): string
    {
        return in_array($mode, self::MODES, true) ? $mode : 'keywords';
    }

    /**
     * Resolve the source table + grouping dims + filterable columns from the
     * mode AND the active filters.
     *
     * @param array<int,mixed> $filters
     * @return array{table:string,dims:array<int,string>,cols:array<int,string>,hasAnon:bool}
     */
    private function resolve(string $mode, array $filters): array
    {
        $mode = self::normalizeMode($mode);
        $qFilter = self::filtersReference($filters, 'query');
        $uFilter = self::filtersReference($filters, 'url') || self::filtersReference($filters, 'page') || self::filtersReference($filters, 'category');
        $cdFilter = self::filtersReference($filters, 'country') || self::filtersReference($filters, 'device');
        $cdNeeded = in_array($mode, ['country', 'device'], true) || $cdFilter;

        // The joint page×query table is the ONLY one holding both page AND query,
        // but it has no country/device. The site/page/query marginals carry
        // country+device but only one of page/query. The grouping dim must always
        // exist in the chosen table; when a combination is impossible (needs page
        // AND query AND country/device), country/device + the mode's own dimension
        // win and the cross-dimension filter is dropped (its column isn't in cols).
        if ($mode === 'both') {
            // Intrinsically the joint table → no country/device here.
            $table = 'gsc_page_query_daily'; $dims = ['page', 'query']; $cols = ['page', 'query'];
        } elseif ($mode === 'urls') {
            if ($qFilter && !$cdNeeded) { $table = 'gsc_page_query_daily'; $dims = ['page']; $cols = ['page', 'query']; }
            else                        { $table = 'gsc_page_daily';       $dims = ['page']; $cols = ['page', 'country', 'device']; }
        } elseif ($mode === 'keywords') {
            if ($uFilter && !$cdNeeded) { $table = 'gsc_page_query_daily'; $dims = ['query']; $cols = ['page', 'query']; }
            else                        { $table = 'gsc_query_daily';      $dims = ['query']; $cols = ['query', 'country', 'device']; }
        } else { // country / device — grouped by the geo/device dimension
            $dim = $mode; // 'country' | 'device'
            if ($uFilter && !$qFilter)      { $table = 'gsc_page_daily';  $cols = ['page', 'country', 'device']; }
            elseif ($qFilter && !$uFilter)  { $table = 'gsc_query_daily'; $cols = ['query', 'country', 'device']; }
            elseif ($uFilter && $qFilter)   { $table = 'gsc_page_daily';  $cols = ['page', 'country', 'device']; } // query filter dropped
            else                            { $table = 'gsc_site_daily';  $cols = ['country', 'device']; }
            $dims = [$dim];
        }

        return ['table' => $table, 'dims' => $dims, 'cols' => $cols, 'hasAnon' => in_array($table, self::ANON_TABLES, true)];
    }

    /**
     * The source table for the HEADLINE aggregates (KPIs + chart), which have no
     * dimension grouping. When nothing narrows the scope (no filter) and the
     * anonymized bucket is included, use the site marginal gsc_site_daily: its
     * per-day position is GSC's true site average position. Weighting the
     * per-query positions instead would EXCLUDE the anonymized queries and skew
     * the average (e.g. 25.1 vs GSC's 23.6). clicks/impressions are unaffected
     * (they match the site totals either way). A filter (or excluding anon) means
     * there is no site-level equivalent → fall back to the granular table.
     *
     * @param array{table:string,dims:array<int,string>,cols:array<int,string>,hasAnon:bool} $r
     * @param array<int,mixed> $filters
     * @return array{table:string,hasAnon:bool}
     */
    private function aggSource(array $r, array $filters, bool $includeAnon): array
    {
        if (empty($filters) && $includeAnon) {
            return ['table' => 'gsc_site_daily', 'hasAnon' => false];
        }
        return ['table' => $r['table'], 'hasAnon' => $r['hasAnon']];
    }

    /**
     * Paginated table rows for the grid.
     *
     * @param array<int,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function table(
        string $mode, string $from, string $to, array $filters,
        string $sort = 'clicks', string $dir = 'desc', int $page = 1, int $perPage = 50, bool $includeAnon = true
    ): array {
        $r = $this->resolve($mode, $filters);
        $params = [];
        $where = $this->baseWhere($from, $to, $params)
               . self::buildFilterSql($filters, $r['cols'], $params, $this->catConds($filters))
               . $this->anonClause($r['hasAnon'], $includeAnon);

        $sort = in_array($sort, ['clicks', 'impressions', 'ctr', 'position', 'query', 'page', 'country', 'device'], true) ? $sort : 'clicks';
        $dir  = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, min(500, $perPage));
        $offset  = max(0, ($page - 1)) * $perPage;
        $groupBy = implode(', ', $r['dims']);

        // Aggregate in a subquery, then ORDER/paginate at the OUTER level — the
        // shim forces prefer_column_name_to_alias=1, so a bare ORDER BY clicks on
        // the aggregate would resolve to the raw column (NOT_AN_AGGREGATE).
        $catCol = $this->catColumn($r['dims']);
        $innerCols = array_merge($r['dims'], [
                'sum(clicks) AS clicks', 'sum(impressions) AS impressions',
                'if(sum(impressions)=0, 0, sum(clicks)/sum(impressions)) AS ctr',
                $this->positionExpr($r['hasAnon']) . ' AS position',
            ], $catCol ? [$catCol] : []);
        $inner = "SELECT " . implode(', ', $innerCols) . " FROM scouter.{$r['table']} FINAL WHERE {$where} GROUP BY {$groupBy}";

        $outerCols = implode(', ', array_merge($r['dims'], ['clicks', 'impressions', 'ctr', 'position'], $catCol ? ['category'] : []));
        $sql = "SELECT {$outerCols} FROM ({$inner}) ORDER BY {$sort} {$dir} LIMIT {$perPage} OFFSET {$offset}";
        $countSql = "SELECT count() FROM (SELECT 1 FROM scouter.{$r['table']} FINAL WHERE {$where} GROUP BY {$groupBy})";

        $ch = ClickHouseDatabase::getInstance();
        return ['rows' => $ch->select($sql, $params), 'total' => (int) $ch->selectValue($countSql, $params)];
    }

    /**
     * Like table(), but computes BOTH periods per row (current + comparison) in a
     * single query via conditional sums, so the grid can show each metric for the
     * two ranges + the row-by-row difference (like GSC's comparison table). Rows
     * present in either period are returned (a row that lost all traffic shows
     * current=0, prev>0). Each row carries: <dims>, clicks, impressions, ctr,
     * position (current) and clicks_p, impressions_p, ctr_p, position_p (compare).
     *
     * @param array<int,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function tableCompare(
        string $mode, string $from, string $to, string $cfrom, string $cto, array $filters,
        string $sort = 'clicks', string $dir = 'desc', int $page = 1, int $perPage = 50, bool $includeAnon = true
    ): array {
        $r = $this->resolve($mode, $filters);
        $params = ['pid' => $this->projectId, 'from' => $this->safeDate($from), 'to' => $this->safeDate($to),
                   'cfrom' => $this->safeDate($cfrom), 'cto' => $this->safeDate($cto)];

        $cur  = "date >= {from:Date} AND date <= {to:Date}";
        $prev = "date >= {cfrom:Date} AND date <= {cto:Date}";
        $where = "project_id = {pid:Int32} AND search_type = 'web' AND (({$cur}) OR ({$prev}))"
               . self::buildFilterSql($filters, $r['cols'], $params, $this->catConds($filters))
               . $this->anonClause($r['hasAnon'], $includeAnon);

        $sort = in_array($sort, ['clicks', 'impressions', 'ctr', 'position', 'query', 'page', 'country', 'device'], true) ? $sort : 'clicks';
        $dir  = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, min(500, $perPage));
        $offset  = max(0, ($page - 1)) * $perPage;
        $groupBy = implode(', ', $r['dims']);

        $catCol = $this->catColumn($r['dims']);
        $innerCols = array_merge($r['dims'], [
                "sumIf(clicks, {$cur}) AS clicks",
                "sumIf(impressions, {$cur}) AS impressions",
                "sumIf(clicks, {$prev}) AS clicks_p",
                "sumIf(impressions, {$prev}) AS impressions_p",
                $this->weightedPos($r['hasAnon'], $cur) . " AS position",
                $this->weightedPos($r['hasAnon'], $prev) . " AS position_p",
            ], $catCol ? [$catCol] : []);
        $inner = "SELECT " . implode(', ', $innerCols) . " FROM scouter.{$r['table']} FINAL WHERE {$where} GROUP BY {$groupBy}";

        $outerCols = implode(', ', array_merge($r['dims'], [
            'clicks', 'impressions', 'if(impressions=0,0,clicks/impressions) AS ctr', 'position',
            'clicks_p', 'impressions_p', 'if(impressions_p=0,0,clicks_p/impressions_p) AS ctr_p', 'position_p',
        ], $catCol ? ['category'] : []));
        $sql = "SELECT {$outerCols} FROM ({$inner}) ORDER BY {$sort} {$dir} LIMIT {$perPage} OFFSET {$offset}";
        $countSql = "SELECT count() FROM (SELECT 1 FROM scouter.{$r['table']} FINAL WHERE {$where} GROUP BY {$groupBy})";

        $ch = ClickHouseDatabase::getInstance();
        return ['rows' => $ch->select($sql, $params), 'total' => (int) $ch->selectValue($countSql, $params)];
    }

    /**
     * Headline KPIs over the whole filtered range.
     *
     * @return array{clicks:int,impressions:int,ctr:float,position:float}
     */
    public function kpis(string $mode, string $from, string $to, array $filters, bool $includeAnon = true): array
    {
        $r = $this->resolve($mode, $filters);
        $agg = $this->aggSource($r, $filters, $includeAnon);
        $params = [];
        $where = $this->baseWhere($from, $to, $params)
               . self::buildFilterSql($filters, $r['cols'], $params, $this->catConds($filters))
               . $this->anonClause($agg['hasAnon'], $includeAnon);

        $sql = "SELECT sum(clicks) AS clicks, sum(impressions) AS impressions,\n"
             . "       if(sum(impressions)=0,0,sum(clicks)/sum(impressions)) AS ctr,\n"
             . "       " . $this->positionExpr($agg['hasAnon']) . " AS position\n"
             . "FROM scouter.{$agg['table']} FINAL WHERE {$where}";

        $row = ClickHouseDatabase::getInstance()->select($sql, $params);
        $x = $row[0] ?? [];
        return [
            'clicks'      => (int) ($x['clicks'] ?? 0),
            'impressions' => (int) ($x['impressions'] ?? 0),
            'ctr'         => (float) ($x['ctr'] ?? 0),
            'position'    => round((float) ($x['position'] ?? 0), 2),
        ];
    }

    /**
     * Daily time-series (clicks, impressions, ctr, position), respecting filters.
     *
     * @return array<int,array<string,mixed>>
     */
    public function timeseries(string $mode, string $from, string $to, array $filters, bool $includeAnon = true): array
    {
        $r = $this->resolve($mode, $filters);
        $agg = $this->aggSource($r, $filters, $includeAnon);
        $params = [];
        $where = $this->baseWhere($from, $to, $params)
               . self::buildFilterSql($filters, $r['cols'], $params, $this->catConds($filters))
               . $this->anonClause($agg['hasAnon'], $includeAnon);

        $sql = "SELECT toString(date) AS date, sum(clicks) AS clicks, sum(impressions) AS impressions,\n"
             . "       if(sum(impressions)=0,0,sum(clicks)/sum(impressions)) AS ctr,\n"
             . "       " . $this->positionExpr($agg['hasAnon']) . " AS position\n"
             . "FROM scouter.{$agg['table']} FINAL WHERE {$where}\n"
             . "GROUP BY date ORDER BY date ASC";

        return ClickHouseDatabase::getInstance()->select($sql, $params);
    }

    /**
     * SELECT + params for a CSV export (streamed via FORMAT CSVWithNames), built
     * to mirror the on-screen table:
     *   - only the metrics visible in the grid ($metrics, following the KPI toggles),
     *   - in comparison mode ($cfrom/$cto set), EACH metric column is doubled
     *     (current + "_prec" for the compared period), like the grid's cells.
     * Column aliases become the CSV headers.
     *
     * @param array<int,mixed>  $filters
     * @param array<int,string> $metrics subset/order of clicks,impressions,ctr,position
     * @return array{sql:string,params:array<string,mixed>}
     */
    public function exportSelect(
        string $mode, string $from, string $to, array $filters, bool $includeAnon = true,
        array $metrics = ['clicks', 'impressions', 'ctr', 'position'], ?string $cfrom = null, ?string $cto = null
    ): array {
        $r = $this->resolve($mode, $filters);
        $comparing = $cfrom !== null && $cto !== null && $cfrom !== '' && $cto !== '';

        // Sanitize the metric list (canonical order, known keys only).
        $known = ['clicks', 'impressions', 'ctr', 'position'];
        $metrics = array_values(array_filter($known, fn($m) => in_array($m, $metrics, true)));
        if (empty($metrics)) {
            $metrics = $known;
        }
        $dimHead = ['query' => 'mot_cle', 'page' => 'url', 'country' => 'pays', 'device' => 'appareil'];

        if (!$comparing) {
            $params = [];
            $where = $this->baseWhere($from, $to, $params)
                   . self::buildFilterSql($filters, $r['cols'], $params, $this->catConds($filters))
                   . $this->anonClause($r['hasAnon'], $includeAnon);
            $inner = "SELECT " . implode(', ', array_merge($r['dims'], [
                    'sum(clicks) AS clicks', 'sum(impressions) AS impressions',
                    $this->positionExpr($r['hasAnon']) . ' AS position',
                ])) . " FROM scouter.{$r['table']} FINAL WHERE {$where} GROUP BY " . implode(', ', $r['dims']);

            $out = array_map(fn($d) => "{$d} AS {$dimHead[$d]}", $r['dims']);
            foreach ($metrics as $m) {
                $out[] = $this->exportMetricCol($m, false);
            }
            return ['sql' => "SELECT " . implode(', ', $out) . " FROM ({$inner}) ORDER BY clicks DESC", 'params' => $params];
        }

        // Comparison: both periods per row, one query.
        $params = ['pid' => $this->projectId, 'from' => $this->safeDate($from), 'to' => $this->safeDate($to),
                   'cfrom' => $this->safeDate($cfrom), 'cto' => $this->safeDate($cto)];
        $cur  = "date >= {from:Date} AND date <= {to:Date}";
        $prev = "date >= {cfrom:Date} AND date <= {cto:Date}";
        $where = "project_id = {pid:Int32} AND search_type = 'web' AND (({$cur}) OR ({$prev}))"
               . self::buildFilterSql($filters, $r['cols'], $params, $this->catConds($filters))
               . $this->anonClause($r['hasAnon'], $includeAnon);
        $inner = "SELECT " . implode(', ', array_merge($r['dims'], [
                "sumIf(clicks, {$cur}) AS clicks", "sumIf(impressions, {$cur}) AS impressions",
                "sumIf(clicks, {$prev}) AS clicks_p", "sumIf(impressions, {$prev}) AS impressions_p",
                $this->weightedPos($r['hasAnon'], $cur) . " AS position",
                $this->weightedPos($r['hasAnon'], $prev) . " AS position_p",
            ])) . " FROM scouter.{$r['table']} FINAL WHERE {$where} GROUP BY " . implode(', ', $r['dims']);

        $out = array_map(fn($d) => "{$d} AS {$dimHead[$d]}", $r['dims']);
        foreach ($metrics as $m) {
            $out[] = $this->exportMetricCol($m, false);      // current period
            $out[] = $this->exportMetricCol($m, true);       // compared period (_prec)
        }
        return ['sql' => "SELECT " . implode(', ', $out) . " FROM ({$inner}) ORDER BY clicks DESC", 'params' => $params];
    }

    /** One export metric column (aliased to its CSV header). $prev picks the
     *  compared-period sub-columns (…_p) and appends "_prec" to the header. */
    private function exportMetricCol(string $m, bool $prev): string
    {
        $s = $prev ? '_p' : '';
        $h = ['clicks' => 'clics', 'impressions' => 'impressions', 'ctr' => 'ctr', 'position' => 'position'][$m] . ($prev ? '_prec' : '');
        return match ($m) {
            'clicks'      => "clicks{$s} AS {$h}",
            'impressions' => "impressions{$s} AS {$h}",
            'ctr'         => "round(if(impressions{$s}=0,0,clicks{$s}/impressions{$s}),4) AS {$h}",
            'position'    => "round(position{$s},2) AS {$h}",
            default       => "clicks{$s} AS {$h}",
        };
    }

    /**
     * Distinct country codes present in the project's GSC data over the range,
     * ordered by impressions desc — powers the country filter dropdown (the user
     * picks among countries that actually exist).
     *
     * @return array<int,array{country:string,clicks:int,impressions:int}>
     */
    public function countries(string $from, string $to): array
    {
        $params = [];
        $where = $this->baseWhere($from, $to, $params);
        // ORDER BY sum(impressions) (not the alias): the shim forces
        // prefer_column_name_to_alias=1, so `ORDER BY impressions` would bind to
        // the raw column → NOT_AN_AGGREGATE.
        $sql = "SELECT country, sum(clicks) AS clicks, sum(impressions) AS impressions "
             . "FROM scouter.gsc_site_daily FINAL WHERE {$where} AND country != '' "
             . "GROUP BY country ORDER BY sum(impressions) DESC";
        return ClickHouseDatabase::getInstance()->select($sql, $params);
    }

    // -------------------------------------------------------------------------
    // SQL building (pure — unit-tested)
    // -------------------------------------------------------------------------

    /** Whether any filter chip targets a given field ('url' also matches 'page'). */
    public static function filtersReference(array $filters, string $field): bool
    {
        foreach ($filters as $group) {
            foreach (($group['items'] ?? []) as $chip) {
                $f = (string) ($chip['field'] ?? '');
                if ($f === $field) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Translate FilterBar groups into a ClickHouse WHERE fragment (with a leading
     * " AND " when non-empty). Groups are AND'd; chips within a group OR'd. Only
     * chips whose column is available in $cols are kept. Values are bound params.
     *
     * @param array<int,mixed>    $filters
     * @param array<int,string>   $cols    columns available on the source table (subset of query/page)
     * @param array<string,mixed> $params  (by ref) accumulates bound params
     */
    public static function buildFilterSql(array $filters, array $cols, array &$params, array $categoryConds = []): string
    {
        $groupSql = [];
        foreach ($filters as $group) {
            $items = is_array($group['items'] ?? null) ? $group['items'] : [];
            $logic = (strtoupper((string) ($group['logic'] ?? 'OR')) === 'AND') ? 'AND' : 'OR';
            $chips = [];
            foreach ($items as $chip) {
                $sql = self::chipSql($chip, $cols, $params, $categoryConds);
                if ($sql !== null) {
                    $chips[] = $sql;
                }
            }
            if ($chips) {
                $groupSql[] = '(' . implode(" {$logic} ", $chips) . ')';
            }
        }
        return $groupSql ? ' AND ' . implode(' AND ', $groupSql) : '';
    }

    /**
     * One filter chip → SQL, or null when its column isn't on the source table.
     *
     * @param array<string,mixed> $chip
     * @param array<int,string>   $cols
     * @param array<string,mixed> $params
     */
    private static function chipSql(array $chip, array $cols, array &$params, array $categoryConds = []): ?string
    {
        $field = (string) ($chip['field'] ?? '');
        $op    = (string) ($chip['operator'] ?? 'contains');

        // Category = the project's URL categorization rules, applied to `page`.
        // in → OR of the selected categories' conditions; not_in → NOT of that.
        if ($field === 'category') {
            if (!in_array('page', $cols, true)) {
                return null;
            }
            $ids = $chip['value'] ?? [];
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            $parts = [];
            foreach ($ids as $id) {
                $cid = (int) $id;
                if (isset($categoryConds[$cid])) {
                    $parts[] = $categoryConds[$cid];
                }
            }
            if (empty($parts)) {
                return null;
            }
            $joined = '(' . implode(' OR ', $parts) . ')';
            return ($op === 'not_in') ? "NOT {$joined}" : $joined;
        }

        // country / device = exact-match dimensions (single value or IN list).
        if ($field === 'country' || $field === 'device') {
            if (!in_array($field, $cols, true)) {
                return null;
            }
            $vals = $chip['value'] ?? [];
            if (!is_array($vals)) {
                $vals = [$vals];
            }
            $vals = array_values(array_filter(array_map('strval', $vals), fn($v) => $v !== ''));
            if (empty($vals)) {
                return null;
            }
            $refs = [];
            foreach ($vals as $v) {
                $p = 'f' . count($params);
                $params[$p] = $v;
                $refs[] = '{' . $p . ':String}';
            }
            $in = "{$field} IN (" . implode(', ', $refs) . ")";
            return in_array($op, ['not_in', 'not_equals', 'not_contains', '!='], true) ? "NOT ({$in})" : $in;
        }

        $value = (string) ($chip['value'] ?? '');
        if ($value === '') {
            return null;
        }

        $col = null;
        if ($field === 'query' && in_array('query', $cols, true)) {
            $col = 'query';
        } elseif (($field === 'url' || $field === 'page') && in_array('page', $cols, true)) {
            $col = 'page';
        }
        if ($col === null) {
            return null;
        }

        $p = 'f' . count($params);
        $params[$p] = $value;
        $ref = '{' . $p . ':String}';

        return match ($op) {
            'contains'     => "positionCaseInsensitive({$col}, {$ref}) > 0",
            'not_contains' => "positionCaseInsensitive({$col}, {$ref}) = 0",
            'regex'        => "match({$col}, {$ref})",
            'not_regex'    => "NOT match({$col}, {$ref})",
            'equals', '='  => "{$col} = {$ref}",
            default        => "positionCaseInsensitive({$col}, {$ref}) > 0",
        };
    }

    /** The category NAME column for the grid, only in URL views (page dimension)
     *  when the project has categorization rules. Wrapped in any() since it's a
     *  function of the `page` group key. */
    private function catColumn(array $dims): ?string
    {
        if (!in_array('page', $dims, true) || !\App\Gsc\GscCategories::hasRules($this->projectId)) {
            return null;
        }
        return 'any(' . \App\Gsc\GscCategories::caseExpr($this->projectId, 'page') . ') AS category';
    }

    /** The category conditions map (id => SQL on `page`), only loaded when a
     *  category filter is actually present. */
    private function catConds(array $filters): array
    {
        return self::filtersReference($filters, 'category')
            ? \App\Gsc\GscCategories::conditions($this->projectId)
            : [];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function baseWhere(string $from, string $to, array &$params): string
    {
        $params['pid']  = $this->projectId;
        $params['from'] = $this->safeDate($from);
        $params['to']   = $this->safeDate($to);
        return "project_id = {pid:Int32} AND search_type = 'web' AND date >= {from:Date} AND date <= {to:Date}";
    }

    private function anonClause(bool $hasAnon, bool $includeAnon): string
    {
        return ($hasAnon && !$includeAnon) ? ' AND is_anon = 0' : '';
    }

    private function positionExpr(bool $hasAnon): string
    {
        // Impression-weighted average over NAMED rows only (anon rows = position 0).
        if ($hasAnon) {
            return 'if(sumIf(impressions, is_anon=0)=0, 0, sumIf(position*impressions, is_anon=0)/sumIf(impressions, is_anon=0))';
        }
        return 'if(sum(impressions)=0, 0, sum(position*impressions)/sum(impressions))';
    }

    /** Impression-weighted average position, scoped to a date-range condition
     *  (used by the comparison query, which aggregates two periods at once). */
    private function weightedPos(bool $hasAnon, string $cond): string
    {
        $c = $hasAnon ? "({$cond}) AND is_anon=0" : $cond;
        return "if(sumIf(impressions, {$c})=0, 0, sumIf(position*impressions, {$c})/sumIf(impressions, {$c}))";
    }

    private function safeDate(string $d): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d');
    }
}
