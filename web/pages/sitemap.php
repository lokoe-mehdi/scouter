<?php
/**
 * ============================================================================
 * PAGE SITEMAP — Compare crawl coverage with the sitemap(s)
 * ============================================================================
 * Requires:
 *  - $pdo, $crawlId, $crawlRecord (set by dashboard.php)
 *  - in_sitemap / in_crawl columns on pages (migration 2026-05-16-12-00-sitemap-columns.php)
 */

// ============================================================================
// GUARD — no sitemap configured for this crawl
// ============================================================================
$configData = is_string($crawlRecord->config)
    ? json_decode($crawlRecord->config, true)
    : (array)$crawlRecord->config;

$sitemapUrls = $configData['advanced']['sitemap_urls'] ?? [];
if (!is_array($sitemapUrls)) {
    $sitemapUrls = [$sitemapUrls];
}
$sitemapUrls = array_values(array_filter(array_map('trim', $sitemapUrls)));

if (empty($sitemapUrls)) {
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
// DATA — distribution + indexability of sitemap URLs
// ============================================================================
// Chart 1 ("coverage") counts ONLY indexable URLs — a non-indexable URL that's
// in the crawl but not the sitemap is not actionable and would noise the chart.
// Totals (scorecards) keep the raw counts for context.
$sqlSitemapDistribution = "
    SELECT
        SUM(CASE WHEN in_crawl = TRUE  AND in_sitemap = FALSE AND compliant = TRUE THEN 1 ELSE 0 END) AS crawl_only,
        SUM(CASE WHEN in_crawl = TRUE  AND in_sitemap = TRUE  AND compliant = TRUE THEN 1 ELSE 0 END) AS both,
        SUM(CASE WHEN in_crawl = FALSE AND in_sitemap = TRUE  AND compliant = TRUE THEN 1 ELSE 0 END) AS sitemap_only,
        SUM(CASE WHEN in_crawl = TRUE  THEN 1 ELSE 0 END)                                              AS total_crawl,
        SUM(CASE WHEN in_sitemap = TRUE THEN 1 ELSE 0 END)                                             AS total_sitemap
    FROM pages
    WHERE crawl_id = :cid
";
$distRows = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'sitemap_distribution', $pdo, $sqlSitemapDistribution, [':cid' => $crawlId], false
);
$dist = $distRows[0] ?? null;

$sqlSitemapIndexability = "
    SELECT
        SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END)                                              AS indexable,
        SUM(CASE WHEN compliant = false AND canonical = false                                THEN 1 ELSE 0 END) AS non_canonical,
        SUM(CASE WHEN compliant = false AND canonical = true  AND noindex = true             THEN 1 ELSE 0 END) AS noindex,
        SUM(CASE WHEN compliant = false AND canonical = true  AND noindex = false AND (code <> 200 OR code IS NULL) THEN 1 ELSE 0 END) AS bad_status
    FROM pages
    WHERE crawl_id = :cid AND in_sitemap = TRUE
";
$sitemapIdxRows = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'sitemap_indexability', $pdo, $sqlSitemapIndexability, [':cid' => $crawlId], false
);
$sitemapIdx = $sitemapIdxRows[0] ?? null;

$crawlOnly    = (int)($dist->crawl_only    ?? 0);
$both         = (int)($dist->both          ?? 0);
$sitemapOnly  = (int)($dist->sitemap_only  ?? 0);
$totalCrawl   = (int)($dist->total_crawl   ?? 0);
$totalSitemap = (int)($dist->total_sitemap ?? 0);

$sqlCrawlIndexableMissing = "
    SELECT COUNT(*) AS cnt FROM pages
    WHERE crawl_id = :cid AND in_crawl = TRUE AND compliant = TRUE AND in_sitemap = FALSE
";
$crawlIndexableMissingRows = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'sitemap_crawl_only_indexable', $pdo, $sqlCrawlIndexableMissing, [':cid' => $crawlId], false
);
$crawlOnlyIndexable = (int)($crawlIndexableMissingRows[0]->cnt ?? 0);

?>

<h1 class="page-title"><?= __('sitemap.page_title') ?></h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1 — Scorecards
         ======================================== -->
    <div class="scorecards-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem;">
        <?php
        Component::card([
            'color' => 'primary',
            'icon'  => 'language',
            'title' => __('sitemap.scorecard_total_crawl'),
            'value' => number_format($totalCrawl),
            'desc'  => __('sitemap.scorecard_total_crawl_desc'),
        ]);
        Component::card([
            'color' => 'info',
            'icon'  => 'map',
            'title' => __('sitemap.scorecard_total_sitemap'),
            'value' => number_format($totalSitemap),
            'desc'  => __('sitemap.scorecard_total_sitemap_desc'),
        ]);
        Component::card([
            'color' => 'success',
            'icon'  => 'join_inner',
            'title' => __('sitemap.scorecard_intersection'),
            'value' => number_format($both),
            'desc'  => __('sitemap.scorecard_intersection_desc'),
        ]);
        Component::card([
            'color' => 'warning',
            'icon'  => 'visibility_off',
            'title' => __('sitemap.scorecard_sitemap_only'),
            'value' => number_format($sitemapOnly),
            'desc'  => __('sitemap.scorecard_sitemap_only_desc'),
        ]);
        Component::card([
            'color' => 'danger',
            'icon'  => 'rule',
            'title' => __('sitemap.scorecard_crawl_only_indexable'),
            'value' => number_format($crawlOnlyIndexable),
            'desc'  => __('sitemap.scorecard_crawl_only_indexable_desc'),
        ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 2 — Charts
         ======================================== -->
    <div class="charts-grid">
    <?php
    // Chart 1: distribution between crawl, sitemap, both
    Component::chart([
        'type'     => 'donut',
        'title'    => __('sitemap.chart_distribution_title'),
        'subtitle' => __('sitemap.chart_distribution_desc'),
        'series'   => [
            [
                'name' => 'URLs',
                'data' => [
                    ['name' => __('sitemap.chart_distribution_both'),         'y' => $both,        'color' => '#6bd899ff'],
                    ['name' => __('sitemap.chart_distribution_crawl_only'),   'y' => $crawlOnly,   'color' => '#cfd86bff'],
                    ['name' => __('sitemap.chart_distribution_sitemap_only'), 'y' => $sitemapOnly, 'color' => '#d8bf6bff'],
                ],
            ],
        ],
        'height' => 350,
        'sqlQuery' => $sqlSitemapDistribution,
    ]);

    // Chart 2: indexability of sitemap URLs (same 4 buckets and colors as accessibility.chart_indexability)
    Component::chart([
        'type'     => 'donut',
        'title'    => __('sitemap.chart_indexability_title'),
        'subtitle' => __('sitemap.chart_indexability_desc'),
        'series'   => [
            [
                'name' => 'URLs',
                'data' => [
                    ['name' => __('accessibility.series_indexable'),      'y' => (int)($sitemapIdx->indexable     ?? 0), 'color' => '#6bd899ff'],
                    ['name' => __('accessibility.series_non_canonical'),  'y' => (int)($sitemapIdx->non_canonical ?? 0), 'color' => '#cfd86bff'],
                    ['name' => __('accessibility.series_noindex'),        'y' => (int)($sitemapIdx->noindex       ?? 0), 'color' => '#d8bf6bff'],
                    ['name' => __('accessibility.series_http_not_200'),   'y' => (int)($sitemapIdx->bad_status    ?? 0), 'color' => '#d86b6bff'],
                ],
            ],
        ],
        'height' => 350,
        'sqlQuery' => $sqlSitemapIndexability,
    ]);
    ?>
    </div>

    <!-- ========================================
         SECTION 3 — Crawl-indexable URLs missing from sitemap
         ======================================== -->
    <?php
    Component::urlTable([
        'title'          => __('sitemap.table_missing_from_sitemap_title'),
        'id'             => 'sitemap_missing_table',
        'whereClause'    => 'WHERE c.in_crawl = TRUE AND c.compliant = TRUE AND c.in_sitemap = FALSE',
        'orderBy'        => 'ORDER BY c.url ASC',
        'defaultColumns' => ['url', 'code', 'depth', 'compliant'],
        'pdo'            => $pdo,
        'crawlId'        => $crawlId,
        'perPage'        => 10,
        'projectDir'     => $_GET['project'] ?? '',
    ]);
    ?>

    <!-- ========================================
         SECTION 4 — Sitemap URLs that are not indexable
         ======================================== -->
    <?php
    Component::urlTable([
        'title'          => __('sitemap.table_non_indexable_in_sitemap_title'),
        'id'             => 'sitemap_non_indexable_table',
        'whereClause'    => 'WHERE c.in_sitemap = TRUE AND c.compliant = FALSE',
        'orderBy'        => 'ORDER BY c.code DESC, c.url ASC',
        'defaultColumns' => ['url', 'code', 'noindex', 'canonical', 'blocked', 'redirect_to'],
        'pdo'            => $pdo,
        'crawlId'        => $crawlId,
        'perPage'        => 10,
        'projectDir'     => $_GET['project'] ?? '',
    ]);
    ?>

</div>
