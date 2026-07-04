<?php
/**
 * ============================================================================
 * PERFORMANCE — Overview (crawl × Google Search Console)
 * ============================================================================
 * Headline Search Console KPIs for the property over the selected range, the
 * daily clicks / impressions trend, how much of the traffic is attributable to
 * crawled URLs (coverage), and the click / impression split by crawl category.
 *
 * Data source: project-scoped gsc_* tables joined to the crawl's `pages` (both
 * exposed through $pdo / ChPdo). $crawlId, $pdo, $useCh come from dashboard.php.
 */

$perf = \App\Gsc\PerformanceReport::context((int) $crawlId, !empty($useCh));
if (!$perf['available']) { \App\Gsc\PerformanceReport::renderUnavailable($perf); return; }
require_once __DIR__ . '/../partials/performance-helpers.php';

$from = $perf['from'];
$to   = $perf['to'];
$gscAgg = \App\Gsc\PerformanceReport::gscPageAgg($from, $to);
$wpos   = \App\Gsc\PerformanceReport::weightedPos('g');
$pRange = [':from' => $from, ':to' => $to];
$pAll   = [':crawl_id' => (int) $crawlId, ':from' => $from, ':to' => $to];

// --- Headline KPIs: the true property totals (gsc_site_daily, matches GSC) ----
$sqlKpis = "
    SELECT
        sum(clicks) AS clicks,
        sum(impressions) AS impressions,
        if(sum(impressions) = 0, 0, sum(clicks) / sum(impressions)) AS ctr,
        if(sum(impressions) = 0, 0, sum(position * impressions) / sum(impressions)) AS position
    FROM gsc_site_daily
    WHERE date >= toDate('{$from}') AND date <= toDate('{$to}')
";
$stmt = $pdo->prepare($sqlKpis); $stmt->execute($pRange); $kpis = $stmt->fetch();

// --- Daily trend -------------------------------------------------------------
$sqlTrend = "
    SELECT toString(date) AS d, sum(clicks) AS clicks, sum(impressions) AS impressions
    FROM gsc_site_daily
    WHERE date >= toDate('{$from}') AND date <= toDate('{$to}')
    GROUP BY date ORDER BY date ASC
";
$stmt = $pdo->prepare($sqlTrend); $stmt->execute($pRange); $trend = $stmt->fetchAll();

// --- Coverage: crawled URLs vs. their Search Console visibility ---------------
$sqlCoverage = "
    SELECT
        count() AS crawled_urls,
        countIf(g.impressions > 0) AS with_impr,
        countIf(g.clicks > 0) AS with_clicks,
        countIf(g.impressions = 0 OR isNull(g.impressions)) AS no_visibility
    FROM pages p
    LEFT JOIN ( {$gscAgg} ) g ON g.page = p.url
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE
";
$stmt = $pdo->prepare($sqlCoverage); $stmt->execute($pAll); $cov = $stmt->fetch();

// GSC URLs receiving impressions that the crawl never reached (not crawled).
$sqlOrphans = "
    SELECT count() AS n FROM (
        SELECT page FROM gsc_page_daily
        WHERE date >= toDate('{$from}') AND date <= toDate('{$to}')
        GROUP BY page HAVING sum(impressions) > 0
    ) g
    WHERE g.page NOT IN (
        SELECT url FROM pages WHERE crawl_id = :crawl_id AND crawled = true AND in_crawl = TRUE
    )
";
$stmt = $pdo->prepare($sqlOrphans); $stmt->execute($pAll); $orphans = (int) ($stmt->fetch()->n ?? 0);

// --- Split by crawl category -------------------------------------------------
$sqlByCat = "
    SELECT
        p.category AS category,
        count() AS urls,
        sum(g.clicks) AS clicks,
        sum(g.impressions) AS impressions,
        {$wpos} AS position
    FROM pages p
    LEFT JOIN ( {$gscAgg} ) g ON g.page = p.url
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE
    GROUP BY p.category
    ORDER BY sum(g.clicks) DESC, sum(g.impressions) DESC
";
$stmt = $pdo->prepare($sqlByCat); $stmt->execute($pAll); $byCat = $stmt->fetchAll();

?>

<h1 class="page-title"><?= __('performance.overview_title') ?></h1>
<?php include __DIR__ . '/../partials/performance-controls.php'; ?>

<div class="perf-note">
    <span class="material-symbols-outlined">info</span>
    <span><?= __('performance.overview_note') ?></span>
</div>

<div style="display:flex; flex-direction:column; gap:1.5rem;">

    <!-- KPIs (true property totals) -->
    <div class="scorecards">
        <?php
        Component::card(['color' => 'primary', 'icon' => 'ads_click', 'title' => __('performance.metric_clicks'),
            'value' => perf_num($kpis->clicks ?? 0), 'desc' => __('performance.kpi_clicks_desc')]);
        Component::card(['color' => 'info', 'icon' => 'visibility', 'title' => __('performance.metric_impressions'),
            'value' => perf_num($kpis->impressions ?? 0), 'desc' => __('performance.kpi_impressions_desc')]);
        Component::card(['color' => 'success', 'icon' => 'percent', 'title' => __('performance.metric_ctr'),
            'value' => perf_ctr($kpis->ctr ?? 0), 'desc' => __('performance.kpi_ctr_desc')]);
        Component::card(['color' => 'warning', 'icon' => 'format_list_numbered', 'title' => __('performance.metric_position'),
            'value' => perf_pos($kpis->position ?? 0), 'desc' => __('performance.kpi_position_desc')]);
        ?>
    </div>

    <!-- Daily trend: clicks & impressions -->
    <div class="charts-grid">
        <?php
        $trendDates = array_map(fn($r) => $r->d, $trend);
        Component::chart([
            'type' => 'line', 'title' => __('performance.trend_clicks_title'),
            'subtitle' => __('performance.trend_clicks_subtitle'),
            'categories' => $trendDates,
            'series' => [['name' => __('performance.metric_clicks'), 'data' => array_map(fn($r) => (int) $r->clicks, $trend), 'color' => '#4ECDC4']],
            'xAxisTitle' => __('performance.axis_date'), 'yAxisTitle' => __('performance.metric_clicks'),
            'height' => 320, 'sqlQuery' => $sqlTrend,
        ]);
        Component::chart([
            'type' => 'line', 'title' => __('performance.trend_impressions_title'),
            'subtitle' => __('performance.trend_impressions_subtitle'),
            'categories' => $trendDates,
            'series' => [['name' => __('performance.metric_impressions'), 'data' => array_map(fn($r) => (int) $r->impressions, $trend), 'color' => '#5B8FF9']],
            'xAxisTitle' => __('performance.axis_date'), 'yAxisTitle' => __('performance.metric_impressions'),
            'height' => 320, 'sqlQuery' => $sqlTrend,
        ]);
        ?>
    </div>

    <!-- Coverage cards -->
    <div class="scorecards">
        <?php
        $crawledUrls = (int) ($cov->crawled_urls ?? 0);
        $withImpr = (int) ($cov->with_impr ?? 0);
        Component::card(['color' => 'primary', 'icon' => 'travel_explore', 'title' => __('performance.cov_crawled'),
            'value' => perf_num($crawledUrls), 'desc' => __('performance.cov_crawled_desc')]);
        Component::card(['color' => 'success', 'icon' => 'visibility', 'title' => __('performance.cov_with_impr'),
            'value' => perf_num($withImpr) . ($crawledUrls > 0 ? ' (' . round($withImpr / $crawledUrls * 100) . '%)' : ''),
            'desc' => __('performance.cov_with_impr_desc')]);
        Component::card(['color' => 'error', 'icon' => 'visibility_off', 'title' => __('performance.cov_no_visibility'),
            'value' => perf_num($cov->no_visibility ?? 0), 'desc' => __('performance.cov_no_visibility_desc')]);
        Component::card(['color' => 'color1', 'icon' => 'link_off', 'title' => __('performance.cov_orphans'),
            'value' => perf_num($orphans), 'desc' => __('performance.cov_orphans_desc')]);
        ?>
    </div>

    <!-- Split by category -->
    <?php
    $clicksDonut = [];
    $imprDonut = [];
    $catTable = [];
    $totClicks = 0; $totImpr = 0;
    foreach ($byCat as $r) { $totClicks += (int) $r->clicks; $totImpr += (int) $r->impressions; }
    foreach ($byCat as $r) {
        $catName = ($r->category ?? '') !== '' ? $r->category : __('common.uncategorized');
        $color = getCategoryColor(($r->category ?? '') !== '' ? $r->category : '');
        if ((int) $r->clicks > 0)      { $clicksDonut[] = ['name' => $catName, 'y' => (int) $r->clicks, 'color' => $color]; }
        if ((int) $r->impressions > 0) { $imprDonut[]   = ['name' => $catName, 'y' => (int) $r->impressions, 'color' => $color]; }
        $catTable[] = [
            'category'     => $catName,
            'urls'         => perf_num($r->urls),
            'clicks'       => perf_num($r->clicks),
            'clicks_pct'   => $totClicks > 0 ? ((int) $r->clicks / $totClicks) : 0,
            'impressions'  => perf_num($r->impressions),
            'ctr'          => perf_ctr((int) $r->impressions > 0 ? (int) $r->clicks / (int) $r->impressions : 0),
            'position'     => perf_pos($r->position),
        ];
    }
    ?>
    <div class="charts-grid">
        <?php
        Component::chart(['type' => 'donut', 'title' => __('performance.cat_clicks_title'),
            'subtitle' => __('performance.cat_clicks_subtitle'), 'legendPosition' => 'bottom', 'height' => 360,
            'series' => [['name' => __('performance.metric_clicks'), 'data' => $clicksDonut]], 'sqlQuery' => $sqlByCat]);
        Component::chart(['type' => 'donut', 'title' => __('performance.cat_impressions_title'),
            'subtitle' => __('performance.cat_impressions_subtitle'), 'legendPosition' => 'bottom', 'height' => 360,
            'series' => [['name' => __('performance.metric_impressions'), 'data' => $imprDonut]], 'sqlQuery' => $sqlByCat]);
        ?>
    </div>

    <?php
    Component::simpleTable([
        'title' => __('performance.cat_table_title'), 'subtitle' => __('performance.cat_table_subtitle'),
        'maxLines' => 0,
        'columns' => [
            ['key' => 'category',    'label' => __('performance.col_category'),     'type' => 'badge-color'],
            ['key' => 'urls',        'label' => __('performance.col_urls'),         'type' => 'default'],
            ['key' => 'clicks',      'label' => __('performance.metric_clicks'),    'type' => 'default'],
            ['key' => 'clicks_pct',  'label' => __('performance.col_clicks_share'), 'type' => 'percent_bar'],
            ['key' => 'impressions', 'label' => __('performance.metric_impressions'),'type' => 'default'],
            ['key' => 'ctr',         'label' => __('performance.metric_ctr'),       'type' => 'default'],
            ['key' => 'position',    'label' => __('performance.metric_position'),  'type' => 'default'],
        ],
        'data' => $catTable,
    ]);

    // Top pages by clicks — standard paginated component.
    perf_url_table([
        'id' => 'perf_overview_top',
        'title' => __('performance.top_pages_title'),
        'whereClause' => "WHERE c.crawled = true AND c.in_crawl = TRUE AND g.clicks > 0",
        'orderBy' => 'ORDER BY g.clicks DESC',
        'extraColumns' => ['code'],
        'gscJoin' => $gscAgg, 'pdo' => $pdo, 'crawlId' => (int) $crawlId,
    ]);
    ?>
</div>
