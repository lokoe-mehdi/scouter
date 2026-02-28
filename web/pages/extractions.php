<?php
/**
 * Extractions personnalisées (PostgreSQL)
 * $crawlId est défini dans dashboard.php
 * Affiche les URLs crawlées avec les extractions custom (JSONB)
 */

// Comptage des pages crawlées
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM pages WHERE crawl_id = :crawl_id AND crawled = true");
$stmt->execute([':crawl_id' => $crawlId]);
$totalCrawled = $stmt->fetch(PDO::FETCH_OBJ)->total ?? 0;

// Récupérer les clés d'extracteurs personnalisés depuis JSONB
$customColumns = [];
$stmt = $pdo->prepare("
    SELECT DISTINCT jsonb_object_keys(extracts) as key_name 
    FROM pages 
    WHERE crawl_id = :crawl_id AND extracts IS NOT NULL AND extracts != '{}'::jsonb
");
$stmt->execute([':crawl_id' => $crawlId]);
$keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($keys as $key) {
    $customColumns[] = $key;
}

$hasCustomExtractions = count($customColumns) > 0;

// Calculer le taux de complétion par catégorie pour chaque extracteur
$completionByCategory = [];
if ($hasCustomExtractions) {
    // Récupérer les catégories avec leurs stats
    $categoriesMap = $GLOBALS['categoriesMap'] ?? [];
    
    // Pour chaque extracteur, calculer le % de complétion par catégorie
    foreach ($customColumns as $colName) {
        $stmt = $pdo->prepare("
            SELECT 
                cat_id,
                COUNT(*) as total,
                SUM(CASE WHEN extracts->>:col_name IS NOT NULL AND extracts->>:col_name != '' THEN 1 ELSE 0 END) as filled
            FROM pages 
            WHERE crawl_id = :crawl_id AND crawled = true
            GROUP BY cat_id
            ORDER BY COUNT(*) DESC
        ");
        $stmt->execute([':crawl_id' => $crawlId, ':col_name' => $colName]);
        $rows = $stmt->fetchAll(PDO::FETCH_OBJ);
        
        foreach ($rows as $row) {
            $catInfo = $categoriesMap[$row->cat_id] ?? null;
            $catName = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
            
            if (!isset($completionByCategory[$catName])) {
                $completionByCategory[$catName] = [
                    'total' => (int)$row->total,
                    'extractors' => []
                ];
            }
            
            $completionByCategory[$catName]['extractors'][$colName] = [
                'filled' => (int)$row->filled,
                'empty' => (int)$row->total - (int)$row->filled,
                'pct' => $row->total > 0 ? round(((int)$row->filled / (int)$row->total) * 100, 1) : 0
            ];
        }
    }
}

// Préparer les données pour le graphe stacked 100% (complétion globale par catégorie)
$chartCategories = array_keys($completionByCategory);
$chartFilled = [];
$chartEmpty = [];

foreach ($chartCategories as $catName) {
    $total = $completionByCategory[$catName]['total'];
    
    // Compter les URLs qui ont AU MOINS une extraction renseignée
    // On prend le max des extractions remplies (car une URL peut avoir plusieurs extracteurs)
    $maxFilled = 0;
    foreach ($completionByCategory[$catName]['extractors'] as $extStats) {
        if ($extStats['filled'] > $maxFilled) {
            $maxFilled = $extStats['filled'];
        }
    }
    
    $filledPct = $total > 0 ? round(($maxFilled / $total) * 100, 1) : 0;
    $emptyPct = 100 - $filledPct;
    
    $chartFilled[] = $filledPct;
    $chartEmpty[] = $emptyPct;
}

$chartSeries = [
    ['name' => 'Non renseigné', 'data' => $chartEmpty, 'color' => '#E5E7EB'],
    ['name' => 'Renseigné', 'data' => $chartFilled, 'color' => '#6bd899ff']
];

?>

<h1 class="page-title">Extractions personnalisées</h1>

<?php if (!$hasCustomExtractions): ?>
    <div class="card" style="padding: 3rem; text-align: center;">
        <span class="material-symbols-outlined" style="font-size: 4rem; color: var(--text-secondary); margin-bottom: 1rem;">info</span>
        <h2 style="color: var(--text-secondary); margin-bottom: 1rem;">Aucune extraction personnalisée</h2>
        <p style="color: var(--text-secondary); max-width: 600px; margin: 0 auto;">
            Ce crawl ne contient pas d'extractions HTML personnalisées. 
            Pour ajouter des extractions, configurez les <strong>xPathExtractors</strong> ou <strong>regexExtractors</strong> 
            dans le fichier <code>config.yml</code> de votre projet.
        </p>
    </div>
<?php else: ?>
<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- Graphique de complétion par catégorie -->
    <div class="charts-grid" style="grid-template-columns: 1fr;">
    <?php
    // Requête SQL pour l'affichage du scope
    $sqlCompletionByCategory = "
SELECT 
    cat_id,
    COUNT(*) as total,
    SUM(CASE WHEN extracts IS NOT NULL AND extracts != '{}'::jsonb AND extracts::text != '{}' THEN 1 ELSE 0 END) as filled
FROM pages 
WHERE crawl_id = :crawl_id AND crawled = true
GROUP BY cat_id
ORDER BY COUNT(*) DESC";
    
    Component::chart([
        'type' => 'horizontalBar',
        'title' => 'Taux de complétion par catégorie',
        'subtitle' => 'Pourcentage d\'URLs avec au moins une extraction personnalisée renseignée',
        'categories' => $chartCategories,
        'series' => $chartSeries,
        'stacking' => 'percent',
        'yAxisMax' => 100,
        'height' => max(200, count($chartCategories) * 40),
        'sqlQuery' => $sqlCompletionByCategory
    ]);
    ?>
    </div>

    <!-- Liste des URLs avec composant -->
    <?php
    // Construire la liste des colonnes par défaut avec les extracteurs custom
    $defaultCols = ['url', 'category', 'compliant'];
    foreach ($customColumns as $col) {
        $defaultCols[] = 'extract_' . $col;
    }

    $urlTableConfig = [
        'title' => 'URLs avec extractions personnalisées',
        'id' => 'extractionsTable',
        'whereClause' => 'WHERE c.crawled = true',
        'orderBy' => 'ORDER BY c.url ASC',
        'defaultColumns' => $defaultCols,
        'customExtractColumns' => $customColumns,
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 10,
        'projectDir' => $_GET['project'] ?? ''
    ];

    Component::urlTable($urlTableConfig);
    ?>
</div>
<?php endif; ?>
