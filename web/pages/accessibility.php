<?php
/**
 * ============================================================================
 * REQUÊTES SQL - Récupération des données (PostgreSQL)
 * ============================================================================
 * Note: Les statistiques globales ($globalStats) sont déjà chargées dans dashboard.php
 * $crawlId est défini dans dashboard.php
 */

// Stats par catégorie (uniquement celles avec des URLs)
$sqlCategoryStats = "
    SELECT
        category as cat,
        COUNT(id) as total,
        SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) as compliant,
        AVG(inlinks) as avg_inlinks,
        AVG(pri) as avg_pagerank
    FROM pages
    WHERE crawl_id = :crawl_id2 AND crawled = true AND is_html = true AND in_crawl = TRUE
    GROUP BY category
    HAVING COUNT(id) > 0
    ORDER BY COUNT(id) DESC
";
$categoryStats = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'accessibility_category_stats', $pdo, $sqlCategoryStats, [':crawl_id2' => $crawlId], true
);

// Distribution des URLs découvertes : on distingue désormais les URLs réellement
// bloquées par robots.txt (blocked = true) des URLs simplement non crawlées
// (limite de profondeur, crawl interrompu, etc.) que l'on classe "hors scope".
$sqlUrlDistribution = "
    SELECT
        SUM(CASE WHEN external = true THEN 1 ELSE 0 END) as external_urls,
        SUM(CASE WHEN external = false AND crawled = true AND is_html = true THEN 1 ELSE 0 END) as crawled_urls,
        SUM(CASE WHEN external = false AND blocked = true THEN 1 ELSE 0 END) as blocked_urls,
        SUM(CASE WHEN external = false AND blocked = false AND crawled = false THEN 1 ELSE 0 END) as out_of_scope_urls,
        SUM(CASE WHEN external = false AND crawled = true AND (is_html = false OR is_html IS NULL) THEN 1 ELSE 0 END) as media_urls
    FROM pages
    WHERE crawl_id = :crawl_id AND in_crawl = TRUE
";
$urlDistributionRows = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'accessibility_url_distribution', $pdo, $sqlUrlDistribution, [':crawl_id' => $crawlId], false
);
$urlDistribution = $urlDistributionRows[0] ?? null;

// Raisons de non-indexabilité (parmi les URLs crawlées)
$sqlNonIndexable = "
    SELECT 
        SUM(CASE WHEN code != 200 AND code IS NOT NULL THEN 1 ELSE 0 END) as bad_status,
        SUM(CASE WHEN code = 200 AND noindex = true THEN 1 ELSE 0 END) as noindex_urls,
        SUM(CASE WHEN code = 200 AND noindex = false AND canonical = false THEN 1 ELSE 0 END) as non_canonical
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = false AND is_html = true AND in_crawl = TRUE
";
$nonIndexableRows = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'accessibility_non_indexable', $pdo, $sqlNonIndexable, [':crawl_id' => $crawlId], false
);
$nonIndexableReasons = $nonIndexableRows[0] ?? null;

// Distribution par catégorie (URLs internes uniquement) :
// indexables vs médias vs bloquées au robots.txt.
$sqlDistributionByCategory = "
    SELECT
        category,
        SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) as indexable,
        SUM(CASE WHEN blocked = false AND crawled = true AND (is_html = false OR is_html IS NULL) THEN 1 ELSE 0 END) as media,
        SUM(CASE WHEN blocked = true THEN 1 ELSE 0 END) as blocked
    FROM pages
    WHERE crawl_id = :crawl_id AND external = false AND in_crawl = TRUE
    GROUP BY category
    ORDER BY SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) DESC
";
$distributionByCategoryRaw = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'accessibility_distribution_by_category', $pdo, $sqlDistributionByCategory, [':crawl_id' => $crawlId], true
);

// Nom de catégorie (déjà fourni par la colonne category)
$distributionByCategory = [];
foreach ($distributionByCategoryRaw as $row) {
    $row->category = (($row->category ?? '') !== '') ? $row->category : __('common.uncategorized');
    $distributionByCategory[] = $row;
}

// Indexabilité par catégorie (sans jointure)
$sqlIndexabilityByCategory = "
    SELECT
        category,
        SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) as indexable,
        SUM(CASE WHEN compliant = false AND canonical = false THEN 1 ELSE 0 END) as non_canonical,
        SUM(CASE WHEN compliant = false AND canonical = true AND noindex = true THEN 1 ELSE 0 END) as noindex,
        SUM(CASE WHEN compliant = false AND canonical = true AND noindex = false AND code != 200 THEN 1 ELSE 0 END) as bad_status
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true AND in_crawl = TRUE
    GROUP BY category
    ORDER BY SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) DESC
";
$indexabilityByCategoryRaw = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'accessibility_indexability_by_category', $pdo, $sqlIndexabilityByCategory, [':crawl_id' => $crawlId], true
);

// Nom de catégorie (déjà fourni par la colonne category)
$indexabilityByCategory = [];
foreach ($indexabilityByCategoryRaw as $row) {
    $row->category = (($row->category ?? '') !== '') ? $row->category : __('common.uncategorized');
    $indexabilityByCategory[] = $row;
}

/**
 * ============================================================================
 * AFFICHAGE HTML - Rendu de l'interface
 * ============================================================================
 */
?>

<h1 class="page-title"><?= __('accessibility.page_title') ?></h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1 : Cartes statistiques
         ======================================== -->
    <div class="scorecards-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
    <?php
    Component::card([
        'color' => 'primary',
        'icon' => 'language',
        'title' => __('accessibility.card_total'),
        'value' => number_format($globalStats->urls),
        'desc' => __('accessibility.card_total_desc')
    ]);
    
    Component::card([
        'color' => 'info',
        'icon' => 'check_circle',
        'title' => __('accessibility.card_crawled'),
        'value' => number_format($globalStats->crawled),
        'desc' => round(($globalStats->crawled/$globalStats->urls)*100, 1).'% '.__('common.of_total')
    ]);
    
    Component::card([
        'color' => 'success',
        'icon' => 'verified',
        'title' => __('accessibility.card_indexable'),
        'value' => number_format($globalStats->compliant),
        'desc' => round(($globalStats->compliant/$globalStats->crawled)*100, 1).'% '.__('accessibility.card_indexable_pct')
    ]);
    
    Component::card([
        'color' => 'warning',
        'icon' => 'content_copy',
        'title' => __('accessibility.card_duplicated'),
        'value' => number_format($globalStats->duplicates),
        'desc' => __('accessibility.card_duplicated_desc')
    ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 2 : Graphiques de distribution
         ======================================== -->
    <div class="charts-grid">
    <?php
    // Graphique 1: Distribution des URLs découvertes (HTML + Médias + Bloquées + Hors scope)
    Component::chart([
        'type' => 'donut',
        'title' => __('accessibility.chart_discovered'),
        'subtitle' => __('accessibility.chart_discovered_desc'),
        'series' => [
            [
                'name' => 'URLs',
                'data' => [
                    ['name' => __('accessibility.series_crawled_html'), 'y' => (int)($urlDistribution->crawled_urls ?? 0), 'color' => '#6bd899ff'],
                    ['name' => __('accessibility.series_external_html'), 'y' => (int)($urlDistribution->external_urls ?? 0), 'color' => '#d8bf6bff'],
                    ['name' => __('accessibility.series_blocked_robots'), 'y' => (int)($urlDistribution->blocked_urls ?? 0), 'color' => '#d86b6bff'],
                    ['name' => __('accessibility.series_out_of_scope'), 'y' => (int)($urlDistribution->out_of_scope_urls ?? 0), 'color' => '#f5a25dff'],
                    ['name' => __('accessibility.series_media'), 'y' => (int)($urlDistribution->media_urls ?? 0), 'color' => '#E5E7EB']
                ]
            ]
        ],
        'height' => 350,
        'sqlQuery' => $sqlUrlDistribution
    ]);
    
    // Graphique 2: Indexabilité des URLs crawlées
    $indexableCount = (int)($globalStats->compliant ?? 0);
    Component::chart([
        'type' => 'donut',
        'title' => __('accessibility.chart_indexability'),
        'subtitle' => __('accessibility.chart_indexability_desc'),
        'series' => [
            [
                'name' => 'URLs',
                'data' => [
                    ['name' => __('accessibility.series_indexable'), 'y' => $indexableCount, 'color' => '#6bd899ff'],
                    ['name' => __('accessibility.series_non_canonical'), 'y' => (int)($nonIndexableReasons->non_canonical ?? 0), 'color' => '#cfd86bff'],
                    ['name' => __('accessibility.series_noindex'), 'y' => (int)($nonIndexableReasons->noindex_urls ?? 0), 'color' => '#d8bf6bff'],
                    ['name' => __('accessibility.series_http_not_200'), 'y' => (int)($nonIndexableReasons->bad_status ?? 0), 'color' => '#d86b6bff']
                ]
            ]
        ],
        'height' => 350,
        'sqlQuery' => $sqlNonIndexable
    ]);
    ?>
    </div>

    <!-- ========================================
         SECTION 2b : Graphiques par catégorie
         ======================================== -->
    <div class="charts-grid">
    <?php
    // Préparation des données pour le stacked bar - Distribution (indexable / média / bloqué)
    $distCategories = array_map(fn($r) => $r->category, $distributionByCategory);
    $distIndexable = array_map(fn($r) => (int)$r->indexable, $distributionByCategory);
    $distMedia = array_map(fn($r) => (int)$r->media, $distributionByCategory);
    $distBlocked = array_map(fn($r) => (int)$r->blocked, $distributionByCategory);

    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('accessibility.chart_category'),
        'subtitle' => __('accessibility.chart_category_desc'),
        'categories' => $distCategories,
        'series' => [
            ['name' => __('accessibility.series_blocked_robots'), 'data' => $distBlocked, 'color' => '#d86b6bff'],
            ['name' => __('accessibility.series_media'), 'data' => $distMedia, 'color' => '#E5E7EB'],
            ['name' => __('accessibility.series_indexable'), 'data' => $distIndexable, 'color' => '#6bd899ff']
        ],
        'stacking' => 'percent',
        'yAxisMax' => 100,
        'height' => 400,
        'sqlQuery' => $sqlDistributionByCategory
    ]);
    
    // Préparation des données pour le stacked bar - Indexabilité
    $idxCategories = array_map(fn($r) => $r->category, $indexabilityByCategory);
    $idxIndexable = array_map(fn($r) => (int)$r->indexable, $indexabilityByCategory);
    $idxNonCanonical = array_map(fn($r) => (int)$r->non_canonical, $indexabilityByCategory);
    $idxNoindex = array_map(fn($r) => (int)$r->noindex, $indexabilityByCategory);
    $idxBadStatus = array_map(fn($r) => (int)$r->bad_status, $indexabilityByCategory);
    
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('accessibility.chart_indexability_category'),
        'subtitle' => __('accessibility.chart_indexability_category_desc'),
        'categories' => $idxCategories,
        'series' => [
            ['name' => __('accessibility.series_http_not_200'), 'data' => $idxBadStatus, 'color' => '#d86b6bff'],
            ['name' => __('accessibility.series_noindex'), 'data' => $idxNoindex, 'color' => '#d8bf6bff'],
            ['name' => __('accessibility.series_non_canonical'), 'data' => $idxNonCanonical, 'color' => '#cfd86bff'],
            ['name' => __('accessibility.series_indexable'), 'data' => $idxIndexable, 'color' => '#6bd899ff']
        ],
        'stacking' => 'percent',
        'yAxisMax' => 100,
        'height' => 400,
        'sqlQuery' => $sqlIndexabilityByCategory
    ]);
    ?>
    </div>

    <!-- ========================================
         SECTION 3 : URLs non indexables
         ======================================== -->
    <?php
    Component::urlTable([
        'title' => __('accessibility.table_non_indexable'),
        'id' => 'nonIndexableTable',
        'whereClause' => 'WHERE c.compliant = false AND (c.is_html = true OR c.blocked = true)',
        'orderBy' => 'ORDER BY c.code DESC, c.url ASC',
        'defaultColumns' => ['url', 'code', 'depth','canonical','noindex','blocked'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>

</div>
