<?php
/**
 * ============================================================================
 * PAGE SITEMAP ANALYSIS - Cross-reference sitemap vs crawl
 * ============================================================================
 * Uses data computed in post-processing (PostProcessor::sitemapAnalysis).
 * Shows 4 segments: OK, not indexable, sitemap-only, crawl-only.
 */

// ============================================================================
// GUARD — Check if sitemap URLs were configured for this crawl
// ============================================================================
$configData = is_string($crawlRecord->config) ? json_decode($crawlRecord->config, true) : (array)$crawlRecord->config;
$sitemapUrls = $configData['general']['sitemap_urls'] ?? [];

if (empty($sitemapUrls)) {
    ?>
    <h1 class="page-title"><?= __('sitemap.page_title') ?></h1>
    <div style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <span class="material-symbols-outlined" style="font-size: 4rem; color: var(--text-secondary); opacity: 0.5;">map</span>
        <h2 style="margin: 1rem 0; color: var(--text-primary);"><?= __('sitemap.disabled_title') ?></h2>
        <p style="color: var(--text-secondary);"><?= __('sitemap.disabled_desc') ?></p>
    </div>
    <?php
    return;
}

// ============================================================================
// PRE-COMPUTED DATA — From crawls table
// ============================================================================
$sitemapTotal = (int)($globalStats->sitemap_total ?? 0);
$sitemapOnly = (int)($globalStats->sitemap_only ?? 0);
$crawlOnlyIndexable = (int)($globalStats->crawl_only_indexable ?? 0);
$sitemapNotIndexable = (int)($globalStats->sitemap_not_indexable ?? 0);

// In both sitemap AND crawl AND indexable
$sitemapInBothIndexable = $sitemapTotal - $sitemapOnly - $sitemapNotIndexable;
if ($sitemapInBothIndexable < 0) $sitemapInBothIndexable = 0;

// Coverage rate
$totalIndexable = $sitemapInBothIndexable + $crawlOnlyIndexable;
$coverageRate = $totalIndexable > 0 ? round(($sitemapInBothIndexable / $totalIndexable) * 100, 1) : 0;

// ============================================================================
// SEGMENT QUERIES — For the URL tables
// ============================================================================

// Segment 1: In sitemap + in crawl + indexable (OK)
$sqlInBoth = "
    SELECT su.url, su.source_sitemap, su.lastmod, su.priority, p.code, p.title, p.inlinks
    FROM sitemap_urls su
    JOIN pages p ON su.url = p.url AND p.crawl_id = :cid2
    WHERE su.crawl_id = :cid
      AND su.is_in_crawl = TRUE
      AND su.is_indexable = TRUE
    ORDER BY p.inlinks DESC
";

// Segment 2: In sitemap + in crawl + NOT indexable (problem)
$sqlNotIndexable = "
    SELECT su.url, su.source_sitemap, su.http_status, p.code, p.noindex, p.canonical, p.blocked, p.title
    FROM sitemap_urls su
    JOIN pages p ON su.url = p.url AND p.crawl_id = :cid2
    WHERE su.crawl_id = :cid
      AND su.is_in_crawl = TRUE
      AND su.is_indexable = FALSE
    ORDER BY su.url
";

// Segment 3: In sitemap but NOT in crawl (orphans)
$sqlSitemapOnly = "
    SELECT su.url, su.source_sitemap, su.lastmod, su.priority, su.changefreq
    FROM sitemap_urls su
    WHERE su.crawl_id = :cid
      AND su.is_in_crawl = FALSE
    ORDER BY su.url
";

// Segment 4: In crawl (indexable) but NOT in sitemap (missing from sitemap)
$sqlCrawlOnly = "
    SELECT p.url, p.code, p.title, p.inlinks, p.depth
    FROM pages p
    WHERE p.crawl_id = :cid
      AND p.compliant = TRUE
      AND p.is_in_sitemap = FALSE
      AND p.external = FALSE
    ORDER BY p.inlinks DESC
";

// Hreflang stats
$sqlHreflang = "
    SELECT COUNT(*) as total,
           COUNT(CASE WHEN hreflang IS NOT NULL AND hreflang != 'null' AND hreflang != '{}' THEN 1 END) as with_hreflang
    FROM sitemap_urls
    WHERE crawl_id = :cid
";
$stmtHl = $pdo->prepare($sqlHreflang);
$stmtHl->execute([':cid' => $crawlId]);
$hreflangStats = $stmtHl->fetch(PDO::FETCH_OBJ);
$hreflangCount = (int)($hreflangStats->with_hreflang ?? 0);

// Source sitemaps list
$sqlSources = "
    SELECT source_sitemap, COUNT(*) as url_count
    FROM sitemap_urls
    WHERE crawl_id = :cid AND source_sitemap IS NOT NULL
    GROUP BY source_sitemap
    ORDER BY url_count DESC
";
$stmtSrc = $pdo->prepare($sqlSources);
$stmtSrc->execute([':cid' => $crawlId]);
$sitemapSources = $stmtSrc->fetchAll(PDO::FETCH_OBJ);

/**
 * ============================================================================
 * HTML OUTPUT
 * ============================================================================
 */
?>

<h1 class="page-title"><?= __('sitemap.page_title') ?></h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1: Scorecards
         ======================================== -->
    <div class="scorecards">
        <?php
        Component::card([
            'color' => 'primary',
            'icon' => 'map',
            'title' => __('sitemap.card_total'),
            'value' => number_format($sitemapTotal),
            'desc' => __('sitemap.card_total_desc')
        ]);

        Component::card([
            'color' => 'success',
            'icon' => 'check_circle',
            'title' => __('sitemap.card_in_both'),
            'value' => number_format($sitemapInBothIndexable),
            'desc' => $coverageRate . '% ' . __('sitemap.card_coverage')
        ]);

        Component::card([
            'color' => 'danger',
            'icon' => 'block',
            'title' => __('sitemap.card_not_indexable'),
            'value' => number_format($sitemapNotIndexable),
            'desc' => __('sitemap.card_not_indexable_desc')
        ]);

        Component::card([
            'color' => 'warning',
            'icon' => 'link_off',
            'title' => __('sitemap.card_sitemap_only'),
            'value' => number_format($sitemapOnly),
            'desc' => __('sitemap.card_sitemap_only_desc')
        ]);

        Component::card([
            'color' => 'info',
            'icon' => 'playlist_add',
            'title' => __('sitemap.card_crawl_only'),
            'value' => number_format($crawlOnlyIndexable),
            'desc' => __('sitemap.card_crawl_only_desc')
        ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 2: Donut chart — Sitemap coverage
         ======================================== -->
    <div class="charts-grid" style="grid-template-columns: repeat(2, 1fr);">
        <?php
        Component::chart([
            'type' => 'donut',
            'title' => __('sitemap.chart_coverage'),
            'subtitle' => __('sitemap.chart_coverage_desc'),
            'series' => [
                [
                    'name' => 'URLs',
                    'data' => [
                        ['name' => __('sitemap.label_in_both'), 'y' => $sitemapInBothIndexable, 'color' => '#6bd899'],
                        ['name' => __('sitemap.label_not_indexable'), 'y' => $sitemapNotIndexable, 'color' => '#d86b6b'],
                        ['name' => __('sitemap.label_sitemap_only'), 'y' => $sitemapOnly, 'color' => '#d8bf6b'],
                        ['name' => __('sitemap.label_crawl_only'), 'y' => $crawlOnlyIndexable, 'color' => '#6bb8d8'],
                    ]
                ]
            ],
            'height' => 300,
            'legendPosition' => 'bottom',
        ]);
        ?>

        <!-- Source sitemaps info -->
        <div class="chart-card" style="padding: 1.25rem;">
            <h3 class="chart-title"><?= __('sitemap.source_sitemaps') ?></h3>
            <p class="chart-subtitle"><?= __('sitemap.source_sitemaps_desc') ?></p>
            <div style="margin-top: 1rem; max-height: 220px; overflow-y: auto;">
                <table style="width: 100%; font-size: 0.85rem; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                            <th style="padding: 0.4rem 0.5rem; color: var(--text-secondary);"><?= __('sitemap.col_source') ?></th>
                            <th style="padding: 0.4rem 0.5rem; color: var(--text-secondary); text-align: right;">URLs</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sitemapSources as $src): ?>
                        <tr style="border-bottom: 1px solid var(--border-color-light);">
                            <td style="padding: 0.4rem 0.5rem; color: var(--text-primary); word-break: break-all; font-size: 0.8rem;"><?= htmlspecialchars($src->source_sitemap) ?></td>
                            <td style="padding: 0.4rem 0.5rem; text-align: right; color: var(--text-primary); font-weight: 500;"><?= number_format($src->url_count) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($hreflangCount > 0): ?>
                        <tr style="border-top: 2px solid var(--border-color);">
                            <td style="padding: 0.4rem 0.5rem; color: var(--text-secondary);">
                                <span class="material-symbols-outlined" style="font-size: 14px; vertical-align: middle;">translate</span>
                                <?= __('sitemap.hreflang_urls') ?>
                            </td>
                            <td style="padding: 0.4rem 0.5rem; text-align: right; color: var(--text-primary); font-weight: 500;"><?= number_format($hreflangCount) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================
         SECTION 3: In sitemap + NOT indexable (problems to fix)
         ======================================== -->
    <?php if ($sitemapNotIndexable > 0): ?>
    <?php
    Component::urlTable([
        'title' => __('sitemap.table_not_indexable'),
        'id' => 'sitemap_not_indexable',
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 100,
        'defaultColumns' => ['url', 'code', 'noindex', 'canonical', 'blocked'],
        'whereClause' => 'WHERE c.crawl_id = :crawl_id AND c.url IN (SELECT url FROM sitemap_urls WHERE crawl_id = :crawl_id2 AND is_in_crawl = TRUE AND is_indexable = FALSE)',
        'sqlParams' => [':crawl_id' => $crawlId, ':crawl_id2' => $crawlId],
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>
    <?php endif; ?>

    <!-- ========================================
         SECTION 4: In sitemap only (not found in crawl)
         ======================================== -->
    <?php if ($sitemapOnly > 0): ?>
    <div class="chart-card" style="padding: 1.25rem;">
        <h3 class="chart-title"><?= __('sitemap.table_sitemap_only') ?></h3>
        <p class="chart-subtitle"><?= __('sitemap.table_sitemap_only_desc') ?></p>
        <div style="margin-top: 1rem; max-height: 400px; overflow-y: auto;">
            <table style="width: 100%; font-size: 0.85rem; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                        <th style="padding: 0.5rem; color: var(--text-secondary);">URL</th>
                        <th style="padding: 0.5rem; color: var(--text-secondary);"><?= __('sitemap.col_source') ?></th>
                        <th style="padding: 0.5rem; color: var(--text-secondary);">Lastmod</th>
                        <th style="padding: 0.5rem; color: var(--text-secondary); text-align: right;"><?= __('sitemap.col_priority') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmtSO = $pdo->prepare($sqlSitemapOnly . " LIMIT 200");
                    $stmtSO->execute([':cid' => $crawlId]);
                    while ($row = $stmtSO->fetch(PDO::FETCH_OBJ)):
                    ?>
                    <tr style="border-bottom: 1px solid var(--border-color-light);">
                        <td style="padding: 0.5rem; color: var(--primary-color); word-break: break-all; font-size: 0.8rem; max-width: 400px;">
                            <a href="<?= htmlspecialchars($row->url) ?>" target="_blank" rel="noopener" style="color: var(--primary-color); text-decoration: none;"><?= htmlspecialchars($row->url) ?></a>
                        </td>
                        <td style="padding: 0.5rem; color: var(--text-secondary); font-size: 0.75rem; max-width: 200px; word-break: break-all;"><?= htmlspecialchars($row->source_sitemap ?? '') ?></td>
                        <td style="padding: 0.5rem; color: var(--text-secondary); font-size: 0.8rem; white-space: nowrap;"><?= $row->lastmod ? date('Y-m-d', strtotime($row->lastmod)) : '—' ?></td>
                        <td style="padding: 0.5rem; text-align: right; color: var(--text-primary);"><?= $row->priority !== null ? $row->priority : '—' ?></td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if ($sitemapOnly > 200): ?>
                    <tr><td colspan="4" style="padding: 0.75rem; text-align: center; color: var(--text-secondary); font-style: italic;">
                        <?= __('sitemap.showing_first', ['count' => 200, 'total' => number_format($sitemapOnly)]) ?>
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========================================
         SECTION 5: Indexable in crawl but NOT in sitemap
         ======================================== -->
    <?php if ($crawlOnlyIndexable > 0): ?>
    <?php
    Component::urlTable([
        'title' => __('sitemap.table_crawl_only'),
        'id' => 'sitemap_crawl_only',
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 100,
        'defaultColumns' => ['url', 'code', 'title', 'inlinks', 'depth'],
        'whereClause' => 'WHERE c.crawl_id = :crawl_id AND c.compliant = TRUE AND c.is_in_sitemap = FALSE AND c.external = FALSE',
        'sqlParams' => [':crawl_id' => $crawlId],
        'projectDir' => $_GET['project'] ?? '',
        'orderBy' => 'ORDER BY c.inlinks DESC'
    ]);
    ?>
    <?php endif; ?>

</div>
