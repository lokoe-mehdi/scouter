<?php
/**
 * Crawl Comparison - Outlinks Analysis
 *
 * Compares outlinks distribution (cumulative area chart),
 * average outlinks by category, and lists URLs whose outlinks
 * increased significantly between the two crawls.
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
// Chart 1: Cumulative outlinks distribution (area) ref vs base
// =========================================
$sqlOutlinksDistribution = "
    SELECT
        outlinks,
        COUNT(*) as url_count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true AND in_crawl = TRUE
    GROUP BY outlinks
    ORDER BY outlinks DESC
";

// Reference + base crawl (cached; warms both crawls)
$outlinksDistRef  = \App\Analysis\ReportPrecompute::cached($safeCrawlId,   'outlinkscmp_dist', $pdo, $sqlOutlinksDistribution, [':crawl_id' => $safeCrawlId],   false);
$outlinksDistBase = \App\Analysis\ReportPrecompute::cached($safeCompareId, 'outlinkscmp_dist', $pdo, $sqlOutlinksDistribution, [':crawl_id' => $safeCompareId], false);

// Build cumulative data for reference
$totalUrlsRef = array_sum(array_column($outlinksDistRef, 'url_count'));
$cumulativeRef = [];
$cumulative = 0;
foreach ($outlinksDistRef as $row) {
    $cumulative += $row->url_count;
    $percentage = $totalUrlsRef > 0 ? ($cumulative / $totalUrlsRef) * 100 : 0;
    $cumulativeRef[] = [
        'outlinks' => (int)$row->outlinks,
        'percentage' => round($percentage, 2)
    ];
}
// Sort by outlinks ascending for cumulative display
usort($cumulativeRef, function($a, $b) { return $a['outlinks'] - $b['outlinks']; });

// Build cumulative data for base
$totalUrlsBase = array_sum(array_column($outlinksDistBase, 'url_count'));
$cumulativeBase = [];
$cumulative = 0;
foreach ($outlinksDistBase as $row) {
    $cumulative += $row->url_count;
    $percentage = $totalUrlsBase > 0 ? ($cumulative / $totalUrlsBase) * 100 : 0;
    $cumulativeBase[] = [
        'outlinks' => (int)$row->outlinks,
        'percentage' => round($percentage, 2)
    ];
}
usort($cumulativeBase, function($a, $b) { return $a['outlinks'] - $b['outlinks']; });

// Prepare chart data: [percentage, outlinks]
$chartDataRef = array_map(function($d) {
    return [$d['percentage'], $d['outlinks']];
}, $cumulativeRef);

$chartDataBase = array_map(function($d) {
    return [$d['percentage'], $d['outlinks']];
}, $cumulativeBase);

// SQL display
$sqlDistDisplay = "SELECT
    COALESCE(r.outlinks, b.outlinks) AS outlinks,
    COALESCE(r.url_count, 0) AS ref_count,
    COALESCE(b.url_count, 0) AS base_count
FROM (
    SELECT outlinks, COUNT(*) AS url_count
    FROM pages@{$safeCrawlId}
    WHERE crawled = true AND compliant = true AND in_crawl = TRUE
    GROUP BY outlinks
) r
FULL OUTER JOIN (
    SELECT outlinks, COUNT(*) AS url_count
    FROM pages@{$safeCompareId}
    WHERE crawled = true AND compliant = true AND in_crawl = TRUE
    GROUP BY outlinks
) b ON r.outlinks = b.outlinks
ORDER BY COALESCE(r.outlinks, b.outlinks) DESC";

// =========================================
// Chart 2: Average outlinks by category (horizontal bar) ref vs base
// =========================================
$sqlByCategory = "
    SELECT
        category,
        COUNT(id) as url_count,
        ROUND(AVG(outlinks)::numeric, 2) as avg_outlinks
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true AND in_crawl = TRUE
    GROUP BY category
    ORDER BY AVG(outlinks) DESC
";

$catRef  = \App\Analysis\ReportPrecompute::cached($safeCrawlId,   'outlinkscmp_by_category', $pdo, $sqlByCategory, [':crawl_id' => $safeCrawlId],   true);
$catBase = \App\Analysis\ReportPrecompute::cached($safeCompareId, 'outlinkscmp_by_category', $pdo, $sqlByCategory, [':crawl_id' => $safeCompareId], true);

// Build maps by category name
$refCatData = [];
foreach ($catRef as $r) {
    $catName = (($r->category ?? '') !== '') ? $r->category : __('common.uncategorized');
    $refCatData[$catName] = (float)$r->avg_outlinks;
}
$baseCatData = [];
foreach ($catBase as $r) {
    $catName = (($r->category ?? '') !== '') ? $r->category : __('common.uncategorized');
    $baseCatData[$catName] = (float)$r->avg_outlinks;
}

$allCatNames = array_unique(array_merge(array_keys($refCatData), array_keys($baseCatData)));

$refCatValues = [];
$baseCatValues = [];
foreach ($allCatNames as $catName) {
    $refCatValues[] = $refCatData[$catName] ?? 0;
    $baseCatValues[] = $baseCatData[$catName] ?? 0;
}

// SQL display for category chart
$sqlCatDisplay = "SELECT
    COALESCE(r.category, b.category) AS category,
    COALESCE(r.avg_outlinks, 0) AS ref_avg_outlinks,
    COALESCE(b.avg_outlinks, 0) AS base_avg_outlinks
FROM (
    SELECT category, ROUND(AVG(outlinks)::numeric, 2) AS avg_outlinks
    FROM pages@{$safeCrawlId}
    WHERE crawled = true AND compliant = true AND in_crawl = TRUE
    GROUP BY category
) r
FULL OUTER JOIN (
    SELECT category, ROUND(AVG(outlinks)::numeric, 2) AS avg_outlinks
    FROM pages@{$safeCompareId}
    WHERE crawled = true AND compliant = true AND in_crawl = TRUE
    GROUP BY category
) b ON r.category = b.category
ORDER BY COALESCE(r.avg_outlinks, 0) DESC";

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

    <!-- Charts -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'area',
        'title' => __('outlinks.chart_distribution'),
        'subtitle' => __('outlinks.chart_distribution_desc'),
        'categories' => [],
        'series' => [
            [
                'name' => __('outlinks.series_outlinks') . ' (' . __('comparison.badge_reference') . ')',
                'data' => $chartDataRef,
                'color' => '#4ECDC4'
            ],
            [
                'name' => __('outlinks.series_outlinks') . ' (' . __('comparison.badge_baseline') . ')',
                'data' => $chartDataBase,
                'color' => hexToRgba('#4ECDC4', 0.5)
            ]
        ],
        'xAxisTitle' => __('outlinks.label_pct_urls'),
        'yAxisTitle' => __('outlinks.label_outlinks_log'),
        'logarithmic' => true,
        'xAxisMin' => 0,
        'xAxisMax' => 100,
        'height' => 400,
        'tooltipFormat' => __('outlinks.tooltip'),
        'sqlQuery' => $sqlDistDisplay
    ]);

    ?>
    </div>

    <?php
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('outlinks.table_category'),
        'subtitle' => __('outlinks.table_category_desc'),
        'categories' => array_values($allCatNames),
        'series' => [
            [
                'name' => __('common.average') . ' (' . __('comparison.badge_reference') . ')',
                'data' => $refCatValues,
                'color' => '#4ECDC4'
            ],
            [
                'name' => __('common.average') . ' (' . __('comparison.badge_baseline') . ')',
                'data' => $baseCatValues,
                'color' => hexToRgba('#4ECDC4', 0.5)
            ]
        ],
        'yAxisTitle' => __('outlinks.card_avg'),
        'height' => 400,
        'sqlQuery' => $sqlCatDisplay
    ]);
    ?>

    <?php
    Component::urlTable([
        'title' => __('comparison.outlinks_changes_table'),
        'id' => 'outlinks_changes_table',
        'whereClause' => "WHERE c.crawled = true AND c.compliant = true AND c.in_crawl = TRUE AND c.url IN (
            SELECT cur.url FROM pages_{$safeCrawlId} cur JOIN pages_{$safeCompareId} b ON cur.url = b.url
            WHERE b.crawled = true AND b.compliant = true AND b.in_crawl = TRUE AND b.outlinks < cur.outlinks
        )",
        'orderBy' => 'ORDER BY c.outlinks DESC',
        'defaultColumns' => ['url', 'category', 'outlinks', 'depth', 'pri'],
        'compareCrawlId' => $safeCompareId,
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
