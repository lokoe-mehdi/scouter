<?php
/**
 * Analyse des Outlinks (PostgreSQL)
 * $crawlId est défini dans dashboard.php
 */
try {
    // Distribution des outlinks (nombre d'URLs par nombre d'outlinks)
    $sqlOutlinksDistribution = "
        SELECT 
            outlinks,
            COUNT(*) as url_count
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
        GROUP BY outlinks
        ORDER BY outlinks DESC
    ";
    $stmt = $pdo->prepare($sqlOutlinksDistribution);
    $stmt->execute([':crawl_id' => $crawlId]);
    $outlinksDistribution = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    // Calcul du total d'URLs pour les pourcentages
    $totalUrls = array_sum(array_column($outlinksDistribution, 'url_count'));
    
    // Calcul des pourcentages cumulés (en partant du plus grand nombre d'outlinks)
    $cumulativeData = [];
    $cumulative = 0;
    
    // Ajouter un point à 0% avec la valeur max d'outlinks pour avoir une ligne horizontale au début
    if(!empty($outlinksDistribution)) {
        $cumulativeData[] = [
            'outlinks' => $outlinksDistribution[0]->outlinks,
            'url_count' => 0,
            'percentage' => 0
        ];
    }
    
    foreach($outlinksDistribution as $row) {
        $cumulative += $row->url_count;
        $percentage = $totalUrls > 0 ? ($cumulative / $totalUrls) * 100 : 0;
        $cumulativeData[] = [
            'outlinks' => $row->outlinks,
            'url_count' => $row->url_count,
            'percentage' => round($percentage, 2)
        ];
    }
    
    // Moyenne d'outlinks par catégorie (sans jointure)
    $stmt = $pdo->prepare("
        SELECT 
            cat_id,
            COUNT(id) as url_count,
            ROUND(AVG(outlinks)::numeric, 2) as avg_outlinks,
            MIN(outlinks) as min_outlinks,
            MAX(outlinks) as max_outlinks,
            SUM(outlinks) as total_outlinks
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
        GROUP BY cat_id
        ORDER BY AVG(outlinks) DESC
    ");
    $stmt->execute([':crawl_id' => $crawlId]);
    $outlinksByCategoryRaw = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    // Convertir cat_id en nom de catégorie
    $categoriesMap = $GLOBALS['categoriesMap'] ?? [];
    $outlinksByCategory = [];
    foreach ($outlinksByCategoryRaw as $row) {
        $catInfo = $categoriesMap[$row->cat_id] ?? null;
        $row->category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
        $outlinksByCategory[] = $row;
    }
    
    // Statistiques globales (renommer pour éviter conflit)
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_urls,
            ROUND(AVG(outlinks)::numeric, 2) as avg_outlinks,
            MIN(outlinks) as min_outlinks,
            MAX(outlinks) as max_outlinks,
            SUM(outlinks) as total_outlinks
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
    ");
    $stmt->execute([':crawl_id' => $crawlId]);
    $outlinksStats = $stmt->fetch(PDO::FETCH_OBJ);
    
    // Top 100 des URLs avec le plus d'outlinks (sans jointure)
    $stmt = $pdo->prepare("
        SELECT 
            url,
            outlinks,
            depth,
            code,
            cat_id
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
        ORDER BY outlinks DESC, url ASC
        LIMIT 100
    ");
    $stmt->execute([':crawl_id' => $crawlId]);
    $topOutlinksUrls = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    // Ajouter le nom de catégorie
    foreach ($topOutlinksUrls as $row) {
        $catInfo = $categoriesMap[$row->cat_id] ?? null;
        $row->category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
    }
    
} catch(PDOException $e) {
    echo "<div class='alert alert-error'>Erreur SQL: " . htmlspecialchars($e->getMessage()) . "</div>";
    $outlinksDistribution = [];
    $outlinksByCategory = [];
    $cumulativeData = [];
    $outlinksStats = null;
    $topOutlinksUrls = [];
}
?>

<style>
.outlinks-layout {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
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

<h1 class="page-title">Analyse des Outlinks</h1>

<div class="outlinks-layout">
    <!-- Statistiques globales -->
    <?php if($outlinksStats): ?>
    <div class="scorecards">
        <?php
        Component::card([
            'color' => 'primary',
            'icon' => 'language',
            'title' => 'Total d\'URLs',
            'value' => number_format($outlinksStats->total_urls),
            'desc' => 'URLs analysées'
        ]);
        
        Component::card([
            'color' => 'info',
            'icon' => 'open_in_new',
            'title' => 'Moyenne d\'outlinks',
            'value' => $outlinksStats->avg_outlinks,
            'desc' => 'Liens sortants moyens'
        ]);
        
        Component::card([
            'color' => 'success',
            'icon' => 'trending_up',
            'title' => 'Min / Max',
            'value' => $outlinksStats->min_outlinks . ' / ' . $outlinksStats->max_outlinks,
            'desc' => 'Intervalle d\'outlinks'
        ]);
        
        Component::card([
            'color' => 'warning',
            'icon' => 'hub',
            'title' => 'Total d\'outlinks',
            'value' => number_format($outlinksStats->total_outlinks),
            'desc' => 'Liens sortants totaux'
        ]);
        ?>
    </div>
    <?php endif; ?>

    <!-- Graphique de distribution -->
    <div>
        <?php
        // Préparation des données pour le graphique area
        $chartData = array_map(function($d) {
            return [$d['percentage'], $d['outlinks']];
        }, $cumulativeData);
        
        Component::chart([
            'type' => 'area',
            'title' => 'Distribution des outlinks',
            'subtitle' => 'Ce graphique montre le pourcentage d\'URLs (axe Y) ayant au moins X outlinks (axe X), en partant des pages avec le plus d\'outlinks. Permet d\'identifier rapidement les pages avec trop de liens sortants.',
            'categories' => [],
            'series' => [
                [
                    'name' => 'Outlinks',
                    'data' => $chartData,
                    'color' => '#4ECDC4'
                ]
            ],
            'xAxisTitle' => 'Pourcentage d\'URLs (%)',
            'yAxisTitle' => 'Nombre d\'outlinks (échelle logarithmique)',
            'logarithmic' => true,
            'xAxisMin' => 0,
            'xAxisMax' => 100,
            'height' => 400,
            'tooltipFormat' => '<b>{x}%</b> des URLs ont <b>{y} outlinks</b> ou plus.',
            'sqlQuery' => $sqlOutlinksDistribution
        ]);
        ?>
    </div>

    <?php
    // Préparer les données pour le tableau
    $outlinksTableData = [];
    foreach ($outlinksByCategory as $row) {
        $outlinksTableData[] = [
            'category' => $row->category,
            'url_count' => number_format($row->url_count),
            'avg_outlinks' => $row->avg_outlinks,
            'min_outlinks' => $row->min_outlinks,
            'max_outlinks' => $row->max_outlinks,
            'total_outlinks' => number_format($row->total_outlinks)
        ];
    }

    Component::simpleTable([
        'title' => 'Outlinks moyens par catégorie',
        'subtitle' => 'Statistiques des liens sortants par type de page',
        'columns' => [
            ['key' => 'category', 'label' => 'Catégorie', 'type' => 'badge-color'],
            ['key' => 'url_count', 'label' => 'Nombre d\'URLs', 'type' => 'default'],
            ['key' => 'avg_outlinks', 'label' => 'Moyenne', 'type' => 'bold'],
            ['key' => 'min_outlinks', 'label' => 'Min', 'type' => 'default'],
            ['key' => 'max_outlinks', 'label' => 'Max', 'type' => 'default'],
            ['key' => 'total_outlinks', 'label' => 'Total', 'type' => 'default']
        ],
        'data' => $outlinksTableData
    ]);
    ?>


<?php
Component::urlTable([
    'title' => 'Top pages avec le plus d\'outlinks',
    'id' => 'outlinksTable',
    'whereClause' => 'WHERE c.compliant = true',
    'orderBy' => 'ORDER BY c.outlinks DESC',
    'defaultColumns' => ['url','category', 'code','depth','outlinks','pri'],
    'pdo' => $pdo,
    'crawlId' => $crawlId,
    'perPage' => 10,
    'projectDir' => $_GET['project'] ?? ''
]);
?>
