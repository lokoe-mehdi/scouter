<?php
/**
 * Crawl Comparison - PageRank Analysis
 *
 * Compares PageRank distribution by depth and category between
 * reference and baseline crawls, and lists URLs that lost PageRank.
 */

if (!$compareId) {
    ?>
    <div style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <span class="material-symbols-outlined" style="font-size: 4rem; color: var(--text-tertiary);">compare_arrows</span>
        <h2 style="margin-top: 1rem; color: var(--text-primary);"><?= __('comparison.single_crawl') ?></h2>
        <p style="color: var(--text-secondary); margin-top: 0.5rem;"><?= __('comparison.single_crawl_desc') ?></p>
    </div>
    <?php
    return;
}

$safeCompareId = intval($compareId);
$safeCrawlId = intval($crawlId);

// Scorecards pré-calculés dans dashboard.php
$newCount = $compNewCount;
$lostCount = $compLostCount;
$commonCount = $compCommonCount;

// =========================================
// Helper: hex to rgba
// =========================================
if (!function_exists('hexToRgba')) {
    function hexToRgba($hex, $alpha = 1.0) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "rgba($r,$g,$b,$alpha)";
    }
}

$categoriesMap = $GLOBALS['categoriesMap'] ?? [];

// =========================================
// Chart 1: PageRank by depth (line) ref vs base
// =========================================
$sqlPrByDepth = "
    SELECT
        depth,
        AVG(pri) as avg_pr,
        COUNT(*) as count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND pri > 0
    GROUP BY depth
    ORDER BY depth
";

$stmtRef = $pdo->prepare($sqlPrByDepth);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$prByDepthRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlPrByDepth);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$prByDepthBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

// Merge all depth levels from both crawls
$allDepths = [];
foreach ($prByDepthRef as $r) $allDepths[$r->depth] = true;
foreach ($prByDepthBase as $r) $allDepths[$r->depth] = true;
ksort($allDepths);
$depthLabels = array_map(function($d) { return __('pagerank.label_depth') . ' ' . $d; }, array_keys($allDepths));
$depthKeys = array_keys($allDepths);

// Build maps
$refDepthMap = [];
foreach ($prByDepthRef as $r) $refDepthMap[$r->depth] = $r;
$baseDepthMap = [];
foreach ($prByDepthBase as $r) $baseDepthMap[$r->depth] = $r;

// Series values (in percentage)
$refDepthValues = [];
$baseDepthValues = [];
foreach ($depthKeys as $d) {
    $refDepthValues[] = isset($refDepthMap[$d]) ? round($refDepthMap[$d]->avg_pr * 100, 2) : 0;
    $baseDepthValues[] = isset($baseDepthMap[$d]) ? round($baseDepthMap[$d]->avg_pr * 100, 2) : 0;
}

// SQL display
$sqlPrByDepthDisplay = "SELECT
    COALESCE(r.depth, b.depth) AS depth,
    COALESCE(r.avg_pr, 0) AS ref_avg_pr,
    COALESCE(r.count, 0) AS ref_count,
    COALESCE(b.avg_pr, 0) AS base_avg_pr,
    COALESCE(b.count, 0) AS base_count
FROM (
    SELECT depth, AVG(pri) AS avg_pr, COUNT(*) AS count
    FROM pages@{$safeCrawlId}
    WHERE crawled = true AND pri > 0 GROUP BY depth
) r
FULL OUTER JOIN (
    SELECT depth, AVG(pri) AS avg_pr, COUNT(*) AS count
    FROM pages@{$safeCompareId}
    WHERE crawled = true AND pri > 0 GROUP BY depth
) b ON r.depth = b.depth
ORDER BY COALESCE(r.depth, b.depth)";

// =========================================
// Chart 2: PageRank distribution by category (donut) ref vs base
// =========================================
$sqlPrByCategory = "
    SELECT
        cat_id,
        SUM(pri) as total_pr,
        AVG(pri) as avg_pr,
        COUNT(*) as count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND pri > 0
    GROUP BY cat_id
    ORDER BY AVG(pri) DESC
";

$stmtRef = $pdo->prepare($sqlPrByCategory);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$prByCatRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlPrByCategory);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$prByCatBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

// Build maps by category name
$refCatData = [];
foreach ($prByCatRef as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $refCatData[$catName] = $r;
}
$baseCatData = [];
foreach ($prByCatBase as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $baseCatData[$catName] = $r;
}

$allCatNames = array_unique(array_merge(array_keys($refCatData), array_keys($baseCatData)));

// Donut data: 2 series (ref full color, base 50% opacity)
$donutRefData = [];
$donutBaseData = [];
foreach ($allCatNames as $catName) {
    $color = getCategoryColor($catName);
    $donutRefData[] = [
        'name' => $catName . ' (' . __('comparison.badge_reference') . ')',
        'y' => isset($refCatData[$catName]) ? round($refCatData[$catName]->total_pr * 100, 2) : 0,
        'color' => $color
    ];
    $donutBaseData[] = [
        'name' => $catName . ' (' . __('comparison.badge_baseline') . ')',
        'y' => isset($baseCatData[$catName]) ? round($baseCatData[$catName]->total_pr * 100, 2) : 0,
        'color' => hexToRgba($color, 0.5)
    ];
}

// SQL display
$sqlPrByCatDisplay = "SELECT
    COALESCE(r.cat_id, b.cat_id) AS cat_id,
    COALESCE(r.total_pr, 0) AS ref_total_pr,
    COALESCE(r.avg_pr, 0) AS ref_avg_pr,
    COALESCE(r.count, 0) AS ref_count,
    COALESCE(b.total_pr, 0) AS base_total_pr,
    COALESCE(b.avg_pr, 0) AS base_avg_pr,
    COALESCE(b.count, 0) AS base_count
FROM (
    SELECT cat_id, SUM(pri) AS total_pr, AVG(pri) AS avg_pr, COUNT(*) AS count
    FROM pages@{$safeCrawlId}
    WHERE crawled = true AND pri > 0 GROUP BY cat_id
) r
FULL OUTER JOIN (
    SELECT cat_id, SUM(pri) AS total_pr, AVG(pri) AS avg_pr, COUNT(*) AS count
    FROM pages@{$safeCompareId}
    WHERE crawled = true AND pri > 0 GROUP BY cat_id
) b ON r.cat_id = b.cat_id
ORDER BY COALESCE(r.avg_pr, b.avg_pr) DESC";

// =========================================
// Chart 3: Average PR by category (horizontal bar) ref vs base
// =========================================
$catBarSeries = [];
$refAvgValues = [];
$baseAvgValues = [];
foreach ($allCatNames as $catName) {
    $refAvgValues[] = isset($refCatData[$catName]) ? round($refCatData[$catName]->avg_pr * 100, 2) : 0;
    $baseAvgValues[] = isset($baseCatData[$catName]) ? round($baseCatData[$catName]->avg_pr * 100, 2) : 0;
}

$catBarSeries[] = [
    'name' => __('pagerank.series_avg') . ' (' . __('comparison.badge_reference') . ')',
    'data' => $refAvgValues,
    'color' => '#4ECDC4',
    'stack' => 'reference'
];
$catBarSeries[] = [
    'name' => __('pagerank.series_avg') . ' (' . __('comparison.badge_baseline') . ')',
    'data' => $baseAvgValues,
    'color' => hexToRgba('#4ECDC4', 0.5),
    'stack' => 'baseline'
];

?>

<?php include __DIR__ . '/../components/comparison-bar.php'; ?>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <div class="scorecards">
    <?php
    Component::card([
        'color' => 'success',
        'icon' => 'add_circle',
        'title' => __('comparison.card_new'),
        'value' => number_format($newCount)
    ]);
    Component::card([
        'color' => 'error',
        'icon' => 'remove_circle',
        'title' => __('comparison.card_lost'),
        'value' => number_format($lostCount)
    ]);
    Component::card([
        'color' => 'info',
        'icon' => 'sync',
        'title' => __('comparison.card_common'),
        'value' => number_format($commonCount)
    ]);
    ?>
    </div>

    <!-- Charts: PageRank comparison -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'line',
        'title' => __('pagerank.chart_by_depth'),
        'subtitle' => __('pagerank.chart_by_depth_desc'),
        'categories' => array_values($depthLabels),
        'series' => [
            [
                'name' => __('pagerank.series_pagerank') . ' (' . __('comparison.badge_reference') . ')',
                'data' => $refDepthValues,
                'color' => '#4ECDC4'
            ],
            [
                'name' => __('pagerank.series_pagerank') . ' (' . __('comparison.badge_baseline') . ')',
                'data' => $baseDepthValues,
                'color' => hexToRgba('#4ECDC4', 0.5)
            ]
        ],
        'xAxisTitle' => __('pagerank.label_depth'),
        'yAxisTitle' => __('pagerank.label_avg_pagerank'),
        'height' => 350,
        'sqlQuery' => $sqlPrByDepthDisplay
    ]);

    Component::chart([
        'type' => 'donut',
        'title' => __('pagerank.chart_distribution'),
        'subtitle' => __('pagerank.chart_distribution_desc'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $donutRefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $donutBaseData]
        ],
        'height' => 350,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlPrByCatDisplay
    ]);
    ?>
    </div>

    <?php
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('pagerank.chart_category_avg'),
        'subtitle' => __('pagerank.chart_category_avg_desc'),
        'categories' => array_values($allCatNames),
        'series' => $catBarSeries,
        'yAxisTitle' => __('pagerank.label_avg_pagerank'),
        'height' => 400,
        'sqlQuery' => $sqlPrByCatDisplay
    ]);
    ?>

    <?php
    Component::urlTable([
        'title' => __('comparison.pagerank_lost_table'),
        'id' => 'pagerank_lost_table',
        'whereClause' => "WHERE c.crawled = true AND c.pri > 0 AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.crawled = true AND b.pri > c.pri
        )",
        'orderBy' => 'ORDER BY c.pri DESC',
        'defaultColumns' => ['url', 'category', 'pri', 'depth', 'inlinks', 'compliant'],
        'compareCrawlId' => $safeCompareId,
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
