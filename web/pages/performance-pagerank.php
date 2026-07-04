<?php
/**
 * PERFORMANCE — Internal PageRank × Search Console (BY CATEGORY).
 * Compares, per crawl category, the total internal PageRank against the clicks
 * and impressions it earns — two grouped bars (shares of the total, so the
 * scales are comparable) ordered from the highest-PageRank category down, to
 * eyeball whether internal authority converts into traffic. The table gives the
 * raw sums; a URL list surfaces high-authority pages that earn no clicks.
 */
$perf = \App\Gsc\PerformanceReport::context((int) $crawlId, !empty($useCh));
if (!$perf['available']) { \App\Gsc\PerformanceReport::renderUnavailable($perf); return; }
require_once __DIR__ . '/../partials/performance-helpers.php';

$from = $perf['from']; $to = $perf['to'];
$gscAgg = \App\Gsc\PerformanceReport::gscPageAgg($from, $to);
$wpos   = \App\Gsc\PerformanceReport::weightedPos('g');
$pAll   = [':crawl_id' => (int) $crawlId];
$base   = "FROM pages p LEFT JOIN ( {$gscAgg} ) g ON g.page = p.url WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.compliant = 1";

// Per-category totals, ordered by total internal PageRank (desc).
$sqlByCat = "
    SELECT p.category AS category,
           count() AS urls,
           sum(p.pri) AS pagerank,
           sum(g.clicks) AS clicks,
           sum(g.impressions) AS impressions,
           {$wpos} AS position
    {$base}
    GROUP BY p.category
    ORDER BY sum(p.pri) DESC";
$stmt = $pdo->prepare($sqlByCat); $stmt->execute($pAll); $raw = $stmt->fetchAll();

// Totals for the share normalisation (so PageRank & traffic sit on one scale).
$totPr = 0; $totClicks = 0; $totImpr = 0;
foreach ($raw as $r) { $totPr += (float) $r->pagerank; $totClicks += (int) $r->clicks; $totImpr += (int) $r->impressions; }

$cats = []; $prShare = []; $clkShare = []; $imprShare = []; $catColors = []; $tableData = [];
foreach ($raw as $r) {
    $catName = ($r->category ?? '') !== '' ? $r->category : __('common.uncategorized');
    $cats[]       = $catName;
    $catColors[]  = getCategoryColor(($r->category ?? '') !== '' ? $r->category : '');
    $prShare[]    = $totPr > 0 ? round((float) $r->pagerank / $totPr * 100, 1) : 0;
    $clkShare[]   = $totClicks > 0 ? round((int) $r->clicks / $totClicks * 100, 1) : 0;
    $imprShare[]  = $totImpr > 0 ? round((int) $r->impressions / $totImpr * 100, 1) : 0;
    $tableData[] = [
        'category'      => $catName,
        'urls'          => perf_num($r->urls),
        'pagerank'      => number_format((float) $r->pagerank, 4, '.', ' '),
        'pr_share'      => $totPr > 0 ? ((float) $r->pagerank / $totPr) : 0,
        'clicks'        => perf_num($r->clicks),
        'impressions'   => perf_num($r->impressions),
        'ctr'           => perf_ctr((int) $r->impressions > 0 ? (int) $r->clicks / (int) $r->impressions : 0),
        'position'      => perf_pos($r->position),
    ];
}

$PR_COLOR = '#8E44AD'; $CLK_COLOR = '#4ECDC4'; $IMPR_COLOR = '#3498DB';
?>

<h1 class="page-title"><?= __('performance.pagerank_title') ?></h1>
<?php include __DIR__ . '/../partials/performance-controls.php'; ?>
<div class="perf-note"><span class="material-symbols-outlined">info</span><span><?= __('performance.pagerank_note_cat') ?></span></div>

<div style="display:flex; flex-direction:column; gap:1.5rem;">
    <div class="charts-grid">
        <?php
        // Two grouped-bar charts: PageRank vs Clicks, PageRank vs Impressions.
        // Values are each metric's SHARE of its total (%), so a category whose
        // PageRank bar and Clicks bar match is "converting" its authority.
        Component::chart([
            'type' => 'bar',
            'title' => __('performance.pr_cat_clicks_title'),
            'subtitle' => __('performance.pr_cat_share_subtitle'),
            'categories' => $cats,
            'series' => [
                ['name' => __('performance.dim_pagerank'), 'data' => $prShare, 'color' => $PR_COLOR],
                ['name' => __('performance.metric_clicks'), 'data' => $clkShare, 'color' => $CLK_COLOR],
            ],
            'yAxisTitle' => __('performance.pr_share_axis'),
            'height' => 380,
            'sqlQuery' => $sqlByCat,
        ]);
        Component::chart([
            'type' => 'bar',
            'title' => __('performance.pr_cat_impr_title'),
            'subtitle' => __('performance.pr_cat_share_subtitle'),
            'categories' => $cats,
            'series' => [
                ['name' => __('performance.dim_pagerank'), 'data' => $prShare, 'color' => $PR_COLOR],
                ['name' => __('performance.metric_impressions'), 'data' => $imprShare, 'color' => $IMPR_COLOR],
            ],
            'yAxisTitle' => __('performance.pr_share_axis'),
            'height' => 380,
            'sqlQuery' => $sqlByCat,
        ]);
        ?>
    </div>

    <?php
    Component::simpleTable([
        'title' => __('performance.pr_cat_table_title'),
        'subtitle' => __('performance.pr_cat_table_subtitle'),
        'maxLines' => 0,
        'columns' => [
            ['key' => 'category',    'label' => __('performance.col_category'),    'type' => 'badge-color'],
            ['key' => 'urls',        'label' => __('performance.col_urls'),        'type' => 'default'],
            ['key' => 'pagerank',    'label' => __('performance.dim_pagerank'),    'type' => 'default'],
            ['key' => 'pr_share',    'label' => __('performance.pr_share_col'),    'type' => 'percent_bar'],
            ['key' => 'clicks',      'label' => __('performance.metric_clicks'),   'type' => 'default'],
            ['key' => 'impressions', 'label' => __('performance.metric_impressions'), 'type' => 'default'],
            ['key' => 'ctr',         'label' => __('performance.metric_ctr'),      'type' => 'default'],
            ['key' => 'position',    'label' => __('performance.metric_position'), 'type' => 'default'],
        ],
        'data' => $tableData,
    ]);

    // Secondary, URL-level actionable list — standard paginated component.
    perf_url_table([
        'id' => 'perf_pr_problem',
        'title' => __('performance.pr_problem_title'),
        'whereClause' => "WHERE c.crawled = true AND c.in_crawl = TRUE AND c.compliant = 1 AND (g.clicks = 0 OR isNull(g.clicks))",
        'orderBy' => 'ORDER BY c.pri DESC',
        'extraColumns' => ['pri', 'inlinks'],
        'gscJoin' => $gscAgg, 'pdo' => $pdo, 'crawlId' => (int) $crawlId,
    ]);
    ?>
</div>
