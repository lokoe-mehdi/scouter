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
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true AND in_crawl = TRUE
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
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true AND in_crawl = TRUE
    GROUP BY depth
    ORDER BY depth
";
$depthStats = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'depth_stats', $pdo, $sqlDepthStats, [':crawl_id' => $crawlId], false
);

// Distribution par profondeur et catégorie (sans jointure)
$sqlDepthByCategory = "
    SELECT
        depth,
        category,
        COUNT(*) as count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true AND in_crawl = TRUE
    GROUP BY depth, category
    ORDER BY depth, category
";
$depthByCategoryRaw = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'depth_by_category', $pdo, $sqlDepthByCategory, [':crawl_id' => $crawlId], true
);

// La colonne category contient déjà le nom de catégorie
$depthByCategory = [];
foreach ($depthByCategoryRaw as $row) {
    $row->category = (($row->category ?? '') !== '') ? $row->category : __('common.uncategorized');
    $depthByCategory[] = $row;
}

/**
 * ============================================================================
 * AFFICHAGE HTML - Rendu de l'interface
 * ============================================================================
 */
?>

<h1 class="page-title"><?= __('depth.page_title') ?></h1>

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
    'title' => __('depth.table_title'),
    'subtitle' => __('depth.table_subtitle'),
    'columns' => [
        ['key' => 'depth', 'label' => __('depth.col_depth'), 'type' => 'bold'],
        ['key' => 'total', 'label' => __('depth.col_crawled'), 'type' => 'default'],
        ['key' => 'compliant', 'label' => __('depth.col_indexable'), 'type' => 'badge-success'],
        ['key' => 'percent_compliant', 'label' => __('depth.col_pct_indexable'), 'type' => 'percent_bar'],
        ['key' => 'ok', 'label' => __('depth.col_200ok'), 'type' => 'default'],
        ['key' => 'avg_inlinks', 'label' => __('depth.col_avg_inlinks'), 'type' => 'default'],
        ['key' => 'avg_pagerank', 'label' => __('depth.col_avg_pagerank'), 'type' => 'default']
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
        'title' => __('depth.chart_title'),
        'subtitle' => __('depth.chart_subtitle'),
        'categories' => array_map(function($s) { return 'Niveau ' . $s->depth; }, $depthStats),
        'series' => [
            [
                'name' => __('depth.series_non_indexable'),
                'data' => array_map(function($s) { return (int)$s->total - (int)$s->compliant; }, $depthStats),
                'color' => '#95a5a6'
            ],
            [
                'name' => __('depth.series_indexable'),
                'data' => array_map(function($s) { return (int)$s->compliant; }, $depthStats),
                'color' => '#6bd899'
            ]
        ],
        'stacking' => 'normal',
        'yAxisTitle' => __('depth.label_url_count'),
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
        'title' => __('depth.chart_category_title'),
        'subtitle' => __('depth.chart_category_subtitle'),
        'categories' => array_map(function($s) { return 'Niveau ' . $s->depth; }, $depthStats),
        'series' => $series,
        'yAxisTitle' => __('common.percentage'),
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
        'title' => __('depth.table_deepest'),
        'id' => 'responsetimetable',
        'whereClause' => 'WHERE c.crawled = true AND c.is_html = true AND c.in_crawl = TRUE',
        'orderBy' => 'ORDER BY c.depth DESC',
        'defaultColumns' => ['url','depth','category','compliant', 'code','inlinks','outlinks'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>

</div>
