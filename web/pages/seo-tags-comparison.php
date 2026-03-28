<?php
/**
 * Crawl Comparison - SEO Tags
 *
 * Compares SEO tag quality (Title, H1, Meta Description) between
 * reference and baseline crawls: donut charts, category breakdowns,
 * and regression tables.
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
// SEO Stats: global counts for both crawls
// =========================================
$sqlSeoStats = "
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN title_status = 'empty' THEN 1 ELSE 0 END) as title_empty,
        SUM(CASE WHEN title_status = 'duplicate' THEN 1 ELSE 0 END) as title_duplicate,
        SUM(CASE WHEN title_status = 'unique' THEN 1 ELSE 0 END) as title_unique,
        SUM(CASE WHEN h1_status = 'empty' THEN 1 ELSE 0 END) as h1_empty,
        SUM(CASE WHEN h1_status = 'duplicate' THEN 1 ELSE 0 END) as h1_duplicate,
        SUM(CASE WHEN h1_status = 'unique' THEN 1 ELSE 0 END) as h1_unique,
        SUM(CASE WHEN metadesc_status = 'empty' THEN 1 ELSE 0 END) as meta_desc_empty,
        SUM(CASE WHEN metadesc_status = 'duplicate' THEN 1 ELSE 0 END) as meta_desc_duplicate,
        SUM(CASE WHEN metadesc_status = 'unique' THEN 1 ELSE 0 END) as meta_desc_unique
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
";

$stmtRef = $pdo->prepare($sqlSeoStats);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$seoRef = $stmtRef->fetch(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlSeoStats);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$seoBase = $stmtBase->fetch(PDO::FETCH_OBJ);

// =========================================
// Chart 1: Three donut charts — Title, H1, Meta Description
// =========================================
$colorUnique = '#6bd899';
$colorDuplicate = '#d8bf6b';
$colorEmpty = '#d86b6b';

// Title donut
$titleRefData = [
    ['name' => __('seo_tags.series_unique') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($seoRef->title_unique ?? 0), 'color' => $colorUnique],
    ['name' => __('seo_tags.series_duplicate') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($seoRef->title_duplicate ?? 0), 'color' => $colorDuplicate],
    ['name' => __('seo_tags.series_empty') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($seoRef->title_empty ?? 0), 'color' => $colorEmpty],
];
$titleBaseData = [
    ['name' => __('seo_tags.series_unique') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($seoBase->title_unique ?? 0), 'color' => hexToRgba($colorUnique, 0.5)],
    ['name' => __('seo_tags.series_duplicate') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($seoBase->title_duplicate ?? 0), 'color' => hexToRgba($colorDuplicate, 0.5)],
    ['name' => __('seo_tags.series_empty') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($seoBase->title_empty ?? 0), 'color' => hexToRgba($colorEmpty, 0.5)],
];

// H1 donut
$h1RefData = [
    ['name' => __('seo_tags.series_unique') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($seoRef->h1_unique ?? 0), 'color' => $colorUnique],
    ['name' => __('seo_tags.series_duplicate') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($seoRef->h1_duplicate ?? 0), 'color' => $colorDuplicate],
    ['name' => __('seo_tags.series_empty') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($seoRef->h1_empty ?? 0), 'color' => $colorEmpty],
];
$h1BaseData = [
    ['name' => __('seo_tags.series_unique') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($seoBase->h1_unique ?? 0), 'color' => hexToRgba($colorUnique, 0.5)],
    ['name' => __('seo_tags.series_duplicate') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($seoBase->h1_duplicate ?? 0), 'color' => hexToRgba($colorDuplicate, 0.5)],
    ['name' => __('seo_tags.series_empty') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($seoBase->h1_empty ?? 0), 'color' => hexToRgba($colorEmpty, 0.5)],
];

// Meta Description donut
$metaRefData = [
    ['name' => __('seo_tags.series_unique') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($seoRef->meta_desc_unique ?? 0), 'color' => $colorUnique],
    ['name' => __('seo_tags.series_duplicate') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($seoRef->meta_desc_duplicate ?? 0), 'color' => $colorDuplicate],
    ['name' => __('seo_tags.series_empty') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($seoRef->meta_desc_empty ?? 0), 'color' => $colorEmpty],
];
$metaBaseData = [
    ['name' => __('seo_tags.series_unique') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($seoBase->meta_desc_unique ?? 0), 'color' => hexToRgba($colorUnique, 0.5)],
    ['name' => __('seo_tags.series_duplicate') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($seoBase->meta_desc_duplicate ?? 0), 'color' => hexToRgba($colorDuplicate, 0.5)],
    ['name' => __('seo_tags.series_empty') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($seoBase->meta_desc_empty ?? 0), 'color' => hexToRgba($colorEmpty, 0.5)],
];

// SQL display for donuts
$sqlSeoStatsDisplay = "SELECT
    SUM(CASE WHEN title_status = 'unique' THEN 1 ELSE 0 END) AS title_unique,
    SUM(CASE WHEN title_status = 'duplicate' THEN 1 ELSE 0 END) AS title_duplicate,
    SUM(CASE WHEN title_status = 'empty' THEN 1 ELSE 0 END) AS title_empty,
    SUM(CASE WHEN h1_status = 'unique' THEN 1 ELSE 0 END) AS h1_unique,
    SUM(CASE WHEN h1_status = 'duplicate' THEN 1 ELSE 0 END) AS h1_duplicate,
    SUM(CASE WHEN h1_status = 'empty' THEN 1 ELSE 0 END) AS h1_empty,
    SUM(CASE WHEN metadesc_status = 'unique' THEN 1 ELSE 0 END) AS meta_desc_unique,
    SUM(CASE WHEN metadesc_status = 'duplicate' THEN 1 ELSE 0 END) AS meta_desc_duplicate,
    SUM(CASE WHEN metadesc_status = 'empty' THEN 1 ELSE 0 END) AS meta_desc_empty
FROM pages@{$safeCrawlId}
WHERE crawled = true AND compliant = true

-- Same query on pages@{$safeCompareId}";

// =========================================
// Chart 2: Three horizontal bar charts by category (stacked percent)
// =========================================
$sqlSeoByCat = "
    SELECT
        cat_id,
        SUM(CASE WHEN title_status = 'unique' THEN 1 ELSE 0 END) as title_unique,
        SUM(CASE WHEN title_status = 'duplicate' THEN 1 ELSE 0 END) as title_duplicate,
        SUM(CASE WHEN title_status = 'empty' THEN 1 ELSE 0 END) as title_empty,
        SUM(CASE WHEN h1_status = 'unique' THEN 1 ELSE 0 END) as h1_unique,
        SUM(CASE WHEN h1_status = 'duplicate' THEN 1 ELSE 0 END) as h1_duplicate,
        SUM(CASE WHEN h1_status = 'empty' THEN 1 ELSE 0 END) as h1_empty,
        SUM(CASE WHEN metadesc_status = 'unique' THEN 1 ELSE 0 END) as meta_desc_unique,
        SUM(CASE WHEN metadesc_status = 'duplicate' THEN 1 ELSE 0 END) as meta_desc_duplicate,
        SUM(CASE WHEN metadesc_status = 'empty' THEN 1 ELSE 0 END) as meta_desc_empty
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
    GROUP BY cat_id
    ORDER BY cat_id
";

$stmtCatRef = $pdo->prepare($sqlSeoByCat);
$stmtCatRef->execute([':crawl_id' => $safeCrawlId]);
$seoCatRef = $stmtCatRef->fetchAll(PDO::FETCH_OBJ);

$stmtCatBase = $pdo->prepare($sqlSeoByCat);
$stmtCatBase->execute([':crawl_id' => $safeCompareId]);
$seoCatBase = $stmtCatBase->fetchAll(PDO::FETCH_OBJ);

$categoriesMap = $GLOBALS['categoriesMap'] ?? [];

// Build maps by category name
$refCatData = [];
foreach ($seoCatRef as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $refCatData[$catName] = $r;
}
$baseCatData = [];
foreach ($seoCatBase as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $baseCatData[$catName] = $r;
}

$allCatNames = array_unique(array_merge(array_keys($refCatData), array_keys($baseCatData)));

// Status definitions for series building
$statuses = [
    'unique' => [__('seo_tags.series_unique'), $colorUnique],
    'duplicate' => [__('seo_tags.series_duplicate'), $colorDuplicate],
    'empty' => [__('seo_tags.series_empty'), $colorEmpty],
];

// Title by category series
$titleCatSeries = [];
foreach ($statuses as $key => [$label, $color]) {
    $field = 'title_' . $key;
    $refValues = [];
    $baseValues = [];
    foreach ($allCatNames as $catName) {
        $refValues[] = isset($refCatData[$catName]) ? (int)$refCatData[$catName]->$field : 0;
        $baseValues[] = isset($baseCatData[$catName]) ? (int)$baseCatData[$catName]->$field : 0;
    }
    if (array_sum($refValues) == 0 && array_sum($baseValues) == 0) continue;
    $titleCatSeries[] = [
        'name' => $label . ' (' . __('comparison.badge_reference') . ')',
        'data' => $refValues,
        'color' => $color,
        'stack' => 'reference'
    ];
    $titleCatSeries[] = [
        'name' => $label . ' (' . __('comparison.badge_baseline') . ')',
        'data' => $baseValues,
        'color' => hexToRgba($color, 0.5),
        'stack' => 'baseline'
    ];
}

// H1 by category series
$h1CatSeries = [];
foreach ($statuses as $key => [$label, $color]) {
    $field = 'h1_' . $key;
    $refValues = [];
    $baseValues = [];
    foreach ($allCatNames as $catName) {
        $refValues[] = isset($refCatData[$catName]) ? (int)$refCatData[$catName]->$field : 0;
        $baseValues[] = isset($baseCatData[$catName]) ? (int)$baseCatData[$catName]->$field : 0;
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

// Meta Description by category series
$metaCatSeries = [];
foreach ($statuses as $key => [$label, $color]) {
    $field = 'meta_desc_' . $key;
    $refValues = [];
    $baseValues = [];
    foreach ($allCatNames as $catName) {
        $refValues[] = isset($refCatData[$catName]) ? (int)$refCatData[$catName]->$field : 0;
        $baseValues[] = isset($baseCatData[$catName]) ? (int)$baseCatData[$catName]->$field : 0;
    }
    if (array_sum($refValues) == 0 && array_sum($baseValues) == 0) continue;
    $metaCatSeries[] = [
        'name' => $label . ' (' . __('comparison.badge_reference') . ')',
        'data' => $refValues,
        'color' => $color,
        'stack' => 'reference'
    ];
    $metaCatSeries[] = [
        'name' => $label . ' (' . __('comparison.badge_baseline') . ')',
        'data' => $baseValues,
        'color' => hexToRgba($color, 0.5),
        'stack' => 'baseline'
    ];
}

// SQL display for category charts
$sqlSeoByCatDisplay = "SELECT
    COALESCE(r.cat_id, b.cat_id) AS cat_id,
    COALESCE(r.title_unique, 0) AS ref_title_unique,
    COALESCE(r.title_duplicate, 0) AS ref_title_duplicate,
    COALESCE(r.title_empty, 0) AS ref_title_empty,
    COALESCE(b.title_unique, 0) AS base_title_unique,
    COALESCE(b.title_duplicate, 0) AS base_title_duplicate,
    COALESCE(b.title_empty, 0) AS base_title_empty
FROM (
    SELECT cat_id,
        SUM(CASE WHEN title_status = 'unique' THEN 1 ELSE 0 END) AS title_unique,
        SUM(CASE WHEN title_status = 'duplicate' THEN 1 ELSE 0 END) AS title_duplicate,
        SUM(CASE WHEN title_status = 'empty' THEN 1 ELSE 0 END) AS title_empty
    FROM pages@{$safeCrawlId} WHERE crawled = true AND compliant = true GROUP BY cat_id
) r
FULL OUTER JOIN (
    SELECT cat_id,
        SUM(CASE WHEN title_status = 'unique' THEN 1 ELSE 0 END) AS title_unique,
        SUM(CASE WHEN title_status = 'duplicate' THEN 1 ELSE 0 END) AS title_duplicate,
        SUM(CASE WHEN title_status = 'empty' THEN 1 ELSE 0 END) AS title_empty
    FROM pages@{$safeCompareId} WHERE crawled = true AND compliant = true GROUP BY cat_id
) b ON r.cat_id = b.cat_id
ORDER BY cat_id";

$sqlH1ByCatDisplay = "SELECT
    COALESCE(r.cat_id, b.cat_id) AS cat_id,
    COALESCE(r.h1_unique, 0) AS ref_h1_unique,
    COALESCE(r.h1_duplicate, 0) AS ref_h1_duplicate,
    COALESCE(r.h1_empty, 0) AS ref_h1_empty,
    COALESCE(b.h1_unique, 0) AS base_h1_unique,
    COALESCE(b.h1_duplicate, 0) AS base_h1_duplicate,
    COALESCE(b.h1_empty, 0) AS base_h1_empty
FROM (
    SELECT cat_id,
        SUM(CASE WHEN h1_status = 'unique' THEN 1 ELSE 0 END) AS h1_unique,
        SUM(CASE WHEN h1_status = 'duplicate' THEN 1 ELSE 0 END) AS h1_duplicate,
        SUM(CASE WHEN h1_status = 'empty' THEN 1 ELSE 0 END) AS h1_empty
    FROM pages@{$safeCrawlId} WHERE crawled = true AND compliant = true GROUP BY cat_id
) r
FULL OUTER JOIN (
    SELECT cat_id,
        SUM(CASE WHEN h1_status = 'unique' THEN 1 ELSE 0 END) AS h1_unique,
        SUM(CASE WHEN h1_status = 'duplicate' THEN 1 ELSE 0 END) AS h1_duplicate,
        SUM(CASE WHEN h1_status = 'empty' THEN 1 ELSE 0 END) AS h1_empty
    FROM pages@{$safeCompareId} WHERE crawled = true AND compliant = true GROUP BY cat_id
) b ON r.cat_id = b.cat_id
ORDER BY cat_id";

$sqlMetaByCatDisplay = "SELECT
    COALESCE(r.cat_id, b.cat_id) AS cat_id,
    COALESCE(r.meta_unique, 0) AS ref_meta_unique,
    COALESCE(r.meta_duplicate, 0) AS ref_meta_duplicate,
    COALESCE(r.meta_empty, 0) AS ref_meta_empty,
    COALESCE(b.meta_unique, 0) AS base_meta_unique,
    COALESCE(b.meta_duplicate, 0) AS base_meta_duplicate,
    COALESCE(b.meta_empty, 0) AS base_meta_empty
FROM (
    SELECT cat_id,
        SUM(CASE WHEN metadesc_status = 'unique' THEN 1 ELSE 0 END) AS meta_unique,
        SUM(CASE WHEN metadesc_status = 'duplicate' THEN 1 ELSE 0 END) AS meta_duplicate,
        SUM(CASE WHEN metadesc_status = 'empty' THEN 1 ELSE 0 END) AS meta_empty
    FROM pages@{$safeCrawlId} WHERE crawled = true AND compliant = true GROUP BY cat_id
) r
FULL OUTER JOIN (
    SELECT cat_id,
        SUM(CASE WHEN metadesc_status = 'unique' THEN 1 ELSE 0 END) AS meta_unique,
        SUM(CASE WHEN metadesc_status = 'duplicate' THEN 1 ELSE 0 END) AS meta_duplicate,
        SUM(CASE WHEN metadesc_status = 'empty' THEN 1 ELSE 0 END) AS meta_empty
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

    <!-- Charts: SEO tag donut comparisons (3 columns) -->
    <div class="charts-grid" style="grid-template-columns: repeat(3, 1fr);">
    <?php
    Component::chart([
        'type' => 'donut',
        'title' => __('seo_tags.chart_title'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $titleRefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $titleBaseData]
        ],
        'height' => 300,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlSeoStatsDisplay
    ]);

    Component::chart([
        'type' => 'donut',
        'title' => __('seo_tags.chart_h1'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $h1RefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $h1BaseData]
        ],
        'height' => 300,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlSeoStatsDisplay
    ]);

    Component::chart([
        'type' => 'donut',
        'title' => __('seo_tags.chart_metadesc'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $metaRefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $metaBaseData]
        ],
        'height' => 300,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlSeoStatsDisplay
    ]);
    ?>
    </div>

    <!-- Charts: SEO tags by category (3 columns, stacked percent) -->
    <div class="charts-grid" style="grid-template-columns: repeat(3, 1fr);">
    <?php
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('seo_tags.chart_title'),
        'subtitle' => __('seo_tags.chart_category_subtitle'),
        'categories' => array_values($allCatNames),
        'series' => $titleCatSeries,
        'yAxisTitle' => __('common.percentage'),
        'yAxisMax' => 100,
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlSeoByCatDisplay
    ]);

    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('seo_tags.chart_h1'),
        'subtitle' => __('seo_tags.chart_category_subtitle'),
        'categories' => array_values($allCatNames),
        'series' => $h1CatSeries,
        'yAxisTitle' => __('common.percentage'),
        'yAxisMax' => 100,
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlH1ByCatDisplay
    ]);

    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('seo_tags.chart_metadesc'),
        'subtitle' => __('seo_tags.chart_category_subtitle'),
        'categories' => array_values($allCatNames),
        'series' => $metaCatSeries,
        'yAxisTitle' => __('common.percentage'),
        'yAxisMax' => 100,
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlMetaByCatDisplay
    ]);
    ?>
    </div>

    <!-- Regression tables -->
    <?php
    Component::urlTable([
        'title' => __('comparison.title_regressions_table'),
        'id' => 'title_regressions_table',
        'whereClause' => "WHERE c.compliant = true AND c.title_status IN ('empty','duplicate') AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.crawled = true AND b.compliant = true AND b.title_status = 'unique'
        )",
        'orderBy' => 'ORDER BY c.title_status ASC, c.inlinks DESC',
        'defaultColumns' => ['url', 'category', 'title_status', 'title'],
        'compareCrawlId' => $safeCompareId,
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);

    Component::urlTable([
        'title' => __('comparison.h1_regressions_table'),
        'id' => 'h1_regressions_table',
        'whereClause' => "WHERE c.compliant = true AND c.h1_status IN ('empty','duplicate') AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.crawled = true AND b.compliant = true AND b.h1_status = 'unique'
        )",
        'orderBy' => 'ORDER BY c.h1_status ASC, c.inlinks DESC',
        'defaultColumns' => ['url', 'category', 'h1_status', 'h1'],
        'compareCrawlId' => $safeCompareId,
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);

    Component::urlTable([
        'title' => __('comparison.metadesc_regressions_table'),
        'id' => 'metadesc_regressions_table',
        'whereClause' => "WHERE c.compliant = true AND c.metadesc_status IN ('empty','duplicate') AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.crawled = true AND b.compliant = true AND b.metadesc_status = 'unique'
        )",
        'orderBy' => 'ORDER BY c.metadesc_status ASC, c.inlinks DESC',
        'defaultColumns' => ['url', 'category', 'metadesc_status', 'metadesc'],
        'compareCrawlId' => $safeCompareId,
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
