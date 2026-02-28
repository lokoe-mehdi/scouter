<?php
/**
 * ============================================================================
 * REQUÊTES SQL - Récupération des données (PostgreSQL)
 * ============================================================================
 * $crawlId est défini dans dashboard.php
 */

// Récupération du filtre et recherche
$filterDepth = isset($_GET['filter_depth']) ? (int)$_GET['filter_depth'] : -1;
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Niveaux de profondeur disponibles
$stmt = $pdo->prepare("
    SELECT DISTINCT depth 
    FROM pages 
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true
    ORDER BY depth
");
$stmt->execute([':crawl_id' => $crawlId]);
$depths = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Distribution par profondeur avec statistiques
$sqlDepthStats = "
    SELECT 
        depth, 
        COUNT(*) as total, 
        SUM(CASE WHEN compliant = true THEN 1 ELSE 0 END) as compliant,
        SUM(CASE WHEN code = 200 THEN 1 ELSE 0 END) as ok,
        AVG(inlinks) as avg_inlinks,
        AVG(pri) as avg_pagerank
    FROM pages 
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true
    GROUP BY depth 
    ORDER BY depth
";
$stmt = $pdo->prepare($sqlDepthStats);
$stmt->execute([':crawl_id' => $crawlId]);
$depthStats = $stmt->fetchAll(PDO::FETCH_OBJ);

// Distribution par profondeur et catégorie (sans jointure)
$sqlDepthByCategory = "
    SELECT 
        depth,
        cat_id,
        COUNT(*) as count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true
    GROUP BY depth, cat_id
    ORDER BY depth, cat_id
";
$stmt = $pdo->prepare($sqlDepthByCategory);
$stmt->execute([':crawl_id' => $crawlId]);
$depthByCategoryRaw = $stmt->fetchAll(PDO::FETCH_OBJ);

// Convertir cat_id en nom de catégorie
$categoriesMap = $GLOBALS['categoriesMap'] ?? [];
$depthByCategory = [];
foreach ($depthByCategoryRaw as $row) {
    $catInfo = $categoriesMap[$row->cat_id] ?? null;
    $row->category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
    $depthByCategory[] = $row;
}

/**
 * ============================================================================
 * AFFICHAGE HTML - Rendu de l'interface
 * ============================================================================
 */
?>

<h1 class="page-title">Analyse des niveaux de profondeur</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1 : Tableau statistiques par profondeur
         ======================================== -->
    <?php
    // Préparation des données pour le tableau
$depthTableData = [];
foreach($depthStats as $stat) {
    $depthTableData[] = [
        'depth' => 'Niveau ' . $stat->depth,
        'total' => number_format($stat->total),
        'compliant' => number_format($stat->compliant),
        'percent_compliant' => $stat->total > 0 ? ($stat->compliant / $stat->total) : 0,
        'ok' => number_format($stat->ok),
        'avg_inlinks' => round($stat->avg_inlinks ?? 0, 1),
        'avg_pagerank' => round(($stat->avg_pagerank ?? 0) * 100, 2) . '%'
    ];
}

    Component::simpleTable([
    'title' => 'Statistiques par niveau',
    'subtitle' => 'Analyse de la profondeur du site',
    'columns' => [
        ['key' => 'depth', 'label' => 'Profondeur', 'type' => 'bold'],
        ['key' => 'total', 'label' => 'URLs Crawlées', 'type' => 'default'],
        ['key' => 'compliant', 'label' => 'URLs Indexables', 'type' => 'badge-success'],
        ['key' => 'percent_compliant', 'label' => '% Indexables', 'type' => 'percent_bar'],
        ['key' => 'ok', 'label' => '200 OK', 'type' => 'default'],
        ['key' => 'avg_inlinks', 'label' => 'Inlinks moyen', 'type' => 'default'],
        ['key' => 'avg_pagerank', 'label' => 'PageRank moyen', 'type' => 'default']
    ],
        'data' => $depthTableData
    ]);
    ?>

    <!-- ========================================
         SECTION 2 : Graphiques
         ======================================== -->
    <div class="charts-grid">
    <?php
    // Graphique 1: Distribution par profondeur (Bar Chart empilé)
    Component::chart([
        'type' => 'bar',
        'title' => 'Distribution des URLs par profondeur',
        'subtitle' => 'Indexables vs Non-indexables par niveau',
        'categories' => array_map(function($s) { return 'Niveau ' . $s->depth; }, $depthStats),
        'series' => [
            [
                'name' => 'Non-indexables',
                'data' => array_map(function($s) { return (int)$s->total - (int)$s->compliant; }, $depthStats),
                'color' => '#95a5a6'
            ],
            [
                'name' => 'Indexables',
                'data' => array_map(function($s) { return (int)$s->compliant; }, $depthStats),
                'color' => '#6bd899'
            ]
        ],
        'stacking' => 'normal',
        'yAxisTitle' => 'Nombre d\'URLs',
        'height' => 400,
        'sqlQuery' => $sqlDepthStats
    ]);
    
        // Bar chart stacked - Distribution par profondeur et catégorie
        // Préparation : organiser les données par catégorie et profondeur
        $depthCategoryData = [];
        foreach($depthByCategory as $row) {
            if(!isset($depthCategoryData[$row->category])) {
                $depthCategoryData[$row->category] = [];
            }
            $depthCategoryData[$row->category][$row->depth] = $row->count;
        }
        
        $series = [];
        foreach($depthCategoryData as $category => $data) {
            $serieData = [];
            foreach($depthStats as $stat) {
                $serieData[] = isset($data[$stat->depth]) ? (int)$data[$stat->depth] : 0;
            }
            $series[] = [
                'name' => $category,
                'data' => $serieData,
                'color' => getCategoryColor($category)
            ];
        }
        
        Component::chart([
        'type' => 'bar',
        'title' => 'Distribution par profondeur et catégorie',
        'subtitle' => 'Répartition en pourcentage par catégorie',
        'categories' => array_map(function($s) { return 'Niveau ' . $s->depth; }, $depthStats),
        'series' => $series,
        'yAxisTitle' => 'Pourcentage',
        'yAxisMax' => 100,
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlDepthByCategory
    ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 3 : Tableau d'URLs
         ======================================== -->
    <?php
    Component::urlTable([
        'title' => 'URLs les plus profondes',
        'id' => 'responsetimetable',
        'whereClause' => 'WHERE c.crawled = true AND c.is_html = true',
        'orderBy' => 'ORDER BY c.depth DESC',
        'defaultColumns' => ['url','depth','category','compliant', 'code','inlinks','outlinks'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>

</div>
