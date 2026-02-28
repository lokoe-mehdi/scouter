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
$minSimilarityPercent = 85; // Near-duplicates = 85%+ (distance Hamming <= 9)

// ============================================================================
// DONNÉES PRÉ-CALCULÉES - Depuis crawls et duplicate_clusters
// ============================================================================

// 1. Stats depuis la table crawls (pré-calculées)
$indexablePages = (int)($globalStats->compliant ?? 0);
$totalDuplicatedPages = (int)($globalStats->compliant_duplicate ?? 0);
$totalClusters = (int)($globalStats->clusters_duplicate ?? 0);
$dupRate = $indexablePages > 0 ? round(($totalDuplicatedPages / $indexablePages) * 100, 1) : 0;

// 2. Récupérer les clusters depuis duplicate_clusters (SANS jointure - rapide)
$stmt = $pdo->prepare("
    SELECT id, similarity, page_count, page_ids
    FROM duplicate_clusters
    WHERE crawl_id = :crawl_id
    ORDER BY page_count DESC
");
$stmt->execute([':crawl_id' => $crawlId]);
$allClustersRaw = $stmt->fetchAll(PDO::FETCH_OBJ);

// 3. Collecter tous les page_ids pour faire UNE SEULE requête sur pages
$allPageIds = [];
foreach ($allClustersRaw as $cluster) {
    // PostgreSQL retourne {id1,id2,...} - parser le format array
    $ids = trim($cluster->page_ids, '{}');
    if (!empty($ids)) {
        foreach (explode(',', $ids) as $id) {
            $allPageIds[] = trim($id, '"');
        }
    }
}

// 4. Récupérer les détails de TOUTES les pages en une seule requête
$pagesMap = [];
if (!empty($allPageIds)) {
    // Construire la liste pour IN clause
    $placeholders = implode(',', array_map(function($id) use ($pdo) {
        return $pdo->quote($id);
    }, array_unique($allPageIds)));
    
    $stmt = $pdo->query("
        SELECT id, url, title, inlinks, cat_id
        FROM pages
        WHERE crawl_id = $crawlId AND id IN ($placeholders)
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pagesMap[$row['id']] = $row;
    }
}

// 5. Enrichir chaque cluster avec les détails des pages
$pagesInExactDup = 0;
$pagesInNearDup = 0;
$dupByCategoryMap = [];

foreach ($allClustersRaw as $cluster) {
    // Parser les page_ids
    $ids = trim($cluster->page_ids, '{}');
    $pageIds = !empty($ids) ? array_map(function($id) { return trim($id, '"'); }, explode(',', $ids)) : [];
    
    // Construire le tableau des pages avec leurs détails
    $clusterPages = [];
    foreach ($pageIds as $pid) {
        if (isset($pagesMap[$pid])) {
            $clusterPages[] = $pagesMap[$pid];
        }
    }
    
    // Trier par inlinks DESC
    usort($clusterPages, function($a, $b) {
        return ($b['inlinks'] ?? 0) - ($a['inlinks'] ?? 0);
    });
    
    // Stocker en JSON pour compatibilité avec le reste du code
    $cluster->pages = json_encode($clusterPages);
    
    // Stats exacts vs near-duplicates
    if ((int)$cluster->similarity === 100) {
        $pagesInExactDup += (int)$cluster->page_count;
    } else {
        $pagesInNearDup += (int)$cluster->page_count;
    }
    
    // Répartition par catégorie
    foreach ($clusterPages as $page) {
        $catId = $page['cat_id'] ?? null;
        $catName = 'Non catégorisé';
        $catColor = '#95a5a6';
        if ($catId && isset($categoriesMap[$catId])) {
            $catName = $categoriesMap[$catId]['cat'];
            $catColor = $categoriesMap[$catId]['color'];
        }
        
        if (!isset($dupByCategoryMap[$catName])) {
            $dupByCategoryMap[$catName] = ['count' => 0, 'color' => $catColor];
        }
        $dupByCategoryMap[$catName]['count']++;
    }
}

// pagesWithSimhash = pages indexables (toutes ont un simhash)
$pagesWithSimhash = $indexablePages;

// Convertir en tableau d'objets
$dupByCategory = [];
foreach ($dupByCategoryMap as $catName => $data) {
    $dupByCategory[] = (object)[
        'category_name' => $catName,
        'category_color' => $data['color'],
        'page_count' => $data['count']
    ];
}

// Trier par nombre de pages décroissant
usort($dupByCategory, function($a, $b) {
    return $b->page_count - $a->page_count;
});

/**
 * ============================================================================
 * AFFICHAGE HTML
 * ============================================================================
 */
?>

<h1 class="page-title">Analyse de la duplication</h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1 : Cartes statistiques
         ======================================== -->
    <div class="scorecards">
        <?php
        Component::card([
            'color' => 'primary',
            'icon' => 'verified',
            'title' => 'URLs indexables',
            'value' => number_format($indexablePages),
            'desc' => 'Analyse de duplication limitée aux pages indexables'
        ]);

        Component::card([
            'color' => 'warning',
            'icon' => 'percent',
            'title' => 'Taux de duplication',
            'value' => $dupRate . '%',
            'desc' => 'Pages dupliquées / Pages indexables'
        ]);
        
        Component::card([
            'color' => 'info',
            'icon' => 'workspaces',
            'title' => 'Clusters',
            'value' => number_format($totalClusters),
            'desc' => 'Nombre de clusters de duplication identifiés'
        ]);

        Component::card([
            'color' => 'danger',
            'icon' => 'content_copy',
            'title' => 'Pages dupliquées',
            'value' => number_format($totalDuplicatedPages),
            'desc' => 'Pages flaggées comme dupliquées'
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
            ['name' => 'Contenus uniques', 'y' => $pagesUniques, 'color' => '#6bd899'],
            ['name' => 'Near-duplicates', 'y' => $pagesInNearDup, 'color' => '#60a5fa'],
            ['name' => 'Duplicates exacts', 'y' => $pagesInExactDup, 'color' => '#f87171'],
        ];
        
        Component::chart([
            'type' => 'donut',
            'title' => 'Répartition du contenu',
            'subtitle' => 'Pages uniques vs duplicates',
            'series' => [
                [
                    'name' => 'Pages',
                    'data' => $pieData
                ]
            ],
            'height' => 300,
            'legendPosition' => 'bottom'
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
            $catPieData[] = ['name' => 'Aucun duplicate', 'y' => 1, 'color' => '#e5e7eb'];
        }
        
        Component::chart([
            'type' => 'donut',
            'title' => 'Duplication par catégorie',
            'subtitle' => 'Nombre de pages dupliquées par catégorie',
            'series' => [
                [
                    'name' => 'Pages',
                    'data' => $catPieData
                ]
            ],
            'height' => 300,
            'legendPosition' => 'bottom'
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
        
        // Préparer les données pour le treemap (allClustersRaw est déjà trié par page_count DESC)
        $top20Clusters = array_slice($allClustersRaw, 0, 20);
        
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
                'name' => 'Aucun duplicate détecté',
                'value' => 1,
                'color' => '#e5e7eb'
            ];
        }
        
        // Titre conditionnel selon le nombre de clusters
        $treemapTitle = $totalClusters > 20 
            ? 'Top 20 clusters de duplication (sur ' . $totalClusters . ')'
            : $totalClusters . ' cluster' . ($totalClusters > 1 ? 's' : '') . ' de duplication identifié' . ($totalClusters > 1 ? 's' : '');
        
        Component::chart([
            'type' => 'treemap',
            'title' => $treemapTitle,
            'subtitle' => 'Taille proportionnelle au nombre de pages',
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
    <?php 
    // Utiliser directement allClustersRaw (déjà trié par page_count DESC)
    // Ajouter le type pour l'affichage
    $allClusters = [];
    foreach ($allClustersRaw as $cluster) {
        $cluster->type = ((int)$cluster->similarity === 100) ? 'exact' : 'near';
        $allClusters[] = $cluster;
    }
    
    // Trier par similarité décroissante (les plus dupliqués en premier)
    usort($allClusters, function($a, $b) {
        return $b->similarity <=> $a->similarity;
    });
    
    $totalAllClusters = count($allClusters);
    ?>
    
    <div class="card" style="background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 600;">Clusters de duplication</h3>
                <p style="margin: 0.25rem 0 0; font-size: 0.85rem; color: var(--text-secondary);">
                    Pages avec un contenu identique ou très similaire (seuil : <?= $minSimilarityPercent ?>% minimum)
                </p>
            </div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <button id="copyClustersBtn" onclick="copyClustersToClipboard()" 
                        class="btn-secondary-action" 
                        style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border: 1px solid var(--border-color); background: white; border-radius: 6px; cursor: pointer; font-size: 0.85rem; transition: all 0.2s;" 
                        onmouseover="this.style.background='var(--background)'; this.style.borderColor='var(--primary-color)';" 
                        onmouseout="this.style.background='white'; this.style.borderColor='var(--border-color)';" 
                        title="Copier tous les clusters">
                    <span class="material-symbols-outlined" style="font-size: 18px;">content_copy</span>
                    Copier
                </button>
                <span class="badge" style="background: #f87171; color: white; padding: 0.25rem 0.75rem; border-radius: 12px;"><?= $totalAllClusters ?> clusters</span>
            </div>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($allClusters)): ?>
                <div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
                    <span class="material-symbols-outlined" style="font-size: 3rem; opacity: 0.5;">check_circle</span>
                    <p style="margin-top: 1rem;">Aucun contenu dupliqué détecté</p>
                </div>
            <?php else: ?>
                <div class="clusters-list" style="max-height: 600px; overflow-y: auto;">
                    <?php foreach ($allClusters as $index => $cluster): 
                        $pages = json_decode($cluster->pages, true) ?? [];
                        $leader = $pages[0] ?? null;
                        $clusterId = $cluster->type . '-' . $index;
                    ?>
                    <div class="cluster-item" id="cluster-<?= $clusterId ?>" style="border-bottom: 1px solid var(--border-color);">
                        <div class="cluster-header" 
                             onclick="toggleCluster('<?= $clusterId ?>')" 
                             style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; cursor: pointer; background: var(--background);">
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <span class="material-symbols-outlined cluster-toggle" id="toggle-<?= $clusterId ?>">expand_more</span>
                                <div>
                                    <strong style="font-size: 0.9rem;">Cluster #<?= $index + 1 ?></strong>
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
                                        <?= $cluster->similarity ?>% similaire
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
                                        $catId = $page['cat_id'] ?? null;
                                        $catName = 'Non catégorisé';
                                        $catColor = '#95a5a6';
                                        if ($catId && isset($categoriesMap[$catId])) {
                                            $catName = $categoriesMap[$catId]['cat'];
                                            $catColor = $categoriesMap[$catId]['color'];
                                        }
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
                                                   title="Ouvrir dans un nouvel onglet" 
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
            <?php endif; ?>
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

// Copier tous les clusters dans le presse-papier
function copyClustersToClipboard() {
    try {
        // Préparer les données PHP en JSON
        const allClusters = <?= json_encode($allClusters) ?>;
        const categoriesMap = <?= json_encode($categoriesMap) ?>;
        
        // Créer le contenu tab-separated (pour Excel/Sheets)
        let content = 'Cluster\tCatégorie\tURL\tTitle\tInlinks\n';
        
        allClusters.forEach((cluster, index) => {
            const clusterNum = index + 1;
            const pages = JSON.parse(cluster.pages || '[]');
            
            pages.forEach(page => {
                // Récupérer la catégorie
                const catId = page.cat_id;
                let catName = 'Non catégorisé';
                if (catId && categoriesMap[catId]) {
                    catName = categoriesMap[catId].cat;
                }
                
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
                showGlobalStatus('✓ Texte copié', 'success');
            }
        }).catch(err => {
            console.error('Erreur copie:', err);
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus('Erreur lors de la copie', 'error');
            } else {
                alert('Impossible de copier dans le presse-papier');
            }
        });
    } catch (error) {
        console.error('Erreur:', error);
        if (typeof showGlobalStatus === 'function') {
            showGlobalStatus('Erreur lors de la préparation des données', 'error');
        } else {
            alert('Erreur lors de la préparation des données');
        }
    }
}
</script>
