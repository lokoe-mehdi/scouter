<?php
/**
 * Crawl Comparison - PageRank Leak
 *
 * Compares PageRank distribution (indexable vs non-indexable vs external)
 * and top external domains between two crawls.
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
// PR distribution for both crawls
// =========================================
$sqlPrDist = "
    SELECT
        COALESCE(SUM(CASE WHEN external = true THEN pri ELSE 0 END), 0) as external_pr,
        COALESCE(SUM(CASE WHEN external = false AND compliant = false THEN pri ELSE 0 END), 0) as non_indexable_pr,
        COALESCE(SUM(CASE WHEN external = false AND compliant = true THEN pri ELSE 0 END), 0) as indexable_pr
    FROM pages
    WHERE crawl_id = :crawl_id AND (crawled = true OR external = true)
";

$stmtRef = $pdo->prepare($sqlPrDist);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$prRef = $stmtRef->fetch(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlPrDist);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$prBase = $stmtBase->fetch(PDO::FETCH_OBJ);

// Compute percentages
$refTotal = (float)$prRef->external_pr + (float)$prRef->non_indexable_pr + (float)$prRef->indexable_pr;
$baseTotal = (float)$prBase->external_pr + (float)$prBase->non_indexable_pr + (float)$prBase->indexable_pr;

$refIndexablePct = $refTotal > 0 ? round(((float)$prRef->indexable_pr / $refTotal) * 100, 1) : 0;
$refNonIndexablePct = $refTotal > 0 ? round(((float)$prRef->non_indexable_pr / $refTotal) * 100, 1) : 0;
$refExternalPct = $refTotal > 0 ? round(((float)$prRef->external_pr / $refTotal) * 100, 1) : 0;

$baseIndexablePct = $baseTotal > 0 ? round(((float)$prBase->indexable_pr / $baseTotal) * 100, 1) : 0;
$baseNonIndexablePct = $baseTotal > 0 ? round(((float)$prBase->non_indexable_pr / $baseTotal) * 100, 1) : 0;
$baseExternalPct = $baseTotal > 0 ? round(((float)$prBase->external_pr / $baseTotal) * 100, 1) : 0;

// Donut data
$donutRefData = [
    ['name' => __('pagerank_leak.series_indexable') . ' (' . __('comparison.badge_reference') . ')', 'y' => $refIndexablePct, 'color' => '#6bd899'],
    ['name' => __('pagerank_leak.series_non_indexable') . ' (' . __('comparison.badge_reference') . ')', 'y' => $refNonIndexablePct, 'color' => '#d8bf6b'],
    ['name' => __('pagerank_leak.series_external') . ' (' . __('comparison.badge_reference') . ')', 'y' => $refExternalPct, 'color' => '#d86b6b'],
];
$donutBaseData = [
    ['name' => __('pagerank_leak.series_indexable') . ' (' . __('comparison.badge_baseline') . ')', 'y' => $baseIndexablePct, 'color' => hexToRgba('#6bd899', 0.5)],
    ['name' => __('pagerank_leak.series_non_indexable') . ' (' . __('comparison.badge_baseline') . ')', 'y' => $baseNonIndexablePct, 'color' => hexToRgba('#d8bf6b', 0.5)],
    ['name' => __('pagerank_leak.series_external') . ' (' . __('comparison.badge_baseline') . ')', 'y' => $baseExternalPct, 'color' => hexToRgba('#d86b6b', 0.5)],
];

$sqlDistDisplay = "SELECT
    SUM(CASE WHEN external = true THEN pri ELSE 0 END) AS external_pr,
    SUM(CASE WHEN external = false AND compliant = false THEN pri ELSE 0 END) AS non_indexable_pr,
    SUM(CASE WHEN external = false AND compliant = true THEN pri ELSE 0 END) AS indexable_pr
FROM pages
WHERE (crawled = true OR external = true)";

// =========================================
// Top 10 external domains for both crawls
// =========================================
$sqlTopDomains = "
    SELECT
        COALESCE(SUBSTRING(url FROM '://([^/]+)'), SUBSTRING(url FROM '^([^/]+)')) as domain,
        COUNT(*) as url_count,
        COALESCE(SUM(pri), 0) as total_pr
    FROM pages
    WHERE crawl_id = :crawl_id AND external = true
    GROUP BY COALESCE(SUBSTRING(url FROM '://([^/]+)'), SUBSTRING(url FROM '^([^/]+)'))
    ORDER BY total_pr DESC
    LIMIT 10
";

$stmtRef = $pdo->prepare($sqlTopDomains);
$stmtRef->execute([':crawl_id' => $safeCrawlId]);
$topDomainsRef = $stmtRef->fetchAll(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlTopDomains);
$stmtBase->execute([':crawl_id' => $safeCompareId]);
$topDomainsBase = $stmtBase->fetchAll(PDO::FETCH_OBJ);

// Merge domain names
$refDomainMap = [];
foreach ($topDomainsRef as $d) $refDomainMap[$d->domain] = round((float)$d->total_pr * 100, 4);
$baseDomainMap = [];
foreach ($topDomainsBase as $d) $baseDomainMap[$d->domain] = round((float)$d->total_pr * 100, 4);

$allDomains = array_unique(array_merge(array_keys($refDomainMap), array_keys($baseDomainMap)));
// Sort by ref PR descending
usort($allDomains, function($a, $b) use ($refDomainMap) {
    return ($refDomainMap[$b] ?? 0) <=> ($refDomainMap[$a] ?? 0);
});
$allDomains = array_slice($allDomains, 0, 10);

$refDomainValues = [];
$baseDomainValues = [];
foreach ($allDomains as $domain) {
    $refDomainValues[] = $refDomainMap[$domain] ?? 0;
    $baseDomainValues[] = $baseDomainMap[$domain] ?? 0;
}

$sqlTopDomainsDisplay = "SELECT
    COALESCE(SUBSTRING(url FROM '://([^/]+)'), SUBSTRING(url FROM '^([^/]+)')) AS domain,
    COUNT(*) AS url_count,
    SUM(pri) AS total_pr
FROM pages
WHERE external = true
GROUP BY domain
ORDER BY total_pr DESC
LIMIT 10";

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

    <!-- Charts -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type' => 'donut',
        'title' => __('pagerank_leak.chart_distribution'),
        'subtitle' => __('pagerank_leak.chart_distribution_desc'),
        'series' => [
            ['name' => __('comparison.badge_reference'), 'data' => $donutRefData],
            ['name' => __('comparison.badge_baseline'), 'data' => $donutBaseData]
        ],
        'height' => 350,
        'legendPosition' => 'bottom',
        'sqlQuery' => $sqlDistDisplay
    ]);

    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('pagerank_leak.chart_top_domains'),
        'subtitle' => __('pagerank_leak.chart_top_domains_desc'),
        'categories' => $allDomains,
        'series' => [
            [
                'name' => __('pagerank.series_pagerank') . ' (' . __('comparison.badge_reference') . ')',
                'data' => $refDomainValues,
                'color' => '#d86b6b'
            ],
            [
                'name' => __('pagerank.series_pagerank') . ' (' . __('comparison.badge_baseline') . ')',
                'data' => $baseDomainValues,
                'color' => hexToRgba('#d86b6b', 0.5)
            ]
        ],
        'height' => max(250, count($allDomains) * 40),
        'sqlQuery' => $sqlTopDomainsDisplay
    ]);
    ?>
    </div>

    <?php
    Component::urlTable([
        'title' => __('comparison.pagerank_leak_regressions_table'),
        'id' => 'pr_leak_regressions_table',
        'whereClause' => "WHERE (c.external = true OR (c.crawled = true AND c.compliant = false)) AND c.pri > 0 AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.pri < c.pri
        )",
        'orderBy' => 'ORDER BY c.pri DESC',
        'defaultColumns' => ['url', 'category', 'pri', 'inlinks', 'compliant'],
        'compareCrawlId' => $safeCompareId,
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
