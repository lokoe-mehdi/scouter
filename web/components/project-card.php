<?php
/**
 * Composant: Carte Projet (ligne compacte "dashboard")
 *
 * Variables attendues:
 * - $project, $crawls, $latestCrawl, $domainName, $categories, $canCreate
 *
 * Rendu: une ligne condensée par projet (nom + KPIs Santé SEO / Pages indexables
 * / Erreurs critiques / Tendance + Ouvrir), dépliable au clic vers la table des
 * derniers crawls. Conserve les hooks JS existants (domain-card, .domain-name,
 * data-category, #domain-project-ID, dropdowns catégorie/kebab).
 */

require_once(__DIR__ . '/project-metrics.php');

$projectId = $project->id ?? 0;
$canManage = $project->can_manage ?? false;
$isOwner   = $project->is_owner ?? false;
$ownerEmail = $project->owner_email ?? '';

$projectCategories = $project->categories ?? [];
$firstCategory = !empty($projectCategories) ? $projectCategories[0] : null;
$projectCategoryIds = array_map(fn($c) => $c->id, $projectCategories);

// --- Métriques du dernier crawl + variations vs crawl précédent --------------
$latestStats = $latestCrawl ? (array)$latestCrawl->stats : [];
$prevCrawl   = (count($crawls) > 1) ? $crawls[1] : null;
$prevStats   = $prevCrawl ? (array)$prevCrawl->stats : [];

// Score CH (5 piliers) si dispo (crawl migré), sinon repli pur-PHP pcHealthScore.
$health     = $latestCrawl ? (int)($latestStats['health_score'] ?? pcHealthScore($latestStats)) : 0;
$indexable  = (int)($latestStats['compliant'] ?? 0);
$critical   = (int)($latestStats['critical_errors'] ?? 0);
// Timestamp de tri = date du dernier crawl du projet (finished_at sinon started_at,
// le max sur tous les crawls). Aligné sur le tri serveur d'index.php pour que le
// tri client "date" reste cohérent, y compris pour un vieux crawl repris récemment.
$lastTs = 0;
foreach ($crawls as $c) {
    $t = max(
        !empty($c->finished_at) ? (int)strtotime($c->finished_at) : 0,
        !empty($c->started_at)  ? (int)strtotime($c->started_at)  : 0
    );
    if ($t > $lastTs) $lastTs = $t;
}

// Série de tendance (santé par crawl, du plus ancien au plus récent, max 12)
$trend = [];
foreach (array_reverse(array_slice($crawls, 0, 12)) as $c) {
    $cStats = (array)$c->stats;
    $trend[] = (int)($cStats['health_score'] ?? pcHealthScore($cStats));
}
// Tendance en vert doux si stable/en hausse, rouge doux si dégradation nette.
$trendColor = (count($trend) > 1 && $trend[count($trend)-1] < $trend[0] - 3) ? '#E0816F' : '#3DBE8B';
?>
<div class="domain-card pc-row" data-category="<?= $firstCategory ? $firstCategory->id : 'uncategorized' ?>" data-project-id="<?= $projectId ?>" data-ts="<?= (int)$lastTs ?>">
    <div class="pc-main" onclick="toggleDomain('project-<?= $projectId ?>')">
        <!-- Identité -->
        <div class="pc-identity">
            <div class="pc-name-line">
                <?php if($firstCategory): ?>
                    <span class="pc-cat-tag category-badge-simple" style="border-left-color: <?= htmlspecialchars($firstCategory->color) ?>;"
                          onclick="event.stopPropagation(); toggleCategoryDropdown('project-<?= $projectId ?>')">
                        <?= htmlspecialchars($firstCategory->name) ?>
                    </span>
                <?php else: ?>
                    <span class="pc-cat-tag category-badge-simple"
                          onclick="event.stopPropagation(); toggleCategoryDropdown('project-<?= $projectId ?>')">
                        <?= __('index.filter_uncategorized') ?>
                    </span>
                <?php endif; ?>
                <img src="https://t3.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=TYPE,SIZE,URL&url=https://<?= htmlspecialchars($domainName) ?>&size=16"
                     alt="" class="pc-favicon domain-favicon" onerror="this.style.display='none'">
                <h3 class="domain-name pc-name"><?= htmlspecialchars($domainName) ?></h3>
            </div>
            <div class="domain-meta pc-sub">
                <span><?= __('index.last_crawl') ?> · <?= $latestCrawl ? $latestCrawl->date : 'N/A' ?></span>
                <?php if(!$isOwner && $ownerEmail): ?>
                    <span class="pc-owner">· <?= htmlspecialchars($ownerEmail) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- KPI: Santé SEO -->
        <div class="pc-kpi pc-kpi-health">
            <span class="pc-kpi-label"><?= __('index.kpi_health') ?></span>
            <div class="pc-health">
                <?= pcDonutSvg($health) ?>
                <span class="pc-health-num"><?= $health ?><small>/100</small></span>
            </div>
        </div>

        <!-- KPI: Pages indexables -->
        <div class="pc-kpi">
            <span class="pc-kpi-label"><?= __('index.kpi_indexable') ?></span>
            <div class="pc-kpi-val">
                <span class="pc-num"><?= number_format($indexable) ?></span>
                <?= pcDelta($indexable, $prevStats['compliant'] ?? 0, true) ?>
            </div>
        </div>

        <!-- KPI: Erreurs critiques -->
        <div class="pc-kpi">
            <span class="pc-kpi-label"><?= __('index.kpi_errors') ?></span>
            <div class="pc-kpi-val">
                <span class="pc-num"><?= number_format($critical) ?></span>
                <?= pcDelta($critical, $prevStats['critical_errors'] ?? 0, false) ?>
            </div>
        </div>

        <!-- KPI: Tendance -->
        <div class="pc-kpi pc-kpi-trend">
            <span class="pc-kpi-label"><?= __('index.kpi_trend') ?></span>
            <?= pcSparklineSvg($trend, $trendColor, [0, 100]) ?>
        </div>

        <!-- Actions -->
        <div class="pc-actions">
            <a href="project.php?id=<?= $projectId ?>" class="pc-open-btn" onclick="event.stopPropagation();">
                <?= __('index.open') ?>
            </a>

            <?php if($canManage): ?>
            <div class="pc-kebab-wrap kebab-menu-wrapper">
                <button class="btn-kebab" onclick="toggleKebabMenu('project-<?= $projectId ?>'); event.stopPropagation();" title="Actions">
                    <span class="material-symbols-outlined">more_vert</span>
                </button>
                <div class="kebab-dropdown-menu" id="kebab-dropdown-project-<?= $projectId ?>">
                    <?php if($canCreate && $isOwner && $latestCrawl): ?>
                    <div class="kebab-dropdown-item primary" onclick="duplicateAndStart('<?= htmlspecialchars($latestCrawl->dir) ?>', <?= $project->user_id ?>); event.stopPropagation();">
                        <span class="material-symbols-outlined">refresh</span>
                        <span><?= __('index.new_crawl') ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if($isOwner): ?>
                    <div class="kebab-dropdown-item" onclick="openProjectSettingsModal(<?= $projectId ?>, '<?= htmlspecialchars($domainName, ENT_QUOTES) ?>'); event.stopPropagation();">
                        <span class="material-symbols-outlined">settings</span>
                        <span><?= __('index.project_settings') ?></span>
                    </div>
                    <div class="kebab-dropdown-item" onclick="openShareModal(<?= $projectId ?>, '<?= htmlspecialchars($domainName, ENT_QUOTES) ?>'); event.stopPropagation();">
                        <span class="material-symbols-outlined">share</span>
                        <span><?= __('index.share') ?></span>
                    </div>
                    <div class="kebab-dropdown-separator"></div>
                    <div class="kebab-dropdown-item danger" onclick="confirmDeleteProject(<?= $projectId ?>, '<?= htmlspecialchars($domainName, ENT_QUOTES) ?>'); event.stopPropagation();">
                        <span class="material-symbols-outlined">delete</span>
                        <span><?= __('common.delete') ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Dropdown catégorie (réutilise le JS existant) -->
            <div class="category-dropdown-menu" id="cat-dropdown-project-<?= $projectId ?>" onclick="event.stopPropagation()">
                <div class="category-dropdown-header"><?= __('index.choose_category') ?></div>
                <div class="category-dropdown-item <?= empty($projectCategories) ? 'active' : '' ?>" onclick="event.stopPropagation(); assignCategory(<?= $projectId ?>, null)">
                    <span class="category-name"><?= __('index.filter_uncategorized') ?></span>
                </div>
                <?php foreach($categories as $cat): ?>
                    <div class="category-dropdown-item <?= in_array($cat->id, $projectCategoryIds) ? 'active' : '' ?>" onclick="event.stopPropagation(); assignCategory(<?= $projectId ?>, <?= $cat->id ?>)">
                        <span class="category-color-dot" style="background: <?= htmlspecialchars($cat->color) ?>;"></span>
                        <span class="category-name"><?= htmlspecialchars($cat->name) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if(!empty($crawls)): ?>
    <div class="pc-crawls" id="domain-project-<?= $projectId ?>" style="display: none;">
        <div class="pc-crawls-head">
            <span><?= __('index.recent_crawls') ?></span>
            <?php if (count($crawls) > 3): ?>
            <a href="project.php?id=<?= $projectId ?>" onclick="event.stopPropagation();"><?= __('index.view_all_crawls', ['count' => count($crawls)]) ?></a>
            <?php endif; ?>
        </div>
        <table class="pc-table">
            <thead>
                <tr>
                    <th><?= __('index.col_date') ?></th>
                    <th><?= __('index.col_status') ?></th>
                    <th><?= __('index.col_crawled') ?></th>
                    <th><?= __('index.col_indexable') ?></th>
                    <th><?= __('index.kpi_errors') ?></th>
                    <th><?= __('index.col_time') ?></th>
                    <?php if($canManage): ?><th style="text-align:right;"><?= __('index.col_actions') ?></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach(array_slice($crawls, 0, 5) as $crawl):
                    $rowUrl = "dashboard.php?crawl=" . $crawl->crawl_id;
                    $isInProgress = in_array($crawl->job_status, ['running', 'queued', 'pending', 'processing', 'stopping']);

                    // Badge de statut
                    $st = $crawl->job_status ?? 'finished';
                    if (in_array($st, ['running', 'stopping'])) { $badge = 'running'; $badgeText = __('index.status_running'); }
                    elseif (in_array($st, ['queued', 'pending'])) { $badge = 'running'; $badgeText = __('index.status_queued'); }
                    elseif ($st === 'processing') { $badge = 'running'; $badgeText = __('index.status_processing'); }
                    elseif ($st === 'failed') { $badge = 'failed'; $badgeText = __('index.status_failed'); }
                    elseif ($st === 'stopped') { $badge = 'stopped'; $badgeText = __('index.status_stopped'); }
                    else { $badge = 'done'; $badgeText = __('index.status_completed'); }

                    if ($canManage && $isInProgress) {
                        $rowAction = "openCrawlPanel('" . htmlspecialchars($crawl->dir) . "', '" . htmlspecialchars($domainName) . "', " . $crawl->crawl_id . ")";
                    } elseif (!$isInProgress) {
                        $rowAction = "window.location.href='" . $rowUrl . "'";
                    } else { $rowAction = ""; }

                    $cs = (array)$crawl->stats;
                ?>
                    <tr class="<?= $rowAction ? 'clickable' : '' ?>" <?= $rowAction ? 'onclick="' . $rowAction . '"' : '' ?>>
                        <td class="pc-num"><?= $crawl->date ?></td>
                        <td><span class="pc-badge <?= $badge ?>"><?= $badgeText ?></span></td>
                        <td class="pc-num"><?= $isInProgress ? '—' : number_format($cs['crawled'] ?? 0) ?></td>
                        <td class="pc-num"><?= $isInProgress ? '—' : number_format($cs['compliant'] ?? 0) ?></td>
                        <td class="pc-num"><?= $isInProgress ? '—' : number_format($cs['critical_errors'] ?? 0) ?></td>
                        <td class="pc-num"><?= $isInProgress ? '—' : pcDuration($crawl->started_at ?? null, $crawl->finished_at ?? null) ?></td>
                        <?php if($canManage): ?>
                        <td onclick="event.stopPropagation();">
                            <div class="pc-table-actions">
                                <a class="pc-act" href="<?= $rowUrl ?>" title="<?= __('index.view_logs') ?>"><span class="material-symbols-outlined">monitoring</span></a>
                                <button type="button" class="pc-act" title="<?= $isInProgress ? __('index.monitoring') : __('index.view_logs') ?>"
                                        onclick="openCrawlPanel('<?= htmlspecialchars($crawl->dir) ?>', '<?= htmlspecialchars($domainName) ?>', <?= $crawl->crawl_id ?>)">
                                    <span class="material-symbols-outlined">terminal</span>
                                </button>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
