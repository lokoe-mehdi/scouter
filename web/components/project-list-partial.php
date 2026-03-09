<?php
/**
 * Partiel : liste des projets (utilisé pour le refresh AJAX sans rechargement de page)
 *
 * Variables attendues (définies par index.php) :
 *   $hasProjects, $isViewer, $isAdmin, $canCreate
 *   $myProjects, $sharedProjects, $otherProjects, $otherProjectsByOwner
 */
?>
<?php if(!$hasProjects): ?>
    <div class="empty-state">
        <div class="empty-state-icon">
            <span class="material-symbols-outlined">folder_open</span>
        </div>
        <?php if($isViewer): ?>
        <h2 class="empty-state-title"><?= __('index.empty_viewer_title') ?></h2>
        <p class="empty-state-text"><?= __('index.empty_viewer_text') ?></p>
        <?php else: ?>
        <h2 class="empty-state-title"><?= __('index.empty_user_title') ?></h2>
        <p class="empty-state-text"><?= __('index.empty_user_text') ?></p>
        <button class="btn btn-primary-action" onclick="openNewProjectModal()" style="display: inline-flex; align-items: center; gap: 0.5rem; margin-top: 1rem;">
            <span class="material-symbols-outlined">add_circle</span>
            <?= __('index.create_first_project') ?>
        </button>
        <?php endif; ?>
    </div>
<?php else: ?>

    <?php if ($isViewer): ?>
        <!-- Viewer: pas d'onglets, juste les projets partagés -->
        <div class="projects-section">
            <div class="domains-list">
            <?php foreach($sharedProjects as $sharedProject):
                $project = $sharedProject;
                $crawls = $project->crawls;
                $latestCrawl = !empty($crawls) ? $crawls[0] : null;
                $domainName = $project->name;
                include(__DIR__ . '/project-card.php');
            endforeach;
            unset($project, $crawls, $latestCrawl, $domainName); ?>
            </div>
        </div>
    <?php else: ?>
        <!-- Admin/User: système d'onglets -->
        <div class="projects-tabs" style="display: flex; gap: 0; margin-bottom: 1.5rem; border-bottom: 2px solid var(--border-color);">
            <button type="button" class="projects-tab active" onclick="switchProjectsTab('my')" data-tab="my" style="flex: 0 0 auto; padding: 0.75rem 1.5rem; background: none; border: none; cursor: pointer; font-weight: 600; color: var(--primary-color); border-bottom: 2px solid var(--primary-color); margin-bottom: -2px; display: flex; align-items: center; gap: 0.5rem;">
                <span class="material-symbols-outlined" style="font-size: 18px;">folder</span>
                <?= __('index.tab_my_projects') ?>
                <span style="background: var(--primary-color); color: white; padding: 0.125rem 0.5rem; border-radius: 12px; font-size: 0.75rem;"><?= count($myProjects) ?></span>
            </button>
            <?php if ($isAdmin && !empty($otherProjects)): ?>
            <button type="button" class="projects-tab" onclick="switchProjectsTab('all')" data-tab="all" style="flex: 0 0 auto; padding: 0.75rem 1.5rem; background: none; border: none; cursor: pointer; font-weight: 500; color: var(--text-secondary); border-bottom: 2px solid transparent; margin-bottom: -2px; display: flex; align-items: center; gap: 0.5rem;">
                <span class="material-symbols-outlined" style="font-size: 18px;">admin_panel_settings</span>
                <?= __('index.tab_all_projects') ?>
                <span style="background: #9B59B6; color: white; padding: 0.125rem 0.5rem; border-radius: 12px; font-size: 0.75rem;"><?= count($otherProjects) ?></span>
            </button>
            <?php elseif (!$isAdmin && !empty($sharedProjects)): ?>
            <button type="button" class="projects-tab" onclick="switchProjectsTab('shared')" data-tab="shared" style="flex: 0 0 auto; padding: 0.75rem 1.5rem; background: none; border: none; cursor: pointer; font-weight: 500; color: var(--text-secondary); border-bottom: 2px solid transparent; margin-bottom: -2px; display: flex; align-items: center; gap: 0.5rem;">
                <span class="material-symbols-outlined" style="font-size: 18px;">group</span>
                <?= __('index.tab_shared') ?>
                <span style="background: #F39C12; color: white; padding: 0.125rem 0.5rem; border-radius: 12px; font-size: 0.75rem;"><?= count($sharedProjects) ?></span>
            </button>
            <?php endif; ?>
        </div>

        <!-- Panneau: Mes Projets -->
        <div id="projectsPane-my" class="projects-pane active">
            <?php if (!empty($myProjects)): ?>
            <div class="domains-list">
            <?php foreach($myProjects as $myProject):
                $project = $myProject;
                $crawls = $project->crawls;
                $latestCrawl = !empty($crawls) ? $crawls[0] : null;
                $domainName = $project->name;
                include(__DIR__ . '/project-card.php');
            endforeach;
            unset($project, $crawls, $latestCrawl, $domainName); ?>
            </div>
            <?php else: ?>
            <div class="empty-projects-box">
                <div class="empty-projects-icon">
                    <span class="material-symbols-outlined">folder_open</span>
                </div>
                <h3><?= __('index.empty_projects_title') ?></h3>
                <p><?= __('index.empty_projects_text') ?></p>
                <button class="btn btn-primary-action" onclick="openNewProjectModal()" style="margin-top: 1rem;">
                    <span class="material-symbols-outlined">add_circle</span>
                    <?= __('index.create_first_project') ?>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($isAdmin && !empty($otherProjects)): ?>
        <!-- Panneau: Tous les projets (Admin) -->
        <div id="projectsPane-all" class="projects-pane" style="display: none;">
            <?php foreach($otherProjectsByOwner as $ownerEmail => $ownerProjects): ?>
            <div class="owner-section" style="margin-bottom: 1.5rem;">
                <h3 style="font-size: 0.95rem; color: var(--text-secondary); margin-bottom: 1rem; padding-left: 0.5rem; border-left: 3px solid #9B59B6;">
                    <?= __('index.owner_section_prefix') ?><?= htmlspecialchars($ownerEmail) ?>
                </h3>
                <div class="domains-list">
                <?php foreach($ownerProjects as $otherProject):
                    $project = $otherProject;
                    $crawls = $project->crawls;
                    $latestCrawl = !empty($crawls) ? $crawls[0] : null;
                    $domainName = $project->name;
                    include(__DIR__ . '/project-card.php');
                endforeach;
                unset($project, $crawls, $latestCrawl, $domainName); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$isAdmin && !empty($sharedProjects)): ?>
        <!-- Panneau: Partagés avec moi (User) -->
        <div id="projectsPane-shared" class="projects-pane" style="display: none;">
            <div class="domains-list">
            <?php foreach($sharedProjects as $sharedProject):
                $project = $sharedProject;
                $crawls = $project->crawls;
                $latestCrawl = !empty($crawls) ? $crawls[0] : null;
                $domainName = $project->name;
                include(__DIR__ . '/project-card.php');
            endforeach;
            unset($project, $crawls, $latestCrawl, $domainName); ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

<?php endif; ?>
