<?php
/**
 * Balises SEO (PostgreSQL)
 * $crawlId est défini dans dashboard.php
 * Note: Les colonnes title_status, h1_status, metadesc_status sont maintenant dans la table pages
 */

// Récupération des catégories
$stmt = $pdo->prepare("SELECT id, cat FROM categories WHERE crawl_id = :crawl_id ORDER BY id");
$stmt->execute([':crawl_id' => $crawlId]);
$categories = $stmt->fetchAll(PDO::FETCH_OBJ);

// Stats globales - colonnes maintenant dans pages
$sqlSeoStats = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN title_status = 'empty' THEN 1 ELSE 0 END) as title_empty,
        SUM(CASE WHEN title_status = 'duplicate' THEN 1 ELSE 0 END) as title_duplicate,
        SUM(CASE WHEN title_status = 'unique' THEN 1 ELSE 0 END) as title_unique,
        SUM(CASE WHEN h1_status = 'empty' THEN 1 ELSE 0 END) as h1_empty,
        SUM(CASE WHEN h1_status = 'duplicate' THEN 1 ELSE 0 END) as h1_duplicate,
        SUM(CASE WHEN h1_status = 'unique' THEN 1 ELSE 0 END) as h1_unique,
        SUM(CASE WHEN metadesc_status = 'empty' THEN 1 ELSE 0 END) as meta_desc_empty,
        SUM(CASE WHEN metadesc_status = 'duplicate' THEN 1 ELSE 0 END) as meta_desc_duplicate,
        SUM(CASE WHEN metadesc_status = 'unique' THEN 1 ELSE 0 END) as meta_desc_unique
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
";
$stmt = $pdo->prepare($sqlSeoStats);
$stmt->execute([':crawl_id' => $crawlId]);
$seoStats = $stmt->fetch(PDO::FETCH_OBJ);

// Préparer les stats finales
$titleStats = [
    'total' => $seoStats->total ?? 0,
    'empty' => $seoStats->title_empty ?? 0,
    'duplicate' => $seoStats->title_duplicate ?? 0,
    'unique' => $seoStats->title_unique ?? 0
];

$h1Stats = [
    'total' => $seoStats->total ?? 0,
    'empty' => $seoStats->h1_empty ?? 0,
    'duplicate' => $seoStats->h1_duplicate ?? 0,
    'unique' => $seoStats->h1_unique ?? 0
];

$metaDescStats = [
    'total' => $seoStats->total ?? 0,
    'empty' => $seoStats->meta_desc_empty ?? 0,
    'duplicate' => $seoStats->meta_desc_duplicate ?? 0,
    'unique' => $seoStats->meta_desc_unique ?? 0
];

// Stats par catégorie (sans jointure)
$sqlSeoByCategory = "
    SELECT 
        cat_id,
        COUNT(*) as total,
        SUM(CASE WHEN title_status = 'empty' THEN 1 ELSE 0 END) as title_empty,
        SUM(CASE WHEN title_status = 'duplicate' THEN 1 ELSE 0 END) as title_duplicate,
        SUM(CASE WHEN title_status = 'unique' THEN 1 ELSE 0 END) as title_unique,
        SUM(CASE WHEN h1_status = 'empty' THEN 1 ELSE 0 END) as h1_empty,
        SUM(CASE WHEN h1_status = 'duplicate' THEN 1 ELSE 0 END) as h1_duplicate,
        SUM(CASE WHEN h1_status = 'unique' THEN 1 ELSE 0 END) as h1_unique,
        SUM(CASE WHEN metadesc_status = 'empty' THEN 1 ELSE 0 END) as meta_desc_empty,
        SUM(CASE WHEN metadesc_status = 'duplicate' THEN 1 ELSE 0 END) as meta_desc_duplicate,
        SUM(CASE WHEN metadesc_status = 'unique' THEN 1 ELSE 0 END) as meta_desc_unique
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true
    GROUP BY cat_id
    ORDER BY cat_id
";
$stmt = $pdo->prepare($sqlSeoByCategory);
$stmt->execute([':crawl_id' => $crawlId]);
$categoryStatsDataRaw = $stmt->fetchAll(PDO::FETCH_OBJ);

// Convertir cat_id en nom de catégorie
$categoriesMap = $GLOBALS['categoriesMap'] ?? [];
$categoryStatsData = [];
foreach ($categoryStatsDataRaw as $row) {
    $catInfo = $categoriesMap[$row->cat_id] ?? null;
    $row->category = $catInfo ? $catInfo['cat'] : __('common.uncategorized');
    $categoryStatsData[] = $row;
}

// Construire les tableaux par catégorie avec les vraies valeurs
$titleByCategory = [];
$h1ByCategory = [];
$metaDescByCategory = [];

foreach ($categoryStatsData as $cat) {
    $titleByCategory[] = [
        'category' => $cat->category,
        'total' => $cat->total,
        'empty' => $cat->title_empty ?? 0,
        'duplicate' => $cat->title_duplicate ?? 0,
        'unique' => $cat->title_unique ?? 0
    ];
    
    $h1ByCategory[] = [
        'category' => $cat->category,
        'total' => $cat->total,
        'empty' => $cat->h1_empty ?? 0,
        'duplicate' => $cat->h1_duplicate ?? 0,
        'unique' => $cat->h1_unique ?? 0
    ];
    
    $metaDescByCategory[] = [
        'category' => $cat->category,
        'total' => $cat->total,
        'empty' => $cat->meta_desc_empty ?? 0,
        'duplicate' => $cat->meta_desc_duplicate ?? 0,
        'unique' => $cat->meta_desc_unique ?? 0
    ];
}
?>

<h1 class="page-title"><?= __('seo_tags.page_title') ?></h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">
<!-- Scorecards globales -->
<div class="scorecards">
    <?php
    
    Component::card([
        'color' => 'primary',
        'icon' => 'check_circle',
        'title' => __('seo_tags.card_analyzed'),
        'value' => number_format($titleStats['total']),
        'desc' => __('seo_tags.card_analyzed_desc')
    ]);


    Component::card([
        'color' => 'error',
        'icon' => 'title',
        'title' => __('seo_tags.card_title_fix'),
        'value' => number_format($titleStats['empty'] + $titleStats['duplicate']),
        'desc' => round((($titleStats['empty'] + $titleStats['duplicate']) / $titleStats['total']) * 100, 1).'% '.__('common.of_total')
    ]);
    
    Component::card([
        'color' => 'error',
        'icon' => 'format_h1',
        'title' => __('seo_tags.card_h1_fix'),
        'value' => number_format($h1Stats['empty'] + $h1Stats['duplicate']),
        'desc' => round((($h1Stats['empty'] + $h1Stats['duplicate']) / $h1Stats['total']) * 100, 1).'% '.__('common.of_total')
    ]);
    
    Component::card([
        'color' => 'error',
        'icon' => 'description',
        'title' => __('seo_tags.card_metadesc_fix'),
        'value' => number_format($metaDescStats['empty'] + $metaDescStats['duplicate']),
        'desc' => round((($metaDescStats['empty'] + $metaDescStats['duplicate']) / $metaDescStats['total']) * 100, 1).'% '.__('common.of_total')
    ]);
    
    ?>
</div>

<!-- Graphiques Donut globaux -->
<div class="charts-grid" style="grid-template-columns: repeat(3, 1fr);">
    <?php
    // Title Donut
    Component::chart([
        'type' => 'donut',
        'title' => __('seo_tags.chart_title'),
        'subtitle' => __('seo_tags.chart_subtitle'),
        'series' => [
            [
                'name' => 'Pages',
                'data' => [
                    ['name' => __('seo_tags.series_unique'), 'y' => $titleStats['unique'], 'color' => '#6bd899ff'],
                    ['name' => __('seo_tags.series_duplicate'), 'y' => $titleStats['duplicate'], 'color' => '#d8bf6bff'],
                    ['name' => __('seo_tags.series_empty'), 'y' => $titleStats['empty'], 'color' => '#d86b6bff']
                ]
            ]
        ],
        'height' => 300,
        'sqlQuery' => $sqlSeoStats
    ]);
    
    // H1 Donut
    Component::chart([
        'type' => 'donut',
        'title' => __('seo_tags.chart_h1'),
        'subtitle' => __('seo_tags.chart_subtitle'),
        'series' => [
            [
                'name' => 'Pages',
                'data' => [
                    ['name' => __('seo_tags.series_unique'), 'y' => $h1Stats['unique'], 'color' => '#6bd899ff'],
                    ['name' => __('seo_tags.series_duplicate'), 'y' => $h1Stats['duplicate'], 'color' => '#d8bf6bff'],
                    ['name' => __('seo_tags.series_empty'), 'y' => $h1Stats['empty'], 'color' => '#d86b6bff']
                ]
            ]
        ],
        'height' => 300,
        'sqlQuery' => $sqlSeoStats
    ]);
    
    // Meta Description Donut
    Component::chart([
        'type' => 'donut',
        'title' => __('seo_tags.chart_metadesc'),
        'subtitle' => __('seo_tags.chart_subtitle'),
        'series' => [
            [
                'name' => 'Pages',
                'data' => [
                    ['name' => __('seo_tags.series_unique'), 'y' => $metaDescStats['unique'], 'color' => '#6bd899ff'],
                    ['name' => __('seo_tags.series_duplicate'), 'y' => $metaDescStats['duplicate'], 'color' => '#d8bf6bff'],
                    ['name' => __('seo_tags.series_empty'), 'y' => $metaDescStats['empty'], 'color' => '#d86b6bff']
                ]
            ]
        ],
        'height' => 300,
        'sqlQuery' => $sqlSeoStats
    ]);
    ?>
</div>

<!-- Graphiques par catégorie -->
<div class="charts-grid" style="grid-template-columns: repeat(3, 1fr);">
    <?php
    // Title par catégorie
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('seo_tags.chart_title'),
        'subtitle' => __('seo_tags.chart_category_subtitle'),
        'categories' => array_map(function($cat) { return $cat['category']; }, $titleByCategory),
        'series' => [
            [
                'name' => __('seo_tags.series_unique'),
                'data' => array_map(function($cat) { return $cat['unique']; }, $titleByCategory),
                'color' => '#6bd899ff'
            ],
            [
                'name' => __('seo_tags.series_empty'),
                'data' => array_map(function($cat) { return $cat['empty']; }, $titleByCategory),
                'color' => '#d86b6bff'
            ],
            [
                'name' => __('seo_tags.series_duplicate'),
                'data' => array_map(function($cat) { return $cat['duplicate']; }, $titleByCategory),
                'color' => '#d8bf6bff'
            ]
        ],
        'yAxisTitle' => __('common.percentage'),
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlSeoByCategory
    ]);
    
    // H1 par catégorie
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('seo_tags.chart_h1'),
        'subtitle' => __('seo_tags.chart_category_subtitle'),
        'categories' => array_map(function($cat) { return $cat['category']; }, $h1ByCategory),
        'series' => [
            [
                'name' => __('seo_tags.series_unique'),
                'data' => array_map(function($cat) { return $cat['unique']; }, $h1ByCategory),
                'color' => '#6bd899ff'
            ],
            [
                'name' => __('seo_tags.series_empty'),
                'data' => array_map(function($cat) { return $cat['empty']; }, $h1ByCategory),
                'color' => '#d86b6bff'
            ],
            [
                'name' => __('seo_tags.series_duplicate'),
                'data' => array_map(function($cat) { return $cat['duplicate']; }, $h1ByCategory),
                'color' => '#d8bf6bff'
            ]
        ],
        'yAxisTitle' => __('common.percentage'),
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlSeoByCategory
    ]);
    
    // Meta Description par catégorie
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('seo_tags.chart_metadesc'),
        'subtitle' => __('seo_tags.chart_category_subtitle'),
        'categories' => array_map(function($cat) { return $cat['category']; }, $metaDescByCategory),
        'series' => [
            [
                'name' => __('seo_tags.series_unique'),
                'data' => array_map(function($cat) { return $cat['unique']; }, $metaDescByCategory),
                'color' => '#6bd899ff'
            ],
            [
                'name' => __('seo_tags.series_empty'),
                'data' => array_map(function($cat) { return $cat['empty']; }, $metaDescByCategory),
                'color' => '#d86b6bff'
            ],
            [
                'name' => __('seo_tags.series_duplicate'),
                'data' => array_map(function($cat) { return $cat['duplicate']; }, $metaDescByCategory),
                'color' => '#d8bf6bff'
            ]
        ],
        'yAxisTitle' => __('common.percentage'),
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlSeoByCategory
    ]);
    ?>
</div>

<!-- Liste des URLs avec composant -->
<?php
$urlTableConfig = [
    'title' => __('seo_tags.table_issues'),
    'id' => 'seoTagsTable',
    'whereClause' => "WHERE c.compliant = true AND (c.title_status IN ('empty', 'duplicate') OR c.h1_status IN ('empty', 'duplicate') OR c.metadesc_status IN ('empty', 'duplicate'))",
    'orderBy' => 'ORDER BY c.url ASC',
    'defaultColumns' => ['url', 'category', 'title', 'title_status', 'h1', 'h1_status', 'metadesc', 'metadesc_status'],
    'pdo' => $pdo,
    'crawlId' => $crawlId,
    'perPage' => 10,
    'projectDir' => $_GET['project'] ?? ''
];

Component::urlTable($urlTableConfig);
?>
</div>