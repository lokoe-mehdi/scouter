<?php
/**
 * Crawl Comparison - Indexability
 *
 * Compares URL discovery distribution, indexability breakdown, and
 * per-category indexability between reference and baseline crawls.
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
// Chart 1: Discovered URL distribution (donut) ref vs base
// =========================================
$sqlUrlDistribution = "
    SELECT
        SUM(CASE WHEN external = true THEN 1 ELSE 0 END) as external_urls,
        SUM(CASE WHEN external = false AND crawled = true AND is_html = true THEN 1 ELSE 0 END) as crawled_urls,
        SUM(CASE WHEN external = false AND crawled = false THEN 1 ELSE 0 END) as not_crawled_urls,
        SUM(CASE WHEN external = false AND crawled = true AND (is_html = false OR is_html IS NULL) THEN 1 ELSE 0 END) as media_urls
    FROM pages
    WHERE crawl_id = :crawl_id
";

$stmtRef = $pdo->prepare($sqlUrlDistribution);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$urlDistRef = $stmtRef->fetch(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlUrlDistribution);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$urlDistBase = $stmtBase->fetch(PDO::FETCH_OBJ);

$donutRefData = [
    ['name' => __('accessibility.series_crawled_html') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($urlDistRef->crawled_urls ?? 0), 'color' => '#6bd899'],
    ['name' => __('accessibility.series_external_html') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($urlDistRef->external_urls ?? 0), 'color' => '#d8bf6b'],
    ['name' => __('accessibility.series_blocked_robots') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($urlDistRef->not_crawled_urls ?? 0), 'color' => '#d86b6b'],
    ['name' => __('accessibility.series_media') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($urlDistRef->media_urls ?? 0), 'color' => '#E5E7EB'],
];
$donutBaseData = [
    ['name' => __('accessibility.series_crawled_html') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($urlDistBase->crawled_urls ?? 0), 'color' => hexToRgba('#6bd899', 0.5)],
    ['name' => __('accessibility.series_external_html') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($urlDistBase->external_urls ?? 0), 'color' => hexToRgba('#d8bf6b', 0.5)],
    ['name' => __('accessibility.series_blocked_robots') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($urlDistBase->not_crawled_urls ?? 0), 'color' => hexToRgba('#d86b6b', 0.5)],
    ['name' => __('accessibility.series_media') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($urlDistBase->media_urls ?? 0), 'color' => hexToRgba('#E5E7EB', 0.5)],
];

// =========================================
// Chart 2: Indexability donut ref vs base
// =========================================
$sqlNonIndexable = "
    SELECT
        SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) as indexable,
        SUM(CASE WHEN compliant = false AND code != 200 AND code IS NOT NULL THEN 1 ELSE 0 END) as bad_status,
        SUM(CASE WHEN compliant = false AND code = 200 AND noindex = true THEN 1 ELSE 0 END) as noindex_urls,
        SUM(CASE WHEN compliant = false AND code = 200 AND noindex = false AND canonical = false THEN 1 ELSE 0 END) as non_canonical
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true
";

$stmtRef = $pdo->prepare($sqlNonIndexable);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$idxRef = $stmtRef->fetch(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlNonIndexable);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$idxBase = $stmtBase->fetch(PDO::FETCH_OBJ);

$idxRefData = [
    ['name' => __('accessibility.series_indexable') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($idxRef->indexable ?? 0), 'color' => '#6bd899'],
    ['name' => __('accessibility.series_non_canonical') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($idxRef->non_canonical ?? 0), 'color' => '#cfd86b'],
    ['name' => __('accessibility.series_noindex') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($idxRef->noindex_urls ?? 0), 'color' => '#d8bf6b'],
    ['name' => __('accessibility.series_http_not_200') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($idxRef->bad_status ?? 0), 'color' => '#d86b6b'],
];
$idxBaseData = [
    ['name' => __('accessibility.series_indexable') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($idxBase->indexable ?? 0), 'color' => hexToRgba('#6bd899', 0.5)],
    ['name' => __('accessibility.series_non_canonical') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($idxBase->non_canonical ?? 0), 'color' => hexToRgba('#cfd86b', 0.5)],
    ['name' => __('accessibility.series_noindex') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($idxBase->noindex_urls ?? 0), 'color' => hexToRgba('#d8bf6b', 0.5)],
    ['name' => __('accessibility.series_http_not_200') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($idxBase->bad_status ?? 0), 'color' => hexToRgba('#d86b6b', 0.5)],
];

// =========================================
// Chart 3: Indexability by category (horizontal bar, stacked percent) ref vs base
// =========================================
$sqlIndexabilityByCategory = "
    SELECT
        cat_id,
        SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) as indexable,
        SUM(CASE WHEN compliant = false AND canonical = false THEN 1 ELSE 0 END) as non_canonical,
        SUM(CASE WHEN compliant = false AND canonical = true AND noindex = true THEN 1 ELSE 0 END) as noindex,
        SUM(CASE WHEN compliant = false AND canonical = true AND noindex = false AND code != 200 THEN 1 ELSE 0 END) as bad_status
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true
    GROUP BY cat_id
";

$stmtRef = $pdo->prepare($sqlIndexabilityByCategory);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$idxCatRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlIndexabilityByCategory);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$idxCatBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

$categoriesMap = $GLOBALS['categoriesMap'] ?? [];

// Build maps by category name
$refIdxCatData = [];
foreach ($idxCatRef as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $refIdxCatData[$catName] = $r;
}
$baseIdxCatData = [];
foreach ($idxCatBase as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $baseIdxCatData[$catName] = $r;
}

$allIdxCatNames = array_unique(array_merge(array_keys($refIdxCatData), array_keys($baseIdxCatData)));

// Build series: each reason × ref/base
$reasons = [
    'indexable' => [__('accessibility.series_indexable'), '#6bd899'],
    'non_canonical' => [__('accessibility.series_non_canonical'), '#cfd86b'],
    'noindex' => [__('accessibility.series_noindex'), '#d8bf6b'],
    'bad_status' => [__('accessibility.series_http_not_200'), '#d86b6b'],
];

$idxCatSeries = [];
foreach ($reasons as $key => [$label, $color]) {
    $refValues = [];
    $baseValues = [];
    foreach ($allIdxCatNames as $catName) {
        $refValues[] = isset($refIdxCatData[$catName]) ? (int)$refIdxCatData[$catName]->$key : 0;
        $baseValues[] = isset($baseIdxCatData[$catName]) ? (int)$baseIdxCatData[$catName]->$key : 0;
    }
    if (array_sum($refValues) == 0 && array_sum($baseValues) == 0) continue;
    $idxCatSeries[] = [
        'name' => $label . ' (' . __('comparison.badge_reference') . ')',
        'data' => $refValues,
        'color' => $color,
        'stack' => 'reference'
    ];
    $idxCatSeries[] = [
        'name' => $label . ' (' . __('comparison.badge_baseline') . ')',
        'data' => $baseValues,
        'color' => hexToRgba($color, 0.5),
        'stack' => 'baseline'
    ];
}

// SQL display
$sqlIdxCatDisplay = "SELECT
    COALESCE(r.cat_id, b.cat_id) AS cat_id,
    COALESCE(r.indexable, 0) AS ref_indexable,
    COALESCE(r.non_canonical, 0) AS ref_non_canonical,
    COALESCE(r.noindex, 0) AS ref_noindex,
    COALESCE(r.bad_status, 0) AS ref_bad_status,
    COALESCE(b.indexable, 0) AS base_indexable,
    COALESCE(b.non_canonical, 0) AS base_non_canonical,
    COALESCE(b.noindex, 0) AS base_noindex,
    COALESCE(b.bad_status, 0) AS base_bad_status
FROM (
    SELECT cat_id,
        SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) AS indexable,
        SUM(CASE WHEN compliant = false AND canonical = false THEN 1 ELSE 0 END) AS non_canonical,
        SUM(CASE WHEN compliant = false AND canonical = true AND noindex = true THEN 1 ELSE 0 END) AS noindex,
        SUM(CASE WHEN compliant = false AND canonical = true AND noindex = false AND code != 200 THEN 1 ELSE 0 END) AS bad_status
    FROM pages@{$safeCrawlId} WHERE crawled = true AND is_html = true GROUP BY cat_id
) r
FULL OUTER JOIN (
    SELECT cat_id,
        SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) AS indexable,
        SUM(CASE WHEN compliant = false AND canonical = false THEN 1 ELSE 0 END) AS non_canonical,
        SUM(CASE WHEN compliant = false AND canonical = true AND noindex = true THEN 1 ELSE 0 END) AS noindex,
        SUM(CASE WHEN compliant = false AND canonical = true AND noindex = false AND code != 200 THEN 1 ELSE 0 END) AS bad_status
    FROM pages@{$safeCompareId} WHERE crawled = true AND is_html = true GROUP BY cat_id
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

    <!-- Charts: Discovery + Indexability donuts -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'donut',
        'title' => __('accessibility.chart_discovered'),
        'subtitle' => __('comparison.subtitle_discovered'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $donutRefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $donutBaseData]
        ],
        'height' => 350,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlUrlDistribution
    ]);

    Component::chart([
        'type' => 'donut',
        'title' => __('accessibility.chart_indexability'),
        'subtitle' => __('comparison.subtitle_indexability'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $idxRefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $idxBaseData]
        ],
        'height' => 350,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlNonIndexable
    ]);
    ?>
    </div>

    <!-- Chart: Indexability by category -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('accessibility.chart_indexability_category'),
        'subtitle' => __('comparison.subtitle_indexability_category'),
        'categories' => array_values($allIdxCatNames),
        'series' => $idxCatSeries,
        'yAxisTitle' => __('common.percentage'),
        'yAxisMax' => 100,
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlIdxCatDisplay
    ]);
    ?>
    </div>

    <?php
    Component::urlTable([
        'title' => __('comparison.indexability_changes_table_title'),
        'id' => 'indexability_changes_table',
        'whereClause' => "WHERE c.crawled = true AND c.is_html = true AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.crawled = true AND b.is_html = true AND b.compliant != c.compliant
        )",
        'orderBy' => 'ORDER BY c.compliant ASC, c.inlinks DESC',
        'defaultColumns' => ['url', 'category', 'compliant', 'code', 'noindex', 'canonical'],
        'compareCrawlId' => $safeCompareId,
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
