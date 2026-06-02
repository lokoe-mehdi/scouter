<?php
/**
 * Fuite de PageRank (PostgreSQL)
 * $crawlId est défini dans dashboard.php
 * Analyse de la distribution du PageRank vers externes et non-indexables
 */

// Total PageRank distribué (somme des pri de toutes les pages crawlées)
$sqlTotalPR = "
    SELECT
        COALESCE(SUM(pri), 0) as total_pr
    FROM pages
    WHERE crawl_id = :crawl_id AND crawled = true AND in_crawl = TRUE
";
$totalPRRows = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'leak_total_pr', $pdo, $sqlTotalPR, [':crawl_id' => $crawlId], false
);
$totalPR = (float)($totalPRRows[0]->total_pr ?? 0);

// PageRank sur URLs externes
$sqlExternalPR = "
SELECT COALESCE(SUM(pri), 0) as pr
FROM pages
WHERE crawl_id = :crawl_id AND external = true AND in_crawl = TRUE";
$externalPRRows = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'leak_external_pr', $pdo, $sqlExternalPR, [':crawl_id' => $crawlId], false
);
$externalPR = (float)($externalPRRows[0]->pr ?? 0);

// PageRank sur URLs internes non indexables
$sqlNonIndexablePR = "
SELECT COALESCE(SUM(pri), 0) as pr
FROM pages
WHERE crawl_id = :crawl_id AND crawled = true AND external = false AND compliant = false AND in_crawl = TRUE";
$nonIndexablePRRows = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'leak_non_indexable_pr', $pdo, $sqlNonIndexablePR, [':crawl_id' => $crawlId], false
);
$nonIndexablePR = (float)($nonIndexablePRRows[0]->pr ?? 0);

// PageRank sur URLs indexables
$sqlIndexablePR = "
SELECT COALESCE(SUM(pri), 0) as pr
FROM pages
WHERE crawl_id = :crawl_id AND crawled = true AND external = false AND compliant = true AND in_crawl = TRUE";
$indexablePRRows = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'leak_indexable_pr', $pdo, $sqlIndexablePR, [':crawl_id' => $crawlId], false
);
$indexablePR = (float)($indexablePRRows[0]->pr ?? 0);

// Calcul des pourcentages
$totalForPct = $externalPR + $nonIndexablePR + $indexablePR;
$externalPct = $totalForPct > 0 ? round(($externalPR / $totalForPct) * 100, 1) : 0;
$nonIndexablePct = $totalForPct > 0 ? round(($nonIndexablePR / $totalForPct) * 100, 1) : 0;
$indexablePct = $totalForPct > 0 ? round(($indexablePR / $totalForPct) * 100, 1) : 0;

// Top 10 domaines externes par PageRank
$sqlTopDomains = "
SELECT 
    COALESCE(
        SUBSTRING(url FROM '://([^/]+)'),
        SUBSTRING(url FROM '^([^/]+)')
    ) as domain,
    COUNT(*) as url_count,
    COALESCE(SUM(pri), 0) as total_pr,
    COALESCE(SUM(inlinks), 0) as total_inlinks
FROM pages
WHERE crawl_id = :crawl_id AND external = true AND in_crawl = TRUE
GROUP BY COALESCE(SUBSTRING(url FROM '://([^/]+)'), SUBSTRING(url FROM '^([^/]+)'))
ORDER BY total_pr DESC
LIMIT 10";
$topDomains = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'leak_top_domains', $pdo, $sqlTopDomains, [':crawl_id' => $crawlId], false
);

// Préparer les données pour le graphe des domaines
$domainNames = array_map(fn($d) => $d->domain ?? __('common.unknown'), $topDomains);
$domainPR = array_map(fn($d) => round((float)$d->total_pr * 100, 4), $topDomains);

?>

<h1 class="page-title"><?= __('pagerank_leak.page_title') ?></h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- Scorecards récapitulatives -->
    <div class="scorecards">
        <?php
        Component::card([
            'color' => 'danger',
            'icon' => 'warning',
            'title' => __('pagerank_leak.card_total'),
            'value' => round($externalPct + $nonIndexablePct, 1) . '%',
            'desc' => __('pagerank_leak.card_total_desc')
        ]);
        
        Component::card([
            'color' => 'warning',
            'icon' => 'public_off',
            'title' => __('pagerank_leak.card_external'),
            'value' => $externalPct . '%',
            'desc' => __('pagerank_leak.card_external_desc', ['count' => count($topDomains) . '+'])
        ]);
        
        Component::card([
            'color' => 'info',
            'icon' => 'block',
            'title' => __('pagerank_leak.card_non_indexable'),
            'value' => $nonIndexablePct . '%',
            'desc' => __('pagerank_leak.card_non_indexable_desc')
        ]);
        
        Component::card([
            'color' => 'success',
            'icon' => 'check_circle',
            'title' => __('pagerank_leak.card_useful'),
            'value' => $indexablePct . '%',
            'desc' => __('pagerank_leak.card_useful_desc')
        ]);
        ?>
    </div>

    <!-- Section graphiques -->
    <div class="charts-grid">
    <?php
    // Graphique 1: Distribution du PageRank
    $sqlDistribution = "
SELECT 
    SUM(CASE WHEN external = true THEN pri ELSE 0 END) as external_pr,
    SUM(CASE WHEN external = false AND compliant = false THEN pri ELSE 0 END) as non_indexable_pr,
    SUM(CASE WHEN external = false AND compliant = true THEN pri ELSE 0 END) as indexable_pr
FROM pages
WHERE crawl_id = :crawl_id AND (crawled = true OR external = true) AND in_crawl = TRUE";
    
    Component::chart([
        'type' => 'donut',
        'title' => __('pagerank_leak.chart_distribution'),
        'subtitle' => __('pagerank_leak.chart_distribution_desc'),
        'series' => [
            [
                'name' => 'PageRank',
                'data' => [
                    ['name' => __('pagerank_leak.series_indexable'), 'y' => $indexablePct, 'color' => '#6bd899ff'],
                    ['name' => __('pagerank_leak.series_non_indexable'), 'y' => $nonIndexablePct, 'color' => '#d8bf6bff'],
                    ['name' => __('pagerank_leak.series_external'), 'y' => $externalPct, 'color' => '#d86b6bff']
                ]
            ]
        ],
        'height' => 350,
        'sqlQuery' => $sqlDistribution
    ]);
    
    // Graphique 2: Top 10 domaines externes
    Component::chart([
        'type' => 'horizontalBar',
        'title' => __('pagerank_leak.chart_top_domains'),
        'subtitle' => __('pagerank_leak.chart_top_domains_desc'),
        'categories' => $domainNames,
        'series' => [
            ['name' => __('pagerank.series_pagerank'), 'data' => $domainPR, 'color' => '#d86b6bff']
        ],
        'height' => max(250, count($domainNames) * 35),
        'sqlQuery' => $sqlTopDomains
    ]);
    ?>
    </div>

    <!-- Table des URLs qui fuient du PageRank -->
    <?php
    Component::urlTable([
        'title' => __('pagerank_leak.table_title'),
        'id' => 'prLeakTable',
        'whereClause' => 'WHERE (c.external = true OR (c.crawled = true AND c.compliant = false)) AND c.in_crawl = TRUE',
        'orderBy' => 'ORDER BY c.pri DESC',
        'defaultColumns' => ['url', 'pri', 'inlinks', 'compliant'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 20,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>
</div>
