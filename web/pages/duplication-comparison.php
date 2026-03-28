<?php
/**
 * Crawl Comparison - Duplication Analysis
 *
 * Compares duplicate content distribution and category breakdown
 * between reference and baseline crawls. Two donut charts only.
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
// Data: Duplicate cluster stats for each crawl
// =========================================
$sqlDupStats = "
    SELECT
        COUNT(DISTINCT dc.id) as total_clusters,
        COALESCE(SUM(dc.page_count), 0) as total_duplicated,
        COALESCE(SUM(CASE WHEN dc.similarity = 100 THEN dc.page_count ELSE 0 END), 0) as exact_dup,
        COALESCE(SUM(CASE WHEN dc.similarity < 100 THEN dc.page_count ELSE 0 END), 0) as near_dup
    FROM duplicate_clusters dc
    WHERE dc.crawl_id = :crawl_id AND dc.similarity >= 80
";

$stmtRef = $pdo->prepare($sqlDupStats);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$dupRef = $stmtRef->fetch(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlDupStats);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$dupBase = $stmtBase->fetch(PDO::FETCH_OBJ);

// Indexable page counts per crawl
$sqlIndexable = "
    SELECT COUNT(*) as indexable FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
";

$stmtRef = $pdo->prepare($sqlIndexable);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$indexableRef = (int)$stmtRef->fetchColumn();

$stmtBase = $pdo->prepare($sqlIndexable);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$indexableBase = (int)$stmtBase->fetchColumn();

// =========================================
// Chart 1: Unique vs Near-duplicate vs Exact duplicate (donut) ref vs base
// =========================================
$refUnique = max(0, $indexableRef - (int)$dupRef->exact_dup - (int)$dupRef->near_dup);
$baseUnique = max(0, $indexableBase - (int)$dupBase->exact_dup - (int)$dupBase->near_dup);

$dupRefData = [
    ['name' => __('duplication.series_unique') . ' (' . __('comparison.badge_reference') . ')', 'y' => $refUnique, 'color' => '#6bd899'],
    ['name' => __('duplication.series_near') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)$dupRef->near_dup, 'color' => '#60a5fa'],
    ['name' => __('duplication.series_exact') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)$dupRef->exact_dup, 'color' => '#f87171'],
];
$dupBaseData = [
    ['name' => __('duplication.series_unique') . ' (' . __('comparison.badge_baseline') . ')', 'y' => $baseUnique, 'color' => hexToRgba('#6bd899', 0.5)],
    ['name' => __('duplication.series_near') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)$dupBase->near_dup, 'color' => hexToRgba('#60a5fa', 0.5)],
    ['name' => __('duplication.series_exact') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)$dupBase->exact_dup, 'color' => hexToRgba('#f87171', 0.5)],
];

$sqlDupDisplay = "SELECT
    r.total_clusters AS ref_clusters, r.total_duplicated AS ref_duplicated,
    r.exact_dup AS ref_exact, r.near_dup AS ref_near,
    b.total_clusters AS base_clusters, b.total_duplicated AS base_duplicated,
    b.exact_dup AS base_exact, b.near_dup AS base_near
FROM (
    SELECT COUNT(DISTINCT id) AS total_clusters,
           COALESCE(SUM(page_count), 0) AS total_duplicated,
           COALESCE(SUM(CASE WHEN similarity = 100 THEN page_count ELSE 0 END), 0) AS exact_dup,
           COALESCE(SUM(CASE WHEN similarity < 100 THEN page_count ELSE 0 END), 0) AS near_dup
    FROM duplicate_clusters WHERE crawl_id = {$safeCrawlId} AND similarity >= 80
) r,
(
    SELECT COUNT(DISTINCT id) AS total_clusters,
           COALESCE(SUM(page_count), 0) AS total_duplicated,
           COALESCE(SUM(CASE WHEN similarity = 100 THEN page_count ELSE 0 END), 0) AS exact_dup,
           COALESCE(SUM(CASE WHEN similarity < 100 THEN page_count ELSE 0 END), 0) AS near_dup
    FROM duplicate_clusters WHERE crawl_id = {$safeCompareId} AND similarity >= 80
) b";

// =========================================
// Chart 2: Duplicated pages by category (donut) ref vs base
// =========================================
$sqlDupByCategory = "
    SELECT p.cat_id, COUNT(*) as page_count
    FROM pages p
    INNER JOIN (
        SELECT unnest(page_ids) as page_id
        FROM duplicate_clusters
        WHERE crawl_id = :crawl_id AND similarity >= 80
    ) dc ON p.id = dc.page_id
    WHERE p.crawl_id = :crawl_id2
    GROUP BY p.cat_id
    ORDER BY page_count DESC
";

$stmtRef = $pdo->prepare($sqlDupByCategory);
$stmtRef->execute([':crawl_id' => $safeCrawlId, ':crawl_id2' => $safeCrawlId]);
$dupCatRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlDupByCategory);
$stmtBase->execute([':crawl_id' => $safeCompareId, ':crawl_id2' => $safeCompareId]);
$dupCatBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

$categoriesMap = $GLOBALS['categoriesMap'] ?? [];

// Build donut data for ref
$catRefData = [];
foreach ($dupCatRef as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $catColor = $catInfo ? $catInfo['color'] : '#95a5a6';
    $catRefData[] = [
        'name' => $catName . ' (' . __('comparison.badge_reference') . ')',
        'y' => (int)$r->page_count,
        'color' => $catColor
    ];
}
if (empty($catRefData)) {
    $catRefData[] = ['name' => __('duplication.no_duplicates') . ' (' . __('comparison.badge_reference') . ')', 'y' => 1, 'color' => '#e5e7eb'];
}

// Build donut data for base
$catBaseData = [];
foreach ($dupCatBase as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $catColor = $catInfo ? $catInfo['color'] : '#95a5a6';
    $catBaseData[] = [
        'name' => $catName . ' (' . __('comparison.badge_baseline') . ')',
        'y' => (int)$r->page_count,
        'color' => hexToRgba($catColor, 0.5)
    ];
}
if (empty($catBaseData)) {
    $catBaseData[] = ['name' => __('duplication.no_duplicates') . ' (' . __('comparison.badge_baseline') . ')', 'y' => 1, 'color' => hexToRgba('#e5e7eb', 0.5)];
}

$sqlDupCatDisplay = "SELECT
    COALESCE(r.cat_id, b.cat_id) AS cat_id,
    COALESCE(r.page_count, 0) AS ref_count,
    COALESCE(b.page_count, 0) AS base_count
FROM (
    SELECT p.cat_id, COUNT(*) AS page_count
    FROM pages p
    INNER JOIN (SELECT unnest(page_ids) AS page_id FROM duplicate_clusters WHERE crawl_id = {$safeCrawlId} AND similarity >= 80) dc ON p.id = dc.page_id
    WHERE p.crawl_id = {$safeCrawlId}
    GROUP BY p.cat_id
) r
FULL OUTER JOIN (
    SELECT p.cat_id, COUNT(*) AS page_count
    FROM pages p
    INNER JOIN (SELECT unnest(page_ids) AS page_id FROM duplicate_clusters WHERE crawl_id = {$safeCompareId} AND similarity >= 80) dc ON p.id = dc.page_id
    WHERE p.crawl_id = {$safeCompareId}
    GROUP BY p.cat_id
) b ON r.cat_id = b.cat_id
ORDER BY COALESCE(r.page_count, 0) + COALESCE(b.page_count, 0) DESC";

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

    <!-- Charts: Duplication distribution + Category breakdown -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'donut',
        'title' => __('duplication.chart_distribution'),
        'subtitle' => __('comparison.subtitle_duplication'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $dupRefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $dupBaseData]
        ],
        'height' => 350,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlDupDisplay
    ]);

    Component::chart([
        'type' => 'donut',
        'title' => __('duplication.chart_category'),
        'subtitle' => __('comparison.subtitle_duplication_category'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $catRefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $catBaseData]
        ],
        'height' => 350,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlDupCatDisplay
    ]);
    ?>
    </div>

</div>
