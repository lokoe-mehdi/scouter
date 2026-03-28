<?php
/**
 * Crawl Comparison - Inlinks Analysis
 *
 * Compares cumulative inlinks distribution and average inlinks
 * per category between reference and baseline crawls.
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
// Chart 1: Cumulative inlinks distribution (area) ref vs base
// =========================================
$sqlInlinksDistribution = "
    SELECT
        inlinks,
        COUNT(*) as url_count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
    GROUP BY inlinks
    ORDER BY inlinks ASC
";

$stmtRef = $pdo->prepare($sqlInlinksDistribution);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$inlinksDistRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlInlinksDistribution);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$inlinksDistBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

// Build cumulative percentage data for ref
$totalUrlsRef = array_sum(array_column($inlinksDistRef, 'url_count'));
$cumulativeDataRef = [];
$cumulative = 0;
if (!empty($inlinksDistRef)) {
    $cumulativeDataRef[] = [$inlinksDistRef[0]->inlinks, 0];
}
foreach ($inlinksDistRef as $row) {
    $cumulative += $row->url_count;
    $percentage = $totalUrlsRef > 0 ? ($cumulative / $totalUrlsRef) * 100 : 0;
    $cumulativeDataRef[] = [round($percentage, 2), $row->inlinks];
}

// Build cumulative percentage data for base
$totalUrlsBase = array_sum(array_column($inlinksDistBase, 'url_count'));
$cumulativeDataBase = [];
$cumulative = 0;
if (!empty($inlinksDistBase)) {
    $cumulativeDataBase[] = [$inlinksDistBase[0]->inlinks, 0];
}
foreach ($inlinksDistBase as $row) {
    $cumulative += $row->url_count;
    $percentage = $totalUrlsBase > 0 ? ($cumulative / $totalUrlsBase) * 100 : 0;
    $cumulativeDataBase[] = [round($percentage, 2), $row->inlinks];
}

// SQL display
$sqlDistDisplay = "SELECT
    COALESCE(r.inlinks, b.inlinks) AS inlinks,
    COALESCE(r.url_count, 0) AS ref_url_count,
    COALESCE(b.url_count, 0) AS base_url_count
FROM (
    SELECT inlinks, COUNT(*) AS url_count
    FROM pages@{$safeCrawlId}
    WHERE crawled = true AND compliant = true
    GROUP BY inlinks
) r
FULL OUTER JOIN (
    SELECT inlinks, COUNT(*) AS url_count
    FROM pages@{$safeCompareId}
    WHERE crawled = true AND compliant = true
    GROUP BY inlinks
) b ON r.inlinks = b.inlinks
ORDER BY COALESCE(r.inlinks, b.inlinks)";

// =========================================
// Chart 2: Average inlinks by category (horizontal bar) ref vs base
// =========================================
$sqlInlinksByCategory = "
    SELECT
        cat_id,
        COUNT(id) as url_count,
        ROUND(AVG(inlinks)::numeric, 2) as avg_inlinks
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
    GROUP BY cat_id
    ORDER BY AVG(inlinks) DESC
";

$stmtRef = $pdo->prepare($sqlInlinksByCategory);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$inlinksCatRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlInlinksByCategory);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$inlinksCatBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

// Build maps by category name
$refCatData = [];
foreach ($inlinksCatRef as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $refCatData[$catName] = (float)$r->avg_inlinks;
}
$baseCatData = [];
foreach ($inlinksCatBase as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $baseCatData[$catName] = (float)$r->avg_inlinks;
}

$allCatNames = array_unique(array_merge(array_keys($refCatData), array_keys($baseCatData)));

// Build ref and base values arrays
$refCatValues = [];
$baseCatValues = [];
foreach ($allCatNames as $catName) {
    $refCatValues[] = $refCatData[$catName] ?? 0;
    $baseCatValues[] = $baseCatData[$catName] ?? 0;
}

// SQL display for category chart
$sqlCatDisplay = "SELECT
    COALESCE(r.cat_id, b.cat_id) AS cat_id,
    COALESCE(r.avg_inlinks, 0) AS ref_avg_inlinks,
    COALESCE(b.avg_inlinks, 0) AS base_avg_inlinks
FROM (
    SELECT cat_id, ROUND(AVG(inlinks)::numeric, 2) AS avg_inlinks
    FROM pages@{$safeCrawlId}
    WHERE crawled = true AND compliant = true
    GROUP BY cat_id
) r
FULL OUTER JOIN (
    SELECT cat_id, ROUND(AVG(inlinks)::numeric, 2) AS avg_inlinks
    FROM pages@{$safeCompareId}
    WHERE crawled = true AND compliant = true
    GROUP BY cat_id
) b ON r.cat_id = b.cat_id
ORDER BY COALESCE(r.avg_inlinks, 0) DESC";

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

    <!-- Chart: Cumulative inlinks distribution comparison -->
    <div>
    <?php
    Component::chart([
        'type' => 'area',
        'title' => __('inlinks.chart_distribution'),
        'subtitle' => __('inlinks.chart_distribution_desc'),
        'categories' => [],
        'series' => [
            [
                'name' => __('inlinks.series_inlinks') . ' (' . __('comparison.badge_reference') . ')',
                'data' => $cumulativeDataRef,
                'color' => '#4ECDC4'
            ],
            [
                'name' => __('inlinks.series_inlinks') . ' (' . __('comparison.badge_baseline') . ')',
                'data' => $cumulativeDataBase,
                'color' => hexToRgba('#4ECDC4', 0.5)
            ]
        ],
        'xAxisTitle' => __('inlinks.label_pct_urls'),
        'yAxisTitle' => __('inlinks.label_inlinks_log'),
        'logarithmic' => true,
        'xAxisMin' => 0,
        'xAxisMax' => 100,
        'height' => 400,
        'tooltipFormat' => __('inlinks.tooltip'),
        'sqlQuery' => $sqlDistDisplay
    ]);
    ?>
    </div>

    <!-- Chart: Average inlinks by category comparison -->
    <div>
    <?php
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('inlinks.table_category'),
        'subtitle' => __('inlinks.table_category_desc'),
        'categories' => array_values($allCatNames),
        'series' => [
            [
                'name' => __('comparison.badge_reference'),
                'data' => $refCatValues,
                'color' => 'var(--primary-color)'
            ],
            [
                'name' => __('comparison.badge_baseline'),
                'data' => $baseCatValues,
                'color' => hexToRgba('#4ECDC4', 0.5)
            ]
        ],
        'yAxisTitle' => __('common.average'),
        'height' => 400,
        'sqlQuery' => $sqlCatDisplay
    ]);
    ?>
    </div>

    <?php
    Component::urlTable([
        'title' => __('comparison.inlinks_lost_table'),
        'id' => 'inlinks_lost_table',
        'whereClause' => "WHERE c.crawled = true AND c.compliant = true AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.crawled = true AND b.compliant = true AND b.inlinks > c.inlinks
        )",
        'orderBy' => 'ORDER BY c.inlinks ASC',
        'defaultColumns' => ['url', 'category', 'inlinks', 'depth', 'pri'],
        'compareCrawlId' => $safeCompareId,
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
