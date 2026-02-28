<?php
/**
 * ============================================================================
 * REQUÊTES SQL - Richesse de contenu (Word Count)
 * ============================================================================
 * Analyse du nombre de mots par page (compliant=true uniquement)
 * $crawlId est défini dans dashboard.php
 */

// Couleurs cohérentes pour la richesse de contenu (du pauvre au premium)
$colorPauvre = '#dc3545';    // Rouge - contenu pauvre
$colorMoyen = '#fd7e14';     // Orange - moyen
$colorRiche = '#20c997';     // Vert clair - riche
$colorPremium = '#28a745';   // Vert foncé - premium

// Stats globales sur le word_count
// Tranches: Pauvre <=250, Moyen 250-500, Bon 500-1200, Premium 1200+
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_pages,
        ROUND(AVG(word_count), 0) as avg_words,
        MIN(word_count) as min_words,
        MAX(word_count) as max_words,
        PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY word_count) as median_words,
        SUM(word_count) as total_words,
        COUNT(CASE WHEN word_count <= 250 THEN 1 END) as pauvre_pages,
        COUNT(CASE WHEN word_count > 250 AND word_count <= 500 THEN 1 END) as moyen_pages,
        COUNT(CASE WHEN word_count > 500 AND word_count <= 1200 THEN 1 END) as riche_pages,
        COUNT(CASE WHEN word_count > 1200 THEN 1 END) as premium_pages
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
");
$stmt->execute([':crawl_id' => $crawlId]);
$globalStats = $stmt->fetch(PDO::FETCH_OBJ);

// Distribution du word_count par tranches (histogramme)
$sqlDistribution = "
    SELECT 
        CASE 
            WHEN word_count = 0 THEN '0 mots'
            WHEN word_count BETWEEN 1 AND 100 THEN '1-100'
            WHEN word_count BETWEEN 101 AND 300 THEN '101-300'
            WHEN word_count BETWEEN 301 AND 500 THEN '301-500'
            WHEN word_count BETWEEN 501 AND 800 THEN '501-800'
            WHEN word_count BETWEEN 801 AND 1200 THEN '801-1200'
            WHEN word_count BETWEEN 1201 AND 2000 THEN '1201-2000'
            WHEN word_count BETWEEN 2001 AND 3000 THEN '2001-3000'
            ELSE '3000+'
        END as word_range,
        COUNT(*) as page_count,
        CASE 
            WHEN word_count = 0 THEN 0
            WHEN word_count BETWEEN 1 AND 100 THEN 1
            WHEN word_count BETWEEN 101 AND 300 THEN 2
            WHEN word_count BETWEEN 301 AND 500 THEN 3
            WHEN word_count BETWEEN 501 AND 800 THEN 4
            WHEN word_count BETWEEN 801 AND 1200 THEN 5
            WHEN word_count BETWEEN 1201 AND 2000 THEN 6
            WHEN word_count BETWEEN 2001 AND 3000 THEN 7
            ELSE 8
        END as sort_order
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
    GROUP BY word_range, sort_order
    ORDER BY sort_order
";
$stmt = $pdo->prepare($sqlDistribution);
$stmt->execute([':crawl_id' => $crawlId]);
$distribution = $stmt->fetchAll(PDO::FETCH_OBJ);

// Moyenne et médiane de mots par catégorie
// Tranches: Pauvre <=250, Moyen 250-500, Bon 500-1200, Premium 1200+
$sqlByCategory = "
    SELECT 
        cat_id,
        COUNT(*) as total_pages,
        ROUND(AVG(word_count), 0) as avg_words,
        PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY word_count) as median_words,
        SUM(word_count) as total_words,
        MIN(word_count) as min_words,
        MAX(word_count) as max_words,
        COUNT(CASE WHEN word_count <= 250 THEN 1 END) as pauvre_pages,
        COUNT(CASE WHEN word_count > 250 AND word_count <= 500 THEN 1 END) as moyen_pages,
        COUNT(CASE WHEN word_count > 500 AND word_count <= 1200 THEN 1 END) as riche_pages,
        COUNT(CASE WHEN word_count > 1200 THEN 1 END) as premium_pages
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
    GROUP BY cat_id
    ORDER BY avg_words DESC
";
$stmt = $pdo->prepare($sqlByCategory);
$stmt->execute([':crawl_id' => $crawlId]);
$byCategory = $stmt->fetchAll(PDO::FETCH_OBJ);

// Convertir cat_id en nom de catégorie
$categoriesMap = $GLOBALS['categoriesMap'] ?? [];
foreach ($byCategory as $row) {
    $catInfo = $categoriesMap[$row->cat_id] ?? null;
    $row->category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
    $row->color = $catInfo ? $catInfo['color'] : '#95a5a6';
}

// Calculer les pourcentages pour les tranches de contenu
$totalPages = $globalStats->total_pages ?? 1;
$pauvrePercent = round(($globalStats->pauvre_pages / max(1, $totalPages)) * 100, 1);
$moyenPercent = round(($globalStats->moyen_pages / max(1, $totalPages)) * 100, 1);
$richePercent = round(($globalStats->riche_pages / max(1, $totalPages)) * 100, 1);
$premiumPercent = round(($globalStats->premium_pages / max(1, $totalPages)) * 100, 1);

/**
 * ============================================================================
 * AFFICHAGE HTML - Rendu de l'interface
 * ============================================================================
 */
?>

<h1 class="page-title">Richesse de contenu</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1 : Cartes statistiques
         ======================================== -->
    <div class="scorecards">
    <?php
    Component::card([
        'color' => 'primary',
        'icon' => 'article',
        'title' => 'Pages analysées',
        'value' => number_format($globalStats->total_pages ?? 0),
        'desc' => number_format($globalStats->total_words ?? 0) . ' mots sur ' . number_format($globalStats->total_pages ?? 0) . ' pages indexables'
    ]);
    
    Component::card([
        'color' => 'info',
        'icon' => 'format_size',
        'title' => 'Médiane',
        'value' => number_format($globalStats->median_words ?? 0),
        'desc' => 'Moyenne : ' . number_format($globalStats->avg_words ?? 0) . ' mots'
    ]);
    
    Component::card([
        'color' => 'error',
        'icon' => 'warning',
        'title' => 'Contenu pauvre',
        'value' => $pauvrePercent . '%',
        'desc' => number_format($globalStats->pauvre_pages ?? 0) . ' pages (≤250 mots)'
    ]);
    
    Component::card([
        'color' => 'success',
        'icon' => 'trending_up',
        'title' => 'Contenu riche',
        'value' => $richePercent . '%',
        'desc' => number_format($globalStats->riche_pages ?? 0) . ' pages (500-1200 mots)'
    ]);
    
    Component::card([
        'color' => 'success',
        'icon' => 'auto_awesome',
        'title' => 'Contenu premium',
        'value' => $premiumPercent . '%',
        'desc' => number_format($globalStats->premium_pages ?? 0) . ' pages (1200+ mots)'
    ]);
    ?>
    </div>

    <!-- ========================================
         SECTION 2 : Graphiques
         ======================================== -->
    <div class="charts-grid">
        <?php
        // Histogramme de distribution des mots
        if (!empty($distribution)) {
            $ranges = [];
            $counts = [];
            $colors = [];
            
            foreach($distribution as $row) {
                $ranges[] = $row->word_range;
                $counts[] = (int)$row->page_count;
                
                // Couleurs basées sur la qualité du contenu (rouge → orange → vert)
                // Pauvre <=250, Moyen 250-500, Bon 500-1200, Premium 1200+
                if ($row->word_range === '0 mots' || $row->word_range === '1-100' || $row->word_range === '101-300') {
                    $colors[] = $colorPauvre; // Rouge - pauvre
                } elseif ($row->word_range === '301-500') {
                    $colors[] = $colorMoyen; // Orange - moyen
                } elseif ($row->word_range === '501-800' || $row->word_range === '801-1200') {
                    $colors[] = $colorRiche; // Vert clair - riche
                } else {
                    $colors[] = $colorPremium; // Vert foncé - premium
                }
            }
            
            Component::chart([
                'type' => 'bar',
                'title' => 'Distribution du nombre de mots',
                'subtitle' => 'Nombre de pages par tranche de mots',
                'categories' => $ranges,
                'series' => [
                    [
                        'name' => 'Pages',
                        'data' => $counts,
                        'colorByPoint' => true,
                        'colors' => $colors
                    ]
                ],
                'height' => 350,
                'sqlQuery' => $sqlDistribution
            ]);
        }
        
        // Donut des catégories de contenu
        $donutData = [
            ['name' => 'Pauvre (≤250)', 'y' => (int)($globalStats->pauvre_pages ?? 0), 'color' => $colorPauvre],
            ['name' => 'Moyen (250-500)', 'y' => (int)($globalStats->moyen_pages ?? 0), 'color' => $colorMoyen],
            ['name' => 'Riche (500-1200)', 'y' => (int)($globalStats->riche_pages ?? 0), 'color' => $colorRiche],
            ['name' => 'Premium (1200+)', 'y' => (int)($globalStats->premium_pages ?? 0), 'color' => $colorPremium]
        ];
        
        Component::chart([
            'type' => 'donut',
            'title' => 'Richesse du contenu',
            'subtitle' => 'Répartition par volume de mots',
            'series' => [
                [
                    'name' => 'Pages',
                    'data' => $donutData
                ]
            ],
            'height' => 350,
            'legendPosition' => 'bottom'
        ]);
        ?>
    </div>
    
    <!-- Graphique 100% stacked : Répartition du contenu par catégorie -->
    <div style="width: 100%;">
        <?php
        if (!empty($byCategory)) {
            $categories = [];
            $pauvreData = [];
            $moyenData = [];
            $richeData = [];
            $premiumData = [];
            
            foreach($byCategory as $row) {
                $categories[] = $row->category;
                $total = $row->total_pages > 0 ? $row->total_pages : 1;
                // Pourcentages pour le stacked 100%
                $pauvreData[] = round(($row->pauvre_pages / $total) * 100, 1);
                $moyenData[] = round(($row->moyen_pages / $total) * 100, 1);
                $richeData[] = round(($row->riche_pages / $total) * 100, 1);
                $premiumData[] = round(($row->premium_pages / $total) * 100, 1);
            }
            
            Component::chart([
                'type' => 'bar',
                'stacking' => 'percent',
                'title' => 'Répartition de la qualité par catégorie',
                'subtitle' => 'Du contenu premium (bas) au contenu pauvre (haut)',
                'categories' => $categories,
                'series' => [
                    ['name' => 'Pauvre (≤250)', 'data' => $pauvreData, 'color' => $colorPauvre],
                    ['name' => 'Moyen (250-500)', 'data' => $moyenData, 'color' => $colorMoyen],
                    ['name' => 'Riche (500-1200)', 'data' => $richeData, 'color' => $colorRiche],
                    ['name' => 'Premium (1200+)', 'data' => $premiumData, 'color' => $colorPremium]
                ],
                'height' => max(300, count($categories) * 45),
                'sqlQuery' => $sqlByCategory
            ]);
        }
        ?>
    </div>
    
    <!-- Graphique pleine largeur : Moyenne et Médiane par catégorie -->
    <div style="width: 100%;">
        <?php
        if (!empty($byCategory)) {
            $categories = [];
            $avgWords = [];
            $medianWords = [];
            
            foreach($byCategory as $row) {
                $categories[] = $row->category;
                $avgWords[] = (int)$row->avg_words;
                $medianWords[] = (int)$row->median_words;
            }
            
            Component::chart([
                'type' => 'horizontalBar',
                'title' => 'Médiane et Moyenne de mots par catégorie',
                'subtitle' => 'Du plus riche au moins riche',
                'categories' => $categories,
                'series' => [
                    [
                        'name' => 'Médiane',
                        'data' => $medianWords,
                        'color' => '#4ECDC4'
                    ],
                    [
                        'name' => 'Moyenne',
                        'data' => $avgWords,
                        'color' => '#95a5a6'
                    ]
                ],
                'height' => max(250, count($categories) * 50),
                'sqlQuery' => $sqlByCategory
            ]);
        }
        ?>
    </div>

    <!-- ========================================
         SECTION 3 : Tableau du contenu par catégorie
         ======================================== -->
    <?php
    $categoryTableData = [];
    foreach($byCategory as $row) {
        $categoryTableData[] = [
            'category' => $row->category,
            'category_color' => $row->color,
            'total_pages' => number_format($row->total_pages, 0, ',', ' '),
            'median_words' => number_format($row->median_words, 0, ',', ' '),
            'avg_words' => number_format($row->avg_words, 0, ',', ' '),
            'min_words' => number_format($row->min_words, 0, ',', ' '),
            'max_words' => number_format($row->max_words, 0, ',', ' '),
            'total_words' => number_format($row->total_words, 0, ',', ' ')
        ];
    }
    
    if (!empty($categoryTableData)) {
        Component::simpleTable([
            'title' => 'Statistiques par catégorie',
            'subtitle' => 'Détail du nombre de mots par segment',
            'columns' => [
                ['key' => 'category', 'label' => 'Catégorie', 'type' => 'category'],
                ['key' => 'total_pages', 'label' => 'Pages', 'type' => 'default'],
                ['key' => 'median_words', 'label' => 'Médiane', 'type' => 'bold'],
                ['key' => 'avg_words', 'label' => 'Moyenne', 'type' => 'default'],
                ['key' => 'min_words', 'label' => 'Min', 'type' => 'default'],
                ['key' => 'max_words', 'label' => 'Max', 'type' => 'default'],
                ['key' => 'total_words', 'label' => 'Total mots', 'type' => 'default']
            ],
            'data' => $categoryTableData
        ]);
    }
    ?>

    <!-- ========================================
         SECTION 4 : Pages contenu pauvre
         ======================================== -->
    <?php
    Component::urlTable([
        'title' => 'Contenu pauvre (≤250 mots)',
        'id' => 'pauvre_content_pages',
        'whereClause' => 'WHERE c.crawled = true AND c.compliant = true AND c.word_count <= 250',
        'orderBy' => 'ORDER BY c.word_count ASC',
        'defaultColumns' => ['url', 'word_count', 'depth', 'category', 'inlinks'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>

    <!-- ========================================
         SECTION 5 : Pages les plus riches
         ======================================== -->
    <?php
    Component::urlTable([
        'title' => 'Contenu le plus riche',
        'id' => 'rich_content_pages',
        'whereClause' => 'WHERE c.crawled = true AND c.compliant = true AND c.word_count > 0',
        'orderBy' => 'ORDER BY c.word_count DESC',
        'defaultColumns' => ['url', 'word_count', 'depth', 'category', 'inlinks'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>

</div>
