<?php
/**
 * Composant: Carte Projet
 * 
 * Variables attendues:
 * - $project: L'objet projet avec ses propriétés (inclut $project->categories)
 * - $crawls: Liste des crawls du projet
 * - $latestCrawl: Le crawl le plus récent
 * - $domainName: Le nom du domaine/projet
 * - $categories: Liste des catégories de l'utilisateur
 * - $canCreate: Si l'utilisateur peut créer
 */

$projectId = $project->id ?? 0;
$canManage = $project->can_manage ?? false;
$isOwner = $project->is_owner ?? false;
$ownerEmail = $project->owner_email ?? '';

// Catégories du projet (pour l'utilisateur courant)
$projectCategories = $project->categories ?? [];
$firstCategory = !empty($projectCategories) ? $projectCategories[0] : null;
$projectCategoryIds = array_map(fn($c) => $c->id, $projectCategories);
?>
<div class="domain-card" data-category="<?= $firstCategory ? $firstCategory->id : 'uncategorized' ?>" data-project-id="<?= $projectId ?>">
    <div class="domain-header" onclick="toggleDomain('project-<?= $projectId ?>')">
        <div class="domain-info">
            <div class="domain-name-row">
                <?php if($firstCategory): ?>
                    <span class="category-badge-simple category-badge-clickable" 
                          style="border-left: 3px solid <?= htmlspecialchars($firstCategory->color) ?>;"
                          onclick="event.stopPropagation(); toggleCategoryDropdown('project-<?= $projectId ?>')">
                        <?= htmlspecialchars($firstCategory->name) ?>
                    </span>
                <?php else: ?>
                    <span class="category-badge-simple category-badge-clickable category-badge-none"
                          onclick="event.stopPropagation(); toggleCategoryDropdown('project-<?= $projectId ?>')">
                        Sans catégorie
                    </span>
                <?php endif; ?>
                <img src="https://t3.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=TYPE,SIZE,URL&url=https://<?= htmlspecialchars($domainName) ?>&size=16" 
                     alt="" 
                     class="domain-favicon"
                     onerror="this.style.display='none'">
                <h3 class="domain-name">
                    <?= htmlspecialchars($domainName) ?>
                </h3>
                <?php if(!$isOwner && $ownerEmail): ?>
                <span class="owner-badge" style="font-size: 0.75rem; color: var(--text-secondary); margin-left: 0.5rem;">
                    (<?= htmlspecialchars($ownerEmail) ?>)
                </span>
                <?php endif; ?>
            </div>
            <div class="domain-meta">
                <span><?= count($crawls) ?> crawl<?= count($crawls) > 1 ? 's' : '' ?></span>
                <span>•</span>
                <span>Dernier: <?= $latestCrawl ? $latestCrawl->date : 'N/A' ?></span>
            </div>
        </div>
        <div class="domain-actions">
            <!-- Dropdown de catégorie -->
            <div class="category-dropdown-menu" id="cat-dropdown-project-<?= $projectId ?>" onclick="event.stopPropagation()">
                <div class="category-dropdown-header">
                    Choisir une catégorie
                </div>
                <div class="category-dropdown-item <?= empty($projectCategories) ? 'active' : '' ?>" onclick="event.stopPropagation(); assignCategory(<?= $projectId ?>, null)">
                    <span class="category-name">Sans catégorie</span>
                </div>
                <?php foreach($categories as $cat): ?>
                    <div class="category-dropdown-item <?= in_array($cat->id, $projectCategoryIds) ? 'active' : '' ?>" onclick="event.stopPropagation(); assignCategory(<?= $projectId ?>, <?= $cat->id ?>)">
                        <span class="category-color-dot" style="background: <?= htmlspecialchars($cat->color) ?>;"></span>
                        <span class="category-name"><?= htmlspecialchars($cat->name) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            
            
            <?php 
            // Trouver le dernier crawl terminé ou arrêté (pas en cours)
            $lastFinishedCrawl = null;
            foreach ($crawls as $c) {
                $cStatus = $c->job_status ?? 'finished';
                if (in_array($cStatus, ['completed', 'stopped', 'failed'])) {
                    $lastFinishedCrawl = $c;
                    break; // Les crawls sont triés par date desc, donc le premier trouvé est le plus récent
                }
            }
            ?>
            <?php if($lastFinishedCrawl): ?>
                <a href="dashboard.php?crawl=<?= $lastFinishedCrawl->crawl_id ?>" class="btn btn-sm btn-primary" onclick="event.stopPropagation();">
                    <span class="material-symbols-outlined" style="font-size: 16px;">bar_chart</span>
                    Dernier crawl
                </a>
            <?php elseif($latestCrawl): ?>
                <span class="btn btn-sm btn-disabled" style="cursor: default; opacity: 0.5; pointer-events: none;">
                    <span class="material-symbols-outlined" style="font-size: 16px;">bar_chart</span>
                    Aucun dashboard disponible
                </span>
            <?php endif; ?>
            <span class="material-symbols-outlined expand-icon">expand_more</span>
            
            <!-- Menu Kebab (actions) - Only for owners -->
            <?php if($canManage): ?>
            <div class="kebab-menu-wrapper">
                <button class="btn-kebab" onclick="toggleKebabMenu('project-<?= $projectId ?>'); event.stopPropagation();" title="Actions">
                    <span class="material-symbols-outlined">more_vert</span>
                </button>
                <div class="kebab-dropdown-menu" id="kebab-dropdown-project-<?= $projectId ?>">
                    <?php if($canCreate && $isOwner && $latestCrawl): ?>
                    <div class="kebab-dropdown-item primary" onclick="duplicateAndStart('<?= htmlspecialchars($latestCrawl->dir) ?>', <?= $project->user_id ?>); event.stopPropagation();">
                        <span class="material-symbols-outlined">refresh</span>
                        <span>Nouveau crawl</span>
                    </div>
                    <?php endif; ?>
                    <?php if($isOwner): ?>
                    <div class="kebab-dropdown-item" onclick="openProjectSettingsModal(<?= $projectId ?>, '<?= htmlspecialchars($domainName, ENT_QUOTES) ?>'); event.stopPropagation();">
                        <span class="material-symbols-outlined">settings</span>
                        <span>Paramètres</span>
                    </div>
                    <div class="kebab-dropdown-item" onclick="openShareModal(<?= $projectId ?>, '<?= htmlspecialchars($domainName, ENT_QUOTES) ?>'); event.stopPropagation();">
                        <span class="material-symbols-outlined">share</span>
                        <span>Partager</span>
                    </div>
                    <div class="kebab-dropdown-separator"></div>
                    <div class="kebab-dropdown-item danger" onclick="confirmDeleteProject(<?= $projectId ?>, '<?= htmlspecialchars($domainName, ENT_QUOTES) ?>'); event.stopPropagation();">
                        <span class="material-symbols-outlined">delete</span>
                        <span>Supprimer</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if(!empty($crawls)): ?>
    <div class="domain-crawls" id="domain-project-<?= $projectId ?>" style="display: none;">
        <table class="crawls-table crawls-table-modern">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Statut</th>
                    <th>URLs</th>
                    <th>Crawlées</th>
                    <th>Indexables</th>
                    <th>Configuration</th>
                    <?php if($canManage): ?><th style="text-align: center; width: 50px;"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach($crawls as $crawl): 
                    // Tous les crawls vont vers le dashboard
                    $rowUrl = "dashboard.php?crawl=" . $crawl->crawl_id;
                    $isInProgress = in_array($crawl->job_status, ['running', 'queued', 'pending', 'processing']);
                    
                    // Badge de statut (style subtle)
                    $badgeClass = 'status-badge status-completed';
                    $badgeText = 'Terminé';
                    
                    if ($crawl->job_status === 'running') {
                        $badgeClass = 'status-badge status-running';
                        $badgeText = 'En cours';
                    } elseif (in_array($crawl->job_status, ['queued', 'pending'])) {
                        $badgeClass = 'status-badge status-queued';
                        $badgeText = 'En attente';
                    } elseif ($crawl->job_status === 'processing') {
                        $badgeClass = 'status-badge status-processing';
                        $badgeText = 'Traitement...';
                    } elseif ($crawl->job_status === 'failed') {
                        $badgeClass = 'status-badge status-failed';
                        $badgeText = 'Échoué';
                    } elseif ($crawl->job_status === 'stopped') {
                        $badgeClass = 'status-badge status-stopped';
                        $badgeText = 'Arrêté';
                    }
                ?>
                    <?php 
                    // Determine row click action based on permissions
                    if ($canManage && $isInProgress) {
                        $rowAction = "openCrawlPanel('" . htmlspecialchars($crawl->dir) . "', '" . htmlspecialchars($domainName) . "', " . $crawl->crawl_id . ")";
                    } elseif (!$isInProgress) {
                        $rowAction = "window.location.href='" . $rowUrl . "'";
                    } else {
                        // Non-manager + in progress = no action
                        $rowAction = "";
                    }
                    ?>
                    <tr class="crawl-row-clickable" <?= $rowAction ? 'onclick="' . $rowAction . '"' : '' ?> <?= !$rowAction ? 'style="cursor: default;"' : '' ?>>
                        <td class="crawl-date"><?= $crawl->date ?></td>
                        <td><span class="<?= $badgeClass ?>"><?= $badgeText ?></span></td>
                        <td class="crawl-stat"><?= ($crawl->in_progress ?? false) ? '<span class="stat-pending">-</span>' : number_format($crawl->stats['urls'] ?? 0) ?></td>
                        <td class="crawl-stat"><?= ($crawl->in_progress ?? false) ? '<span class="stat-pending">-</span>' : number_format($crawl->stats['crawled'] ?? 0) ?></td>
                        <td class="crawl-stat"><?= ($crawl->in_progress ?? false) ? '<span class="stat-pending">-</span>' : number_format($crawl->stats['compliant'] ?? 0) ?></td>
                        <td>
                            <div class="config-icons">
                                <span class="material-symbols-outlined config-icon <?= ($crawl->config['general']['crawl_mode'] ?? 'classic') === 'javascript' ? 'active' : 'inactive' ?>" title="Mode JavaScript">javascript</span>
                                <span class="material-symbols-outlined config-icon <?= !empty($crawl->config['advanced']['respect']['robots']) ? 'active' : 'inactive' ?>" title="Respect du robots.txt">smart_toy</span>
                                <span class="material-symbols-outlined config-icon <?= !empty($crawl->config['advanced']['respect']['canonical']) ? 'active' : 'inactive' ?>" title="Respect des canonicals">content_copy</span>
                                <span class="material-symbols-outlined config-icon <?= !empty($crawl->config['advanced']['respect']['nofollow']) ? 'active' : 'inactive' ?>" title="Respect du nofollow">link_off</span>
                                <span class="config-depth-badge" title="Profondeur max"><?= $crawl->config['general']['depthMax'] ?? '-' ?></span>
                            </div>
                        </td>
                        <?php if($canManage): ?>
                        <td class="crawl-action" onclick="event.stopPropagation();">
                            <button type="button" class="action-icon" title="<?= $isInProgress ? 'Monitoring' : 'Voir les logs' ?>" style="cursor:pointer; background:none; border:none; padding:0;" onclick="openCrawlPanel('<?= htmlspecialchars($crawl->dir) ?>', '<?= htmlspecialchars($domainName) ?>', <?= $crawl->crawl_id ?>)">
                                <span class="material-symbols-outlined">terminal</span>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
