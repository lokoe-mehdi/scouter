<?php
/**
 * Crawl Comparison - Response Codes
 *
 * Shows response code distribution comparison (donut + horizontal bar)
 * and lists URLs whose HTTP code changed between the two crawls.
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

// =========================================
// Scorecards: new / lost / common
// =========================================
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
// Response code distribution (donut)
// =========================================
$sqlCodeDist = "
    SELECT code, COUNT(*) as total
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND in_crawl = TRUE
    GROUP BY code ORDER BY total DESC
";
$codeStatsRef  = \App\Analysis\ReportPrecompute::cached($safeCrawlId,   'codechanges_dist', $pdo, $sqlCodeDist, [':crawl_id' => $safeCrawlId],   false);
$codeStatsBase = \App\Analysis\ReportPrecompute::cached($safeCompareId, 'codechanges_dist', $pdo, $sqlCodeDist, [':crawl_id' => $safeCompareId], false);

// Build donut data — Ref: full color, Base: 50% opacity
$donutRefData = [];
foreach ($codeStatsRef as $s) {
    $donutRefData[] = ['name' => 'Ref: ' . getCodeFullLabel($s->code), 'y' => (int)$s->total, 'color' => getCodeColor($s->code)];
}
$donutBaseData = [];
foreach ($codeStatsBase as $s) {
    $donutBaseData[] = ['name' => 'Base: ' . getCodeFullLabel($s->code), 'y' => (int)$s->total, 'color' => hexToRgba(getCodeColor($s->code), 0.5)];
}

// SQL display for donut
$sqlCodeDisplay = "SELECT
    COALESCE(r.code, b.code) AS code,
    COALESCE(r.total, 0) AS reference,
    COALESCE(b.total, 0) AS baseline
FROM (
    SELECT code, COUNT(*) AS total FROM pages@{$safeCrawlId}
    WHERE crawled = true AND in_crawl = TRUE GROUP BY code
) r
FULL OUTER JOIN (
    SELECT code, COUNT(*) AS total FROM pages@{$safeCompareId}
    WHERE crawled = true AND in_crawl = TRUE GROUP BY code
) b ON r.code = b.code
ORDER BY COALESCE(r.total, 0) + COALESCE(b.total, 0) DESC";

// =========================================
// Response code x Category distribution (horizontal bar)
// =========================================
$sqlCodeCat = "
    SELECT category, code, COUNT(*) as count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND in_crawl = TRUE
    GROUP BY category, code ORDER BY count DESC
";
$codeCatRef  = \App\Analysis\ReportPrecompute::cached($safeCrawlId,   'codechanges_by_category', $pdo, $sqlCodeCat, [':crawl_id' => $safeCrawlId],   true);
$codeCatBase = \App\Analysis\ReportPrecompute::cached($safeCompareId, 'codechanges_by_category', $pdo, $sqlCodeCat, [':crawl_id' => $safeCompareId], true);

$categoriesMap = $GLOBALS['categoriesMap'] ?? [];

// Categories are project-level: same IDs across all crawls

// Helper: group code into family
if (!function_exists('codeFamily')) {
    function codeFamily($code) {
        if ($code == 0) return '0xx';
        if ($code >= 100 && $code < 200) return '1xx';
        if ($code >= 200 && $code < 300) return '2xx';
        if ($code >= 300 && $code < 400) return '3xx';
        if ($code >= 400 && $code < 500) return '4xx';
        if ($code >= 500 && $code < 600) return '5xx';
        return 'other';
    }
}

// Organize by category -> code family for ref
$refCodeCatData = [];
foreach ($codeCatRef as $r) {
    $catName = (($r->category ?? '') !== '') ? $r->category : __('common.uncategorized');
    if (!isset($refCodeCatData[$catName])) $refCodeCatData[$catName] = ['0xx'=>0,'1xx'=>0,'2xx'=>0,'3xx'=>0,'4xx'=>0,'5xx'=>0];
    $refCodeCatData[$catName][codeFamily($r->code)] += (int)$r->count;
}
// Same for base
$baseCodeCatData = [];
foreach ($codeCatBase as $r) {
    $catName = (($r->category ?? '') !== '') ? $r->category : __('common.uncategorized');
    if (!isset($baseCodeCatData[$catName])) $baseCodeCatData[$catName] = ['0xx'=>0,'1xx'=>0,'2xx'=>0,'3xx'=>0,'4xx'=>0,'5xx'=>0];
    $baseCodeCatData[$catName][codeFamily($r->code)] += (int)$r->count;
}

// Merge all category names
$allCodeCatNames = array_unique(array_merge(array_keys($refCodeCatData), array_keys($baseCodeCatData)));

// Build series: each code family × ref/base
$codeFamilies = ['2xx' => [200,'2xx - OK'], '3xx' => [300,'3xx - Redirect'], '4xx' => [400,'4xx - Client Error'], '5xx' => [500,'5xx - Server Error'], '0xx' => [0,'0 - Timeout'], '1xx' => [100,'1xx - Info']];
$codeCatSeries = [];
foreach ($codeFamilies as $fam => $info) {
    $refVals = [];
    $baseVals = [];
    foreach ($allCodeCatNames as $cat) {
        $refVals[] = $refCodeCatData[$cat][$fam] ?? 0;
        $baseVals[] = $baseCodeCatData[$cat][$fam] ?? 0;
    }
    if (array_sum($refVals) == 0 && array_sum($baseVals) == 0) continue;
    $color = getCodeColor($info[0]);
    $codeCatSeries[] = [
        'name' => $info[1] . ' (Ref)',
        'data' => $refVals,
        'color' => $color,
        'stack' => 'reference',
    ];
    $codeCatSeries[] = [
        'name' => $info[1] . ' (Base)',
        'data' => $baseVals,
        'color' => hexToRgba($color, 0.5),
        'stack' => 'baseline',
    ];
}

// SQL display for code x category
$codeFamCols = [];
foreach (['2xx'=>[200,300],'3xx'=>[300,400],'4xx'=>[400,500],'5xx'=>[500,600]] as $fam => $range) {
    $codeFamCols[] = "    SUM(CASE WHEN code >= {$range[0]} AND code < {$range[1]} THEN r_count ELSE 0 END) AS {$fam}_ref";
    $codeFamCols[] = "    SUM(CASE WHEN code >= {$range[0]} AND code < {$range[1]} THEN b_count ELSE 0 END) AS {$fam}_base";
}
$codeFamColsSql = implode(",\n", $codeFamCols);
$sqlCodeCatDisplay = "SELECT
    category,
{$codeFamColsSql}
FROM (
    SELECT
        COALESCE(r.category, b.category) AS category,
        COALESCE(r.code, b.code) AS code,
        COALESCE(r.count, 0) AS r_count,
        COALESCE(b.count, 0) AS b_count
    FROM (
        SELECT category, code, COUNT(*) AS count FROM pages@{$safeCrawlId}
        WHERE crawled = true AND in_crawl = TRUE GROUP BY category, code
    ) r
    FULL OUTER JOIN (
        SELECT category, code, COUNT(*) AS count FROM pages@{$safeCompareId}
        WHERE crawled = true AND in_crawl = TRUE GROUP BY category, code
    ) b ON r.category = b.category AND r.code = b.code
) sub
GROUP BY category
ORDER BY category";

// Build cat_name -> id mapping for SQL generation (IDs are stable across crawls)
$catNameToIds = [];
foreach ($categoriesMap as $id => $info) {
    $catNameToIds[$info['cat']][] = $id;
}

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

    <!-- Charts: Response code distribution comparison -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'donut',
        'title' => __('codes.chart_title'),
        'subtitle' => __('comparison.subtitle_codes'),
        'series' => [
            [
                'name' => __('comparison.badge_reference'),
                'data' => $donutRefData
            ],
            [
                'name' => __('comparison.badge_baseline'),
                'data' => $donutBaseData
            ]
        ],
        'height' => 400,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlCodeDisplay
    ]);

    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('codes.chart_category_title'),
        'subtitle' => __('comparison.subtitle_codes_category'),
        'categories' => array_values($allCodeCatNames),
        'series' => $codeCatSeries,
        'yAxisTitle' => __('common.percentage'),
        'yAxisMax' => 100,
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlCodeCatDisplay
    ]);
    ?>
    </div>

    <?php
    Component::urlTable([
        'title' => __('comparison.code_changes_table_title'),
        'id' => 'code_changes_table',
        'whereClause' => "WHERE c.crawled = true AND c.in_crawl = TRUE AND c.url IN (
            SELECT cur.url FROM pages_{$safeCrawlId} cur JOIN pages_{$safeCompareId} b ON cur.url = b.url
            WHERE cur.code != b.code AND b.crawled = true AND b.in_crawl = TRUE
        )",
        'orderBy' => 'ORDER BY c.code DESC, c.inlinks DESC',
        'defaultColumns' => ['url', 'depth', 'code', 'category', 'inlinks', 'pri'],
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
