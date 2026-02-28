<?php
/**
 * ============================================================================
 * REQUÊTES SQL - Récupération des données (PostgreSQL)
 * ============================================================================
 * $crawlId est défini dans dashboard.php
 */

// Statistiques globales du PageRank
$stmt = $pdo->prepare("
    SELECT 
        AVG(pri) as avg_pr,
        MAX(pri) as max_pr,
        MIN(pri) as min_pr,
        COUNT(CASE WHEN pri > 0 THEN 1 END) as pages_with_pr
    FROM pages 
    WHERE crawl_id = :crawl_id AND crawled = true
");
$stmt->execute([':crawl_id' => $crawlId]);
$prStats = $stmt->fetch(PDO::FETCH_OBJ);

// Distribution PageRank par profondeur
$sqlPrByDepth = "
    SELECT 
        depth, 
        AVG(pri) as avg_pr, 
        COUNT(*) as count
    FROM pages 
    WHERE crawl_id = :crawl_id AND crawled = true AND pri > 0
    GROUP BY depth 
    ORDER BY depth
";
$stmt = $pdo->prepare($sqlPrByDepth);
$stmt->execute([':crawl_id' => $crawlId]);
$prByDepth = $stmt->fetchAll(PDO::FETCH_OBJ);

// Distribution PageRank par catégorie (sans jointure)
$sqlPrByCategory = "
    SELECT 
        cat_id,
        SUM(pri) as total_pr,
        AVG(pri) as avg_pr,
        COUNT(*) as count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND pri > 0
    GROUP BY cat_id
    ORDER BY AVG(pri) DESC
";
$stmt = $pdo->prepare($sqlPrByCategory);
$stmt->execute([':crawl_id' => $crawlId]);
$prByCategoryRaw = $stmt->fetchAll(PDO::FETCH_OBJ);

// Convertir cat_id en nom de catégorie
$categoriesMap = $GLOBALS['categoriesMap'] ?? [];
$prByCategory = [];
foreach ($prByCategoryRaw as $row) {
    $catInfo = $categoriesMap[$row->cat_id] ?? null;
    $row->category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
    $prByCategory[] = $row;
}

// ============================================================================
// SANKEY : Flux de liens entre catégories
// Compte simplement les liens internes dofollow entre catégories
// ============================================================================
$crawlIdInt = intval($crawlId);

// Compter les liens internes dofollow entre catégories
$sqlFluxCategories = "
    SELECT 
        ps.cat_id as source_cat_id,
        pt.cat_id as target_cat_id,
        COUNT(*) as link_count
    FROM links l
    INNER JOIN pages ps ON ps.crawl_id = l.crawl_id AND ps.id = l.src
    INNER JOIN pages pt ON pt.crawl_id = l.crawl_id AND pt.id = l.target
    WHERE l.crawl_id = {$crawlIdInt}
      AND l.nofollow = false
      AND ps.external = false AND pt.external = false
      AND ps.cat_id IS NOT NULL AND pt.cat_id IS NOT NULL
    GROUP BY ps.cat_id, pt.cat_id
";
$stmt = $pdo->prepare($sqlFluxCategories);
$stmt->execute();
$linkCountsRaw = $stmt->fetchAll(PDO::FETCH_OBJ);

// Compter les liens vers l'externe par catégorie source
$sqlExternalLinks = "
    SELECT 
        ps.cat_id as source_cat_id,
        COUNT(*) as link_count
    FROM links l
    INNER JOIN pages ps ON ps.crawl_id = l.crawl_id AND ps.id = l.src
    INNER JOIN pages pt ON pt.crawl_id = l.crawl_id AND pt.id = l.target
    WHERE l.crawl_id = {$crawlIdInt}
      AND l.nofollow = false
      AND ps.external = false AND pt.external = true
      AND ps.cat_id IS NOT NULL
    GROUP BY ps.cat_id
";
$stmt = $pdo->prepare($sqlExternalLinks);
$stmt->execute();
$externalLinksRaw = $stmt->fetchAll(PDO::FETCH_OBJ);

// Calculer le total de tous les liens
$totalLinks = 0;
foreach ($linkCountsRaw as $row) {
    $totalLinks += (int)$row->link_count;
}
foreach ($externalLinksRaw as $row) {
    $totalLinks += (int)$row->link_count;
}

// Préparer les données : liens internes (en %)
$fluxCategoriesRaw = [];
foreach ($linkCountsRaw as $row) {
    $row->flux_pr = $totalLinks > 0 ? round(((int)$row->link_count / $totalLinks) * 100, 2) : 0;
    $row->is_external = false;
    $fluxCategoriesRaw[] = $row;
}

// Ajouter les liens externes (en %)
foreach ($externalLinksRaw as $row) {
    $extRow = new stdClass();
    $extRow->source_cat_id = $row->source_cat_id;
    $extRow->target_cat_id = 'external';
    $extRow->link_count = $row->link_count;
    $extRow->flux_pr = $totalLinks > 0 ? round(((int)$row->link_count / $totalLinks) * 100, 2) : 0;
    $extRow->is_external = true;
    $fluxCategoriesRaw[] = $extRow;
}

usort($fluxCategoriesRaw, fn($a, $b) => $b->flux_pr <=> $a->flux_pr);

// Préparer les données pour le Sankey
// Nœuds séparés gauche (source) et droite (cible) avec colonnes forcées
$sankeyData = [];
$sourceNodes = [];
$targetNodes = [];

foreach ($fluxCategoriesRaw as $row) {
    $sourceCatInfo = $categoriesMap[$row->source_cat_id] ?? null;
    $sourceName = $sourceCatInfo ? $sourceCatInfo['cat'] : 'Non catégorisé';
    
    // Gérer le cas externe
    if ($row->target_cat_id === 'external') {
        $targetName = 'Externe';
        $targetColor = '#e74c3c'; // Rouge pour externe
    } else {
        $targetCatInfo = $categoriesMap[$row->target_cat_id] ?? null;
        $targetName = $targetCatInfo ? $targetCatInfo['cat'] : 'Non catégorisé';
        $targetColor = getCategoryColor($targetName);
    }
    
    // IDs distincts pour gauche et droite
    $sourceId = $sourceName . '_L';
    $targetId = $targetName . '_R';
    
    // Ajouter les flux
    $sankeyData[] = [$sourceId, $targetId, round((float)$row->flux_pr, 4)];
    
    // Nœuds sources (colonne 0) - pas pour externe
    if (!isset($sourceNodes[$sourceId])) {
        $sourceNodes[$sourceId] = [
            'id' => $sourceId,
            'name' => $sourceName,
            'color' => getCategoryColor($sourceName),
            'column' => 0
        ];
    }
    // Nœuds cibles (colonne 1)
    if (!isset($targetNodes[$targetId])) {
        $targetNodes[$targetId] = [
            'id' => $targetId,
            'name' => $targetName,
            'color' => $targetColor,
            'column' => 1
        ];
    }
}

// Fusionner les nœuds (triés par nombre de liens)
$linksByCategory = [];
foreach ($sankeyData as $flux) {
    $src = str_replace('_L', '', $flux[0]);
    $linksByCategory[$src] = ($linksByCategory[$src] ?? 0) + $flux[2];
}
$sankeyNodes = array_merge(array_values($sourceNodes), array_values($targetNodes));
usort($sankeyNodes, function($a, $b) use ($linksByCategory) {
    $linksA = $linksByCategory[$a['name']] ?? 0;
    $linksB = $linksByCategory[$b['name']] ?? 0;
    return $linksB <=> $linksA;
});

/**
 * ============================================================================
 * AFFICHAGE HTML - Rendu de l'interface
 * ============================================================================
 */
?>

<h1 class="page-title">Analyse du PageRank</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1 : Cartes statistiques
         ======================================== -->
    <div class="scorecards">
        <?php
        Component::card([
            'color' => 'primary',
            'icon' => 'analytics',
            'title' => 'PageRank moyen',
            'value' => number_format(($prStats->avg_pr ?? 0) * 100, 2) . '%',
            'desc' => 'Score moyen'
        ]);
        
        Component::card([
            'color' => 'success',
            'icon' => 'emoji_events',
            'title' => 'PageRank maximum',
            'value' => number_format(($prStats->max_pr ?? 0) * 100, 2) . '%',
            'desc' => 'Meilleur score'
        ]);
        
        Component::card([
            'color' => 'info',
            'icon' => 'trending_up',
            'title' => 'Pages avec PageRank',
            'value' => number_format($prStats->pages_with_pr ?? 0),
            'desc' => 'Pages analysées'
        ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 2 : Graphiques
         ======================================== -->
    <div class="charts-grid">
        <?php
        // Graphique 1: PageRank moyen par profondeur (Line Chart)
        Component::chart([
            'type' => 'line',
            'title' => 'PageRank moyen par profondeur',
            'subtitle' => 'Évolution du PageRank selon la profondeur',
            'categories' => array_map(function($d) { return 'Niveau ' . $d->depth; }, $prByDepth),
            'series' => [
                [
                    'name' => 'PageRank (%)',
                    'data' => array_map(function($d) { return round($d->avg_pr * 100, 2); }, $prByDepth)
                ]
            ],
            'xAxisTitle' => 'Profondeur',
            'yAxisTitle' => 'PageRank moyen (%)',
            'height' => 350,
            'sqlQuery' => $sqlPrByDepth
        ]);
        
        // Donut chart - Distribution du PageRank par catégorie
        // Préparation des données avec couleurs personnalisées
        $donutData = [];
        foreach($prByCategory as $cat) {
            $donutData[] = [
                'name' => $cat->category,
                'y' => round($cat->total_pr * 100, 2),
                'color' => getCategoryColor($cat->category)
            ];
        }
        
        Component::chart([
            'type' => 'donut',
            'title' => 'Distribution du PageRank par catégorie',
            'subtitle' => 'Pourcentage du PageRank total',
            'categories' => [],
            'series' => [
                [
                    'name' => 'PageRank total (%)',
                    'data' => $donutData
                ]
            ],
            'height' => 350,
            'sqlQuery' => $sqlPrByCategory
        ]);
        ?>
    </div>

    <?php
    // Horizontal bar chart - PageRank moyen par catégorie avec couleurs personnalisées
    
    Component::chart([
        'type' => 'horizontalBar',
        'title' => 'PageRank moyen par catégorie',
        'subtitle' => 'Classement des catégories par PageRank moyen',
        'categories' => array_map(function($c) { return $c->category; }, $prByCategory),
        'series' => [
            [
                'name' => 'PageRank moyen (%)',
                'data' => array_map(function($c) { return round($c->avg_pr * 100, 2); }, $prByCategory)
            ]
        ],
        'yAxisTitle' => 'PageRank moyen (%)',
        'height' => 400,
        'sqlQuery' => $sqlPrByCategory
    ]);
    
    // Diagramme Sankey - Flux de liens entre catégories
    if (!empty($sankeyData)):
    Component::chart([
        'type' => 'sankey',
        'title' => 'Flux de liens entre catégories',
        'subtitle' => 'Nombre de liens internes dofollow entre catégories',
        'series' => [
            [
                'name' => 'Liens',
                'data' => $sankeyData,
                'nodes' => $sankeyNodes
            ]
        ],
        'height' => 500,
        'sqlQuery' => $sqlFluxCategories
    ]);
    endif;
    ?>

    <!-- ========================================
         SECTION 3 : Tableau d'URLs
         ======================================== -->
    <?php
    Component::urlTable([
        'title' => 'Top PageRank',
        'id' => 'pagerankTable',
        'whereClause' => 'WHERE c.crawled = true',
        'orderBy' => 'ORDER BY c.pri DESC',
        'defaultColumns' => ['url', 'code', 'depth', 'pri','inlinks', 'outlinks', 'compliant'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>

</div>