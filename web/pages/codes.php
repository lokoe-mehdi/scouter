<?php
/**
 * ============================================================================
 * REQUÊTES SQL - Récupération des données (PostgreSQL)
 * ============================================================================
 * $crawlId est défini dans dashboard.php
 */

// Récupération du filtre et recherche
$filterCode = isset($_GET['filter_code']) ? (int)$_GET['filter_code'] : -1;
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Codes disponibles
$stmt = $pdo->prepare("
    SELECT DISTINCT code 
    FROM pages 
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true
    ORDER BY code
");
$stmt->execute([':crawl_id' => $crawlId]);
$codes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Stats par famille de codes (0xx, 1xx, 2xx, 3xx, 4xx, 5xx)
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN code = 0 THEN 1 ELSE 0 END) as code_0xx,
        SUM(CASE WHEN code >= 100 AND code < 200 THEN 1 ELSE 0 END) as code_1xx,
        SUM(CASE WHEN code >= 200 AND code < 300 THEN 1 ELSE 0 END) as code_2xx,
        SUM(CASE WHEN code >= 300 AND code < 400 THEN 1 ELSE 0 END) as code_3xx,
        SUM(CASE WHEN code >= 400 AND code < 500 THEN 1 ELSE 0 END) as code_4xx,
        SUM(CASE WHEN code >= 500 AND code < 600 THEN 1 ELSE 0 END) as code_5xx
    FROM pages 
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true
");
$stmt->execute([':crawl_id' => $crawlId]);
$codeFamilyStats = $stmt->fetch(PDO::FETCH_OBJ);

// Distribution par code HTTP avec moyennes
$sqlCodeStats = "
    SELECT 
        code, 
        COUNT(*) as total,
        AVG(response_time) as avg_time,
        AVG(inlinks) as avg_inlinks,
        AVG(outlinks) as avg_outlinks
    FROM pages 
    WHERE crawl_id = :crawl_id AND crawled = true AND is_html = true
    GROUP BY code 
    ORDER BY COUNT(*) DESC
";
$stmt = $pdo->prepare($sqlCodeStats);
$stmt->execute([':crawl_id' => $crawlId]);
$codeStats = $stmt->fetchAll(PDO::FETCH_OBJ);

// Distribution par catégorie et code HTTP (sans jointure, on utilise le tableau PHP)
$sqlCodeByCategory = "
    SELECT 
        p.cat_id,
        p.code,
        COUNT(*) as count
    FROM pages p
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.is_html = true
    GROUP BY p.cat_id, p.code
    ORDER BY count DESC, p.cat_id, p.code
";
$stmt = $pdo->prepare($sqlCodeByCategory);
$stmt->execute([':crawl_id' => $crawlId]);
$codeByCategory = $stmt->fetchAll(PDO::FETCH_OBJ);

// Convertir cat_id en nom de catégorie via le tableau global
$categoriesMap = $GLOBALS['categoriesMap'] ?? [];
foreach ($codeByCategory as $row) {
    $catInfo = $categoriesMap[$row->cat_id] ?? null;
    $row->category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
}

/**
 * ============================================================================
 * AFFICHAGE HTML - Rendu de l'interface
 * ============================================================================
 */
?>

<h1 class="page-title">Analyse des codes de réponse HTTP</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1 : Cartes statistiques
         ======================================== -->
    <div class="scorecards">
    <?php
    if ($codeFamilyStats->code_0xx > 0) {
        Component::card([
            'color' => 'error',
            'icon' => 'schedule',
            'title' => 'Timeout',
            'value' => number_format($codeFamilyStats->code_0xx),
            'desc' => 'Code 0'
        ]);
    }
    
    if ($codeFamilyStats->code_1xx > 0) {
        Component::card([
            'color' => 'info',
            'icon' => 'info',
            'title' => 'Codes 1xx',
            'value' => number_format($codeFamilyStats->code_1xx),
            'desc' => 'Informationnel'
        ]);
    }
    
    Component::card([
        'color' => 'success',
        'icon' => 'check_circle',
        'title' => 'Codes 2xx',
        'value' => number_format($codeFamilyStats->code_2xx),
        'desc' => 'Succès'
    ]);
    
    Component::card([
        'color' => 'warning',
        'icon' => 'arrow_forward',
        'title' => 'Codes 3xx',
        'value' => number_format($codeFamilyStats->code_3xx),
        'desc' => 'Redirections'
    ]);
    
    Component::card([
        'color' => 'error',
        'icon' => 'warning',
        'title' => 'Codes 4xx',
        'value' => number_format($codeFamilyStats->code_4xx),
        'desc' => 'Erreurs client'
    ]);
    
    Component::card([
        'color' => 'color1',
        'icon' => 'local_fire_department',
        'title' => 'Codes 5xx',
        'value' => number_format($codeFamilyStats->code_5xx),
        'desc' => 'Erreurs serveur'
    ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 2 : Tableau statistiques par code
         ======================================== -->
    <?php
    // Préparation des données pour le tableau
    $totalCrawled = array_sum(array_map(function($s) { return $s->total; }, $codeStats));
    $codeTableData = [];
    foreach($codeStats as $stat) {
        $codeTableData[] = [
            'code' => $stat->code,
            'description' => getCodeLabel($stat->code),
            'total' => number_format($stat->total),
            'percent' => $totalCrawled > 0 ? ($stat->total / $totalCrawled) : 0,
            'avg_time' => round($stat->avg_time, 2),
            'avg_inlinks' => round($stat->avg_inlinks, 1),
            'avg_outlinks' => round($stat->avg_outlinks, 1)
        ];
    }
    
    Component::simpleTable([
    'title' => 'Statistiques par code HTTP',
    'subtitle' => 'Vue détaillée des codes de réponse',
    'columns' => [
        ['key' => 'code', 'label' => 'Code HTTP', 'type' => 'badge-autodetect'],
        ['key' => 'description', 'label' => 'Description', 'type' => 'bold'],
        ['key' => 'total', 'label' => 'Total URLs', 'type' => 'default'],
        ['key' => 'percent', 'label' => 'Pourcentage', 'type' => 'percent_bar'],
        ['key' => 'avg_time', 'label' => 'Temps moyen (ms)', 'type' => 'default'],
        ['key' => 'avg_inlinks', 'label' => 'Inlinks moyen', 'type' => 'default'],
        ['key' => 'avg_outlinks', 'label' => 'Outlinks moyen', 'type' => 'default']
    ],
        'data' => $codeTableData
    ]);
    ?>

    <!-- ========================================
         SECTION 3 : Graphiques
         ======================================== -->
    <div class="charts-grid">
        <?php
        // Donut chart - Répartition des codes HTTP
        $donutData = [];
        foreach($codeStats as $stat) {
            $donutData[] = [
                'name' => getCodeFullLabel($stat->code),
                'y' => (int)$stat->total,
                'color' => getCodeColor($stat->code)
            ];
        }
        
        Component::chart([
        'type' => 'donut',
        'title' => 'Répartition des codes HTTP',
        'subtitle' => 'Distribution globale en donut',
        'series' => [
            [
                'name' => 'URLs',
                'data' => $donutData
            ]
        ],
            'height' => 350,
            'legendPosition' => 'bottom',
            'sqlQuery' => $sqlCodeStats
        ]);
        
        // Bar horizontal chart - Distribution par catégorie
        // Préparation des données : organiser par catégorie et famille de codes
        $categoryCodeData = [];
        foreach($codeByCategory as $row) {
            if(!isset($categoryCodeData[$row->category])) {
                $categoryCodeData[$row->category] = [
                    '0xx' => 0,
                    '1xx' => 0,
                    '2xx' => 0,
                    '3xx' => 0,
                    '4xx' => 0,
                    '5xx' => 0
                ];
            }
            if($row->code == 0) {
                $categoryCodeData[$row->category]['0xx'] += $row->count;
            } elseif($row->code >= 100 && $row->code < 200) {
                $categoryCodeData[$row->category]['1xx'] += $row->count;
            } elseif($row->code >= 200 && $row->code < 300) {
                $categoryCodeData[$row->category]['2xx'] += $row->count;
            } elseif($row->code >= 300 && $row->code < 400) {
                $categoryCodeData[$row->category]['3xx'] += $row->count;
            } elseif($row->code >= 400 && $row->code < 500) {
                $categoryCodeData[$row->category]['4xx'] += $row->count;
            } elseif($row->code >= 500 && $row->code < 600) {
                $categoryCodeData[$row->category]['5xx'] += $row->count;
            }
        }
        
        $categories = array_keys($categoryCodeData);
        $series0xx = [];
        $series1xx = [];
        $series2xx = [];
        $series3xx = [];
        $series4xx = [];
        $series5xx = [];
        
        foreach($categoryCodeData as $data) {
            $series0xx[] = (int)$data['0xx'];
            $series1xx[] = (int)$data['1xx'];
            $series2xx[] = (int)$data['2xx'];
            $series3xx[] = (int)$data['3xx'];
            $series4xx[] = (int)$data['4xx'];
            $series5xx[] = (int)$data['5xx'];
        }
        
        // Construire les séries en filtrant celles qui n'ont aucune donnée
        $allSeries = [];
        
        if (array_sum($series0xx) > 0) {
            $allSeries[] = [
                'name' => '0 - Timeout',
                'data' => $series0xx,
                'color' => getCodeColor(0)
            ];
        }
        
        if (array_sum($series1xx) > 0) {
            $allSeries[] = [
                'name' => '1xx - Info',
                'data' => $series1xx,
                'color' => getCodeColor(100)
            ];
        }
        
        if (array_sum($series2xx) > 0) {
            $allSeries[] = [
                'name' => '2xx - OK',
                'data' => $series2xx,
                'color' => getCodeColor(200)
            ];
        }
        
        if (array_sum($series3xx) > 0) {
            $allSeries[] = [
                'name' => '3xx - Redirect',
                'data' => $series3xx,
                'color' => getCodeColor(300)
            ];
        }
        
        if (array_sum($series4xx) > 0) {
            $allSeries[] = [
                'name' => '4xx - Client Error',
                'data' => $series4xx,
                'color' => getCodeColor(400)
            ];
        }
        
        if (array_sum($series5xx) > 0) {
            $allSeries[] = [
                'name' => '5xx - Server Error',
                'data' => $series5xx,
                'color' => getCodeColor(500)
            ];
        }
        
        Component::chart([
        'type' => 'horizontalBar',
        'title' => 'Distribution par catégorie',
        'subtitle' => 'Pourcentage de codes HTTP par catégorie',
        'categories' => $categories,
        'series' => $allSeries,
        'yAxisTitle' => 'Pourcentage',
        'yAxisMax' => 100,
        'stacking' => 'percent',
            'height' => 350,
            'sqlQuery' => $sqlCodeByCategory
        ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 4 : Tableau d'URLs
         ======================================== -->
    <?php
    Component::urlTable([
        'title' => 'URLs non 200',
        'id' => 'codes_urls',
        'whereClause' => 'WHERE c.code != 200 AND c.crawled = true AND c.is_html = true',
        'orderBy' => 'ORDER BY c.code DESC, c.inlinks DESC',
        'defaultColumns' => ['url', 'category', 'code', 'depth', 'inlinks', 'pri'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>

    <!-- ========================================
         SECTION 5 : Tableau de liens vers URLs non 200
         ======================================== -->
    <?php
    Component::linkTable([
        'title' => 'Liens vers les URLs non 200',
        'id' => 'codes_links',
        'whereClause' => 'WHERE ct.code != 200 AND ct.crawled = true AND ct.is_html = true',
        'orderBy' => 'ORDER BY ct.code DESC, ct.inlinks DESC',
        'defaultColumns' => ['url', 'code', 'type', 'anchor', 'nofollow'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>

</div>