<?php
/**
 * Crawl Comparison - Headings
 *
 * Compares heading structure (H1 duplicates, Hn hierarchy)
 * between reference and baseline crawls.
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

// =========================================
// Chart 1: H1 Multiple vs Unique (donut) ref vs base
// =========================================
$sqlHeadingsStats = "
    SELECT
        SUM(CASE WHEN h1_multiple = false THEN 1 ELSE 0 END) as h1_unique_count,
        SUM(CASE WHEN h1_multiple = true THEN 1 ELSE 0 END) as h1_multiple_count,
        SUM(CASE WHEN headings_missing = false THEN 1 ELSE 0 END) as hn_ok_count,
        SUM(CASE WHEN headings_missing = true THEN 1 ELSE 0 END) as hn_missing_count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
";

$stmtRef = $pdo->prepare($sqlHeadingsStats);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$statsRef = $stmtRef->fetch(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlHeadingsStats);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$statsBase = $stmtBase->fetch(PDO::FETCH_OBJ);

$h1RefData = [
    ['name' => __('headings.series_h1_unique') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($statsRef->h1_unique_count ?? 0), 'color' => '#6bd899'],
    ['name' => __('headings.series_h1_multiple') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($statsRef->h1_multiple_count ?? 0), 'color' => '#d86b6b'],
];
$h1BaseData = [
    ['name' => __('headings.series_h1_unique') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($statsBase->h1_unique_count ?? 0), 'color' => hexToRgba('#6bd899', 0.5)],
    ['name' => __('headings.series_h1_multiple') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($statsBase->h1_multiple_count ?? 0), 'color' => hexToRgba('#d86b6b', 0.5)],
];

// =========================================
// Chart 2: Hn Structure OK vs Bad (donut) ref vs base
// =========================================
$hnRefData = [
    ['name' => __('headings.series_structure_ok') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($statsRef->hn_ok_count ?? 0), 'color' => '#6bd899'],
    ['name' => __('headings.series_bad_structure') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($statsRef->hn_missing_count ?? 0), 'color' => '#d8bf6b'],
];
$hnBaseData = [
    ['name' => __('headings.series_structure_ok') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($statsBase->hn_ok_count ?? 0), 'color' => hexToRgba('#6bd899', 0.5)],
    ['name' => __('headings.series_bad_structure') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($statsBase->hn_missing_count ?? 0), 'color' => hexToRgba('#d8bf6b', 0.5)],
];

// =========================================
// Charts 3 & 4: H1 and Hn by category (horizontal bar, stacked percent) ref vs base
// =========================================
$sqlHeadingsByCategory = "
    SELECT
        cat_id,
        SUM(CASE WHEN h1_multiple = false THEN 1 ELSE 0 END) as h1_unique_count,
        SUM(CASE WHEN h1_multiple = true THEN 1 ELSE 0 END) as h1_multiple_count,
        SUM(CASE WHEN headings_missing = false THEN 1 ELSE 0 END) as hn_ok_count,
        SUM(CASE WHEN headings_missing = true THEN 1 ELSE 0 END) as hn_missing_count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
    GROUP BY cat_id
    ORDER BY cat_id
";

$stmtRef = $pdo->prepare($sqlHeadingsByCategory);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$catRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlHeadingsByCategory);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$catBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

$categoriesMap = $GLOBALS['categoriesMap'] ?? [];

// Build maps by category name
$refCatData = [];
foreach ($catRef as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $refCatData[$catName] = $r;
}
$baseCatData = [];
foreach ($catBase as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $baseCatData[$catName] = $r;
}

$allCatNames = array_unique(array_merge(array_keys($refCatData), array_keys($baseCatData)));

// H1 by category series
$h1Reasons = [
    'h1_unique_count' => [__('headings.series_h1_unique'), '#6bd899'],
    'h1_multiple_count' => [__('headings.series_h1_multiple'), '#d86b6b'],
];

$h1CatSeries = [];
foreach ($h1Reasons as $key => [$label, $color]) {
    $refValues = [];
    $baseValues = [];
    foreach ($allCatNames as $catName) {
        $refValues[] = isset($refCatData[$catName]) ? (int)$refCatData[$catName]->$key : 0;
        $baseValues[] = isset($baseCatData[$catName]) ? (int)$baseCatData[$catName]->$key : 0;
    }
    if (array_sum($refValues) == 0 && array_sum($baseValues) == 0) continue;
    $h1CatSeries[] = [
        'name' => $label . ' (' . __('comparison.badge_reference') . ')',
        'data' => $refValues,
        'color' => $color,
        'stack' => 'reference'
    ];
    $h1CatSeries[] = [
        'name' => $label . ' (' . __('comparison.badge_baseline') . ')',
        'data' => $baseValues,
        'color' => hexToRgba($color, 0.5),
        'stack' => 'baseline'
    ];
}

// Hn by category series
$hnReasons = [
    'hn_ok_count' => [__('headings.series_structure_ok'), '#6bd899'],
    'hn_missing_count' => [__('headings.series_bad_structure'), '#d8bf6b'],
];

$hnCatSeries = [];
foreach ($hnReasons as $key => [$label, $color]) {
    $refValues = [];
    $baseValues = [];
    foreach ($allCatNames as $catName) {
        $refValues[] = isset($refCatData[$catName]) ? (int)$refCatData[$catName]->$key : 0;
        $baseValues[] = isset($baseCatData[$catName]) ? (int)$baseCatData[$catName]->$key : 0;
    }
    if (array_sum($refValues) == 0 && array_sum($baseValues) == 0) continue;
    $hnCatSeries[] = [
        'name' => $label . ' (' . __('comparison.badge_reference') . ')',
        'data' => $refValues,
        'color' => $color,
        'stack' => 'reference'
    ];
    $hnCatSeries[] = [
        'name' => $label . ' (' . __('comparison.badge_baseline') . ')',
        'data' => $baseValues,
        'color' => hexToRgba($color, 0.5),
        'stack' => 'baseline'
    ];
}

// SQL display for category charts
$sqlCatDisplay = "SELECT
    COALESCE(r.cat_id, b.cat_id) AS cat_id,
    COALESCE(r.h1_unique_count, 0) AS ref_h1_unique,
    COALESCE(r.h1_multiple_count, 0) AS ref_h1_multiple,
    COALESCE(r.hn_ok_count, 0) AS ref_hn_ok,
    COALESCE(r.hn_missing_count, 0) AS ref_hn_missing,
    COALESCE(b.h1_unique_count, 0) AS base_h1_unique,
    COALESCE(b.h1_multiple_count, 0) AS base_h1_multiple,
    COALESCE(b.hn_ok_count, 0) AS base_hn_ok,
    COALESCE(b.hn_missing_count, 0) AS base_hn_missing
FROM (
    SELECT cat_id,
        SUM(CASE WHEN h1_multiple = false THEN 1 ELSE 0 END) AS h1_unique_count,
        SUM(CASE WHEN h1_multiple = true THEN 1 ELSE 0 END) AS h1_multiple_count,
        SUM(CASE WHEN headings_missing = false THEN 1 ELSE 0 END) AS hn_ok_count,
        SUM(CASE WHEN headings_missing = true THEN 1 ELSE 0 END) AS hn_missing_count
    FROM pages@{$safeCrawlId} WHERE crawled = true AND compliant = true GROUP BY cat_id
) r
FULL OUTER JOIN (
    SELECT cat_id,
        SUM(CASE WHEN h1_multiple = false THEN 1 ELSE 0 END) AS h1_unique_count,
        SUM(CASE WHEN h1_multiple = true THEN 1 ELSE 0 END) AS h1_multiple_count,
        SUM(CASE WHEN headings_missing = false THEN 1 ELSE 0 END) AS hn_ok_count,
        SUM(CASE WHEN headings_missing = true THEN 1 ELSE 0 END) AS hn_missing_count
    FROM pages@{$safeCompareId} WHERE crawled = true AND compliant = true GROUP BY cat_id
) b ON r.cat_id = b.cat_id
ORDER BY cat_id";

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

    <!-- Charts: H1 Duplicates + Hn Hierarchy donuts -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'donut',
        'title' => __('headings.chart_h1_duplicates'),
        'subtitle' => __('headings.chart_h1_subtitle'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $h1RefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $h1BaseData]
        ],
        'height' => 350,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlHeadingsStats
    ]);

    Component::chart([
        'type' => 'donut',
        'title' => __('headings.chart_hierarchy'),
        'subtitle' => __('headings.chart_hierarchy_subtitle'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $hnRefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $hnBaseData]
        ],
        'height' => 350,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlHeadingsStats
    ]);
    ?>
    </div>

    <!-- Charts: H1 and Hn by category -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('headings.chart_h1_duplicates'),
        'subtitle' => __('seo_tags.chart_category_subtitle'),
        'categories' => array_values($allCatNames),
        'series' => $h1CatSeries,
        'yAxisTitle' => __('common.percentage'),
        'yAxisMax' => 100,
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlCatDisplay
    ]);

    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('headings.chart_hierarchy'),
        'subtitle' => __('seo_tags.chart_category_subtitle'),
        'categories' => array_values($allCatNames),
        'series' => $hnCatSeries,
        'yAxisTitle' => __('common.percentage'),
        'yAxisMax' => 100,
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlCatDisplay
    ]);
    ?>
    </div>

    <?php
    Component::urlTable([
        'title' => __('comparison.headings_regressions_table'),
        'id' => 'headings_regressions_table',
        'whereClause' => "WHERE c.compliant = true AND (c.h1_multiple = true OR c.headings_missing = true) AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.crawled = true AND b.compliant = true
            AND b.h1_multiple = false AND b.headings_missing = false
        )",
        'orderBy' => 'ORDER BY c.h1_multiple DESC, c.headings_missing DESC, c.url',
        'defaultColumns' => ['url', 'category', 'h1_multiple', 'headings_missing'],
        'compareCrawlId' => $safeCompareId,
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
