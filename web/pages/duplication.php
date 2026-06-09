<?php
/**
 * ============================================================================
 * PAGE DUPLICATION - Analyse des contenus dupliqués et near-duplicate
 * ============================================================================
 * Utilise les données pré-calculées dans duplicate_clusters et crawls.
 * Les calculs sont faits en post-traitement (CrawlDatabase::duplicateAnalysis).
 */

// ============================================================================
// CONFIGURATION - Seuil de similarité minimum (pour information)
// ============================================================================
$minSimilarityPercent = 80; // Near-duplicates = 80%+ (distance Hamming <= 12)

// ============================================================================
// DONNÉES PRÉ-CALCULÉES - Depuis crawls et duplicate_clusters
// ============================================================================

// 1. Stats depuis la table crawls (pré-calculées)
$indexablePages = (int)($globalStats->compliant ?? 0);
$totalDuplicatedPages = (int)($globalStats->compliant_duplicate ?? 0);
$totalClusters = (int)($globalStats->clusters_duplicate ?? 0);
$dupRate = $indexablePages > 0 ? round(($totalDuplicatedPages / $indexablePages) * 100, 1) : 0;

// 2. Récupérer les clusters depuis duplicate_clusters (SANS jointure - rapide)
$sqlClustersRaw = "
    SELECT id, similarity, page_count, page_ids
    FROM duplicate_clusters
    WHERE crawl_id = :crawl_id AND similarity >= :min_similarity
    ORDER BY page_count DESC
";
$allClustersRaw = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'dup_clusters_raw', $pdo, $sqlClustersRaw,
    [':crawl_id' => $crawlId, ':min_similarity' => $minSimilarityPercent], false
);

// Version exploitable dans le SQL Explorer : UNE LIGNE PAR URL (on déplie les
// page_ids du cluster avec arrayJoin, puis on joint pages) — pas une liste d'ids.
$sqlClustersExplorer =
      "SELECT\n"
    . "    c.cluster_id,\n"
    . "    c.similarity,\n"
    . "    c.page_count,\n"
    . "    p.url,\n"
    . "    p.category,\n"
    . "    p.inlinks,\n"
    . "    p.title\n"
    . "FROM (\n"
    . "    SELECT cluster_id, similarity, page_count, arrayJoin(page_ids) AS pid\n"
    . "    FROM duplicate_clusters\n"
    . "    WHERE similarity >= " . (int) $minSimilarityPercent . "\n"
    . ") c\n"
    . "INNER JOIN pages p ON p.id = c.pid\n"
    . "ORDER BY c.page_count DESC, c.cluster_id";

// Helper: parse le format page_ids ('{a,b}' PG ou liste) en tableau d'ids.
$dupParseIds = function ($raw): array {
    $s = trim((string) $raw, '{}');
    if ($s === '') {
        return [];
    }
    return array_map(fn($id) => trim($id, '"'), explode(',', $s));
};

// 3. Distribution exact/near — depuis les page_count des clusters (AUCUN détail de
//    page → on ne charge plus des dizaines de milliers d'ids).
$pagesInExactDup = 0;
$pagesInNearDup  = 0;
foreach ($allClustersRaw as $cluster) {
    if ((int) $cluster->similarity === 100) {
        $pagesInExactDup += (int) $cluster->page_count;
    } else {
        $pagesInNearDup += (int) $cluster->page_count;
    }
}
$pagesWithSimhash = $indexablePages;

// 4. Répartition par catégorie — UN agrégat (pas de détail de page). L'ancien code
//    inlinait TOUS les page_ids dans un IN → "Max query size exceeded" sur les gros
//    crawls. Ici le sous-SELECT (arrayJoin sur les page_ids des clusters) est évalué
//    côté serveur. category-dependent → recalculé au save de catégorisation.
$sqlDupByCategory = "SELECT category, COUNT(*) AS page_count
FROM pages
WHERE crawled = true AND compliant = true AND in_crawl = TRUE AND id IN (
    SELECT arrayJoin(page_ids) FROM duplicate_clusters WHERE similarity >= {$minSimilarityPercent}
)
GROUP BY category ORDER BY page_count DESC";
$dupByCategoryRows = \App\Analysis\ReportPrecompute::cached(
    (int) $crawlId, 'dup_by_category', $pdo, $sqlDupByCategory, [], true
);
$dupByCategory = [];
foreach ($dupByCategoryRows as $row) {
    $catName = (($row->category ?? '') !== '') ? $row->category : __('common.uncategorized');
    $dupByCategory[] = (object) [
        'category_name'  => $catName,
        'category_color' => getCategoryColor($catName),
        'page_count'     => (int) $row->page_count,
    ];
}

// 5. Liste des clusters : tri similarité DESC + PAGINATION 10 par 10 (sur la liste
//    triée). Le treemap utilise le top 20 par nombre de pages (allClustersRaw est
//    déjà trié page_count DESC).
$allClusters = $allClustersRaw;
foreach ($allClusters as $cluster) {
    $cluster->type = ((int) $cluster->similarity === 100) ? 'exact' : 'near';
}
usort($allClusters, fn($a, $b) => $b->similarity <=> $a->similarity);
$totalAllClusters = count($allClusters);

$clusterPerPageOptions = [10, 25, 50, 100];
$clusterPerPage = (int) ($_GET['cluster_per_page'] ?? 10);
if (!in_array($clusterPerPage, $clusterPerPageOptions, true)) {
    $clusterPerPage = 10;
}
$clusterPages   = max(1, (int) ceil($totalAllClusters / $clusterPerPage));
$clusterPageNum = max(1, (int) ($_GET['cluster_page'] ?? 1));
$clusterPageNum = min($clusterPageNum, $clusterPages);
$clusterOffset  = ($clusterPageNum - 1) * $clusterPerPage;
$pageClusters   = array_slice($allClusters, $clusterOffset, $clusterPerPage);

$top20Clusters = array_slice($allClustersRaw, 0, 20);

// 6. Détails de page UNIQUEMENT pour les clusters affichés (page courante + top 20)
//    → IN-list bornée (plus de "Max query size"). Chunké par sécurité.
$neededIds = [];
foreach (array_merge($top20Clusters, $pageClusters) as $cluster) {
    foreach ($dupParseIds($cluster->page_ids) as $pid) {
        if ($pid !== '') {
            $neededIds[$pid] = true;
        }
    }
}
$pagesMap = [];
foreach (array_chunk(array_keys($neededIds), 2000) as $chunk) {
    $placeholders = implode(',', array_map(fn($id) => $pdo->quote($id), $chunk));
    if ($placeholders === '') {
        continue;
    }
    $stmt = $pdo->query("
        SELECT id, url, title, inlinks, category
        FROM pages
        WHERE crawl_id = " . (int) $crawlId . " AND id IN ($placeholders) AND in_crawl = TRUE
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pagesMap[$row['id']] = $row;
    }
}

// 7. Enrichir les clusters AFFICHÉS avec leurs pages (triées par inlinks DESC).
$dupEnrich = function ($cluster) use ($dupParseIds, $pagesMap) {
    if (isset($cluster->pages)) {
        return; // déjà enrichi (objet partagé entre top20 et page courante)
    }
    $clusterPagesArr = [];
    foreach ($dupParseIds($cluster->page_ids) as $pid) {
        if (isset($pagesMap[$pid])) {
            $clusterPagesArr[] = $pagesMap[$pid];
        }
    }
    usort($clusterPagesArr, fn($a, $b) => ($b['inlinks'] ?? 0) - ($a['inlinks'] ?? 0));
    $cluster->pages = json_encode($clusterPagesArr);
};
foreach ($top20Clusters as $cluster) {
    $dupEnrich($cluster);
}
foreach ($pageClusters as $cluster) {
    $dupEnrich($cluster);
}

/**
 * ============================================================================
 * AFFICHAGE HTML
 * ============================================================================
 */
?>

<h1 class="page-title"><?= __('duplication.page_title') ?></h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1 : Cartes statistiques
         ======================================== -->
    <div class="scorecards">
        <?php
        Component::card([
            'color' => 'primary',
            'icon' => 'verified',
            'title' => __('duplication.card_indexable'),
            'value' => number_format($indexablePages),
            'desc' => __('duplication.card_indexable_desc')
        ]);

        Component::card([
            'color' => 'warning',
            'icon' => 'percent',
            'title' => __('duplication.card_rate'),
            'value' => $dupRate . '%',
            'desc' => __('duplication.card_rate_desc')
        ]);
        
        Component::card([
            'color' => 'info',
            'icon' => 'workspaces',
            'title' => __('duplication.card_clusters'),
            'value' => number_format($totalClusters),
            'desc' => __('duplication.card_clusters_desc')
        ]);

        Component::card([
            'color' => 'danger',
            'icon' => 'content_copy',
            'title' => __('duplication.card_duplicated'),
            'value' => number_format($totalDuplicatedPages),
            'desc' => __('duplication.card_duplicated_desc')
        ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 2 : Graphiques de répartition
         ======================================== -->
    <div class="charts-grid" style="grid-template-columns: repeat(2, 1fr);">
        <?php
        // Donut 1 - Répartition unique vs duplicates
        $pagesUniques = max(0, $pagesWithSimhash - $pagesInNearDup - $pagesInExactDup);
        $pieData = [
            ['name' => __('duplication.series_unique'), 'y' => $pagesUniques, 'color' => '#6bd899'],
            ['name' => __('duplication.series_near'), 'y' => $pagesInNearDup, 'color' => '#60a5fa'],
            ['name' => __('duplication.series_exact'), 'y' => $pagesInExactDup, 'color' => '#f87171'],
        ];
        
        $sqlDupDistribution = "WITH exact_pages AS (
    SELECT DISTINCT unnest(page_ids) AS page_id
    FROM duplicate_clusters WHERE similarity = 100
),
near_pages AS (
    SELECT DISTINCT unnest(page_ids) AS page_id
    FROM duplicate_clusters WHERE similarity >= {$minSimilarityPercent} AND similarity < 100
),
totals AS (
    SELECT COUNT(*) AS indexable FROM pages WHERE crawled = true AND compliant = true AND in_crawl = TRUE
)
SELECT
    totals.indexable - COALESCE(e.cnt, 0) - COALESCE(n.cnt, 0) AS unique_pages,
    COALESCE(n.cnt, 0) AS near_duplicate_pages,
    COALESCE(e.cnt, 0) AS exact_duplicate_pages
FROM totals,
    (SELECT COUNT(*) AS cnt FROM near_pages) n,
    (SELECT COUNT(*) AS cnt FROM exact_pages) e";

        Component::chart([
            'type' => 'donut',
            'title' => __('duplication.chart_distribution'),
            'subtitle' => __('duplication.chart_distribution_desc'),
            'series' => [
                [
                    'name' => 'Pages',
                    'data' => $pieData
                ]
            ],
            'height' => 300,
            'legendPosition' => 'bottom',
            'sqlQuery' => $sqlDupDistribution
        ]);
        
        // Donut 2 - Pages dupliquées par catégorie
        $catPieData = [];
        foreach ($dupByCategory as $cat) {
            $catPieData[] = [
                'name' => $cat->category_name,
                'y' => (int)$cat->page_count,
                'color' => $cat->category_color
            ];
        }
        
        if (empty($catPieData)) {
            $catPieData[] = ['name' => __('duplication.no_duplicates'), 'y' => 1, 'color' => '#e5e7eb'];
        }
        
        $sqlDupByCategory = "SELECT category, COUNT(*) AS page_count
FROM pages
WHERE crawled = true AND compliant = true AND in_crawl = TRUE AND id IN (
    SELECT unnest(page_ids) FROM duplicate_clusters WHERE similarity >= {$minSimilarityPercent}
)
GROUP BY category ORDER BY page_count DESC";

        Component::chart([
            'type' => 'donut',
            'title' => __('duplication.chart_category'),
            'subtitle' => __('duplication.chart_category_desc'),
            'series' => [
                [
                    'name' => 'Pages',
                    'data' => $catPieData
                ]
            ],
            'height' => 300,
            'legendPosition' => 'bottom',
            'sqlQuery' => $sqlDupByCategory
        ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 2b : Treemap des clusters (ligne seule)
         ======================================== -->
    <div class="charts-grid" style="grid-template-columns: 1fr;">
        <?php

        // Treemap - Tous les clusters (exacts + near-duplicates)
        // 20 couleurs pastels saturées (assez foncées pour écrire en blanc)
        $pastelColors = [
            '#e57373', // Rouge pastel
            '#64b5f6', // Bleu pastel
            '#81c784', // Vert pastel
            '#ffb74d', // Orange pastel
            '#ba68c8', // Violet pastel
            '#4dd0e1', // Cyan pastel
            '#f06292', // Rose pastel
            '#aed581', // Vert lime pastel
            '#7986cb', // Indigo pastel
            '#ff8a65', // Corail pastel
            '#4db6ac', // Teal pastel
            '#dce775', // Jaune-vert pastel
            '#9575cd', // Violet profond pastel
            '#4fc3f7', // Bleu ciel pastel
            '#f48fb1', // Rose clair pastel
            '#a1887f', // Brun pastel
            '#90a4ae', // Bleu-gris pastel
            '#ffcc80', // Pêche pastel
            '#80cbc4', // Aqua pastel
            '#ce93d8', // Mauve pastel
        ];
        
        // $top20Clusters est défini + enrichi (->pages) dans la section données.
        $treemapData = [];
        foreach ($top20Clusters as $index => $cluster) {
            $pages = json_decode($cluster->pages, true) ?? [];
            $leader = $pages[0] ?? null;
            $leaderTitle = $leader['title'] ?? $leader['url'] ?? 'Cluster';
            $leaderTitle = trim(preg_replace('/\s+/', ' ', strip_tags($leaderTitle)));
            if (mb_strlen($leaderTitle) > 35) {
                $leaderTitle = mb_substr($leaderTitle, 0, 32) . '...';
            }
            
            $treemapData[] = [
                'name' => $leaderTitle . ' (' . $cluster->page_count . 'p)',
                'value' => (int)$cluster->page_count,
                'color' => $pastelColors[$index % 20]
            ];
        }
        
        if (empty($treemapData)) {
            $treemapData[] = [
                'name' => __('duplication.no_duplicates_found'),
                'value' => 1,
                'color' => '#e5e7eb'
            ];
        }
        
        // Titre conditionnel selon le nombre de clusters
        $treemapTitle = $totalClusters > 20
            ? __('duplication.chart_top20') . ' ' . $totalClusters . ')'
            : $totalClusters . ' ' . ($totalClusters > 1 ? __('duplication.unit_clusters') : __('duplication.unit_cluster')) . ' de duplication identifié' . ($totalClusters > 1 ? 's' : '');
        
        Component::chart([
            'type' => 'treemap',
            'title' => $treemapTitle,
            'subtitle' => __('duplication.chart_top20_desc'),
            'series' => [
                [
                    'data' => $treemapData,
                    'borderWidth' => 1,
                    'borderColor' => '#ffffff'
                ]
            ],
            'height' => 400,
            'tooltip' => false,
            'actions' => false
        ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 3 : Clusters de duplication (exacts + near-duplicate fusionnés)
         ======================================== -->
    <?php /* Liste + pagination préparées en amont : $pageClusters (page courante),
             $clusterPageNum, $clusterPages, $clusterOffset, $totalAllClusters. */ ?>

    <div class="card" style="background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 600; display: flex; align-items: center; gap: 0.6rem;">
                    <?= __('duplication.section_clusters') ?>
                    <span class="badge" style="background: #f87171; color: white; padding: 0.15rem 0.6rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600;"><?= number_format($totalAllClusters) ?></span>
                </h3>
                <p style="margin: 0.25rem 0 0; font-size: 0.85rem; color: var(--text-secondary);">
                    <?= __('duplication.section_clusters_desc') ?> <?= $minSimilarityPercent ?>% minimum)
                </p>
            </div>
            <div style="display: flex; align-items: center; gap: 0.25rem;">
                <button onclick="copyClustersToClipboard()" class="chart-action-btn" title="<?= __('duplication.copy_all_clusters') ?>">
                    <span class="material-symbols-outlined">content_copy</span>
                    <span class="chart-tooltip"><?= __('common.copy') ?></span>
                </button>
                <button onclick="openClustersSql()" class="chart-action-btn" title="<?= __('chart.view_sql') ?>">
                    <span class="material-symbols-outlined">database</span>
                    <span class="chart-tooltip"><?= __('chart.view_sql') ?></span>
                </button>
            </div>
        </div>
        <div class="card-body" style="padding: 0;">
          <div id="clustersContainer">
            <?php if (empty($allClusters)): ?>
                <div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
                    <span class="material-symbols-outlined" style="font-size: 3rem; opacity: 0.5;">check_circle</span>
                    <p style="margin-top: 1rem;"><?= __('duplication.no_duplicates_found') ?></p>
                </div>
            <?php else: ?>
                <div class="clusters-list">
                    <?php foreach ($pageClusters as $index => $cluster):
                        $globalIndex = $clusterOffset + $index;
                        $pages = json_decode($cluster->pages ?? '[]', true) ?? [];
                        $leader = $pages[0] ?? null;
                        $clusterId = $cluster->type . '-' . $globalIndex;
                    ?>
                    <div class="cluster-item" id="cluster-<?= $clusterId ?>" style="border-bottom: 1px solid var(--border-color);">
                        <div class="cluster-header" 
                             onclick="toggleCluster('<?= $clusterId ?>')" 
                             style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; cursor: pointer; background: var(--background);">
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <span class="material-symbols-outlined cluster-toggle" id="toggle-<?= $clusterId ?>">expand_more</span>
                                <div>
                                    <strong style="font-size: 0.9rem;"><?= __('duplication.cluster_label') ?><?= $globalIndex + 1 ?></strong>
                                    <span style="margin-left: 0.5rem; background: #94a3b8; color: white; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">
                                        <?= $cluster->page_count ?> pages
                                    </span>
                                    <?php
                                    // Couleurs pastels pour le badge de similarité
                                    $simColor = '#fdba74'; // orange pastel par défaut
                                    if ($cluster->similarity >= 100) $simColor = '#fca5a5'; // rouge pastel
                                    elseif ($cluster->similarity >= 95) $simColor = '#f9a8d4'; // rose pastel
                                    elseif ($cluster->similarity >= 90) $simColor = '#fdba74'; // orange pastel
                                    else $simColor = '#fde047'; // jaune pastel
                                    ?>
                                    <span style="margin-left: 0.25rem; background: <?= $simColor ?>; color: #1f2937; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 500;">
                                        <?= $cluster->similarity ?>% <?= __('duplication.similar') ?>
                                    </span>
                                </div>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary); max-width: 50%; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                                <?= htmlspecialchars($leader['title'] ?? $leader['url'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div class="cluster-pages" id="pages-<?= $clusterId ?>" style="display: none; padding: 0 1rem 1rem;">
                            <table style="width: 100%; font-size: 0.85rem; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: var(--background);">
                                        <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border-color);">URL</th>
                                        <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border-color); width: 120px;">Catégorie</th>
                                        <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border-color);">Title</th>
                                        <th style="padding: 0.5rem; text-align: center; border-bottom: 1px solid var(--border-color); width: 80px;">Inlinks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pages as $page):
                                        // Récupérer la catégorie et sa couleur
                                        $catName = (($page['category'] ?? '') !== '') ? $page['category'] : __('common.uncategorized');
                                        $catColor = getCategoryColor($catName);
                                        // Calculer la couleur du texte
                                        $textColor = getTextColorForBackground($catColor);
                                    ?>
                                    <tr style="transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                        <td style="padding: 0.75rem 0.5rem; border-bottom: 1px solid var(--border-color); max-width: 400px;">
                                            <div style="display: flex; align-items: center; overflow: hidden;">
                                                <span class="url-clickable" data-url="<?= htmlspecialchars($page['url']) ?>" 
                                                      style="cursor: pointer; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0;">
                                                    <?= htmlspecialchars($page['url']) ?>
                                                </span>
                                                <a href="<?= htmlspecialchars($page['url']) ?>" target="_blank" rel="noopener noreferrer" 
                                                   title="<?= __('common.open_new_tab') ?>" 
                                                   style="display: inline-flex; align-items: center; color: var(--text-secondary); text-decoration: none; margin-left: 0.5rem; flex-shrink: 0;">
                                                    <span class="material-symbols-outlined" style="font-size: 16px;">open_in_new</span>
                                                </a>
                                            </div>
                                        </td>
                                        <td style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">
                                            <span style="background: <?= $catColor ?>; color: <?= $textColor ?>; padding: 0.25rem 0.6rem; border-radius: 12px; font-size: 0.75rem; font-weight: 500; white-space: nowrap; display: inline-block;">
                                                <?= htmlspecialchars($catName) ?>
                                            </span>
                                        </td>
                                        <td style="padding: 0.75rem 0.5rem; border-bottom: 1px solid var(--border-color); max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($page['title'] ?? '') ?>">
                                            <?= htmlspecialchars($page['title'] ?? '') ?>
                                        </td>
                                        <td style="padding: 0.5rem; text-align: center; border-bottom: 1px solid var(--border-color);">
                                            <span style="background: #e2e8f0; color: #334155; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.75rem;"><?= $page['inlinks'] ?? 0 ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php
                $cStart = $totalAllClusters > 0 ? $clusterOffset + 1 : 0;
                $cEnd   = min($clusterOffset + $clusterPerPage, $totalAllClusters);
                ?>
                <div style="display:flex; justify-content:space-between; align-items:center; padding:0.75rem 1rem; border-top:1px solid var(--border-color); font-size:0.85rem; color:var(--text-secondary);">
                    <!-- Gauche : nombre de clusters affichés -->
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <span><?= __('table.show') ?></span>
                        <div style="position: relative;">
                            <button id="clusterPerPageBtn" onclick="toggleClusterPerPageDropdown()" style="padding: 0.4rem 0.6rem; border: 1px solid #dee2e6; border-radius: 4px; background: white; cursor: pointer; font-size: 0.85rem; display: flex; align-items: center; gap: 0.3rem; transition: all 0.2s ease;">
                                <span><?= $clusterPerPage ?></span>
                                <span class="material-symbols-outlined" style="font-size: 14px;">expand_more</span>
                            </button>
                            <div id="clusterPerPageDropdown" style="display: none; position: absolute; left: 0; bottom: 100%; margin-bottom: 0.25rem; background: white; border: 1px solid #dee2e6; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 1000; min-width: 60px;">
                                <?php foreach ($clusterPerPageOptions as $opt): ?>
                                    <div onclick="selectClusterPerPage(<?= $opt ?>)" style="padding: 0.4rem 0.6rem; cursor: pointer; <?= $clusterPerPage === $opt ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $clusterPerPage === $opt ? '#f8f9fa' : 'white' ?>'"><?= $opt ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <span>clusters</span>
                    </div>
                    <!-- Droite : pagination -->
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <span><?= number_format($cStart) ?>–<?= number_format($cEnd) ?> / <?= number_format($totalAllClusters) ?></span>
                        <button onclick="changeClusterPage(<?= $clusterPageNum - 1 ?>)" <?= $clusterPageNum <= 1 ? 'disabled' : '' ?> style="padding:0.3rem; border:1px solid #dee2e6; background:white; border-radius:4px; cursor:pointer; display:flex; align-items:center; justify-content:center; <?= $clusterPageNum <= 1 ? 'opacity:0.4; cursor:default;' : '' ?>">
                            <span class="material-symbols-outlined" style="font-size:18px;">chevron_left</span>
                        </button>
                        <button onclick="changeClusterPage(<?= $clusterPageNum + 1 ?>)" <?= $clusterPageNum >= $clusterPages ? 'disabled' : '' ?> style="padding:0.3rem; border:1px solid #dee2e6; background:white; border-radius:4px; cursor:pointer; display:flex; align-items:center; justify-content:center; <?= $clusterPageNum >= $clusterPages ? 'opacity:0.4; cursor:default;' : '' ?>">
                            <span class="material-symbols-outlined" style="font-size:18px;">chevron_right</span>
                        </button>
                    </div>
                </div>
            <?php endif; /* empty($allClusters) */ ?>
          </div><!-- /#clustersContainer -->
        </div>
    </div>

</div>

<style>
.cluster-header:hover {
    background: var(--background-hover, #f0f0f0) !important;
}

.cluster-toggle {
    transition: transform 0.2s;
}

.cluster-toggle.expanded {
    transform: rotate(180deg);
}

.badge-sm {
    font-size: 0.7rem;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    background: var(--border-color);
    color: var(--text-primary);
}
</style>

<script>
function toggleCluster(clusterId) {
    const pagesDiv = document.getElementById('pages-' + clusterId);
    const toggleIcon = document.getElementById('toggle-' + clusterId);

    if (pagesDiv.style.display === 'none') {
        pagesDiv.style.display = 'block';
        toggleIcon.classList.add('expanded');
    } else {
        pagesDiv.style.display = 'none';
        toggleIcon.classList.remove('expanded');
    }
}

// Icône base de données → modale SQL + lien "ouvrir dans le SQL Explorer"
// (même composant partagé openScopeModal que les graphiques).
function openClustersSql() {
    if (typeof openScopeModal !== 'function') return;
    openScopeModal({
        title: <?= json_encode(__('duplication.section_clusters'), JSON_UNESCAPED_UNICODE) ?>,
        sqlQuery: <?= json_encode($sqlClustersExplorer, JSON_UNESCAPED_UNICODE) ?>
    });
}

// Pagination des clusters EN AJAX (même principe que le composant tableau) : on
// recharge le dashboard avec les params cluster_page / cluster_per_page et on ne
// remplace que le fragment #clustersContainer — pas de rechargement complet.
function changeClusterPage(page) {
    if (page < 1) return;
    _reloadClusters({ cluster_page: page });
}
function changeClusterPerPage(n) {
    _reloadClusters({ cluster_per_page: n, cluster_page: 1 });
}
// Dropdown "nombre de clusters" — même UX que le select du composant tableau.
function toggleClusterPerPageDropdown() {
    const dd = document.getElementById('clusterPerPageDropdown');
    if (dd) dd.style.display = (dd.style.display === 'none' || !dd.style.display) ? 'block' : 'none';
}
function selectClusterPerPage(n) {
    const dd = document.getElementById('clusterPerPageDropdown');
    if (dd) dd.style.display = 'none';
    changeClusterPerPage(n);
}
// Fermer le dropdown au clic en dehors (l'élément est recréé à chaque swap AJAX,
// on le relit donc par id au moment du clic). Listener enregistré une seule fois
// (ids fixes) pour ne pas s'empiler à chaque navigation htmx.
if (!window.__dupClusterWired) {
    window.__dupClusterWired = true;
    document.addEventListener('click', function (e) {
        const btn = document.getElementById('clusterPerPageBtn');
        const dd = document.getElementById('clusterPerPageDropdown');
        if (dd && dd.style.display === 'block' && btn && !btn.contains(e.target) && !dd.contains(e.target)) {
            dd.style.display = 'none';
        }
    });
}
function _reloadClusters(updates) {
    const container = document.getElementById('clustersContainer');
    if (!container) return;
    const params = new URLSearchParams(window.location.search);
    Object.keys(updates).forEach(k => params.set(k, updates[k]));
    const newUrl = window.location.pathname + '?' + params.toString();
    container.style.opacity = '0.5';
    container.style.pointerEvents = 'none';
    window.history.pushState({}, '', newUrl);
    fetch(newUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const fresh = doc.querySelector('#clustersContainer');
            if (fresh) {
                container.innerHTML = fresh.innerHTML;
            }
        })
        .catch(err => console.error('Cluster pagination error:', err))
        .finally(() => {
            container.style.opacity = '1';
            container.style.pointerEvents = '';
        });
}

function scrollToCluster(clusterId) {
    const element = document.getElementById('exact-cluster-' + clusterId);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        // Ouvrir le cluster
        toggleCluster('exact-' + clusterId);
        // Highlight temporaire
        element.style.background = 'var(--warning-bg, #fff3cd)';
        setTimeout(() => {
            element.style.background = '';
        }, 2000);
    }
}

// Copier les clusters AFFICHÉS (page courante) dans le presse-papier. On n'inline
// pas les 10 000 clusters d'un coup (perf + DOM) : seule la page courante a ses
// pages détaillées chargées.
function copyClustersToClipboard() {
    try {
        const allClusters = <?= json_encode($pageClusters) ?>;
        const pageOffset = <?= (int) $clusterOffset ?>;

        // Créer le contenu tab-separated (pour Excel/Sheets)
        let content = 'Cluster\t<?= __('common.category') ?>\tURL\tTitle\tInlinks\n';

        allClusters.forEach((cluster, index) => {
            const clusterNum = pageOffset + index + 1;
            const pages = JSON.parse(cluster.pages || '[]');
            
            pages.forEach(page => {
                // Récupérer la catégorie
                let catName = page.category ? page.category : '<?= __('common.uncategorized') ?>';
                
                // Nettoyer les valeurs pour le format TSV
                const url = (page.url || '').replace(/\t/g, ' ').replace(/\n/g, ' ');
                const title = (page.title || '').replace(/\t/g, ' ').replace(/\n/g, ' ');
                const inlinks = page.inlinks || 0;
                
                content += `Cluster ${clusterNum}\t${catName}\t${url}\t${title}\t${inlinks}\n`;
            });
        });
        
        // Copier dans le presse-papier
        navigator.clipboard.writeText(content).then(() => {
            // Utiliser showGlobalStatus pour afficher la notification
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus(__('table.text_copied'), 'success');
            }
        }).catch(err => {
            console.error('Erreur copie:', err);
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus(__('table.copy_error'), 'error');
            } else {
                alert(__('table.copy_error'));
            }
        });
    } catch (error) {
        console.error('Erreur:', error);
        if (typeof showGlobalStatus === 'function') {
            showGlobalStatus(__('duplication.data_prep_error'), 'error');
        } else {
            alert(__('duplication.data_prep_error'));
        }
    }
}
</script>
