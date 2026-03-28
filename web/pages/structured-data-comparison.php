<?php
/**
 * Crawl Comparison - Structured Data (Schema.org)
 *
 * Compares schema type distribution, top 10 schema types,
 * average schemas per page by category, and lists pages
 * that lost structured data between crawls.
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
// Chart 1: Schema type distribution (donut) ref vs base
// =========================================
$sqlSchemaDistribution = "
    SELECT
        ps.schema_type,
        COUNT(DISTINCT ps.page_id) as page_count
    FROM page_schemas ps
    INNER JOIN pages p ON p.crawl_id = ps.crawl_id AND p.id = ps.page_id
    WHERE ps.crawl_id = :crawl_id AND p.compliant = true
    GROUP BY ps.schema_type
    ORDER BY page_count DESC
";

$stmtRef = $pdo->prepare($sqlSchemaDistribution);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$schemaDistRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlSchemaDistribution);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$schemaDistBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

$colors = ['#4ECDC4', '#FF6B6B', '#45B7D1', '#96CEB4', '#FFEAA7', '#DDA0DD', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E9'];

// Build donut data for ref
$donutRefData = [];
$colorIndex = 0;
foreach ($schemaDistRef as $stat) {
    $donutRefData[] = [
        'name' => __('comparison.badge_reference') . ': ' . $stat->schema_type,
        'y' => (int)$stat->page_count,
        'color' => $colors[$colorIndex % count($colors)]
    ];
    $colorIndex++;
}

// Build donut data for base
$donutBaseData = [];
$colorIndex = 0;
foreach ($schemaDistBase as $stat) {
    $donutBaseData[] = [
        'name' => __('comparison.badge_baseline') . ': ' . $stat->schema_type,
        'y' => (int)$stat->page_count,
        'color' => hexToRgba($colors[$colorIndex % count($colors)], 0.5)
    ];
    $colorIndex++;
}

$sqlSchemaDistDisplay = "SELECT
    COALESCE(r.schema_type, b.schema_type) AS schema_type,
    COALESCE(r.page_count, 0) AS ref_count,
    COALESCE(b.page_count, 0) AS base_count
FROM (
    SELECT ps.schema_type, COUNT(DISTINCT ps.page_id) AS page_count
    FROM page_schemas ps
    INNER JOIN pages p ON p.crawl_id = ps.crawl_id AND p.id = ps.page_id
    WHERE ps.crawl_id = {$safeCrawlId} AND p.compliant = true
    GROUP BY ps.schema_type
) r
FULL OUTER JOIN (
    SELECT ps.schema_type, COUNT(DISTINCT ps.page_id) AS page_count
    FROM page_schemas ps
    INNER JOIN pages p ON p.crawl_id = ps.crawl_id AND p.id = ps.page_id
    WHERE ps.crawl_id = {$safeCompareId} AND p.compliant = true
    GROUP BY ps.schema_type
) b ON r.schema_type = b.schema_type
ORDER BY COALESCE(r.page_count, 0) + COALESCE(b.page_count, 0) DESC";

// =========================================
// Chart 2: Top 10 schema types (horizontal bar) ref vs base
// =========================================
// Merge all schema types from both crawls
$refSchemaMap = [];
foreach ($schemaDistRef as $stat) $refSchemaMap[$stat->schema_type] = (int)$stat->page_count;
$baseSchemaMap = [];
foreach ($schemaDistBase as $stat) $baseSchemaMap[$stat->schema_type] = (int)$stat->page_count;

$allSchemaTypes = array_unique(array_merge(array_keys($refSchemaMap), array_keys($baseSchemaMap)));

// Sort by combined count and take top 10
$schemaCombined = [];
foreach ($allSchemaTypes as $type) {
    $schemaCombined[$type] = ($refSchemaMap[$type] ?? 0) + ($baseSchemaMap[$type] ?? 0);
}
arsort($schemaCombined);
$top10Types = array_slice(array_keys($schemaCombined), 0, 10);

$top10Labels = [];
$top10RefValues = [];
$top10BaseValues = [];
foreach ($top10Types as $type) {
    $top10Labels[] = $type;
    $top10RefValues[] = $refSchemaMap[$type] ?? 0;
    $top10BaseValues[] = $baseSchemaMap[$type] ?? 0;
}

// =========================================
// Chart 3: Avg schemas per page by category (horizontal bar) ref vs base
// =========================================
$sqlSchemaByCategory = "
    SELECT
        p.cat_id,
        COALESCE(AVG(array_length(p.schemas, 1)), 0) as avg_schemas
    FROM pages p
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.compliant = true
    GROUP BY p.cat_id
    ORDER BY avg_schemas DESC
";

$stmtRef = $pdo->prepare($sqlSchemaByCategory);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$schemaCatRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlSchemaByCategory);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$schemaCatBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

$categoriesMap = $GLOBALS['categoriesMap'] ?? [];

// Build maps by category name
$refSchemaCatData = [];
foreach ($schemaCatRef as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $refSchemaCatData[$catName] = round((float)$r->avg_schemas, 2);
}
$baseSchemaCatData = [];
foreach ($schemaCatBase as $r) {
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $baseSchemaCatData[$catName] = round((float)$r->avg_schemas, 2);
}

$allSchemaCatNames = array_unique(array_merge(array_keys($refSchemaCatData), array_keys($baseSchemaCatData)));

$schemaCatLabels = [];
$schemaCatRefValues = [];
$schemaCatBaseValues = [];
foreach ($allSchemaCatNames as $catName) {
    $schemaCatLabels[] = $catName;
    $schemaCatRefValues[] = $refSchemaCatData[$catName] ?? 0;
    $schemaCatBaseValues[] = $baseSchemaCatData[$catName] ?? 0;
}

$sqlSchemaCatDisplay = "SELECT
    COALESCE(r.cat_id, b.cat_id) AS cat_id,
    COALESCE(r.avg_schemas, 0) AS ref_avg_schemas,
    COALESCE(b.avg_schemas, 0) AS base_avg_schemas
FROM (
    SELECT cat_id, COALESCE(AVG(array_length(schemas, 1)), 0) AS avg_schemas
    FROM pages@{$safeCrawlId} WHERE crawled = true AND compliant = true GROUP BY cat_id
) r
FULL OUTER JOIN (
    SELECT cat_id, COALESCE(AVG(array_length(schemas, 1)), 0) AS avg_schemas
    FROM pages@{$safeCompareId} WHERE crawled = true AND compliant = true GROUP BY cat_id
) b ON r.cat_id = b.cat_id
ORDER BY COALESCE(r.avg_schemas, 0) + COALESCE(b.avg_schemas, 0) DESC";

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

    <!-- Charts: Schema distribution donut + Top 10 horizontal bar -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'donut',
        'title' => __('structured_data.chart_distribution'),
        'subtitle' => __('comparison.subtitle_schema_distribution'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $donutRefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $donutBaseData]
        ],
        'height' => 350,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlSchemaDistDisplay
    ]);

    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('structured_data.chart_top10'),
        'subtitle' => __('comparison.subtitle_schema_top10'),
        'categories' => $top10Labels,
        'series' => [
            [
                'name' => __('comparison.badge_reference'),
                'data' => $top10RefValues,
                'color' => '#4ECDC4'
            ],
            [
                'name' => __('comparison.badge_baseline'),
                'data' => $top10BaseValues,
                'color' => hexToRgba('#4ECDC4', 0.5)
            ]
        ],
        'height' => 350,
        'sqlQuery' => $sqlSchemaDistDisplay
    ]);
    ?>
    </div>

    <!-- Chart: Avg schemas per page by category -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('structured_data.chart_avg'),
        'subtitle' => __('comparison.subtitle_schema_avg_category'),
        'categories' => $schemaCatLabels,
        'series' => [
            [
                'name' => __('comparison.badge_reference'),
                'data' => $schemaCatRefValues,
                'color' => 'var(--primary-color)'
            ],
            [
                'name' => __('comparison.badge_baseline'),
                'data' => $schemaCatBaseValues,
                'color' => hexToRgba('#6366f1', 0.5)
            ]
        ],
        'height' => max(250, count($schemaCatLabels) * 50),
        'sqlQuery' => $sqlSchemaCatDisplay
    ]);
    ?>
    </div>

    <?php
    Component::urlTable([
        'title' => __('comparison.lost_schemas_table'),
        'id' => 'lost_schemas_table',
        'whereClause' => "WHERE c.crawled = true AND c.compliant = true AND (array_length(c.schemas, 1) IS NULL OR array_length(c.schemas, 1) = 0) AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.crawled = true AND b.compliant = true AND array_length(b.schemas, 1) > 0
        )",
        'orderBy' => 'ORDER BY c.inlinks DESC',
        'defaultColumns' => ['url', 'category', 'depth', 'inlinks'],
        'compareCrawlId' => $safeCompareId,
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
