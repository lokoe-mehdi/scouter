<?php
/**
 * Analyse des Inlinks (PostgreSQL)
 * $crawlId est défini dans dashboard.php
 */
try {
    // Distribution des inlinks (nombre d'URLs par nombre d'inlinks)
    $sqlInlinksDistribution = "
        SELECT 
            inlinks,
            COUNT(*) as url_count
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
        GROUP BY inlinks
        ORDER BY inlinks ASC
    ";
    $stmt = $pdo->prepare($sqlInlinksDistribution);
    $stmt->execute([':crawl_id' => $crawlId]);
    $inlinksDistribution = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    // Calcul du total d'URLs pour les pourcentages
    $totalUrls = array_sum(array_column($inlinksDistribution, 'url_count'));
    
    // Calcul des pourcentages cumulés
    $cumulativeData = [];
    $cumulative = 0;
    
    // Ajouter un point à 0% avec la valeur max d'inlinks pour avoir une ligne horizontale au début
    if(!empty($inlinksDistribution)) {
        $cumulativeData[] = [
            'inlinks' => $inlinksDistribution[0]->inlinks,
            'percentage' => 0
        ];
    }
    
    foreach($inlinksDistribution as $row) {
        $cumulative += $row->url_count;
        $percentage = $totalUrls > 0 ? ($cumulative / $totalUrls) * 100 : 0;
        $cumulativeData[] = [
            'inlinks' => $row->inlinks,
            'percentage' => round($percentage, 2)
        ];
    }
    
    // Moyenne d'inlinks par catégorie (sans jointure)
    $stmt = $pdo->prepare("
        SELECT 
            cat_id,
            COUNT(id) as url_count,
            ROUND(AVG(inlinks)::numeric, 2) as avg_inlinks,
            MIN(inlinks) as min_inlinks,
            MAX(inlinks) as max_inlinks,
            SUM(inlinks) as total_inlinks
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
        GROUP BY cat_id
        ORDER BY AVG(inlinks) DESC
    ");
    $stmt->execute([':crawl_id' => $crawlId]);
    $inlinksByCategoryRaw = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    // Convertir cat_id en nom de catégorie
    $categoriesMap = $GLOBALS['categoriesMap'] ?? [];
    $inlinksByCategory = [];
    foreach ($inlinksByCategoryRaw as $row) {
        $catInfo = $categoriesMap[$row->cat_id] ?? null;
        $row->category = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
        $inlinksByCategory[] = $row;
    }
    
    // Statistiques globales (renommer pour éviter conflit avec $globalStats de dashboard)
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_urls,
            ROUND(AVG(inlinks)::numeric, 2) as avg_inlinks,
            MIN(inlinks) as min_inlinks,
            MAX(inlinks) as max_inlinks,
            SUM(inlinks) as total_inlinks
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
    ");
    $stmt->execute([':crawl_id' => $crawlId]);
    $inlinksStats = $stmt->fetch(PDO::FETCH_OBJ);
    
    // URLs avec 0 ou 1 inlinks (sans jointure)
    $stmt = $pdo->prepare("
        SELECT 
            url,
            inlinks,
            depth,
            code,
            cat_id
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true AND inlinks <= 1
        ORDER BY inlinks ASC, url ASC
        LIMIT 100
    ");
    $stmt->execute([':crawl_id' => $crawlId]);
    $lowInlinksUrls = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    // Ajouter le nom de catégorie
    foreach ($lowInlinksUrls as $row) {
        $catInfo = $categoriesMap[$row->cat_id] ?? null;
        $row->category = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    }
    
} catch(PDOException $e) {
    echo "<div class='alert alert-error'>" . __('common.sql_error') . ": " . htmlspecialchars($e->getMessage()) . "</div>";
    $inlinksDistribution = [];
    $inlinksByCategory = [];
    $cumulativeData = [];
    $inlinksStats = null;
    $lowInlinksUrls = [];
}
?>

<style>
.inlinks-layout {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: var(--card-bg);
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.stat-card-label {
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
}

.stat-card-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--primary-color);
}

.chart-container {
    background: var(--card-bg);
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.chart-container h2 {
    margin: 0 0 1rem 0;
    color: var(--text-primary);
    font-size: 1.3rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.table-container {
    background: var(--card-bg);
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: var(--background);
}

.data-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: var(--text-primary);
    border-bottom: 2px solid var(--border-color);
}

.data-table td {
    padding: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.data-table tbody tr:hover {
    background: var(--background);
}

</style>

<h1 class="page-title"><?= __('inlinks.page_title') ?></h1>

<div class="inlinks-layout">
    <!-- Statistiques globales -->
    <?php if($inlinksStats): ?>
    <div class="scorecards">
        <?php
        Component::card([
            'color' => 'primary',
            'icon' => 'language',
            'title' => __('inlinks.card_total'),
            'value' => number_format($inlinksStats->total_urls),
            'desc' => __('inlinks.card_total_desc')
        ]);
        
        Component::card([
            'color' => 'info',
            'icon' => 'link',
            'title' => __('inlinks.card_avg'),
            'value' => $inlinksStats->avg_inlinks,
            'desc' => __('inlinks.card_avg_desc')
        ]);
        
        Component::card([
            'color' => 'success',
            'icon' => 'trending_up',
            'title' => __('inlinks.card_minmax'),
            'value' => $inlinksStats->min_inlinks . ' / ' . $inlinksStats->max_inlinks,
            'desc' => __('inlinks.card_minmax_desc')
        ]);
        
        Component::card([
            'color' => 'warning',
            'icon' => 'hub',
            'title' => __('inlinks.card_total_inlinks'),
            'value' => number_format($inlinksStats->total_inlinks),
            'desc' => __('inlinks.card_total_inlinks_desc')
        ]);
        ?>
    </div>
    <?php endif; ?>

    <!-- Graphique de distribution cumulative -->
    <div>
        <?php
        // Préparation des données pour le graphique area
        $chartData = array_map(function($d) {
            return [$d['percentage'], $d['inlinks']];
        }, $cumulativeData);
        
        Component::chart([
            'type' => 'area',
            'title' => __('inlinks.chart_distribution'),
            'subtitle' => __('inlinks.chart_distribution_desc'),
            'categories' => [],
            'series' => [
                [
                    'name' => __('inlinks.series_inlinks'),
                    'data' => $chartData,
                    'color' => '#4ECDC4'
                ]
            ],
            'xAxisTitle' => __('inlinks.label_pct_urls'),
            'yAxisTitle' => __('inlinks.label_inlinks_log'),
            'logarithmic' => true,
            'xAxisMin' => 0,
            'xAxisMax' => 100,
            'height' => 400,
            'tooltipFormat' => __('inlinks.tooltip'),
            'sqlQuery' => $sqlInlinksDistribution
        ]); ?>
    </div>

    <?php
    // Préparer les données pour le tableau
    $inlinksTableData = [];
    foreach ($inlinksByCategory as $row) {
        $inlinksTableData[] = [
            'category' => $row->category,
            'url_count' => number_format($row->url_count),
            'avg_inlinks' => $row->avg_inlinks,
            'min_inlinks' => $row->min_inlinks,
            'max_inlinks' => $row->max_inlinks,
            'total_inlinks' => number_format($row->total_inlinks)
        ];
    }

    Component::simpleTable([
        'title' => __('inlinks.table_category'),
        'subtitle' => __('inlinks.table_category_desc'),
        'columns' => [
            ['key' => 'category', 'label' => __('common.category'), 'type' => 'badge-color'],
            ['key' => 'url_count', 'label' => __('inlinks.col_url_count'), 'type' => 'default'],
            ['key' => 'avg_inlinks', 'label' => __('common.average'), 'type' => 'bold'],
            ['key' => 'min_inlinks', 'label' => __('common.min'), 'type' => 'default'],
            ['key' => 'max_inlinks', 'label' => __('common.max'), 'type' => 'default'],
            ['key' => 'total_inlinks', 'label' => __('common.total'), 'type' => 'default']
        ],
        'data' => $inlinksTableData
    ]);
    ?>

    <!-- Liste des URLs -->
    <?php
    Component::urlTable([
        'title' => __('inlinks.table_low'),
        'id' => 'inlinkstable',
        'whereClause' => 'WHERE c.compliant = true AND c.inlinks <= 5',
        'orderBy' => 'ORDER BY c.inlinks ASC, c.pri ASC',
        'defaultColumns' => ['url','category', 'code','depth','inlinks','pri'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>
