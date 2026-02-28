<?php
/**
 * ============================================================================
 * REQUÊTES SQL - Récupération des données (PostgreSQL)
 * ============================================================================
 * Note: Les statistiques globales ($globalStats) sont déjà chargées dans dashboard.php
 * $crawlId est défini dans dashboard.php
 */

// Distribution des URLs par profondeur (pour le bar chart)
$sqlDepth = "
    SELECT depth, COUNT(*) as count 
    FROM pages 
    WHERE crawl_id = :crawl_id AND crawled = true 
    GROUP BY depth 
    ORDER BY depth
";
$stmt = $pdo->prepare($sqlDepth);
$stmt->execute([':crawl_id' => $crawlId]);
$depthData = $stmt->fetchAll(PDO::FETCH_OBJ);

// Distribution des URLs par famille de codes HTTP (pour le donut chart)
$sqlCodes = "
    SELECT 
        CASE 
            WHEN code = 0 THEN 0
            WHEN code >= 100 AND code < 200 THEN 100
            WHEN code >= 200 AND code < 300 THEN 200
            WHEN code >= 300 AND code < 400 THEN 300
            WHEN code >= 400 AND code < 500 THEN 400
            WHEN code >= 500 AND code < 600 THEN 500
            ELSE 999
        END as code_family,
        COUNT(*) as count 
    FROM pages 
    WHERE crawl_id = :crawl_id AND crawled = true 
    GROUP BY code_family
    ORDER BY code_family
";
$stmt = $pdo->prepare($sqlCodes);
$stmt->execute([':crawl_id' => $crawlId]);
$codeDataRaw = $stmt->fetchAll(PDO::FETCH_OBJ);

// Convertir les familles de codes en labels lisibles
$codeData = [];
foreach ($codeDataRaw as $row) {
    $obj = new stdClass();
    switch ($row->code_family) {
        case 0:
            $obj->code = 0;
            $obj->label = '0 - Timeout';
            break;
        case 100:
            $obj->code = 100;
            $obj->label = '1xx - Info';
            break;
        case 200:
            $obj->code = 200;
            $obj->label = '2xx - OK';
            break;
        case 300:
            $obj->code = 300;
            $obj->label = '3xx - Redirect';
            break;
        case 400:
            $obj->code = 400;
            $obj->label = '4xx - Client Error';
            break;
        case 500:
            $obj->code = 500;
            $obj->label = '5xx - Server Error';
            break;
        default:
            $obj->code = 999;
            $obj->label = 'Autres';
            break;
    }
    $obj->count = $row->count;
    $codeData[] = $obj;
}

// Distribution des catégories (sans jointure, on utilise le tableau PHP)
$sqlCategories = "
    SELECT cat_id, COUNT(*) as count 
    FROM pages 
    WHERE crawl_id = :crawl_id AND crawled = true 
    GROUP BY cat_id 
    ORDER BY count DESC 
    LIMIT 20
";
$stmt = $pdo->prepare($sqlCategories);
$stmt->execute([':crawl_id' => $crawlId]);
$catDataRaw = $stmt->fetchAll(PDO::FETCH_OBJ);

// Convertir cat_id en nom de catégorie
$categoriesMap = $GLOBALS['categoriesMap'] ?? [];
$catData = [];
foreach ($catDataRaw as $row) {
    $catInfo = $categoriesMap[$row->cat_id] ?? null;
    $obj = new stdClass();
    $obj->cat = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
    $obj->count = $row->count;
    $catData[] = $obj;
}

// Distribution PageRank par catégorie (sans jointure)
$sqlPageRank = "
    SELECT 
        cat_id,
        SUM(pri) as total_pr
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND pri > 0
    GROUP BY cat_id
    ORDER BY total_pr DESC
";
$stmt = $pdo->prepare($sqlPageRank);
$stmt->execute([':crawl_id' => $crawlId]);
$prByCategoryRaw = $stmt->fetchAll(PDO::FETCH_OBJ);

// Convertir cat_id en nom de catégorie
$prByCategory = [];
foreach ($prByCategoryRaw as $row) {
    $catInfo = $categoriesMap[$row->cat_id] ?? null;
    $obj = new stdClass();
    $obj->category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
    $obj->total_pr = $row->total_pr;
    $prByCategory[] = $obj;
}

// Stats Title/H1/Meta Desc (Unique/Duplicate/Empty) - colonnes maintenant dans pages
$sqlContentStats = "
    SELECT 
        SUM(CASE WHEN title_status = 'unique' THEN 1 ELSE 0 END) as title_unique,
        SUM(CASE WHEN title_status = 'duplicate' THEN 1 ELSE 0 END) as title_duplicate,
        SUM(CASE WHEN title_status = 'empty' OR title_status IS NULL THEN 1 ELSE 0 END) as title_empty,
        SUM(CASE WHEN h1_status = 'unique' THEN 1 ELSE 0 END) as h1_unique,
        SUM(CASE WHEN h1_status = 'duplicate' THEN 1 ELSE 0 END) as h1_duplicate,
        SUM(CASE WHEN h1_status = 'empty' OR h1_status IS NULL THEN 1 ELSE 0 END) as h1_empty,
        SUM(CASE WHEN metadesc_status = 'unique' THEN 1 ELSE 0 END) as meta_unique,
        SUM(CASE WHEN metadesc_status = 'duplicate' THEN 1 ELSE 0 END) as meta_duplicate,
        SUM(CASE WHEN metadesc_status = 'empty' OR metadesc_status IS NULL THEN 1 ELSE 0 END) as meta_empty
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
";
$stmt = $pdo->prepare($sqlContentStats);
$stmt->execute([':crawl_id' => $crawlId]);
$contentStats = $stmt->fetch(PDO::FETCH_OBJ);



/**
 * ============================================================================
 * AFFICHAGE HTML - Rendu de l'interface
 * ============================================================================
 */
?>

<h1 class="page-title">Vue d'ensemble</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1 : Cartes statistiques
         ======================================== -->
    <div class="scorecards">
        <?php
        Component::card([
            'color' => 'primary',
            'icon' => 'language',
            'title' => 'Total URLs',
            'value' => number_format($globalStats->urls),
            'desc' => 'URLs découvertes dans le crawl'
        ]);

        Component::card([
            'color' => 'info',
            'icon' => 'check_circle',
            'title' => 'URLs Crawlées',
            'value' => number_format($globalStats->crawled),
            'desc' => ($globalStats->urls > 0 ? round(($globalStats->crawled/$globalStats->urls)*100, 2) : 0).'% d\'URLs crawlées'
        ]);
        
        Component::card([
            'color' => 'success',
            'icon' => 'verified',
            'title' => 'URLs Indexables',
            'value' => number_format($globalStats->compliant),
            'desc' => ($globalStats->crawled > 0 ? round(($globalStats->compliant/$globalStats->crawled)*100, 2) : 0).'% d\'URLs indexables'
        ]);
        
        Component::card([
            'color' => 'info',
            'icon' => 'speed',
            'title' => 'TTFB moyen',
            'value' => round($globalStats->response_time, 2),
            'desc' => 'Time To First Byte moyen (ms)'
        ]);

        Component::card([
            'color' => 'info',
            'icon' => 'layers',
            'title' => 'Profondeur',
            'value' => $globalStats->depth_max,
            'desc' => 'Profondeur maximale crawlée'
        ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 2 : Ligne 2 - Codes HTTP + Distribution par profondeur
         ======================================== -->
    <div class="charts-grid" style="grid-template-columns: repeat(2, 1fr);">
        <?php
        // Donut chart - Distribution par famille de codes HTTP
        $codeChartData = [];
        foreach($codeData as $code) {
            $codeChartData[] = [
                'name' => $code->label,
                'y' => (int)$code->count,
                'color' => getCodeColor($code->code)
            ];
        }
        
        Component::chart([
            'type' => 'donut',
            'title' => 'Codes de réponse HTTP',
            'subtitle' => 'Répartition des codes HTTP',
            'series' => [
                [
                    'name' => 'URLs',
                    'data' => $codeChartData
                ]
            ],
            'height' => 300,
            'legendPosition' => 'bottom',
            'sqlQuery' => $sqlCodes
        ]);

        // Bar chart - Distribution par profondeur
        Component::chart([
            'type' => 'bar',
            'title' => 'Distribution par profondeur',
            'subtitle' => 'URLs crawlées par niveau de profondeur',
            'categories' => array_map(function($d) { return 'Niveau ' . $d->depth; }, $depthData),
            'series' => [
                [
                    'name' => 'URLs',
                    'data' => array_map(function($d) { return (int)$d->count; }, $depthData)
                ]
            ],
            'xAxisTitle' => 'Profondeur',
            'yAxisTitle' => 'Nombre d\'URLs',
            'height' => 300,
            'sqlQuery' => $sqlDepth
        ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 3 : Ligne 3 - Répartition URLs + PageRank
         ======================================== -->
    <div class="charts-grid" style="grid-template-columns: repeat(2, 1fr);">
        <?php
        // Donut chart - Distribution par catégorie
        $catChartData = [];
        foreach($catData as $cat) {
            $categoryName = $cat->cat ?: 'Non catégorisé';
            $catChartData[] = [
                'name' => $categoryName,
                'y' => (int)$cat->count,
                'color' => getCategoryColor($categoryName)
            ];
        }
        
        Component::chart([
            'type' => 'donut',
            'title' => 'Répartition des URLs',
            'subtitle' => 'Répartition des URLs crawlées par catégorie',
            'series' => [
                [
                    'name' => 'URLs',
                    'data' => $catChartData
                ]
            ],
            'height' => 300,
            'legendPosition' => 'bottom',
            'sqlQuery' => $sqlCategories
        ]);
        
        // Donut chart - Distribution du PageRank par catégorie
        $prDonutData = [];
        foreach($prByCategory as $cat) {
            $prDonutData[] = [
                'name' => $cat->category,
                'y' => round($cat->total_pr * 100, 2),
                'color' => getCategoryColor($cat->category)
            ];
        }
        
        Component::chart([
            'type' => 'donut',
            'title' => 'Distribution du PageRank',
            'subtitle' => 'Pourcentage du PageRank total par catégorie',
            'series' => [
                [
                    'name' => 'PageRank (%)',
                    'data' => $prDonutData
                ]
            ],
            'height' => 300,
            'legendPosition' => 'bottom',
            'sqlQuery' => $sqlPageRank
        ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 4 : Ligne 4 - Unicité balises (pleine largeur)
         ======================================== -->
    <div class="charts-grid" style="grid-template-columns: 1fr;">
        <?php
        // Bar chart - Unicité des balises sémantiques
        Component::chart([
            'type' => 'bar',
            'title' => 'Unicité des balises sémantiques',
            'subtitle' => 'Répartition Unique / Duplicate / Empty pour Title, H1 et Meta Description',
            'categories' => ['Title', 'H1', 'Meta Desc'],
            'series' => [
                [
                    'name' => 'Empty',
                    'data' => [
                        (int)($contentStats->title_empty ?? 0),
                        (int)($contentStats->h1_empty ?? 0),
                        (int)($contentStats->meta_empty ?? 0)
                    ],
                    'color' => '#d86b6bff'
                ],
                [
                    'name' => 'Duplicate',
                    'data' => [
                        (int)($contentStats->title_duplicate ?? 0),
                        (int)($contentStats->h1_duplicate ?? 0),
                        (int)($contentStats->meta_duplicate ?? 0)
                    ],
                    'color' => '#d8bf6bff'
                ],
                [
                    'name' => 'Unique',
                    'data' => [
                        (int)($contentStats->title_unique ?? 0),
                        (int)($contentStats->h1_unique ?? 0),
                        (int)($contentStats->meta_unique ?? 0)
                    ],
                    'color' => '#6bd899ff'
                ]
            ],
            'stacking' => 'percent',
            'yAxisTitle' => 'Pourcentage',
            'yAxisMax' => 100,
            'height' => 300,
            'sqlQuery' => $sqlContentStats
        ]);
        ?>
    </div>
</div>
