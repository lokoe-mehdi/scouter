<?php
/**
 * Fuite de PageRank (PostgreSQL)
 * $crawlId est défini dans dashboard.php
 * Analyse de la distribution du PageRank vers externes et non-indexables
 */

// Total PageRank distribué (somme des pri de toutes les pages crawlées)
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(pri), 0) as total_pr
    FROM pages 
    WHERE crawl_id = :crawl_id AND crawled = true
");
$stmt->execute([':crawl_id' => $crawlId]);
$totalPR = (float)$stmt->fetch(PDO::FETCH_OBJ)->total_pr;

// PageRank sur URLs externes
$sqlExternalPR = "
SELECT COALESCE(SUM(pri), 0) as pr
FROM pages 
WHERE crawl_id = :crawl_id AND external = true";
$stmt = $pdo->prepare($sqlExternalPR);
$stmt->execute([':crawl_id' => $crawlId]);
$externalPR = (float)$stmt->fetch(PDO::FETCH_OBJ)->pr;

// PageRank sur URLs internes non indexables
$sqlNonIndexablePR = "
SELECT COALESCE(SUM(pri), 0) as pr
FROM pages 
WHERE crawl_id = :crawl_id AND crawled = true AND external = false AND compliant = false";
$stmt = $pdo->prepare($sqlNonIndexablePR);
$stmt->execute([':crawl_id' => $crawlId]);
$nonIndexablePR = (float)$stmt->fetch(PDO::FETCH_OBJ)->pr;

// PageRank sur URLs indexables
$sqlIndexablePR = "
SELECT COALESCE(SUM(pri), 0) as pr
FROM pages 
WHERE crawl_id = :crawl_id AND crawled = true AND external = false AND compliant = true";
$stmt = $pdo->prepare($sqlIndexablePR);
$stmt->execute([':crawl_id' => $crawlId]);
$indexablePR = (float)$stmt->fetch(PDO::FETCH_OBJ)->pr;

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
WHERE crawl_id = :crawl_id AND external = true
GROUP BY COALESCE(SUBSTRING(url FROM '://([^/]+)'), SUBSTRING(url FROM '^([^/]+)'))
ORDER BY total_pr DESC
LIMIT 10";
$stmt = $pdo->prepare($sqlTopDomains);
$stmt->execute([':crawl_id' => $crawlId]);
$topDomains = $stmt->fetchAll(PDO::FETCH_OBJ);

// Préparer les données pour le graphe des domaines
$domainNames = array_map(fn($d) => $d->domain ?? 'Inconnu', $topDomains);
$domainPR = array_map(fn($d) => round((float)$d->total_pr * 100, 4), $topDomains);

?>

<h1 class="page-title">Fuite de PageRank</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- Scorecards récapitulatives -->
    <div class="scorecards">
        <?php
        Component::card([
            'color' => 'danger',
            'icon' => 'warning',
            'title' => 'Fuite totale',
            'value' => round($externalPct + $nonIndexablePct, 1) . '%',
            'desc' => 'PageRank perdu (externes + non indexables)'
        ]);
        
        Component::card([
            'color' => 'warning',
            'icon' => 'public_off',
            'title' => 'Vers externes',
            'value' => $externalPct . '%',
            'desc' => number_format($externalPR, 2) . ' PR vers ' . count($topDomains) . '+ domaines'
        ]);
        
        Component::card([
            'color' => 'info',
            'icon' => 'block',
            'title' => 'Vers non indexables',
            'value' => $nonIndexablePct . '%',
            'desc' => 'PageRank sur URLs internes non indexables'
        ]);
        
        Component::card([
            'color' => 'success',
            'icon' => 'check_circle',
            'title' => 'PageRank utile',
            'value' => $indexablePct . '%',
            'desc' => 'PageRank sur URLs indexables'
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
WHERE crawl_id = :crawl_id AND (crawled = true OR external = true)";
    
    Component::chart([
        'type' => 'donut',
        'title' => 'Distribution du PageRank',
        'subtitle' => 'Répartition du PageRank entre URLs indexables, non-indexables et externes',
        'series' => [
            [
                'name' => 'PageRank',
                'data' => [
                    ['name' => 'Indexables', 'y' => $indexablePct, 'color' => '#6bd899ff'],
                    ['name' => 'Non indexables', 'y' => $nonIndexablePct, 'color' => '#d8bf6bff'],
                    ['name' => 'Externes', 'y' => $externalPct, 'color' => '#d86b6bff']
                ]
            ]
        ],
        'height' => 350,
        'sqlQuery' => $sqlDistribution
    ]);
    
    // Graphique 2: Top 10 domaines externes
    Component::chart([
        'type' => 'horizontalBar',
        'title' => 'Top 10 domaines externes',
        'subtitle' => 'Domaines externes recevant le plus de PageRank',
        'categories' => $domainNames,
        'series' => [
            ['name' => 'PageRank (%)', 'data' => $domainPR, 'color' => '#d86b6bff']
        ],
        'height' => max(250, count($domainNames) * 35),
        'sqlQuery' => $sqlTopDomains
    ]);
    ?>
    </div>

    <!-- Table des URLs qui fuient du PageRank -->
    <?php
    Component::urlTable([
        'title' => 'URLs vers lesquelles le PageRank fuit',
        'id' => 'prLeakTable',
        'whereClause' => 'WHERE (c.external = true OR (c.crawled = true AND c.compliant = false))',
        'orderBy' => 'ORDER BY c.pri DESC',
        'defaultColumns' => ['url', 'pri', 'inlinks', 'compliant', 'external'],
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 20,
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>
</div>
