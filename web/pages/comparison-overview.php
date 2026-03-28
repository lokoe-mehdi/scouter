<?php
/**
 * Crawl Comparison - Overview
 *
 * Summary table comparing key KPIs between the reference crawl and the baseline crawl.
 * Only considers crawled URLs (crawled = true) where relevant.
 */

if (!$compareId) {
    ?>
    <div style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <span class="material-symbols-outlined" style="font-size: 4rem; color: var(--text-tertiary);">compare_arrows</span>
        <h2 style="margin-top: 1rem; color: var(--text-primary);"><?= __('comparison.no_compare') ?></h2>
        <p style="color: var(--text-secondary); margin-top: 0.5rem;"><?= __('comparison.no_compare_desc') ?></p>
    </div>
    <?php
    return;
}

$safeCompareId = intval($compareId);
$safeCrawlId = intval($crawlId);

// Count new URLs (in current, not in comparison) — crawled only
$stmtNew = $pdo->prepare("
    SELECT COUNT(*) FROM pages
    WHERE crawl_id = :current AND crawled = true AND url NOT IN (
        SELECT url FROM pages WHERE crawl_id = :compare AND crawled = true
    )
");
$stmtNew->execute([':current' => $safeCrawlId, ':compare' => $safeCompareId]);
$newCount = (int)$stmtNew->fetchColumn();

// Count lost URLs (in comparison, not in current) — crawled only
$stmtLost = $pdo->prepare("
    SELECT COUNT(*) FROM pages
    WHERE crawl_id = :compare AND crawled = true AND url NOT IN (
        SELECT url FROM pages WHERE crawl_id = :current AND crawled = true
    )
");
$stmtLost->execute([':compare' => $safeCompareId, ':current' => $safeCrawlId]);
$lostCount = (int)$stmtLost->fetchColumn();

// Count common URLs — crawled only
$stmtCommon = $pdo->prepare("
    SELECT COUNT(*) FROM pages a
    JOIN pages b ON a.url = b.url AND b.crawl_id = :compare AND b.crawled = true
    WHERE a.crawl_id = :current AND a.crawled = true
");
$stmtCommon->execute([':current' => $safeCrawlId, ':compare' => $safeCompareId]);
$commonCount = (int)$stmtCommon->fetchColumn();

// =========================================
// Depth distribution — indexable URLs only
// =========================================
$sqlDepthRef = "
    SELECT depth, COUNT(*) as total
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true AND is_html = true
    GROUP BY depth ORDER BY depth
";
$stmtDepthRef = $pdo->prepare($sqlDepthRef);
$stmtDepthRef->execute([':crawl_id' => $safeCrawlId]);
$depthRef = $stmtDepthRef->fetchAll(PDO::FETCH_OBJ);

$stmtDepthBase = $pdo->prepare($sqlDepthRef);
$stmtDepthBase->execute([':crawl_id' => $safeCompareId]);
$depthBase = $stmtDepthBase->fetchAll(PDO::FETCH_OBJ);

// Merge depth levels from both crawls
$allDepths = [];
foreach ($depthRef as $r) $allDepths[$r->depth] = true;
foreach ($depthBase as $r) $allDepths[$r->depth] = true;
ksort($allDepths);
$depthLabels = array_map(function($d) { return 'Niveau ' . $d; }, array_keys($allDepths));

$refDepthData = [];
$baseDepthData = [];
$refDepthMap = [];
$baseDepthMap = [];
foreach ($depthRef as $r) $refDepthMap[$r->depth] = (int)$r->total;
foreach ($depthBase as $r) $baseDepthMap[$r->depth] = (int)$r->total;
foreach (array_keys($allDepths) as $d) {
    $refDepthData[] = $refDepthMap[$d] ?? 0;
    $baseDepthData[] = $baseDepthMap[$d] ?? 0;
}

// =========================================
// Depth x Category distribution (indexable HTML only)
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

// Load baseline categories
$baselineCategoriesMap = [];
$stmtCat = $pdo->prepare("SELECT id, cat, color FROM categories WHERE crawl_id = :crawl_id");
$stmtCat->execute([':crawl_id' => $safeCompareId]);
while ($row = $stmtCat->fetch(PDO::FETCH_ASSOC)) {
    $baselineCategoriesMap[$row['id']] = ['cat' => $row['cat'], 'color' => $row['color']];
}

$categoriesMap = $GLOBALS['categoriesMap'] ?? [];

// Collect all depth levels from both crawls (for category chart)
$allDepthsCat = [];
foreach ($depthCatRef as $r) $allDepthsCat[$r->depth] = true;
foreach ($depthCatBase as $r) $allDepthsCat[$r->depth] = true;
ksort($allDepthsCat);
$depthCatLabels = array_map(function($d) { return 'Niveau ' . $d; }, array_keys($allDepthsCat));
$depthCatKeys = array_keys($allDepthsCat);

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
    $catInfo = $baselineCategoriesMap[$r->cat_id] ?? $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    if (!isset($baseCatData[$catName])) $baseCatData[$catName] = [];
    $baseCatData[$catName][$r->depth] = (int)$r->count;
}

// Helper: hex color to rgba
function hexToRgba($hex, $alpha = 1.0) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "rgba($r,$g,$b,$alpha)";
}

// Build paired series with stack grouping:
// Each category creates 2 series — one stacked in 'reference', one in 'baseline'
$allCatNames = array_unique(array_merge(array_keys($refCatData), array_keys($baseCatData)));
$depthCatSeries = [];

// Build cat_id -> name mapping for SQL generation
$catNameToIds = [];
foreach ($categoriesMap as $id => $info) {
    $catNameToIds[$info['cat']][] = $id;
}
foreach ($baselineCategoriesMap as $id => $info) {
    if (!isset($catNameToIds[$info['cat']])) {
        $catNameToIds[$info['cat']][] = $id;
    }
}

foreach ($allCatNames as $catName) {
    $color = getCategoryColor($catName);
    // Reference series (stacked together in left bar)
    $refValues = [];
    foreach ($depthCatKeys as $d) {
        $refValues[] = $refCatData[$catName][$d] ?? 0;
    }
    $depthCatSeries[] = [
        'name' => $catName . ' (Ref)',
        'data' => $refValues,
        'color' => $color,
        'stack' => 'reference',
    ];
    // Baseline series (stacked together in right bar, lower opacity)
    $baseValues = [];
    foreach ($depthCatKeys as $d) {
        $baseValues[] = $baseCatData[$catName][$d] ?? 0;
    }
    $depthCatSeries[] = [
        'name' => $catName . ' (Base)',
        'data' => $baseValues,
        'color' => hexToRgba($color, 0.5),
        'stack' => 'baseline',
    ];
}

// Build pivot SQL for the category chart
$catCols = [];
foreach ($allCatNames as $catName) {
    $ids = $catNameToIds[$catName] ?? [];
    $inList = !empty($ids) ? implode(',', $ids) : '-1';
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '_', $catName);
    $catCols[] = "    SUM(CASE WHEN r.cat_id IN ({$inList}) THEN r.count ELSE 0 END) AS {$alias}_ref";
    $catCols[] = "    SUM(CASE WHEN b.cat_id IN ({$inList}) THEN b.count ELSE 0 END) AS {$alias}_base";
}
$catColsSql = implode(",\n", $catCols);
$sqlDepthCatDisplay = "SELECT
    COALESCE(r.depth, b.depth) AS depth,
{$catColsSql}
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
GROUP BY COALESCE(r.depth, b.depth)
ORDER BY depth";

// =========================================
// Response code distribution (donut)
// =========================================
$sqlCodeDist = "
    SELECT code, COUNT(*) as total
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true
    GROUP BY code ORDER BY total DESC
";
$stmtCodeRef = $pdo->prepare($sqlCodeDist);
$stmtCodeRef->execute([':crawl_id' => $safeCrawlId]);
$codeStatsRef = $stmtCodeRef->fetchAll(PDO::FETCH_OBJ);

$stmtCodeBase = $pdo->prepare($sqlCodeDist);
$stmtCodeBase->execute([':crawl_id' => $safeCompareId]);
$codeStatsBase = $stmtCodeBase->fetchAll(PDO::FETCH_OBJ);

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
    WHERE crawled = true GROUP BY code
) r
FULL OUTER JOIN (
    SELECT code, COUNT(*) AS total FROM pages@{$safeCompareId}
    WHERE crawled = true GROUP BY code
) b ON r.code = b.code
ORDER BY COALESCE(r.total, 0) + COALESCE(b.total, 0) DESC";

// =========================================
// Response code x Category distribution (horizontal bar)
// =========================================
$sqlCodeCat = "
    SELECT cat_id, code, COUNT(*) as count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true
    GROUP BY cat_id, code ORDER BY count DESC
";
$stmtCodeCatRef = $pdo->prepare($sqlCodeCat);
$stmtCodeCatRef->execute([':crawl_id' => $safeCrawlId]);
$codeCatRef = $stmtCodeCatRef->fetchAll(PDO::FETCH_OBJ);

$stmtCodeCatBase = $pdo->prepare($sqlCodeCat);
$stmtCodeCatBase->execute([':crawl_id' => $safeCompareId]);
$codeCatBase = $stmtCodeCatBase->fetchAll(PDO::FETCH_OBJ);

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
    $catInfo = $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    if (!isset($refCodeCatData[$catName])) $refCodeCatData[$catName] = ['0xx'=>0,'1xx'=>0,'2xx'=>0,'3xx'=>0,'4xx'=>0,'5xx'=>0];
    $refCodeCatData[$catName][codeFamily($r->code)] += (int)$r->count;
}
// Same for base
$baseCodeCatData = [];
foreach ($codeCatBase as $r) {
    $catInfo = $baselineCategoriesMap[$r->cat_id] ?? $categoriesMap[$r->cat_id] ?? null;
    $catName = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
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
    // Skip if both empty
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
    cat_id,
{$codeFamColsSql}
FROM (
    SELECT
        COALESCE(r.cat_id, b.cat_id) AS cat_id,
        COALESCE(r.code, b.code) AS code,
        COALESCE(r.count, 0) AS r_count,
        COALESCE(b.count, 0) AS b_count
    FROM (
        SELECT cat_id, code, COUNT(*) AS count FROM pages@{$safeCrawlId}
        WHERE crawled = true GROUP BY cat_id, code
    ) r
    FULL OUTER JOIN (
        SELECT cat_id, code, COUNT(*) AS count FROM pages@{$safeCompareId}
        WHERE crawled = true GROUP BY cat_id, code
    ) b ON r.cat_id = b.cat_id AND r.code = b.code
) sub
GROUP BY cat_id
ORDER BY cat_id";

// KPI values from pre-computed crawl stats
$refUrls = $crawlRecord->urls ?? 0;
$refCrawled = $crawlRecord->crawled ?? 0;
$refCompliant = $crawlRecord->compliant ?? 0;
$refTtfb = round($crawlRecord->response_time ?? 0, 2);
$refDepth = $crawlRecord->depth_max ?? 0;

$baseUrls = $compareRecord->urls ?? 0;
$baseCrawled = $compareRecord->crawled ?? 0;
$baseCompliant = $compareRecord->compliant ?? 0;
$baseTtfb = round($compareRecord->response_time ?? 0, 2);
$baseDepth = $compareRecord->depth_max ?? 0;

// Build comparison rows
function comparisonDiff($ref, $base, $format = 'number', $invertColor = false) {
    $diff = $ref - $base;
    $pct = $base != 0 ? round((($ref - $base) / $base) * 100, 1) : ($ref != 0 ? 100 : 0);

    $sign = $diff > 0 ? '+' : '';
    $pctSign = $pct > 0 ? '+' : '';

    if ($format === 'ms') {
        $diffStr = $sign . round($diff, 2) . ' ms';
    } else {
        $diffStr = $sign . number_format($diff);
    }

    $pctStr = $pctSign . $pct . '%';

    // Determine color: green = positive change, red = negative
    // For TTFB, lower is better so we invert
    if ($diff == 0) {
        $color = 'var(--text-secondary)';
    } elseif ($invertColor) {
        $color = $diff < 0 ? '#27ae60' : '#e74c3c';
    } else {
        $color = $diff > 0 ? '#27ae60' : '#e74c3c';
    }

    return '<span style="color: ' . $color . '; font-weight: 600;">' . htmlspecialchars($pctStr) . '</span> <span style="color: var(--text-secondary);">(' . htmlspecialchars($diffStr) . ')</span>';
}

$kpiRows = [
    [
        'kpi' => __('home.card_total_urls'),
        'reference' => number_format($refUrls),
        'baseline' => number_format($baseUrls),
        'diff' => comparisonDiff($refUrls, $baseUrls),
    ],
    [
        'kpi' => __('home.card_crawled'),
        'reference' => number_format($refCrawled),
        'baseline' => number_format($baseCrawled),
        'diff' => comparisonDiff($refCrawled, $baseCrawled),
    ],
    [
        'kpi' => __('home.card_compliant'),
        'reference' => number_format($refCompliant),
        'baseline' => number_format($baseCompliant),
        'diff' => comparisonDiff($refCompliant, $baseCompliant),
    ],
    [
        'kpi' => __('home.card_ttfb'),
        'reference' => $refTtfb . ' ms',
        'baseline' => $baseTtfb . ' ms',
        'diff' => comparisonDiff($refTtfb, $baseTtfb, 'ms', true),
    ],
    [
        'kpi' => __('home.card_depth'),
        'reference' => $refDepth,
        'baseline' => $baseDepth,
        'diff' => comparisonDiff($refDepth, $baseDepth),
    ],
];
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
        'categories' => $depthLabels,
        'series' => [
            [
                'name' => __('comparison.badge_reference'),
                'data' => $refDepthData,
                'color' => '#4ECDC4'
            ],
            [
                'name' => __('comparison.badge_baseline'),
                'data' => $baseDepthData,
                'color' => '#e67e22'
            ]
        ],
        'yAxisTitle' => __('depth.label_url_count'),
        'height' => 400,
        'sqlQuery' => "SELECT
    COALESCE(r.depth, b.depth) AS depth,
    COALESCE(r.total, 0) AS reference,
    COALESCE(b.total, 0) AS baseline
FROM (
    SELECT depth, COUNT(*) AS total FROM pages@{$safeCrawlId}
    WHERE crawled = true AND compliant = true AND is_html = true
    GROUP BY depth
) r
FULL OUTER JOIN (
    SELECT depth, COUNT(*) AS total FROM pages@{$safeCompareId}
    WHERE crawled = true AND compliant = true AND is_html = true
    GROUP BY depth
) b ON r.depth = b.depth
ORDER BY depth"
    ]);

    Component::chart([
        'type' => 'bar',
        'title' => __('depth.chart_category_title'),
        'subtitle' => __('comparison.subtitle_depth_category'),
        'categories' => $depthCatLabels,
        'series' => $depthCatSeries,
        'stacking' => 'normal',
        'yAxisTitle' => __('depth.label_url_count'),
        'height' => 400,
        'sqlQuery' => $sqlDepthCatDisplay
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
    Component::simpleTable([
        'title' => __('sidebar.overview'),
        'maxLines' => 0,
        'columns' => [
            ['key' => 'kpi', 'label' => 'KPI', 'type' => 'bold'],
            ['key' => 'reference', 'label' => __('comparison.badge_reference'), 'labelHtml' => '<span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:7px;height:7px;border-radius:50%;background:var(--primary-color);"></span>' . htmlspecialchars(__('comparison.badge_reference')) . '</span>', 'type' => 'default'],
            ['key' => 'baseline', 'label' => __('comparison.badge_baseline'), 'labelHtml' => '<span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:7px;height:7px;border-radius:50%;background:#e67e22;"></span>' . htmlspecialchars(__('comparison.badge_baseline')) . '</span>', 'type' => 'default'],
            ['key' => 'diff', 'label' => __('comparison.col_difference'), 'type' => 'html'],
        ],
        'data' => $kpiRows,
    ]);
    ?>

</div>
