<?php
/**
 * Crawl Comparison - Depth Analysis
 *
 * Shows depth distribution comparison (bar charts) and lists URLs
 * whose depth level changed between the two crawls.
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

// Scorecards pré-calculés dans dashboard.php (NOT EXISTS, O(n) au lieu de NOT IN O(n²))
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

// =========================================
// Chart 1: Depth distribution (indexable vs non-indexable) × ref/base
// =========================================
$sqlDepthStats = "
    SELECT depth, COUNT(*) as total,
           SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) as compliant
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true
    GROUP BY depth ORDER BY depth
";

$stmtRef = $pdo->prepare($sqlDepthStats);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$depthStatsRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlDepthStats);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$depthStatsBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

// Merge all depth levels
$allDepths = [];
foreach ($depthStatsRef as $r) $allDepths[$r->depth] = true;
foreach ($depthStatsBase as $r) $allDepths[$r->depth] = true;
ksort($allDepths);
$depthLabels = array_map(function($d) { return __('depth.col_depth') . ' ' . $d; }, array_keys($allDepths));
$depthKeys = array_keys($allDepths);

// Build maps
$refMap = [];
foreach ($depthStatsRef as $r) $refMap[$r->depth] = $r;
$baseMap = [];
foreach ($depthStatsBase as $r) $baseMap[$r->depth] = $r;

// Series: Indexable Ref, Non-indexable Ref, Indexable Base, Non-indexable Base
$refIndexable = [];
$refNonIndexable = [];
$baseIndexable = [];
$baseNonIndexable = [];
foreach ($depthKeys as $d) {
    $rTotal = isset($refMap[$d]) ? (int)$refMap[$d]->total : 0;
    $rCompliant = isset($refMap[$d]) ? (int)$refMap[$d]->compliant : 0;
    $bTotal = isset($baseMap[$d]) ? (int)$baseMap[$d]->total : 0;
    $bCompliant = isset($baseMap[$d]) ? (int)$baseMap[$d]->compliant : 0;
    $refIndexable[] = $rCompliant;
    $refNonIndexable[] = $rTotal - $rCompliant;
    $baseIndexable[] = $bCompliant;
    $baseNonIndexable[] = $bTotal - $bCompliant;
}

// SQL display
$sqlDepthDisplay = "SELECT
    COALESCE(r.depth, b.depth) AS depth,
    COALESCE(r.total, 0) AS ref_total,
    COALESCE(r.compliant, 0) AS ref_indexable,
    COALESCE(b.total, 0) AS base_total,
    COALESCE(b.compliant, 0) AS base_indexable
FROM (
    SELECT depth, COUNT(*) AS total,
           SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) AS compliant
    FROM pages@{$safeCrawlId}
    WHERE crawled = true AND is_html = true GROUP BY depth
) r
FULL OUTER JOIN (
    SELECT depth, COUNT(*) AS total,
           SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) AS compliant
    FROM pages@{$safeCompareId}
    WHERE crawled = true AND is_html = true GROUP BY depth
) b ON r.depth = b.depth
ORDER BY COALESCE(r.depth, b.depth)";

// =========================================
// Chart 2: Depth × Category (stacked percent) ref vs base
// =========================================
$sqlDepthCat = "
    SELECT depth, cat_id, COUNT(*) as count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true AND is_html = true
    GROUP BY depth, cat_id ORDER BY depth, cat_id
";

$stmtCatRef = $pdo->prepare($sqlDepthCat);
$stmtCatRef->execute([':crawl_id' => $safeCrawlId]);
$depthCatRef = $stmtCatRef->fetchAll(PDO::FETCH_OBJ);

$stmtCatBase = $pdo->prepare($sqlDepthCat);
$stmtCatBase->execute([':crawl_id' => $safeCompareId]);
$depthCatBase = $stmtCatBase->fetchAll(PDO::FETCH_OBJ);

$categoriesMap = $GLOBALS['categoriesMap'] ?? [];

// Organize by category name
$refCatData = [];
foreach ($depthCatRef as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    if (!isset($refCatData[$catName])) $refCatData[$catName] = [];
    $refCatData[$catName][$r->depth] = (int)$r->count;
}
$baseCatData = [];
foreach ($depthCatBase as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    if (!isset($baseCatData[$catName])) $baseCatData[$catName] = [];
    $baseCatData[$catName][$r->depth] = (int)$r->count;
}

$allCatNames = array_unique(array_merge(array_keys($refCatData), array_keys($baseCatData)));

// Build paired series
$depthCatSeries = [];
foreach ($allCatNames as $catName) {
    $color = getCategoryColor($catName);
    $refValues = [];
    $baseValues = [];
    foreach ($depthKeys as $d) {
        $refValues[] = $refCatData[$catName][$d] ?? 0;
        $baseValues[] = $baseCatData[$catName][$d] ?? 0;
    }
    if (array_sum($refValues) == 0 && array_sum($baseValues) == 0) continue;
    $depthCatSeries[] = [
        'name' => $catName . ' (' . __('comparison.badge_reference') . ')',
        'data' => $refValues,
        'color' => $color,
        'stack' => 'reference'
    ];
    $depthCatSeries[] = [
        'name' => $catName . ' (' . __('comparison.badge_baseline') . ')',
        'data' => $baseValues,
        'color' => hexToRgba($color, 0.5),
        'stack' => 'baseline'
    ];
}

// SQL display for category chart
$sqlDepthCatDisplay = "SELECT
    COALESCE(r.depth, b.depth) AS depth,
    COALESCE(r.cat_id, b.cat_id) AS cat_id,
    COALESCE(r.count, 0) AS ref_count,
    COALESCE(b.count, 0) AS base_count
FROM (
    SELECT depth, cat_id, COUNT(*) AS count FROM pages@{$safeCrawlId}
    WHERE crawled = true AND compliant = true AND is_html = true
    GROUP BY depth, cat_id
) r
FULL OUTER JOIN (
    SELECT depth, cat_id, COUNT(*) AS count FROM pages@{$safeCompareId}
    WHERE crawled = true AND compliant = true AND is_html = true
    GROUP BY depth, cat_id
) b ON r.depth = b.depth AND r.cat_id = b.cat_id
ORDER BY COALESCE(r.depth, b.depth), cat_id";

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

    <!-- Charts: Depth distribution comparison -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'bar',
        'title' => __('depth.chart_title'),
        'subtitle' => __('comparison.subtitle_depth'),
        'categories' => array_values($depthLabels),
        'series' => [
            [
                'name' => __('depth.series_indexable') . ' (' . __('comparison.badge_reference') . ')',
                'data' => $refIndexable,
                'color' => '#6bd899',
                'stack' => 'reference'
            ],
            [
                'name' => __('depth.series_non_indexable') . ' (' . __('comparison.badge_reference') . ')',
                'data' => $refNonIndexable,
                'color' => '#95a5a6',
                'stack' => 'reference'
            ],
            [
                'name' => __('depth.series_indexable') . ' (' . __('comparison.badge_baseline') . ')',
                'data' => $baseIndexable,
                'color' => hexToRgba('#6bd899', 0.5),
                'stack' => 'baseline'
            ],
            [
                'name' => __('depth.series_non_indexable') . ' (' . __('comparison.badge_baseline') . ')',
                'data' => $baseNonIndexable,
                'color' => hexToRgba('#95a5a6', 0.5),
                'stack' => 'baseline'
            ]
        ],
        'stacking' => 'normal',
        'yAxisTitle' => __('depth.label_url_count'),
        'height' => 400,
        'sqlQuery' => $sqlDepthDisplay
    ]);

    Component::chart([
        'type' => 'bar',
        'title' => __('depth.chart_category_title'),
        'subtitle' => __('comparison.subtitle_depth_category'),
        'categories' => array_values($depthLabels),
        'series' => $depthCatSeries,
        'yAxisTitle' => __('common.percentage'),
        'yAxisMax' => 100,
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlDepthCatDisplay
    ]);
    ?>
    </div>

    <?php
    Component::urlTable([
        'title' => __('comparison.depth_changes_table_title'),
        'id' => 'depth_changes_table',
        'whereClause' => "WHERE c.crawled = true AND c.is_html = true AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.crawled = true AND b.is_html = true AND b.depth != c.depth
        )",
        'orderBy' => 'ORDER BY c.depth ASC, c.inlinks DESC',
        'defaultColumns' => ['url', 'category', 'depth', 'compliant', 'code'],
        'compareCrawlId' => $safeCompareId,
        'compareColumns' => ['depth', 'compliant', 'code'],
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
