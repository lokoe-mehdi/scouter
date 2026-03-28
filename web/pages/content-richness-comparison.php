<?php
/**
 * Crawl Comparison - Content Richness
 *
 * Compares word count distribution, quality breakdown, and
 * per-category quality between reference and baseline crawls.
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
// Chart 1: Word count distribution by ranges (bar) ref vs base
// =========================================
$sqlDistribution = "
    SELECT
        CASE
            WHEN word_count = 0 THEN '0'
            WHEN word_count BETWEEN 1 AND 100 THEN '1-100'
            WHEN word_count BETWEEN 101 AND 300 THEN '101-300'
            WHEN word_count BETWEEN 301 AND 500 THEN '301-500'
            WHEN word_count BETWEEN 501 AND 800 THEN '501-800'
            WHEN word_count BETWEEN 801 AND 1200 THEN '801-1200'
            WHEN word_count BETWEEN 1201 AND 2000 THEN '1201-2000'
            WHEN word_count BETWEEN 2001 AND 3000 THEN '2001-3000'
            ELSE '3000+'
        END as word_range,
        COUNT(*) as page_count,
        CASE
            WHEN word_count = 0 THEN 0
            WHEN word_count BETWEEN 1 AND 100 THEN 1
            WHEN word_count BETWEEN 101 AND 300 THEN 2
            WHEN word_count BETWEEN 301 AND 500 THEN 3
            WHEN word_count BETWEEN 501 AND 800 THEN 4
            WHEN word_count BETWEEN 801 AND 1200 THEN 5
            WHEN word_count BETWEEN 1201 AND 2000 THEN 6
            WHEN word_count BETWEEN 2001 AND 3000 THEN 7
            ELSE 8
        END as sort_order
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
    GROUP BY word_range, sort_order
    ORDER BY sort_order
";

$stmtRef = $pdo->prepare($sqlDistribution);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$distRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlDistribution);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$distBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

// Merge all ranges
$rangeLabels = ['0', '1-100', '101-300', '301-500', '501-800', '801-1200', '1201-2000', '2001-3000', '3000+'];
$refDistMap = [];
foreach ($distRef as $r) $refDistMap[$r->word_range] = (int)$r->page_count;
$baseDistMap = [];
foreach ($distBase as $r) $baseDistMap[$r->word_range] = (int)$r->page_count;

$refDistValues = [];
$baseDistValues = [];
foreach ($rangeLabels as $range) {
    $refDistValues[] = $refDistMap[$range] ?? 0;
    $baseDistValues[] = $baseDistMap[$range] ?? 0;
}

// Colors per range for ref
$rangeColors = ['#dc3545', '#dc3545', '#dc3545', '#fd7e14', '#20c997', '#20c997', '#28a745', '#28a745', '#28a745'];

// SQL display
$sqlDistDisplay = "SELECT
    COALESCE(r.word_range, b.word_range) AS word_range,
    COALESCE(r.page_count, 0) AS ref_count,
    COALESCE(b.page_count, 0) AS base_count
FROM (
    SELECT CASE
        WHEN word_count = 0 THEN '0'
        WHEN word_count BETWEEN 1 AND 100 THEN '1-100'
        WHEN word_count BETWEEN 101 AND 300 THEN '101-300'
        WHEN word_count BETWEEN 301 AND 500 THEN '301-500'
        WHEN word_count BETWEEN 501 AND 800 THEN '501-800'
        WHEN word_count BETWEEN 801 AND 1200 THEN '801-1200'
        WHEN word_count BETWEEN 1201 AND 2000 THEN '1201-2000'
        WHEN word_count BETWEEN 2001 AND 3000 THEN '2001-3000'
        ELSE '3000+'
    END AS word_range, COUNT(*) AS page_count
    FROM pages@{$safeCrawlId} WHERE crawled = true AND compliant = true
    GROUP BY word_range
) r
FULL OUTER JOIN (
    SELECT CASE
        WHEN word_count = 0 THEN '0'
        WHEN word_count BETWEEN 1 AND 100 THEN '1-100'
        WHEN word_count BETWEEN 101 AND 300 THEN '101-300'
        WHEN word_count BETWEEN 301 AND 500 THEN '301-500'
        WHEN word_count BETWEEN 501 AND 800 THEN '501-800'
        WHEN word_count BETWEEN 801 AND 1200 THEN '801-1200'
        WHEN word_count BETWEEN 1201 AND 2000 THEN '1201-2000'
        WHEN word_count BETWEEN 2001 AND 3000 THEN '2001-3000'
        ELSE '3000+'
    END AS word_range, COUNT(*) AS page_count
    FROM pages@{$safeCompareId} WHERE crawled = true AND compliant = true
    GROUP BY word_range
) b ON r.word_range = b.word_range
ORDER BY r.word_range";

// =========================================
// Chart 2: Quality distribution donut (Poor/Medium/Rich/Premium) ref vs base
// =========================================
$colorPoor = '#dc3545';
$colorMedium = '#fd7e14';
$colorRich = '#20c997';
$colorPremium = '#28a745';

$sqlQuality = "
    SELECT
        COUNT(CASE WHEN word_count <= 250 THEN 1 END) as poor,
        COUNT(CASE WHEN word_count > 250 AND word_count <= 500 THEN 1 END) as medium,
        COUNT(CASE WHEN word_count > 500 AND word_count <= 1200 THEN 1 END) as rich,
        COUNT(CASE WHEN word_count > 1200 THEN 1 END) as premium
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
";

$stmtRef = $pdo->prepare($sqlQuality);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$qualRef = $stmtRef->fetch(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlQuality);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$qualBase = $stmtBase->fetch(PDO::FETCH_OBJ);

$qualRefData = [
    ['name' => __('content_richness.series_poor') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($qualRef->poor ?? 0), 'color' => $colorPoor],
    ['name' => __('content_richness.series_medium') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($qualRef->medium ?? 0), 'color' => $colorMedium],
    ['name' => __('content_richness.series_rich') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($qualRef->rich ?? 0), 'color' => $colorRich],
    ['name' => __('content_richness.series_premium') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($qualRef->premium ?? 0), 'color' => $colorPremium],
];
$qualBaseData = [
    ['name' => __('content_richness.series_poor') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($qualBase->poor ?? 0), 'color' => hexToRgba($colorPoor, 0.5)],
    ['name' => __('content_richness.series_medium') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($qualBase->medium ?? 0), 'color' => hexToRgba($colorMedium, 0.5)],
    ['name' => __('content_richness.series_rich') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($qualBase->rich ?? 0), 'color' => hexToRgba($colorRich, 0.5)],
    ['name' => __('content_richness.series_premium') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($qualBase->premium ?? 0), 'color' => hexToRgba($colorPremium, 0.5)],
];

$sqlQualityDisplay = "SELECT
    COALESCE(r.poor, 0) AS ref_poor, COALESCE(r.medium, 0) AS ref_medium,
    COALESCE(r.rich, 0) AS ref_rich, COALESCE(r.premium, 0) AS ref_premium,
    COALESCE(b.poor, 0) AS base_poor, COALESCE(b.medium, 0) AS base_medium,
    COALESCE(b.rich, 0) AS base_rich, COALESCE(b.premium, 0) AS base_premium
FROM (
    SELECT COUNT(CASE WHEN word_count <= 250 THEN 1 END) AS poor,
           COUNT(CASE WHEN word_count > 250 AND word_count <= 500 THEN 1 END) AS medium,
           COUNT(CASE WHEN word_count > 500 AND word_count <= 1200 THEN 1 END) AS rich,
           COUNT(CASE WHEN word_count > 1200 THEN 1 END) AS premium
    FROM pages@{$safeCrawlId} WHERE crawled = true AND compliant = true
) r,
(
    SELECT COUNT(CASE WHEN word_count <= 250 THEN 1 END) AS poor,
           COUNT(CASE WHEN word_count > 250 AND word_count <= 500 THEN 1 END) AS medium,
           COUNT(CASE WHEN word_count > 500 AND word_count <= 1200 THEN 1 END) AS rich,
           COUNT(CASE WHEN word_count > 1200 THEN 1 END) AS premium
    FROM pages@{$safeCompareId} WHERE crawled = true AND compliant = true
) b";

// =========================================
// Chart 3: Quality breakdown per category (horizontal bar, stacked percent) ref vs base
// =========================================
$sqlQualityByCategory = "
    SELECT
        cat_id,
        COUNT(CASE WHEN word_count <= 250 THEN 1 END) as poor,
        COUNT(CASE WHEN word_count > 250 AND word_count <= 500 THEN 1 END) as medium,
        COUNT(CASE WHEN word_count > 500 AND word_count <= 1200 THEN 1 END) as rich,
        COUNT(CASE WHEN word_count > 1200 THEN 1 END) as premium
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
    GROUP BY cat_id
";

$stmtRef = $pdo->prepare($sqlQualityByCategory);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$qualCatRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlQualityByCategory);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$qualCatBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

$categoriesMap = $GLOBALS['categoriesMap'] ?? [];

// Build maps by category name
$refQualCatData = [];
foreach ($qualCatRef as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $refQualCatData[$catName] = $r;
}
$baseQualCatData = [];
foreach ($qualCatBase as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $baseQualCatData[$catName] = $r;
}

$allQualCatNames = array_unique(array_merge(array_keys($refQualCatData), array_keys($baseQualCatData)));

// Build series: each quality level x ref/base
$qualityLevels = [
    'poor' => [__('content_richness.series_poor'), $colorPoor],
    'medium' => [__('content_richness.series_medium'), $colorMedium],
    'rich' => [__('content_richness.series_rich'), $colorRich],
    'premium' => [__('content_richness.series_premium'), $colorPremium],
];

$qualCatSeries = [];
foreach ($qualityLevels as $key => [$label, $color]) {
    $refValues = [];
    $baseValues = [];
    foreach ($allQualCatNames as $catName) {
        $refValues[] = isset($refQualCatData[$catName]) ? (int)$refQualCatData[$catName]->$key : 0;
        $baseValues[] = isset($baseQualCatData[$catName]) ? (int)$baseQualCatData[$catName]->$key : 0;
    }
    if (array_sum($refValues) == 0 && array_sum($baseValues) == 0) continue;
    $qualCatSeries[] = [
        'name' => $label . ' (' . __('comparison.badge_reference') . ')',
        'data' => $refValues,
        'color' => $color,
        'stack' => 'reference'
    ];
    $qualCatSeries[] = [
        'name' => $label . ' (' . __('comparison.badge_baseline') . ')',
        'data' => $baseValues,
        'color' => hexToRgba($color, 0.5),
        'stack' => 'baseline'
    ];
}

// SQL display for category chart
$sqlQualCatDisplay = "SELECT
    COALESCE(r.cat_id, b.cat_id) AS cat_id,
    COALESCE(r.poor, 0) AS ref_poor, COALESCE(r.medium, 0) AS ref_medium,
    COALESCE(r.rich, 0) AS ref_rich, COALESCE(r.premium, 0) AS ref_premium,
    COALESCE(b.poor, 0) AS base_poor, COALESCE(b.medium, 0) AS base_medium,
    COALESCE(b.rich, 0) AS base_rich, COALESCE(b.premium, 0) AS base_premium
FROM (
    SELECT cat_id,
        COUNT(CASE WHEN word_count <= 250 THEN 1 END) AS poor,
        COUNT(CASE WHEN word_count > 250 AND word_count <= 500 THEN 1 END) AS medium,
        COUNT(CASE WHEN word_count > 500 AND word_count <= 1200 THEN 1 END) AS rich,
        COUNT(CASE WHEN word_count > 1200 THEN 1 END) AS premium
    FROM pages@{$safeCrawlId} WHERE crawled = true AND compliant = true GROUP BY cat_id
) r
FULL OUTER JOIN (
    SELECT cat_id,
        COUNT(CASE WHEN word_count <= 250 THEN 1 END) AS poor,
        COUNT(CASE WHEN word_count > 250 AND word_count <= 500 THEN 1 END) AS medium,
        COUNT(CASE WHEN word_count > 500 AND word_count <= 1200 THEN 1 END) AS rich,
        COUNT(CASE WHEN word_count > 1200 THEN 1 END) AS premium
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

    <!-- Chart: Word count distribution comparison -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'bar',
        'title' => __('content_richness.chart_distribution'),
        'subtitle' => __('comparison.subtitle_content_distribution'),
        'categories' => $rangeLabels,
        'series' => [
            [
                'name' => __('comparison.badge_reference'),
                'data' => $refDistValues,
                'color' => '#4ECDC4',
                'stack' => 'reference'
            ],
            [
                'name' => __('comparison.badge_baseline'),
                'data' => $baseDistValues,
                'color' => hexToRgba('#4ECDC4', 0.5),
                'stack' => 'baseline'
            ]
        ],
        'stacking' => 'normal',
        'yAxisTitle' => __('depth.label_url_count'),
        'height' => 400,
        'sqlQuery' => $sqlDistDisplay
    ]);

    Component::chart([
        'type' => 'donut',
        'title' => __('content_richness.chart_quality'),
        'subtitle' => __('comparison.subtitle_quality'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $qualRefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $qualBaseData]
        ],
        'height' => 350,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlQualityDisplay
    ]);
    ?>
    </div>

    <!-- Chart: Quality breakdown by category -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('content_richness.chart_category_quality'),
        'subtitle' => __('comparison.subtitle_quality_category'),
        'categories' => array_values($allQualCatNames),
        'series' => $qualCatSeries,
        'yAxisTitle' => __('common.percentage'),
        'yAxisMax' => 100,
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlQualCatDisplay
    ]);
    ?>
    </div>

    <?php
    Component::urlTable([
        'title' => __('comparison.poor_content_regressions_table'),
        'id' => 'poor_content_regressions_table',
        'whereClause' => "WHERE c.crawled = true AND c.compliant = true AND c.word_count <= 250 AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.crawled = true AND b.compliant = true AND b.word_count > 250
        )",
        'orderBy' => 'ORDER BY c.word_count ASC, c.inlinks DESC',
        'defaultColumns' => ['url', 'category', 'word_count', 'depth', 'inlinks'],
        'compareCrawlId' => $safeCompareId,
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
