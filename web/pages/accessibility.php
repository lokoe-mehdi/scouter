<?php
/**
 * ============================================================================
 * REQUÊTES SQL - Récupération des données (PostgreSQL)
 * ============================================================================
 * Note: Les statistiques globales ($globalStats) sont déjà chargées dans dashboard.php
 * $crawlId est défini dans dashboard.php
 */

// Catégories disponibles
$stmt = $pdo->prepare("SELECT id, cat FROM categories WHERE crawl_id = :crawl_id ORDER BY id");
$stmt->execute([':crawl_id' => $crawlId]);
$categories = $stmt->fetchAll(PDO::FETCH_OBJ);

// Stats par catégorie (uniquement celles avec des URLs)
$stmt = $pdo->prepare("
    SELECT 
        c.id,
        c.cat,
        COUNT(p.id) as total,
        SUM(CASE WHEN p.compliant = true THEN 1 ELSE 0 END) as compliant,
        AVG(p.inlinks) as avg_inlinks,
        AVG(p.pri) as avg_pagerank
    FROM categories c
    LEFT JOIN pages p ON c.id = p.cat_id AND p.crawl_id = :crawl_id2 AND p.crawled = true AND p.is_html = true
    WHERE c.crawl_id = :crawl_id
    GROUP BY c.id, c.cat
    HAVING COUNT(p.id) > 0
    ORDER BY COUNT(p.id) DESC
");
$stmt->execute([':crawl_id' => $crawlId, ':crawl_id2' => $crawlId]);
$categoryStats = $stmt->fetchAll(PDO::FETCH_OBJ);

// Distribution des URLs découvertes (external, crawled, non crawlées, médias)
// Note: is_html ne s'applique qu'aux URLs crawlées (on ne peut pas savoir si une externe est HTML ou média)
$sqlUrlDistribution = "
    SELECT 
        SUM(CASE WHEN external = true THEN 1 ELSE 0 END) as external_urls,
        SUM(CASE WHEN external = false AND crawled = true AND is_html = true THEN 1 ELSE 0 END) as crawled_urls,
        SUM(CASE WHEN external = false AND crawled = false THEN 1 ELSE 0 END) as not_crawled_urls,
        SUM(CASE WHEN external = false AND crawled = true AND (is_html = false OR is_html IS NULL) THEN 1 ELSE 0 END) as media_urls
    FROM pages
    WHERE crawl_id = :crawl_id
";
$stmt = $pdo->prepare($sqlUrlDistribution);
$stmt->execute([':crawl_id' => $crawlId]);
$urlDistribution = $stmt->fetch(PDO::FETCH_OBJ);

// Raisons de non-indexabilité (parmi les URLs crawlées)
$sqlNonIndexable = "
    SELECT 
        SUM(CASE WHEN code != 200 AND code IS NOT NULL THEN 1 ELSE 0 END) as bad_status,
        SUM(CASE WHEN code = 200 AND noindex = true THEN 1 ELSE 0 END) as noindex_urls,
        SUM(CASE WHEN code = 200 AND noindex = false AND canonical = false THEN 1 ELSE 0 END) as non_canonical
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = false AND is_html = true
";
$stmt = $pdo->prepare($sqlNonIndexable);
$stmt->execute([':crawl_id' => $crawlId]);
$nonIndexableReasons = $stmt->fetch(PDO::FETCH_OBJ);

// Distribution par catégorie (sans jointure)
$sqlDistributionByCategory = "
    SELECT 
        cat_id,
        SUM(CASE WHEN external = false AND crawled = true THEN 1 ELSE 0 END) as crawled,
        SUM(CASE WHEN external = true THEN 1 ELSE 0 END) as external,
        SUM(CASE WHEN external = false AND crawled = false THEN 1 ELSE 0 END) as blocked
    FROM pages
    WHERE crawl_id = :crawl_id AND is_html = true
    GROUP BY cat_id
    ORDER BY SUM(CASE WHEN external = false AND crawled = true THEN 1 ELSE 0 END) DESC
";
$stmt = $pdo->prepare($sqlDistributionByCategory);
$stmt->execute([':crawl_id' => $crawlId]);
$distributionByCategoryRaw = $stmt->fetchAll(PDO::FETCH_OBJ);

// Convertir cat_id en nom de catégorie
$categoriesMap = $GLOBALS['categoriesMap'] ?? [];
$distributionByCategory = [];
foreach ($distributionByCategoryRaw as $row) {
    $catInfo = $categoriesMap[$row->cat_id] ?? null;
    $row->category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
    $distributionByCategory[] = $row;
}

// Indexabilité par catégorie (sans jointure)
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
    ORDER BY SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) DESC
";
$stmt = $pdo->prepare($sqlIndexabilityByCategory);
$stmt->execute([':crawl_id' => $crawlId]);
$indexabilityByCategoryRaw = $stmt->fetchAll(PDO::FETCH_OBJ);

// Convertir cat_id en nom de catégorie
$indexabilityByCategory = [];
foreach ($indexabilityByCategoryRaw as $row) {
    $catInfo = $categoriesMap[$row->cat_id] ?? null;
    $row->category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
    $indexabilityByCategory[] = $row;
}

/**
 * ============================================================================
 * AFFICHAGE HTML - Rendu de l'interface
 * ============================================================================
 */
?>

<h1 class="page-title">Indexabilité</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1 : Cartes statistiques
         ======================================== -->
    <div class="scorecards-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
    <?php
    Component::card([
        'color' => 'primary',
        'icon' => 'language',
        'title' => 'Total URLs',
        'value' => number_format($globalStats->urls),
        'desc' => 'URLs découvertes'
    ]);
    
    Component::card([
        'color' => 'info',
        'icon' => 'check_circle',
        'title' => 'URLs Crawlées',
        'value' => number_format($globalStats->crawled),
        'desc' => round(($globalStats->crawled/$globalStats->urls)*100, 1).'% du total'
    ]);
    
    Component::card([
        'color' => 'success',
        'icon' => 'verified',
        'title' => 'URLs Indexables',
        'value' => number_format($globalStats->compliant),
        'desc' => round(($globalStats->compliant/$globalStats->crawled)*100, 1).'% des crawlées'
    ]);
    
    Component::card([
        'color' => 'warning',
        'icon' => 'content_copy',
        'title' => 'URLs Dupliquées',
        'value' => number_format($globalStats->duplicates),
        'desc' => 'Non canoniques'
    ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 2 : Graphiques de distribution
         ======================================== -->
    <div class="charts-grid">
    <?php
    // Graphique 1: Distribution des URLs découvertes (HTML + Médias)
    Component::chart([
        'type' => 'donut',
        'title' => 'Répartition des URLs découvertes',
        'subtitle' => 'Distribution entre URLs HTML (crawlées, externes, bloquées) et médias',
        'series' => [
            [
                'name' => 'URLs',
                'data' => [
                    ['name' => 'HTML Crawlées', 'y' => (int)($urlDistribution->crawled_urls ?? 0), 'color' => '#6bd899ff'],
                    ['name' => 'HTML Externes', 'y' => (int)($urlDistribution->external_urls ?? 0), 'color' => '#d8bf6bff'],
                    ['name' => 'HTML Blocage robots.txt', 'y' => (int)($urlDistribution->not_crawled_urls ?? 0), 'color' => '#d86b6bff'],
                    ['name' => 'Médias', 'y' => (int)($urlDistribution->media_urls ?? 0), 'color' => '#E5E7EB']
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
        'title' => 'Indexabilité des URLs crawlées',
        'subtitle' => 'Répartition entre URLs indexables et causes de non-indexabilité',
        'series' => [
            [
                'name' => 'URLs',
                'data' => [
                    ['name' => 'Indexables', 'y' => $indexableCount, 'color' => '#6bd899ff'],
                    ['name' => 'Non canonique', 'y' => (int)($nonIndexableReasons->non_canonical ?? 0), 'color' => '#cfd86bff'],
                    ['name' => 'Noindex', 'y' => (int)($nonIndexableReasons->noindex_urls ?? 0), 'color' => '#d8bf6bff'],
                    ['name' => 'Code HTTP ≠ 200', 'y' => (int)($nonIndexableReasons->bad_status ?? 0), 'color' => '#d86b6bff']
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
    // Préparation des données pour le stacked bar - Distribution
    $distCategories = array_map(fn($r) => $r->category, $distributionByCategory);
    $distCrawled = array_map(fn($r) => (int)$r->crawled, $distributionByCategory);
    $distExternal = array_map(fn($r) => (int)$r->external, $distributionByCategory);
    $distBlocked = array_map(fn($r) => (int)$r->blocked, $distributionByCategory);
    
    Component::chart([
        'type' => 'horizontalBar',
        'title' => 'Répartition par catégorie',
        'subtitle' => 'Distribution des URLs découvertes par catégorie',
        'categories' => $distCategories,
        'series' => [
            ['name' => 'Blocage robots.txt', 'data' => $distBlocked, 'color' => '#d86b6bff'],
            ['name' => 'Externes', 'data' => $distExternal, 'color' => '#d8bf6bff'],
            ['name' => 'Crawlées', 'data' => $distCrawled, 'color' => '#6bd899ff']
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
        'title' => 'Indexabilité par catégorie',
        'subtitle' => 'Répartition des causes de non-indexabilité par catégorie',
        'categories' => $idxCategories,
        'series' => [
            ['name' => 'Code HTTP ≠ 200', 'data' => $idxBadStatus, 'color' => '#d86b6bff'],
            ['name' => 'Noindex', 'data' => $idxNoindex, 'color' => '#d8bf6bff'],
            ['name' => 'Non canonique', 'data' => $idxNonCanonical, 'color' => '#cfd86bff'],
            ['name' => 'Indexables', 'data' => $idxIndexable, 'color' => '#6bd899ff']
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
        'title' => 'URLs non indexables',
        'id' => 'nonIndexableTable',
        'whereClause' => 'WHERE c.compliant = false AND c.is_html = true',
        'orderBy' => 'ORDER BY c.code DESC, c.url ASC',
        'defaultColumns' => ['url', 'code', 'depth','canonical','noindex','blocked'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>

</div>
