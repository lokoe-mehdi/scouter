<?php
/**
 * Analyse des temps de réponse (PostgreSQL)
 * $crawlId est défini dans dashboard.php
 */
try {
    // Statistiques globales (URLs compliant = code 200)
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_urls,
            ROUND(AVG(response_time)::numeric, 2) as avg_time,
            MAX(response_time) as max_time
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true AND code = 200 AND is_html = true
    ");
    $stmt->execute([':crawl_id' => $crawlId]);
    $responseStats = $stmt->fetch(PDO::FETCH_OBJ);
    
    // Calcul de la médiane avec PERCENTILE_CONT en PostgreSQL
    $stmt = $pdo->prepare("
        SELECT PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY response_time) as median_time
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true AND code = 200 AND is_html = true
    ");
    $stmt->execute([':crawl_id' => $crawlId]);
    $medianResult = $stmt->fetch(PDO::FETCH_OBJ);
    
    $responseStats->median_time = $medianResult ? round($medianResult->median_time, 2) : 0;
    
    // Statistiques par catégorie (sans jointure)
    $sqlResponseByCategory = "
        SELECT 
            cat_id,
            COUNT(*) as total_urls,
            SUM(CASE WHEN response_time < 200 THEN 1 ELSE 0 END) as fast_count,
            SUM(CASE WHEN response_time >= 200 AND response_time < 600 THEN 1 ELSE 0 END) as medium_count,
            SUM(CASE WHEN response_time >= 600 THEN 1 ELSE 0 END) as slow_count,
            ROUND(AVG(response_time)::numeric, 2) as avg_time
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true AND code = 200 AND is_html = true
        GROUP BY cat_id
        ORDER BY AVG(response_time) DESC
    ";
    $stmt = $pdo->prepare($sqlResponseByCategory);
    $stmt->execute([':crawl_id' => $crawlId]);
    $categoryStatsRaw = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    // Convertir cat_id en nom de catégorie
    $categoriesMap = $GLOBALS['categoriesMap'] ?? [];
    $categoryStats = [];
    foreach ($categoryStatsRaw as $row) {
        $catInfo = $categoriesMap[$row->cat_id] ?? null;
        $row->category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
        $categoryStats[] = $row;
    }
    
} catch(PDOException $e) {
    echo "<div class='alert alert-error'>Erreur SQL: " . htmlspecialchars($e->getMessage()) . "</div>";
    $responseStats = null;
    $categoryStats = [];
}
?>

<style>
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

.stat-card-unit {
    font-size: 1rem;
    color: var(--text-secondary);
    margin-left: 0.25rem;
}

.legend-box {
    background: var(--card-bg);
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    display: flex;
    gap: 2rem;
    align-items: center;
    flex-wrap: wrap;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.legend-color {
    width: 24px;
    height: 24px;
    border-radius: 4px;
}

.legend-color.fast {
    background: #2ECC71;
}

.legend-color.medium {
    background: #F39C12;
}

.legend-color.slow {
    background: #E74C3C;
}

.category-performance {
    background: var(--card-bg);
    padding: 1.5rem;
    padding-top: 2.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    width: 100%;
    overflow: visible;
}

.category-performance h2 {
    margin: 0 0 1.5rem 0;
    color: var(--text-primary);
    font-size: 1.3rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.category-item {
    margin-bottom: 1.5rem;
    width: 100%;
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: 1rem;
    align-items: start;
    border: none !important;
    background: transparent !important;
    box-shadow: none !important;
    padding: 0 !important;
}

.category-name {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 1rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding-top: 2px;
}

.category-avg {
    font-size: 0.85rem;
    color: var(--text-secondary);
    text-align: right;
    margin-top: 0.5rem;
}

.performance-bar {
    display: flex;
    width: 100%;
    height: 24px;
    border-radius: 4px;
    overflow: visible;
    background: transparent;
    border: 1px solid var(--border-color);
    position: relative;
}

.performance-segment {
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.75rem;
    font-weight: 600;
    transition: all 0.3s;
    cursor: help;
    position: relative;
}

.performance-segment:first-child {
    border-top-left-radius: 4px;
    border-bottom-left-radius: 4px;
}

.performance-segment:last-child {
    border-top-right-radius: 4px;
    border-bottom-right-radius: 4px;
}

.performance-segment:hover {
    opacity: 0.85;
    filter: brightness(1.1);
}

.performance-segment::after {
    content: attr(data-tooltip);
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0, 0, 0, 0.92);
    color: white;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 500;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.25s ease, visibility 0.25s ease, transform 0.25s ease;
    z-index: 9999;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.performance-segment:hover::after {
    opacity: 1;
    visibility: visible;
    transform: translateX(-50%) translateY(-4px);
}

/* Tooltip à droite pour les segments à gauche */
.performance-segment:first-child::after {
    left: 0;
    transform: translateX(0);
}

.performance-segment:first-child:hover::after {
    transform: translateX(0) translateY(-4px);
}

/* Tooltip à gauche pour les segments à droite */
.performance-segment:last-child::after {
    left: auto;
    right: 0;
    transform: translateX(0);
}

.performance-segment:last-child:hover::after {
    transform: translateX(0) translateY(-4px);
}

.performance-segment.fast {
    background: #2ECC71;
}

.performance-segment.medium {
    background: #F39C12;
}

.performance-segment.slow {
    background: #E74C3C;
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

.badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 500;
}

.badge-primary {
    background: var(--primary-color);
    color: white;
}

.badge-danger {
    background: var(--danger);
    color: white;
}
</style>

<h1 class="page-title" title="Time To First Byte">Analyse du TTFB</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">
<!-- Statistiques globales -->
<?php if($responseStats): ?>
<div class="scorecards">
        <?php
        Component::card([
            'color' => 'primary',
            'icon' => 'language',
            'title' => 'Total d\'URLs',
            'value' => number_format($responseStats->total_urls ?? 0),
            'desc' => 'URLs avec code 200'
        ]);
        
        Component::card([
            'color' => 'info',
            'icon' => 'speed',
            'title' => 'TTFB moyen',
            'value' => number_format($responseStats->avg_time ?? 0, 0, '.', ' ') . ' ms',
            'desc' => 'Time To First Byte moyen'
        ]);
        
        Component::card([
            'color' => 'success',
            'icon' => 'timer',
            'title' => 'TTFB médian',
            'value' => number_format($responseStats->median_time ?? 0, 0, '.', ' ') . ' ms',
            'desc' => 'Time To First Byte médian'
        ]);
        
        Component::card([
            'color' => 'warning',
            'icon' => 'trending_up',
            'title' => 'TTFB maximum',
            'value' => number_format($responseStats->max_time ?? 0, 0, '.', ' ') . ' ms',
            'desc' => 'Time To First Byte le plus lent'
        ]);
        ?>
</div>
<?php endif; ?>

<!-- Performance par catégorie -->
<div>
    <?php
    // Préparation des données pour le graphique horizontal empilé
    $categories = [];
    $fastData = [];
    $mediumData = [];
    $slowData = [];

    foreach($categoryStats as $cat) {
        $categories[] = $cat->category;
        $fastData[] = (int)($cat->fast_count ?? 0);
        $mediumData[] = (int)($cat->medium_count ?? 0);
        $slowData[] = (int)($cat->slow_count ?? 0);
    }

    Component::chart([
        'type' => 'horizontalBar',
        'title' => 'Performance par catégorie',
        'subtitle' => 'Répartition du TTFB par catégorie',
        'categories' => $categories,
        'series' => [
            [
                'name' => 'Lent (> 600ms)',
                'data' => $slowData,
                'color' => '#d86b6bff'  // Rouge
            ],
            [
                'name' => 'Correct (200-600ms)',
                'data' => $mediumData,
                'color' => '#d8bf6bff'  // Orange
            ],
            [
                'name' => 'Rapide (< 200ms)',
                'data' => $fastData,
                'color' => '#6bd899ff'  // Vert
            ]
        ],
        'yAxisTitle' => 'Pourcentage',
        'yAxisMax' => 100,
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlResponseByCategory
    ]);
    ?>
</div>

<!-- Tableau des URLs lentes -->
<?php
Component::urlTable([
    'title' => 'URLs lentes - TTFB > 600ms',
    'id' => 'responsetimetable',
    'whereClause' => 'WHERE c.response_time >= 600 AND (code=200 OR code=304) AND c.is_html = true',
    'orderBy' => 'ORDER BY c.response_time DESC',
    'defaultColumns' => ['url','category', 'code', 'response_time'],
    'pdo' => $pdo,
    'crawlId' => $crawlId,
    'perPage' => 10,
    'projectDir' => $_GET['project'] ?? ''
]);
?>
</div>
