<?php
/**
 * Crawl Comparison — Sitemap
 *
 * Compares sitemap coverage and the indexability of sitemap URLs between
 * the reference crawl and the baseline crawl.
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
$safeCrawlId   = intval($crawlId);

// ============================================================================
// GUARD — at least one of the two crawls must have a sitemap configured
// ============================================================================
$refConfig = is_string($crawlRecord->config)
    ? json_decode($crawlRecord->config, true)
    : (array)$crawlRecord->config;
$refSitemapUrls = $refConfig['advanced']['sitemap_urls'] ?? [];
if (!is_array($refSitemapUrls)) $refSitemapUrls = [$refSitemapUrls];
$refSitemapUrls = array_values(array_filter(array_map('trim', $refSitemapUrls)));

$baseConfig = isset($compareRecord) && $compareRecord
    ? (is_string($compareRecord->config) ? json_decode($compareRecord->config, true) : (array)$compareRecord->config)
    : [];
$baseSitemapUrls = $baseConfig['advanced']['sitemap_urls'] ?? [];
if (!is_array($baseSitemapUrls)) $baseSitemapUrls = [$baseSitemapUrls];
$baseSitemapUrls = array_values(array_filter(array_map('trim', $baseSitemapUrls)));

if (empty($refSitemapUrls) && empty($baseSitemapUrls)) {
    ?>
    <h1 class="page-title"><?= __('sitemap.page_title') ?></h1>
    <div style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <span class="material-symbols-outlined" style="font-size: 4rem; color: var(--text-secondary); opacity: 0.5;">map</span>
        <h2 style="margin: 1rem 0; color: var(--text-primary);"><?= __('sitemap.no_sitemap_configured_title') ?></h2>
        <p style="color: var(--text-secondary);"><?= __('sitemap.no_sitemap_configured_desc') ?></p>
    </div>
    <?php
    return;
}

// ============================================================================
// HELPER — hex to rgba
// ============================================================================
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

// ============================================================================
// DATA — sitemap distribution (only indexable URLs, like the sitemap view)
// ============================================================================
$sqlSitemapDistribution = "
    SELECT
        SUM(CASE WHEN in_crawl = TRUE  AND in_sitemap = FALSE AND compliant = TRUE THEN 1 ELSE 0 END) AS crawl_only,
        SUM(CASE WHEN in_crawl = TRUE  AND in_sitemap = TRUE  AND compliant = TRUE THEN 1 ELSE 0 END) AS both,
        SUM(CASE WHEN in_crawl = FALSE AND in_sitemap = TRUE  AND compliant = TRUE THEN 1 ELSE 0 END) AS sitemap_only
    FROM pages
    WHERE crawl_id = :cid
";

$stmtRef = $pdo->prepare($sqlSitemapDistribution);
$stmtRef->execute([':cid' => $safeCrawlId]);
$distRef = $stmtRef->fetch(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlSitemapDistribution);
$stmtBase->execute([':cid' => $safeCompareId]);
$distBase = $stmtBase->fetch(PDO::FETCH_OBJ);

$sqlSitemapDistributionDisplay = "-- Reference crawl
SELECT 'reference' AS source,
       SUM(CASE WHEN in_crawl = TRUE  AND in_sitemap = FALSE AND compliant = TRUE THEN 1 ELSE 0 END) AS crawl_only,
       SUM(CASE WHEN in_crawl = TRUE  AND in_sitemap = TRUE  AND compliant = TRUE THEN 1 ELSE 0 END) AS both,
       SUM(CASE WHEN in_crawl = FALSE AND in_sitemap = TRUE  AND compliant = TRUE THEN 1 ELSE 0 END) AS sitemap_only
FROM pages@{$safeCrawlId}
UNION ALL
-- Baseline crawl
SELECT 'baseline' AS source,
       SUM(CASE WHEN in_crawl = TRUE  AND in_sitemap = FALSE AND compliant = TRUE THEN 1 ELSE 0 END) AS crawl_only,
       SUM(CASE WHEN in_crawl = TRUE  AND in_sitemap = TRUE  AND compliant = TRUE THEN 1 ELSE 0 END) AS both,
       SUM(CASE WHEN in_crawl = FALSE AND in_sitemap = TRUE  AND compliant = TRUE THEN 1 ELSE 0 END) AS sitemap_only
FROM pages@{$safeCompareId}";

$coverageRefData = [
    ['name' => __('sitemap.chart_distribution_both') . ' (' . __('comparison.badge_reference') . ')',         'y' => (int)($distRef->both         ?? 0), 'color' => '#6bd899'],
    ['name' => __('sitemap.chart_distribution_crawl_only') . ' (' . __('comparison.badge_reference') . ')',   'y' => (int)($distRef->crawl_only   ?? 0), 'color' => '#cfd86b'],
    ['name' => __('sitemap.chart_distribution_sitemap_only') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($distRef->sitemap_only ?? 0), 'color' => '#d8bf6b'],
];
$coverageBaseData = [
    ['name' => __('sitemap.chart_distribution_both') . ' (' . __('comparison.badge_baseline') . ')',         'y' => (int)($distBase->both         ?? 0), 'color' => hexToRgba('#6bd899', 0.5)],
    ['name' => __('sitemap.chart_distribution_crawl_only') . ' (' . __('comparison.badge_baseline') . ')',   'y' => (int)($distBase->crawl_only   ?? 0), 'color' => hexToRgba('#cfd86b', 0.5)],
    ['name' => __('sitemap.chart_distribution_sitemap_only') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($distBase->sitemap_only ?? 0), 'color' => hexToRgba('#d8bf6b', 0.5)],
];

// ============================================================================
// DATA — indexability of URLs declared in the sitemap
// ============================================================================
$sqlSitemapIndexability = "
    SELECT
        SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END)                                                                                  AS indexable,
        SUM(CASE WHEN compliant = false AND canonical = false                                                                  THEN 1 ELSE 0 END) AS non_canonical,
        SUM(CASE WHEN compliant = false AND canonical = true  AND noindex = true                                               THEN 1 ELSE 0 END) AS noindex,
        SUM(CASE WHEN compliant = false AND canonical = true  AND noindex = false AND (code <> 200 OR code IS NULL)            THEN 1 ELSE 0 END) AS bad_status
    FROM pages
    WHERE crawl_id = :cid AND in_sitemap = TRUE
";

$stmtRef = $pdo->prepare($sqlSitemapIndexability);
$stmtRef->execute([':cid' => $safeCrawlId]);
$idxRef = $stmtRef->fetch(PDO::FETCH_OBJ);

$stmtBase = $pdo->prepare($sqlSitemapIndexability);
$stmtBase->execute([':cid' => $safeCompareId]);
$idxBase = $stmtBase->fetch(PDO::FETCH_OBJ);

$sqlSitemapIndexabilityDisplay = "-- Reference crawl
SELECT 'reference' AS source,
       SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) AS indexable,
       SUM(CASE WHEN compliant = false AND canonical = false THEN 1 ELSE 0 END) AS non_canonical,
       SUM(CASE WHEN compliant = false AND canonical = true  AND noindex = true THEN 1 ELSE 0 END) AS noindex,
       SUM(CASE WHEN compliant = false AND canonical = true  AND noindex = false AND (code <> 200 OR code IS NULL) THEN 1 ELSE 0 END) AS bad_status
FROM pages@{$safeCrawlId} WHERE in_sitemap = TRUE
UNION ALL
-- Baseline crawl
SELECT 'baseline' AS source,
       SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) AS indexable,
       SUM(CASE WHEN compliant = false AND canonical = false THEN 1 ELSE 0 END) AS non_canonical,
       SUM(CASE WHEN compliant = false AND canonical = true  AND noindex = true THEN 1 ELSE 0 END) AS noindex,
       SUM(CASE WHEN compliant = false AND canonical = true  AND noindex = false AND (code <> 200 OR code IS NULL) THEN 1 ELSE 0 END) AS bad_status
FROM pages@{$safeCompareId} WHERE in_sitemap = TRUE";

$indexabilityRefData = [
    ['name' => __('accessibility.series_indexable') . ' (' . __('comparison.badge_reference') . ')',     'y' => (int)($idxRef->indexable     ?? 0), 'color' => '#6bd899'],
    ['name' => __('accessibility.series_non_canonical') . ' (' . __('comparison.badge_reference') . ')', 'y' => (int)($idxRef->non_canonical ?? 0), 'color' => '#cfd86b'],
    ['name' => __('accessibility.series_noindex') . ' (' . __('comparison.badge_reference') . ')',       'y' => (int)($idxRef->noindex       ?? 0), 'color' => '#d8bf6b'],
    ['name' => __('accessibility.series_http_not_200') . ' (' . __('comparison.badge_reference') . ')',  'y' => (int)($idxRef->bad_status    ?? 0), 'color' => '#d86b6b'],
];
$indexabilityBaseData = [
    ['name' => __('accessibility.series_indexable') . ' (' . __('comparison.badge_baseline') . ')',     'y' => (int)($idxBase->indexable     ?? 0), 'color' => hexToRgba('#6bd899', 0.5)],
    ['name' => __('accessibility.series_non_canonical') . ' (' . __('comparison.badge_baseline') . ')', 'y' => (int)($idxBase->non_canonical ?? 0), 'color' => hexToRgba('#cfd86b', 0.5)],
    ['name' => __('accessibility.series_noindex') . ' (' . __('comparison.badge_baseline') . ')',       'y' => (int)($idxBase->noindex       ?? 0), 'color' => hexToRgba('#d8bf6b', 0.5)],
    ['name' => __('accessibility.series_http_not_200') . ' (' . __('comparison.badge_baseline') . ')',  'y' => (int)($idxBase->bad_status    ?? 0), 'color' => hexToRgba('#d86b6b', 0.5)],
];

?>

<?php include __DIR__ . '/../components/comparison-bar.php'; ?>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- Scorecards: shared new/lost/common counts (computed in dashboard.php) -->
    <div class="scorecards">
    <?php
    Component::card([
        'color' => 'success',
        'icon'  => 'add_circle',
        'title' => __('comparison.card_new'),
        'value' => number_format($compNewCount),
    ]);
    Component::card([
        'color' => 'error',
        'icon'  => 'remove_circle',
        'title' => __('comparison.card_lost'),
        'value' => number_format($compLostCount),
    ]);
    Component::card([
        'color' => 'info',
        'icon'  => 'sync',
        'title' => __('comparison.card_common'),
        'value' => number_format($compCommonCount),
    ]);
    ?>
    </div>

    <!-- Charts: sitemap coverage + sitemap-URL indexability, both as double-ring donuts -->
    <div class="charts-grid">
    <?php
    Component::chart([
        'type'     => 'donut',
        'title'    => __('sitemap.chart_distribution_title'),
        'subtitle' => __('comparison.subtitle_sitemap_coverage'),
        'series'   => [
            ['name' => __('comparison.badge_reference'), 'data' => $coverageRefData],
            ['name' => __('comparison.badge_baseline'),  'data' => $coverageBaseData],
        ],
        'height'         => 350,
        'legendPosition' => 'bottom',
        'sqlQuery'       => $sqlSitemapDistributionDisplay,
    ]);

    Component::chart([
        'type'     => 'donut',
        'title'    => __('sitemap.chart_indexability_title'),
        'subtitle' => __('comparison.subtitle_sitemap_indexability'),
        'series'   => [
            ['name' => __('comparison.badge_reference'), 'data' => $indexabilityRefData],
            ['name' => __('comparison.badge_baseline'),  'data' => $indexabilityBaseData],
        ],
        'height'         => 350,
        'legendPosition' => 'bottom',
        'sqlQuery'       => $sqlSitemapIndexabilityDisplay,
    ]);
    ?>
    </div>

    <!-- Table: URLs that entered the sitemap (in current crawl but not in baseline) -->
    <?php
    Component::urlTable([
        'title'          => __('comparison.sitemap_urls_added_title'),
        'id'             => 'sitemap_added_table',
        'whereClause'    => "WHERE c.in_sitemap = TRUE AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.in_sitemap = FALSE
        )",
        'orderBy'        => 'ORDER BY c.url ASC',
        'defaultColumns' => ['url', 'code', 'compliant', 'depth'],
        'compareCrawlId' => $safeCompareId,
        'pdo'            => $pdo,
        'crawlId'        => $safeCrawlId,
        'perPage'        => 10,
        'projectDir'     => $_GET['project'] ?? '',
    ]);
    ?>

    <!-- Table: URLs that left the sitemap (in baseline but not in current) -->
    <?php
    Component::urlTable([
        'title'          => __('comparison.sitemap_urls_removed_title'),
        'id'             => 'sitemap_removed_table',
        'whereClause'    => "WHERE c.in_sitemap = FALSE AND EXISTS (
            SELECT 1 FROM pages_{$safeCompareId} b
            WHERE b.url = c.url AND b.in_sitemap = TRUE
        )",
        'orderBy'        => 'ORDER BY c.url ASC',
        'defaultColumns' => ['url', 'code', 'compliant', 'depth'],
        'compareCrawlId' => $safeCompareId,
        'pdo'            => $pdo,
        'crawlId'        => $safeCrawlId,
        'perPage'        => 10,
        'projectDir'     => $_GET['project'] ?? '',
    ]);
    ?>

</div>
