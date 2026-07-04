<?php
/**
 * Presentation helpers shared by the "Performance" (crawl × Search Console)
 * report pages. Loaded once per request (function_exists guards) from each page.
 *
 * These emit the standard component idioms (Component::chart / Component::simpleTable)
 * so the Performance pages look and behave exactly like the classic reports —
 * the only difference is the data source is a crawl↔GSC join.
 */

if (!function_exists('perf_num')) {
    /** Locale-agnostic thousands formatting for a count metric. */
    function perf_num($v): string
    {
        return number_format((float) $v, 0, '.', ' ');
    }
}

if (!function_exists('perf_ctr')) {
    /** CTR (a 0..1 ratio) → "x.x %". */
    function perf_ctr($ratio): string
    {
        return number_format(((float) $ratio) * 100, 2, '.', ' ') . ' %';
    }
}

if (!function_exists('perf_pos')) {
    /** Average position → 1 decimal (or "—" when there is no data). */
    function perf_pos($p): string
    {
        $p = (float) $p;
        return $p > 0 ? number_format($p, 1, '.', ' ') : '—';
    }
}

if (!function_exists('perf_url_cell')) {
    /** A clickable, truncated URL cell (opens the URL-details modal like the classic tables). */
    function perf_url_cell(string $url): string
    {
        $safe = htmlspecialchars($url);
        return '<a href="' . $safe . '" target="_blank" rel="noopener" '
             . 'style="color:var(--primary-color);text-decoration:none;word-break:break-all;">'
             . $safe . '</a>';
    }
}

if (!function_exists('perf_render_buckets')) {
    /**
     * Render the canonical "vs clicks / vs impressions" bucket analysis: two
     * charts (clicks-by-bucket, impressions-by-bucket) + a per-bucket metrics
     * table. Every chart carries its own source SQL (the SQL-scope icon).
     *
     * $cfg keys:
     *   rows          array of ['label','color','urls','clicks','impressions','ctr','position']
     *   sql           the grouped source SQL (shown on both charts' SQL icon)
     *   dimLabel      column header for the bucket dimension
     *   clicksTitle / clicksSubtitle
     *   imprTitle    / imprSubtitle
     *   tableTitle   / tableSubtitle
     *   chartType     'bar' (default) | 'horizontalBar'
     */
    function perf_render_buckets(array $cfg): void
    {
        $rows       = $cfg['rows'] ?? [];
        $sql        = $cfg['sql'] ?? null;
        $dimLabel   = $cfg['dimLabel'] ?? '';
        $chartType  = $cfg['chartType'] ?? 'bar';

        $categories = array_map(fn($r) => (string) $r['label'], $rows);
        $colors     = array_map(fn($r) => $r['color'] ?? '#4ECDC4', $rows);
        $clicks     = array_map(fn($r) => (int) $r['clicks'], $rows);
        $impr       = array_map(fn($r) => (int) $r['impressions'], $rows);

        echo '<div class="charts-grid">';
        Component::chart([
            'type'      => $chartType,
            'title'     => $cfg['clicksTitle'] ?? __('performance.clicks_by', ['dim' => $dimLabel]),
            'subtitle'  => $cfg['clicksSubtitle'] ?? '',
            'categories'=> $categories,
            'series'    => [[ 'name' => __('performance.metric_clicks'), 'data' => $clicks, 'colorByPoint' => true, 'colors' => $colors ]],
            'yAxisTitle'=> __('performance.metric_clicks'),
            'xAxisTitle'=> $dimLabel,
            'height'    => 360,
            'sqlQuery'  => $sql,
        ]);
        Component::chart([
            'type'      => $chartType,
            'title'     => $cfg['imprTitle'] ?? __('performance.impressions_by', ['dim' => $dimLabel]),
            'subtitle'  => $cfg['imprSubtitle'] ?? '',
            'categories'=> $categories,
            'series'    => [[ 'name' => __('performance.metric_impressions'), 'data' => $impr, 'colorByPoint' => true, 'colors' => $colors ]],
            'yAxisTitle'=> __('performance.metric_impressions'),
            'xAxisTitle'=> $dimLabel,
            'height'    => 360,
            'sqlQuery'  => $sql,
        ]);
        echo '</div>';

        // Per-bucket metrics table.
        $totalClicks = array_sum($clicks);
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'bucket'      => (string) $r['label'],
                'urls'        => perf_num($r['urls'] ?? 0),
                'clicks'      => perf_num($r['clicks'] ?? 0),
                'clicks_pct'  => $totalClicks > 0 ? ((int) $r['clicks'] / $totalClicks) : 0,
                'impressions' => perf_num($r['impressions'] ?? 0),
                'ctr'         => perf_ctr($r['ctr'] ?? 0),
                'position'    => perf_pos($r['position'] ?? 0),
            ];
        }
        Component::simpleTable([
            'title'    => $cfg['tableTitle'] ?? '',
            'subtitle' => $cfg['tableSubtitle'] ?? '',
            'maxLines' => 0,
            'columns'  => [
                ['key' => 'bucket',      'label' => $dimLabel,                          'type' => 'bold'],
                ['key' => 'urls',        'label' => __('performance.col_urls'),         'type' => 'default'],
                ['key' => 'clicks',      'label' => __('performance.metric_clicks'),    'type' => 'default'],
                ['key' => 'clicks_pct',  'label' => __('performance.col_clicks_share'), 'type' => 'percent_bar'],
                ['key' => 'impressions', 'label' => __('performance.metric_impressions'),'type' => 'default'],
                ['key' => 'ctr',         'label' => __('performance.metric_ctr'),       'type' => 'default'],
                ['key' => 'position',    'label' => __('performance.metric_position'),  'type' => 'default'],
            ],
            'data' => $data,
        ]);
    }
}

if (!function_exists('perf_url_table')) {
    /**
     * The standard PAGINATED url-table (Component::urlTable) for the Performance
     * "problem / opportunity" lists — same component as every other report, with
     * the project-scoped GSC per-URL aggregate joined in (gscJoin). You get real
     * pagination, sortable headers, the column picker and the URL-details modal,
     * plus the gsc_clicks / gsc_impressions / gsc_ctr / gsc_position columns. CSV
     * export is hidden (per-URL GSC export lives in the Search Analytics view).
     *
     * $cfg:
     *   id            unique table id
     *   title         heading (the component appends the URL count)
     *   whereClause   `WHERE …` using `c.` for crawl columns + `g.` for the GSC
     *                 aggregate (e.g. "WHERE c.crawled = true AND c.in_crawl = TRUE
     *                 AND c.compliant = 1 AND (g.clicks = 0 OR isNull(g.clicks))").
     *                 crawl_id is injected by the component — never add it.
     *   orderBy       e.g. "ORDER BY g.impressions DESC" (default) / "ORDER BY c.pri DESC"
     *   extraColumns  pages column keys shown between url/category and the GSC metrics
     *   gscJoin       PerformanceReport::gscPageAgg($from, $to)
     *   pdo, crawlId  from the page scope
     *   perPage       default 20
     */
    function perf_url_table(array $cfg): void
    {
        $cols = array_merge(
            ['url', 'category'],
            $cfg['extraColumns'] ?? [],
            ['gsc_clicks', 'gsc_impressions', 'gsc_ctr', 'gsc_position']
        );
        Component::urlTable([
            'title'          => $cfg['title'] ?? '',
            'id'             => $cfg['id'],
            'whereClause'    => $cfg['whereClause'],
            'orderBy'        => $cfg['orderBy'] ?? 'ORDER BY g.impressions DESC',
            'defaultColumns' => $cols,
            'gscJoin'        => $cfg['gscJoin'],
            'noExport'       => true,
            'pdo'            => $cfg['pdo'],
            'crawlId'        => $cfg['crawlId'],
            'perPage'        => $cfg['perPage'] ?? 10,
            'projectDir'     => $_GET['project'] ?? '',
        ]);
    }
}

if (!function_exists('perf_gsc_url_table')) {
    /**
     * The SAME paginated url-table component, but for GSC-ONLY URLs that don't
     * exist in the crawl `pages` (orphan pages). It drives the component with a
     * full custom `sqlQuery` (columns aliased to url / gsc_clicks / gsc_impressions
     * / gsc_ctr / gsc_position) instead of a `pages` whereClause. Same look,
     * pagination, sorting and column picker.
     *
     * $cfg: id, title, sqlQuery (SELECT … aliased as above), pdo, crawlId, perPage.
     */
    function perf_gsc_url_table(array $cfg): void
    {
        Component::urlTable([
            'title'          => $cfg['title'] ?? '',
            'id'             => $cfg['id'],
            'sqlQuery'       => $cfg['sqlQuery'],
            'sqlParams'      => $cfg['sqlParams'] ?? [],
            'defaultColumns' => $cfg['defaultColumns'] ?? ['url', 'gsc_clicks', 'gsc_impressions', 'gsc_ctr', 'gsc_position'],
            'gscColumns'     => true,
            'noExport'       => true,
            'pdo'            => $cfg['pdo'],
            'crawlId'        => $cfg['crawlId'],
            'perPage'        => $cfg['perPage'] ?? 10,
            'projectDir'     => $_GET['project'] ?? '',
        ]);
    }
}
