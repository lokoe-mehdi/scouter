<?php
/**
 * ============================================================================
 * REQUÊTES SQL - Données structurées (Schema.org)
 * ============================================================================
 * $crawlId est défini dans dashboard.php
 */

// Stats globales sur les schemas
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT p.id) as total_pages,
        COUNT(DISTINCT CASE WHEN array_length(p.schemas, 1) > 0 THEN p.id END) as pages_with_schema,
        COUNT(DISTINCT CASE WHEN array_length(p.schemas, 1) IS NULL OR array_length(p.schemas, 1) = 0 THEN p.id END) as pages_without_schema
    FROM pages p
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.compliant = true
");
$stmt->execute([':crawl_id' => $crawlId]);
$globalStats = $stmt->fetch(PDO::FETCH_OBJ);

// Distribution des types de schemas (via table de liaison, filtré sur pages compliant)
$sqlSchemaDistribution = "
    SELECT 
        ps.schema_type,
        COUNT(DISTINCT ps.page_id) as page_count
    FROM page_schemas ps
    INNER JOIN pages p ON p.crawl_id = ps.crawl_id AND p.id = ps.page_id
    WHERE ps.crawl_id = :crawl_id AND p.compliant = true
    GROUP BY ps.schema_type
    ORDER BY page_count DESC
";
$stmt = $pdo->prepare($sqlSchemaDistribution);
$stmt->execute([':crawl_id' => $crawlId]);
$schemaDistribution = $stmt->fetchAll(PDO::FETCH_OBJ);

// Nombre moyen de schemas par page par catégorie
$sqlSchemaByCategory = "
    SELECT 
        p.cat_id,
        COUNT(DISTINCT p.id) as total_pages,
        COALESCE(AVG(array_length(p.schemas, 1)), 0) as avg_schemas
    FROM pages p
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.compliant = true
    GROUP BY p.cat_id
    ORDER BY avg_schemas DESC
";
$stmt = $pdo->prepare($sqlSchemaByCategory);
$stmt->execute([':crawl_id' => $crawlId]);
$schemaByCategory = $stmt->fetchAll(PDO::FETCH_OBJ);

// Convertir cat_id en nom de catégorie via le tableau global
$categoriesMap = $GLOBALS['categoriesMap'] ?? [];
foreach ($schemaByCategory as $row) {
    $catInfo = $categoriesMap[$row->cat_id] ?? null;
    $row->category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
}

// Nombre total de types de schemas distincts
$totalSchemaTypes = count($schemaDistribution);

// Calculer le pourcentage de pages avec schema
$percentWithSchema = $globalStats->total_pages > 0 
    ? round(($globalStats->pages_with_schema / $globalStats->total_pages) * 100, 1) 
    : 0;

/**
 * ============================================================================
 * AFFICHAGE HTML - Rendu de l'interface
 * ============================================================================
 */
?>

<h1 class="page-title">Données structurées (Schema.org)</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1 : Cartes statistiques
         ======================================== -->
    <div class="scorecards">
    <?php
    Component::card([
        'color' => 'primary',
        'icon' => 'check_circle',
        'title' => 'Pages analysées',
        'value' => number_format($globalStats->total_pages ?? 0),
        'desc' => 'Pages indexables'
    ]);
    
    Component::card([
        'color' => 'success',
        'icon' => 'percent',
        'title' => 'Taux de complétion',
        'value' => $percentWithSchema . '%',
        'desc' => 'Pages avec schema'
    ]);
    
    Component::card([
        'color' => 'success',
        'icon' => 'data_object',
        'title' => 'Pages avec schema',
        'value' => number_format($globalStats->pages_with_schema ?? 0),
        'desc' => $totalSchemaTypes . ' types distincts'
    ]);
    
    Component::card([
        'color' => 'warning',
        'icon' => 'warning',
        'title' => 'Pages sans schema',
        'value' => number_format($globalStats->pages_without_schema ?? 0),
        'desc' => (100 - $percentWithSchema) . '% des pages'
    ]);
    ?>
    </div>

    <!-- ========================================
         SECTION 2 : Tableau des types de schemas
         ======================================== -->
    <?php
    // Préparation des données pour le tableau
    $schemaTableData = [];
    $maxSchemaCount = !empty($schemaDistribution) ? (int)$schemaDistribution[0]->page_count : 1;
    
    foreach($schemaDistribution as $stat) {
        $schemaTableData[] = [
            'schema_type' => $stat->schema_type,
            'page_count' => number_format($stat->page_count),
            'percent' => $stat->page_count / $maxSchemaCount // Relatif au schema le plus utilisé
        ];
    }
    
    if (!empty($schemaTableData)) {
        Component::simpleTable([
            'title' => 'Distribution des types de schemas',
            'subtitle' => 'Nombre de pages par type Schema.org',
            'columns' => [
                ['key' => 'schema_type', 'label' => 'Type Schema.org', 'type' => 'bold'],
                ['key' => 'page_count', 'label' => 'Nombre de pages', 'type' => 'default'],
                ['key' => 'percent', 'label' => 'Pourcentage', 'type' => 'percent_bar']
            ],
            'data' => $schemaTableData
        ]);
    }
    ?>

    <!-- ========================================
         SECTION 3 : Graphiques
         ======================================== -->
    <div class="charts-grid">
        <?php
        if (!empty($schemaDistribution)) {
            // Donut chart - Répartition des types de schemas
            $donutData = [];
            $colors = ['#4ECDC4', '#FF6B6B', '#45B7D1', '#96CEB4', '#FFEAA7', '#DDA0DD', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E9'];
            $colorIndex = 0;
            
            foreach($schemaDistribution as $stat) {
                $donutData[] = [
                    'name' => $stat->schema_type,
                    'y' => (int)$stat->page_count,
                    'color' => $colors[$colorIndex % count($colors)]
                ];
                $colorIndex++;
            }
            
            Component::chart([
                'type' => 'donut',
                'title' => 'Répartition des schemas',
                'subtitle' => 'Types de données structurées',
                'series' => [
                    [
                        'name' => 'Pages',
                        'data' => $donutData
                    ]
                ],
                'height' => 350,
                'legendPosition' => 'bottom',
                'sqlQuery' => $sqlSchemaDistribution
            ]);
            
            // Horizontal bar chart - Top 10 schemas les plus utilisés
            $schemaTypes = [];
            $schemaPageCounts = [];
            $top10 = array_slice($schemaDistribution, 0, 10);
            
            foreach($top10 as $stat) {
                $schemaTypes[] = $stat->schema_type;
                $schemaPageCounts[] = (int)$stat->page_count;
            }
            
            Component::chart([
                'type' => 'horizontalBar',
                'title' => 'Top 10 schemas les plus utilisés',
                'subtitle' => 'Nombre de pages par type',
                'categories' => $schemaTypes,
                'series' => [
                    [
                        'name' => 'Pages',
                        'data' => $schemaPageCounts,
                        'color' => '#4ECDC4'
                    ]
                ],
                'height' => 350,
                'sqlQuery' => $sqlSchemaDistribution
            ]);
        }
        
        ?>
    </div>
    
    <!-- Graphique pleine largeur -->
    <div style="width: 100%;">
        <?php
        // Bar chart - Nombre moyen de schemas par page par catégorie
        if (!empty($schemaByCategory)) {
            $categories = [];
            $seriesAvgSchemas = [];
            
            foreach($schemaByCategory as $row) {
                $categories[] = $row->category;
                $seriesAvgSchemas[] = round((float)$row->avg_schemas, 2);
            }
            
            Component::chart([
                'type' => 'horizontalBar',
                'title' => 'Nombre moyen de schemas par page',
                'subtitle' => 'Par catégorie, du plus riche au moins riche',
                'categories' => $categories,
                'series' => [
                    [
                        'name' => 'Schemas/page',
                        'data' => $seriesAvgSchemas,
                        'color' => 'var(--primary-color)'
                    ]
                ],
                'height' => max(250, count($categories) * 40),
                'sqlQuery' => $sqlSchemaByCategory
            ]);
        }
        ?>
    </div>

    <!-- ========================================
         SECTION 4 : Tableau d'URLs avec schemas
         ======================================== -->
    <?php
    Component::urlTable([
        'title' => 'Pages avec données structurées',
        'id' => 'structured_data_urls',
        'whereClause' => 'WHERE array_length(c.schemas, 1) > 0 AND c.crawled = true AND c.compliant = true',
        'orderBy' => 'ORDER BY c.depth ASC, c.pri DESC',
        'defaultColumns' => ['url', 'depth', 'inlinks', 'outlinks', 'pri'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>

    <!-- ========================================
         SECTION 5 : Tableau d'URLs sans schemas
         ======================================== -->
    <?php
    Component::urlTable([
        'title' => 'Pages sans données structurées',
        'id' => 'no_structured_data_urls',
        'whereClause' => 'WHERE (array_length(c.schemas, 1) IS NULL OR array_length(c.schemas, 1) = 0) AND c.crawled = true AND c.compliant = true',
        'orderBy' => 'ORDER BY c.depth ASC, c.pri DESC',
        'defaultColumns' => ['url', 'depth', 'inlinks', 'outlinks', 'pri'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>

</div>
