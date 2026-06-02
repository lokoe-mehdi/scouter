<?php
/**
 * Hiérarchie des titres <hn> (PostgreSQL)
 * $crawlId est défini dans dashboard.php
 * 
 * Analyse les problèmes de hiérarchie des headings :
 * - h1_multiple : plusieurs <h1> sur la même page
 * - headings_missing : niveaux de heading sautés (ex: h2 -> h4 sans h3)
 */

// Stats globales headings
$sqlHeadingsStats = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN h1_multiple = true THEN 1 ELSE 0 END) as h1_multiple_count,
        SUM(CASE WHEN h1_multiple = false THEN 1 ELSE 0 END) as h1_unique_count,
        SUM(CASE WHEN headings_missing = true THEN 1 ELSE 0 END) as hn_missing_count,
        SUM(CASE WHEN headings_missing = false THEN 1 ELSE 0 END) as hn_ok_count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true AND in_crawl = TRUE
";
$headingsStatsRows = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'headings_stats', $pdo, $sqlHeadingsStats, [':crawl_id' => $crawlId], false
);
$headingsStats = $headingsStatsRows[0] ?? null;

$total = (int)($headingsStats->total ?? 0);
$h1MultipleCount = (int)($headingsStats->h1_multiple_count ?? 0);
$h1UniqueCount = (int)($headingsStats->h1_unique_count ?? 0);
$hnMissingCount = (int)($headingsStats->hn_missing_count ?? 0);
$hnOkCount = (int)($headingsStats->hn_ok_count ?? 0);

// Stats par catégorie
$sqlHeadingsByCategory = "
    SELECT
        category,
        COUNT(*) as total,
        SUM(CASE WHEN h1_multiple = true THEN 1 ELSE 0 END) as h1_multiple_count,
        SUM(CASE WHEN h1_multiple = false THEN 1 ELSE 0 END) as h1_unique_count,
        SUM(CASE WHEN headings_missing = true THEN 1 ELSE 0 END) as hn_missing_count,
        SUM(CASE WHEN headings_missing = false THEN 1 ELSE 0 END) as hn_ok_count
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true AND in_crawl = TRUE
    GROUP BY category
    ORDER BY category
";
$categoryStatsRaw = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'headings_by_category', $pdo, $sqlHeadingsByCategory, [':crawl_id' => $crawlId], true
);

// category est déjà le nom de la catégorie
$h1ByCategory = [];
$hnByCategory = [];

foreach ($categoryStatsRaw as $row) {
    $catName = (($row->category ?? '') !== '') ? $row->category : __('common.uncategorized');

    $h1ByCategory[] = [
        'category' => $catName,
        'total' => $row->total,
        'unique' => $row->h1_unique_count ?? 0,
        'multiple' => $row->h1_multiple_count ?? 0
    ];
    
    $hnByCategory[] = [
        'category' => $catName,
        'total' => $row->total,
        'ok' => $row->hn_ok_count ?? 0,
        'missing' => $row->hn_missing_count ?? 0
    ];
}
?>

<h1 class="page-title"><?= __('headings.page_title') ?></h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

<!-- Scorecards globales -->
<div class="scorecards">
    <?php
    // Calcul du % de pages avec problèmes (h1_multiple OU headings_missing)
    // Note: on doit compter les pages avec au moins un problème (pas la somme des deux)
    $sqlProblemCount = "
        SELECT COUNT(*) as problem_count
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true AND compliant = true AND in_crawl = TRUE
        AND (h1_multiple = true OR headings_missing = true)
    ";
    $problemCountRows = \App\Analysis\ReportPrecompute::cached(
        (int) $crawlId, 'headings_problem_count', $pdo, $sqlProblemCount, [':crawl_id' => $crawlId], false
    );
    $problemCount = (int)($problemCountRows[0]->problem_count ?? 0);
    $problemPercent = $total > 0 ? round(($problemCount / $total) * 100, 1) : 0;
    
    Component::card([
        'color' => 'primary',
        'icon' => 'verified',
        'title' => __('headings.card_analyzed'),
        'value' => number_format($total),
        'desc' => __('headings.card_analyzed_desc')
    ]);
    
    Component::card([
        'color' => $problemCount > 0 ? 'error' : 'success',
        'icon' => 'warning',
        'title' => __('headings.card_problems'),
        'value' => $problemPercent . '%',
        'desc' => number_format($problemCount) . ' ' . __('headings.card_problems_desc')
    ]);
    
    Component::card([
        'color' => $h1MultipleCount > 0 ? 'error' : 'success',
        'icon' => 'format_h1',
        'title' => __('headings.card_h1_multiple'),
        'value' => number_format($h1MultipleCount),
        'desc' => ($total > 0 ? round(($h1MultipleCount / $total) * 100, 1) : 0) . '% '.__('common.of_pages')
    ]);
    
    Component::card([
        'color' => $hnMissingCount > 0 ? 'warning' : 'success',
        'icon' => 'format_list_numbered',
        'title' => __('headings.card_bad_structure'),
        'value' => number_format($hnMissingCount),
        'desc' => ($total > 0 ? round(($hnMissingCount / $total) * 100, 1) : 0) . '% '.__('common.of_pages')
    ]);
    ?>
</div>

<!-- Graphiques Donut globaux -->
<div class="charts-grid" style="grid-template-columns: repeat(2, 1fr);">
    <?php
    // H1 Multiple vs Unique Donut
    Component::chart([
        'type' => 'donut',
        'title' => __('headings.chart_h1_duplicates'),
        'subtitle' => __('headings.chart_h1_subtitle'),
        'series' => [
            [
                'name' => 'Pages',
                'data' => [
                    ['name' => __('headings.series_h1_unique'), 'y' => $h1UniqueCount, 'color' => '#6bd899'],
                    ['name' => __('headings.series_h1_multiple'), 'y' => $h1MultipleCount, 'color' => '#d86b6b']
                ]
            ]
        ],
        'height' => 300,
        'sqlQuery' => $sqlHeadingsStats
    ]);
    
    // Hn Missing vs OK Donut
    Component::chart([
        'type' => 'donut',
        'title' => __('headings.chart_hierarchy'),
        'subtitle' => __('headings.chart_hierarchy_subtitle'),
        'series' => [
            [
                'name' => 'Pages',
                'data' => [
                    ['name' => __('headings.series_structure_ok'), 'y' => $hnOkCount, 'color' => '#6bd899'],
                    ['name' => __('headings.series_bad_structure'), 'y' => $hnMissingCount, 'color' => '#d8bf6b']
                ]
            ]
        ],
        'height' => 300,
        'sqlQuery' => $sqlHeadingsStats
    ]);
    ?>
</div>

<!-- Graphiques par catégorie -->
<div class="charts-grid" style="grid-template-columns: repeat(2, 1fr);">
    <?php
    // H1 par catégorie
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('headings.chart_h1_duplicates'),
        'subtitle' => __('seo_tags.chart_category_subtitle'),
        'categories' => array_map(function($cat) { return $cat['category']; }, $h1ByCategory),
        'series' => [
            [
                'name' => __('headings.series_h1_unique'),
                'data' => array_map(function($cat) { return $cat['unique']; }, $h1ByCategory),
                'color' => '#6bd899'
            ],
            [
                'name' => __('headings.series_h1_multiple'),
                'data' => array_map(function($cat) { return $cat['multiple']; }, $h1ByCategory),
                'color' => '#d86b6b'
            ]
        ],
        'yAxisTitle' => __('common.percentage'),
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlHeadingsByCategory
    ]);
    
    // Hn par catégorie
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('headings.chart_hierarchy'),
        'subtitle' => __('seo_tags.chart_category_subtitle'),
        'categories' => array_map(function($cat) { return $cat['category']; }, $hnByCategory),
        'series' => [
            [
                'name' => __('headings.series_structure_ok'),
                'data' => array_map(function($cat) { return $cat['ok']; }, $hnByCategory),
                'color' => '#6bd899'
            ],
            [
                'name' => __('headings.series_bad_structure'),
                'data' => array_map(function($cat) { return $cat['missing']; }, $hnByCategory),
                'color' => '#d8bf6b'
            ]
        ],
        'yAxisTitle' => __('common.percentage'),
        'stacking' => 'percent',
        'height' => 400,
        'sqlQuery' => $sqlHeadingsByCategory
    ]);
    ?>
</div>

<!-- Liste des URLs avec problèmes de headings -->
<?php
$urlTableConfig = [
    'title' => __('headings.table_problems'),
    'id' => 'headingsTable',
    'whereClause' => "WHERE c.compliant = true AND (c.h1_multiple = true OR c.headings_missing = true) AND c.in_crawl = TRUE",
    'orderBy' => 'ORDER BY c.h1_multiple DESC, c.headings_missing DESC, c.url',
    'defaultColumns' => ['url', 'category', 'h1_multiple', 'headings_missing'],
    'pdo' => $pdo,
    'crawlId' => $crawlId,
    'projectDir' => $crawlId
];

Component::urlTable($urlTableConfig);
?>

</div>
