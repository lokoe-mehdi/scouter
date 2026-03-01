<?php

// Initialisation et vérification d'authentification automatique
require_once(__DIR__ . '/init.php');

use App\Job\JobManager;
use App\Database\ProjectRepository;
use App\Database\CrawlRepository;
use App\Database\CategoryRepository;
use App\Auth\Auth;

$jobManager = new JobManager();
$projects = new ProjectRepository();
$crawls = new CrawlRepository();
$categoryRepo = new CategoryRepository();
$auth = new Auth();

// Nettoyer les jobs orphelins (processus terminés mais statut encore "running")
$jobManager->cleanupStaleJobs();

// Informations utilisateur
$currentUserId = $auth->getCurrentUserId();
$currentRole = $auth->getCurrentRole();
$isAdmin = $auth->isAdmin();
$isViewer = $auth->isViewer();
$canCreate = $auth->canCreate();

// Récupération des catégories de l'utilisateur
$categories = $categoryRepo->getForUser($currentUserId);

// Récupération des projets selon le rôle
$myProjects = [];
$sharedProjects = [];
$otherProjects = [];
$categoryStats = [];

/**
 * Transforme les crawls d'un projet en structure utilisable
 */
function processCrawlsForProject($project, $crawls, $jobManager) {
    $projectCrawls = $crawls->getByProjectId($project->id);
    $processedCrawls = [];
    
    foreach ($projectCrawls as $crawl) {
        $dir = $crawl->path ?? $crawl->id;
        
        // Récupérer le statut du job
        $job = $crawl->path ? $jobManager->getJobByProject($crawl->path) : null;
        $jobStatus = $job ? $job->status : ($crawl->status ?? 'finished');
        
        // Utiliser started_at directement (déjà trié par la DB)
        $timestamp = strtotime($crawl->started_at ?? 'now');
        
        $processedCrawls[] = (object) [
            "dir" => $dir,
            "crawl_id" => $crawl->id,
            "name" => $crawl->domain,
            "date" => date("d/m/Y H:i", $timestamp),
            "timestamp" => $timestamp,
            "stats" => [
                'urls' => $crawl->urls,
                'crawled' => $crawl->crawled,
                'compliant' => $crawl->compliant
            ],
            "job_status" => $jobStatus,
            "in_progress" => $crawl->in_progress ?? 0,
            "config" => json_decode($crawl->config ?? '{}', true),
            "depth_max" => $crawl->depth_max
        ];
    }
    
    // Trier par ID décroissant (le plus récent a l'ID le plus grand)
    usort($processedCrawls, function($a, $b) {
        return $b->crawl_id - $a->crawl_id;
    });
    
    return $processedCrawls;
}

try {
    // ================================================================
    // CAS 1: ADMIN - Voit ses projets + tous les autres projets
    // ================================================================
    if ($isAdmin) {
        // Mes projets (dont je suis propriétaire)
        $myProjects = $projects->getForUser($currentUserId);
        foreach ($myProjects as &$p) {
            $p->crawls = processCrawlsForProject($p, $crawls, $jobManager);
            $p->is_owner = true;
            $p->can_manage = true;
        }
        unset($p);
        
        // Tous les autres projets (dont je ne suis PAS propriétaire)
        $allProjects = $projects->getAllWithOwner();
        foreach ($allProjects as $p) {
            if ($p->user_id != $currentUserId) {
                $p->crawls = processCrawlsForProject($p, $crawls, $jobManager);
                $p->is_owner = true; // Admin peut tout faire
                $p->can_manage = true;
                $otherProjects[] = $p;
            }
        }
        
        // Pas de projets partagés pour admin (il voit tout dans "Tous les projets")
        $sharedProjects = [];
    }
    // ================================================================
    // CAS 2: USER - Voit ses projets + projets partagés avec lui
    // ================================================================
    elseif (!$isViewer) {
        // Mes projets (dont je suis propriétaire)
        $myProjects = $projects->getForUser($currentUserId);
        $myProjectIds = [];
        foreach ($myProjects as &$p) {
            $p->crawls = processCrawlsForProject($p, $crawls, $jobManager);
            $p->is_owner = true;
            $p->can_manage = true;
            $myProjectIds[] = $p->id;
        }
        unset($p);
        
        // Projets partagés avec moi (dont je ne suis PAS propriétaire)
        // La requête SQL exclut déjà les projets dont je suis proprio
        $sharedProjectsRaw = $projects->getSharedForUser($currentUserId);
        $sharedProjects = [];
        $seenIds = [];
        foreach ($sharedProjectsRaw as $p) {
            // Double sécurité: exclure mes propres projets et les doublons
            if (!in_array($p->id, $myProjectIds) && !in_array($p->id, $seenIds)) {
                $p->crawls = processCrawlsForProject($p, $crawls, $jobManager);
                $p->is_owner = false;
                $p->can_manage = false;
                $sharedProjects[] = $p;
                $seenIds[] = $p->id;
            }
        }
        
        // Pas d'autres projets pour user
        $otherProjects = [];
    }
    // ================================================================
    // CAS 3: VIEWER - Voit uniquement les projets partagés avec lui
    // ================================================================
    else {
        // Pas de projets personnels pour viewer
        $myProjects = [];
        
        // Projets partagés avec moi (dont je ne suis PAS propriétaire)
        $sharedProjectsRaw = $projects->getSharedForUser($currentUserId);
        $sharedProjects = [];
        $seenIds = [];
        foreach ($sharedProjectsRaw as $p) {
            // Exclure les doublons
            if (!in_array($p->id, $seenIds)) {
                $p->crawls = processCrawlsForProject($p, $crawls, $jobManager);
                $p->is_owner = false;
                $p->can_manage = false;
                $sharedProjects[] = $p;
                $seenIds[] = $p->id;
            }
        }
        
        // Pas d'autres projets pour viewer
        $otherProjects = [];
    }
    
    // ================================================================
    // TRI ET ORGANISATION COMMUNES
    // ================================================================
    
    // Fonction de tri par ID du dernier crawl (le plus récent = ID le plus grand)
    $sortByLastCrawl = function($a, $b) {
        $aId = !empty($a->crawls) ? $a->crawls[0]->crawl_id : 0;
        $bId = !empty($b->crawls) ? $b->crawls[0]->crawl_id : 0;
        return $bId - $aId;
    };
    
    if (!empty($myProjects)) usort($myProjects, $sortByLastCrawl);
    if (!empty($sharedProjects)) usort($sharedProjects, $sortByLastCrawl);
    if (!empty($otherProjects)) usort($otherProjects, $sortByLastCrawl);
    
    // Grouper les autres projets par propriétaire (pour Admin)
    $otherProjectsByOwner = [];
    foreach ($otherProjects as $project) {
        $ownerEmail = $project->owner_email;
        if (!isset($otherProjectsByOwner[$ownerEmail])) {
            $otherProjectsByOwner[$ownerEmail] = [];
        }
        $otherProjectsByOwner[$ownerEmail][] = $project;
    }
    
    // ================================================================
    // CATÉGORIES DES PROJETS
    // ================================================================
    
    // Fonction helper pour ajouter les catégories à un projet
    $addCategoriesToProject = function(&$project) use ($categoryRepo, $currentUserId, &$categoryStats) {
        try {
            $project->categories = $categoryRepo->getForProject($project->id, $currentUserId);
        } catch (Exception $e) {
            // Si erreur, on met un tableau vide
            $project->categories = [];
        }
        
        if (!empty($project->categories)) {
            $firstCat = $project->categories[0];
            $project->category_id = $firstCat->id;
            $project->category_name = $firstCat->name;
            $project->category_color = $firstCat->color;
            
            foreach ($project->categories as $cat) {
                if (!isset($categoryStats[$cat->id])) {
                    $categoryStats[$cat->id] = 0;
                }
                $categoryStats[$cat->id]++;
            }
        } else {
            $project->category_id = null;
            $project->category_name = null;
            $project->category_color = null;
            
            if (!isset($categoryStats['uncategorized'])) {
                $categoryStats['uncategorized'] = 0;
            }
            $categoryStats['uncategorized']++;
        }
    };
    
    // Ajouter les catégories à mes projets
    foreach ($myProjects as &$p) {
        $addCategoriesToProject($p);
    }
    unset($p);
    
    // Ajouter les catégories aux projets partagés
    foreach ($sharedProjects as &$p) {
        $addCategoriesToProject($p);
    }
    unset($p);
    
    // Ajouter les catégories aux autres projets (pour admin)
    foreach ($otherProjects as &$p) {
        $addCategoriesToProject($p);
    }
    unset($p);
    
    // Calculer le total des projets pour l'affichage
    $totalProjects = count($myProjects) + count($sharedProjects) + count($otherProjects);
    $hasProjects = $totalProjects > 0;
    
} catch(Exception $e) {
    error_log("Erreur lors du chargement des projets: " . $e->getMessage());
    $hasProjects = false;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scouter - Mes Projets SEO</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/crawl-panel.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/vendor/material-symbols/material-symbols.css" />
    <style>
        .config-icon {
            font-size: 20px;
            margin: 0 2px;
            vertical-align: middle;
            cursor: default;
            color: var(--text-primary);
        }
        .config-icon.active {
            color: var(--primary-color);
            font-variation-settings: 'FILL' 1;
        }
        .config-icon.inactive {
            opacity: 0.3;
            font-variation-settings: 'FILL' 0;
        }
        .config-depth {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-left: 2px;
            vertical-align: middle;
        }
        .btn{cursor:pointer !important;}
        
        /* Empty projects box avec rectangle pointillé */
        .empty-projects-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 4rem 2rem;
            margin: 1rem;
            border: 3px dashed var(--border-color);
            border-radius: 16px;
            background: var(--bg-secondary);
            text-align: center;
        }
        .empty-projects-icon {
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-primary);
            border-radius: 50%;
            margin-bottom: 1.5rem;
        }
        .empty-projects-icon .material-symbols-outlined {
            font-size: 40px;
            color: var(--text-tertiary);
        }
        .empty-projects-box h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0 0 0.5rem 0;
        }
        .empty-projects-box p {
            font-size: 0.95rem;
            color: var(--text-secondary);
            margin: 0;
            max-width: 350px;
        }
    </style>
    <script src="assets/tooltip.js?v=<?= time() ?>"></script>
</head>
<body>
    <!-- Header -->
    <?php $headerContext = 'index'; include 'components/top-header.php'; ?>

    <!-- Main Container with Sidebar -->
    <div class="container-with-sidebar">
        <?php if($hasProjects): ?>
        <!-- Categories Sidebar -->
        <aside class="categories-sidebar">
            <div class="categories-sidebar-header">
                <h3>Catégories</h3>
                <button class="btn-icon" onclick="openCategoriesModal()" title="Gérer les catégories" style="cursor:pointer">
                    <span class="material-symbols-outlined">settings</span>
                </button>
            </div>
            
            <div class="categories-sidebar-filters">
                <div class="category-filter-item active" onclick="filterByCategory('all')" data-category="all">
                    <span class="category-filter-name">Tout</span>
                    <span class="category-filter-count"><?= $totalProjects ?></span>
                </div>
                
                <?php foreach($categories as $cat): ?>
                    <div class="category-filter-item" 
                         onclick="filterByCategory(<?= $cat->id ?>)"
                         data-category="<?= $cat->id ?>">
                        <span class="category-color-dot" style="background: <?= htmlspecialchars($cat->color) ?>;"></span>
                        <span class="category-filter-name"><?= htmlspecialchars($cat->name) ?></span>
                        <span class="category-filter-count"><?= $categoryStats[$cat->id] ?? 0 ?></span>
                    </div>
                <?php endforeach; ?>
                
                <div class="category-filter-item" onclick="filterByCategory('uncategorized')" data-category="uncategorized">
                    <span class="category-filter-name">Sans catégorie</span>
                    <span class="category-filter-count"><?= $categoryStats['uncategorized'] ?? 0 ?></span>
                </div>
            </div>
            
            <div class="category-add-link">
                <button class="btn-add-category" onclick="openQuickAddCategoryModal()">
                    + Ajouter une catégorie
                </button>
            </div>
        </aside>
        <?php endif; ?>
        
        <!-- Main Content -->
        <div class="main-content-area">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                <h1 class="page-title"><?= $isViewer ? 'Projets partagés' : 'Mes projets SEO' ?></h1>
                <div style="display: flex; gap: 1rem;">
                    <?php if(!$hasProjects): ?>
                    <button class="btn btn-secondary" onclick="openCategoriesModal()" style="display: flex; align-items: center; gap: 0.5rem;">
                        <span class="material-symbols-outlined">category</span>
                        Gérer les catégories
                    </button>
                    <?php endif; ?>
                    <?php if($canCreate): ?>
                    <button class="btn btn-primary-action" onclick="openNewProjectModal()" style="display: flex; align-items: center; gap: 0.5rem;cursor:pointer;">
                        <span class="material-symbols-outlined">add_circle</span>
                        Nouveau projet
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if($hasProjects): ?>
            <div class="search-container" style="margin-bottom: 2rem; display: flex; gap: 1rem; align-items: center;">
                <div style="position: relative; flex: 1; max-width: 500px;">
                    <span class="material-symbols-outlined" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-secondary);">search</span>
                    <input 
                        type="text" 
                        id="domainSearch" 
                        placeholder="Rechercher un projet..." 
                        class="search-input"
                        oninput="filterDomains()"
                        style="width: 100%; padding: 0.75rem 1rem 0.75rem 3rem; border: 2px solid var(--border-color); border-radius: 8px; font-size: 1rem; transition: all 0.3s ease;"
                        onfocus="this.style.borderColor='var(--primary-color)'"
                        onblur="this.style.borderColor='var(--border-color)'"
                    >
                </div>
                
                <div class="sort-dropdown-wrapper">
                    <button class="sort-dropdown-btn" onclick="toggleSortDropdown()">
                        <span class="material-symbols-outlined">sort</span>
                        <span id="currentSortLabel">Du plus récent au plus ancien</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </button>
                    <div class="sort-dropdown-menu" id="sortDropdown">
                        <div class="sort-dropdown-item active" data-sort="date-desc" onclick="changeSortOption('date-desc')">
                            <span class="material-symbols-outlined">schedule</span>
                            Du plus récent au plus ancien
                        </div>
                        <div class="sort-dropdown-item" data-sort="date-asc" onclick="changeSortOption('date-asc')">
                            <span class="material-symbols-outlined">history</span>
                            Du plus ancien au plus récent
                        </div>
                        <div class="sort-dropdown-item" data-sort="alpha-asc" onclick="changeSortOption('alpha-asc')">
                            <span class="material-symbols-outlined">sort_by_alpha</span>
                            Par ordre alphabétique (A → Z)
                        </div>
                        <div class="sort-dropdown-item" data-sort="alpha-desc" onclick="changeSortOption('alpha-desc')">
                            <span class="material-symbols-outlined">sort_by_alpha</span>
                            Par ordre alphabétique (Z → A)
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if(!$hasProjects): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <span class="material-symbols-outlined">folder_open</span>
                    </div>
                    <?php if($isViewer): ?>
                    <h2 class="empty-state-title">Aucun projet partagé</h2>
                    <p class="empty-state-text">Aucun projet n'a été partagé avec vous pour le moment.</p>
                    <?php else: ?>
                    <h2 class="empty-state-title">Aucun projet pour le moment</h2>
                    <p class="empty-state-text">Commencez par créer votre premier projet de crawl pour analyser un site web.</p>
                    <button class="btn btn-primary-action" onclick="openNewProjectModal()" style="display: inline-flex; align-items: center; gap: 0.5rem; margin-top: 1rem;">
                        <span class="material-symbols-outlined">add_circle</span>
                        Créer mon premier projet
                    </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
            
                <?php if ($isViewer): ?>
                    <!-- Viewer: pas d'onglets, juste les projets partagés -->
                    <div class="projects-section">
                        <div class="domains-list">
                        <?php foreach($sharedProjects as $sharedProject): 
                            // Variables isolées pour ce projet partagé
                            $project = $sharedProject;
                            $crawls = $project->crawls;
                            $latestCrawl = !empty($crawls) ? $crawls[0] : null;
                            $domainName = $project->name;
                            include(__DIR__ . '/components/project-card.php');
                        endforeach; 
                        unset($project, $crawls, $latestCrawl, $domainName); ?>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Admin/User: système d'onglets -->
                    <div class="projects-tabs" style="display: flex; gap: 0; margin-bottom: 1.5rem; border-bottom: 2px solid var(--border-color);">
                        <button type="button" class="projects-tab active" onclick="switchProjectsTab('my')" data-tab="my" style="flex: 0 0 auto; padding: 0.75rem 1.5rem; background: none; border: none; cursor: pointer; font-weight: 600; color: var(--primary-color); border-bottom: 2px solid var(--primary-color); margin-bottom: -2px; display: flex; align-items: center; gap: 0.5rem;">
                            <span class="material-symbols-outlined" style="font-size: 18px;">folder</span>
                            Mes Projets
                            <span style="background: var(--primary-color); color: white; padding: 0.125rem 0.5rem; border-radius: 12px; font-size: 0.75rem;"><?= count($myProjects) ?></span>
                        </button>
                        <?php if ($isAdmin && !empty($otherProjects)): ?>
                        <button type="button" class="projects-tab" onclick="switchProjectsTab('all')" data-tab="all" style="flex: 0 0 auto; padding: 0.75rem 1.5rem; background: none; border: none; cursor: pointer; font-weight: 500; color: var(--text-secondary); border-bottom: 2px solid transparent; margin-bottom: -2px; display: flex; align-items: center; gap: 0.5rem;">
                            <span class="material-symbols-outlined" style="font-size: 18px;">admin_panel_settings</span>
                            Tous les projets
                            <span style="background: #9B59B6; color: white; padding: 0.125rem 0.5rem; border-radius: 12px; font-size: 0.75rem;"><?= count($otherProjects) ?></span>
                        </button>
                        <?php elseif (!$isAdmin && !empty($sharedProjects)): ?>
                        <button type="button" class="projects-tab" onclick="switchProjectsTab('shared')" data-tab="shared" style="flex: 0 0 auto; padding: 0.75rem 1.5rem; background: none; border: none; cursor: pointer; font-weight: 500; color: var(--text-secondary); border-bottom: 2px solid transparent; margin-bottom: -2px; display: flex; align-items: center; gap: 0.5rem;">
                            <span class="material-symbols-outlined" style="font-size: 18px;">group</span>
                            Partagés avec moi
                            <span style="background: #F39C12; color: white; padding: 0.125rem 0.5rem; border-radius: 12px; font-size: 0.75rem;"><?= count($sharedProjects) ?></span>
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Panneau: Mes Projets -->
                    <div id="projectsPane-my" class="projects-pane active">
                        <?php if (!empty($myProjects)): ?>
                        <div class="domains-list">
                        <?php foreach($myProjects as $myProject): 
                            // Variables isolées pour MON projet
                            $project = $myProject;
                            $crawls = $project->crawls;
                            $latestCrawl = !empty($crawls) ? $crawls[0] : null;
                            $domainName = $project->name;
                            include(__DIR__ . '/components/project-card.php');
                        endforeach; 
                        unset($project, $crawls, $latestCrawl, $domainName); ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-projects-box">
                            <div class="empty-projects-icon">
                                <span class="material-symbols-outlined">folder_open</span>
                            </div>
                            <h3>Vous n'avez pas encore de projet</h3>
                            <p>Créez votre premier projet pour commencer à analyser un site web.</p>
                            <button class="btn btn-primary-action" onclick="openNewProjectModal()" style="margin-top: 1rem;">
                                <span class="material-symbols-outlined">add_circle</span>
                                Créer mon premier projet
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
                                Projets de <?= htmlspecialchars($ownerEmail) ?>
                            </h3>
                            <div class="domains-list">
                            <?php foreach($ownerProjects as $otherProject): 
                                // Variables isolées pour projet AUTRE
                                $project = $otherProject;
                                $crawls = $project->crawls;
                                $latestCrawl = !empty($crawls) ? $crawls[0] : null;
                                $domainName = $project->name;
                                include(__DIR__ . '/components/project-card.php');
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
                            // Variables isolées pour projet PARTAGÉ
                            $project = $sharedProject;
                            $crawls = $project->crawls;
                            $latestCrawl = !empty($crawls) ? $crawls[0] : null;
                            $domainName = $project->name;
                            include(__DIR__ . '/components/project-card.php');
                        endforeach; 
                        unset($project, $crawls, $latestCrawl, $domainName); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Nouveau Projet - Design UX Progressive Disclosure -->
    <div id="newProjectModal" class="modal">
        <div class="modal-content crawl-modal-redesign">
            <form id="newProjectForm" onsubmit="return createProject(event)">
                
                <!-- Header + URL fusionnés -->
                <div class="crawl-modal-hero">
                    <div class="hero-header">
                        <div class="hero-title">
                            <span class="material-symbols-outlined">rocket_launch</span>
                            Nouveau Projet de Crawl
                        </div>
                        <button type="button" class="hero-close" onclick="closeNewProjectModal()">&times;</button>
                    </div>
                    <div class="hero-url-group">
                        <label for="start_url" class="hero-label">
                            <span class="material-symbols-outlined">language</span>
                            URL de départ
                        </label>
                        <input type="url" id="start_url" name="start_url" 
                               class="hero-input" 
                               placeholder="https://site-a-crawler.com" 
                               required
                               autofocus>
                    </div>
                </div>

                <!-- Système d'onglets -->
                <div class="crawl-tabs">
                    <button type="button" class="crawl-tab active" data-tab="general" onclick="switchCrawlTab('general')">
                        <span class="material-symbols-outlined">tune</span>
                        Général
                    </button>
                    <button type="button" class="crawl-tab" data-tab="scope" onclick="switchCrawlTab('scope')">
                        <span class="material-symbols-outlined">rule</span>
                        Règles & Scope
                    </button>
                    <button type="button" class="crawl-tab" data-tab="extraction" onclick="switchCrawlTab('extraction')">
                        <span class="material-symbols-outlined">data_object</span>
                        Extraction
                    </button>
                    <button type="button" class="crawl-tab" data-tab="advanced" onclick="switchCrawlTab('advanced')">
                        <span class="material-symbols-outlined">settings</span>
                        Avancé
                    </button>
                </div>

                <!-- Contenu des onglets -->
                <div class="crawl-tab-content">
                    
                    <!-- Onglet Général -->
                    <div class="crawl-tab-pane active" id="tab-general">
                        <div class="settings-grid">
                            <!-- Profondeur maximale -->
                            <div class="setting-row">
                                <div class="setting-row-label">
                                    <span class="material-symbols-outlined">layers</span>
                                    <h4>Profondeur maximale</h4>
                                </div>
                                <div class="setting-row-control">
                                    <input type="number" id="depth_max" name="depth_max" value="30" min="1" max="100" required class="setting-input-number">
                                    <span class="setting-unit">niveaux</span>
                                </div>
                            </div>

                            <!-- Vitesse de crawl -->
                            <div class="setting-row">
                                <div class="setting-row-label">
                                    <span class="material-symbols-outlined">speed</span>
                                    <h4>Vitesse de crawl</h4>
                                </div>
                                <div class="setting-row-control">
                                    <input type="hidden" id="crawl_speed" name="crawl_speed" value="fast">
                                    <div class="custom-speed-select" id="speedSelect">
                                        <div class="speed-select-trigger" onclick="toggleSpeedDropdown(event)">
                                            <div class="speed-select-value">
                                                <span class="material-symbols-outlined speed-icon speed-icon-fast">speed</span>
                                                <div class="speed-select-text">
                                                    <span class="speed-select-name">Rapide</span>
                                                    <span class="speed-select-desc">20 URLs/seconde</span>
                                                </div>
                                            </div>
                                            <span class="material-symbols-outlined speed-select-arrow">expand_more</span>
                                        </div>
                                        <div class="speed-select-dropdown" id="speedDropdown">
                                            <div class="speed-select-option" data-value="very_slow" onclick="selectSpeedOption('very_slow', 'Très lent', '1 URL/seconde', 'hourglass_top')">
                                                <span class="material-symbols-outlined speed-icon speed-icon-very_slow">hourglass_top</span>
                                                <div class="speed-select-text">
                                                    <span class="speed-select-name">Très lent</span>
                                                    <span class="speed-select-desc">1 URL/seconde</span>
                                                </div>
                                            </div>
                                            <div class="speed-select-option" data-value="slow" onclick="selectSpeedOption('slow', 'Lent', '5 URLs/seconde', 'pace')">
                                                <span class="material-symbols-outlined speed-icon speed-icon-slow">pace</span>
                                                <div class="speed-select-text">
                                                    <span class="speed-select-name">Lent</span>
                                                    <span class="speed-select-desc">5 URLs/seconde</span>
                                                </div>
                                            </div>
                                            <div class="speed-select-option selected" data-value="fast" onclick="selectSpeedOption('fast', 'Rapide', '20 URLs/seconde', 'speed')">
                                                <span class="material-symbols-outlined speed-icon speed-icon-fast">speed</span>
                                                <div class="speed-select-text">
                                                    <span class="speed-select-name">Rapide</span>
                                                    <span class="speed-select-desc">20 URLs/seconde</span>
                                                </div>
                                            </div>
                                            <div class="speed-select-option" data-value="unlimited" onclick="selectSpeedOption('unlimited', 'Sans limite', 'Maximum de performance', 'bolt')">
                                                <span class="material-symbols-outlined speed-icon speed-icon-unlimited">bolt</span>
                                                <div class="speed-select-text">
                                                    <span class="speed-select-name">Sans limite</span>
                                                    <span class="speed-select-desc">Maximum de performance</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Mode de crawl -->
                            <div class="setting-row">
                                <div class="setting-row-label">
                                    <span class="material-symbols-outlined">code</span>
                                    <h4>Mode de crawl</h4>
                                </div>
                                <div class="setting-row-control">
                                    <div class="mode-selector">
                                        <button type="button" class="mode-btn active" data-mode="classic" onclick="selectMode('classic', this)">
                                            <span class="material-symbols-outlined">http</span>
                                            <span class="mode-label">Classique</span>
                                        </button>
                                        <button type="button" class="mode-btn" data-mode="javascript" onclick="selectMode('javascript', this)">
                                            <span class="material-symbols-outlined">javascript</span>
                                            <span class="mode-label">JavaScript</span>
                                        </button>
                                    </div>
                                    <input type="hidden" id="crawl_mode" name="crawl_mode" value="classic">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Onglet Règles & Scope -->
                    <div class="crawl-tab-pane" id="tab-scope">
                        <div class="scope-section">
                            <h4 class="scope-section-title">
                                <span class="material-symbols-outlined">domain</span>
                                Domaines autorisés
                            </h4>
                            <div class="scope-section-content">
                                <textarea id="allowed_domains" name="allowed_domains" rows="3" 
                                          placeholder="Un domaine par ligne..."
                                          class="domains-textarea"></textarea>
                                <div class="scope-hint">
                                    <span class="material-symbols-outlined">auto_awesome</span>
                                    Auto-rempli depuis l'URL.
                                </div>
                            </div>
                        </div>

                        <div class="scope-section">
                            <h4 class="scope-section-title">
                                <span class="material-symbols-outlined">rule</span>
                                Règles de crawl
                            </h4>
                            <div class="rules-grid">
                                <label class="rule-toggle">
                                    <input type="checkbox" id="respect_robots" name="respect_robots" checked>
                                    <span class="rule-toggle-slider"></span>
                                    <div class="rule-toggle-content">
                                        <span class="rule-toggle-label">Respecter robots.txt</span>
                                        <span class="rule-toggle-hint">Suivre les règles du fichier robots.txt</span>
                                    </div>
                                </label>

                                <label class="rule-toggle">
                                    <input type="checkbox" id="respect_nofollow" name="respect_nofollow" checked>
                                    <span class="rule-toggle-slider"></span>
                                    <div class="rule-toggle-content">
                                        <span class="rule-toggle-label">Respecter les nofollow</span>
                                        <span class="rule-toggle-hint">Ignorer les liens avec rel="nofollow"</span>
                                    </div>
                                </label>

                                <label class="rule-toggle">
                                    <input type="checkbox" id="respect_canonical" name="respect_canonical" checked>
                                    <span class="rule-toggle-slider"></span>
                                    <div class="rule-toggle-content">
                                        <span class="rule-toggle-label">Respecter les canonicals</span>
                                        <span class="rule-toggle-hint">Suivre les balises canonical</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div class="scope-section">
                            <h4 class="scope-section-title">
                                <span class="material-symbols-outlined">lock</span>
                                Authentification HTTP
                            </h4>
                            <div class="scope-section-content">
                                <label class="rule-toggle" style="margin-bottom: 1rem;">
                                    <input type="checkbox" id="enable_auth" name="enable_auth" onchange="toggleAuthFields()">
                                    <span class="rule-toggle-slider"></span>
                                    <div class="rule-toggle-content">
                                        <span class="rule-toggle-label">Activer l'authentification HTTP Basic</span>
                                        <span class="rule-toggle-hint">Pour les sites protégés par mot de passe</span>
                                    </div>
                                </label>
                                
                                <div id="authFields" class="auth-fields" style="display: none;">
                                    <div class="auth-grid">
                                        <div class="form-group">
                                            <label for="auth_username">Identifiant</label>
                                            <input type="text" id="auth_username" name="auth_username" placeholder="utilisateur">
                                        </div>
                                        <div class="form-group">
                                            <label for="auth_password">Mot de passe</label>
                                            <input type="password" id="auth_password" name="auth_password" placeholder="••••••••">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Onglet Extraction -->
                    <div class="crawl-tab-pane" id="tab-extraction">

                        <div class="extractors-container">
                            <h4 class="scope-section-title">
                                <span class="material-symbols-outlined">data_object</span>
                                Extracteurs personnalisés
                            </h4>
                            <div id="extractorsList" class="extractors-list">
                                <!-- Les extracteurs seront ajoutés ici dynamiquement -->
                            </div>
                            
                            <div id="extractorsEmpty" class="extractors-empty">
                                <div class="extractors-empty-icon">
                                    <span class="material-symbols-outlined">data_object</span>
                                </div>
                                <h4>Aucun extracteur configuré</h4>
                                <p>Récupérez des données spécifiques depuis vos pages<br>(prix, titres, SKU, etc.)</p>
                            </div>
                            
                            <button type="button" class="btn-add-extractor" onclick="addExtractor()">
                                <span class="material-symbols-outlined">add</span>
                                Ajouter un extracteur
                            </button>
                        </div>

                        <div class="extraction-help-toggle">
                            <a href="#" onclick="toggleExtractionHelp(event)">
                                <span class="material-symbols-outlined">help_outline</span>
                                Voir des exemples d'expressions
                            </a>
                        </div>
                        <div class="extraction-help" id="extractionHelp" style="display: none;">
                            <div class="extraction-examples">
                                <div class="extraction-example">
                                    <span class="extraction-example-type">XPath</span>
                                    <code>//h2</code>
                                    <span class="extraction-example-desc">Sélection simple</span>
                                </div>
                                <div class="extraction-example">
                                    <span class="extraction-example-type">XPath</span>
                                    <code>count(//h2)</code>
                                    <span class="extraction-example-desc">Fonction XPath</span>
                                </div>
                                <div class="extraction-example">
                                    <span class="extraction-example-type">Regex</span>
                                    <code>price: (\d+)</code>
                                    <span class="extraction-example-desc">Extraction de valeur</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Onglet Avancé -->
                    <div class="crawl-tab-pane" id="tab-advanced">

                        <div class="advanced-section">
                            <h4 class="advanced-section-title">
                                <span class="material-symbols-outlined">smart_toy</span>
                                User-Agent
                            </h4>
                            <input type="hidden" id="user_agent" name="user_agent" value="Scouter/0.3 (Crawler developed by Lokoé SASU; +https://lokoe.fr/scouter-crawler)" required>
                            <div class="custom-ua-select" id="uaSelect">
                                <div class="ua-select-trigger" onclick="toggleUADropdown()">
                                    <div class="ua-select-value">
                                        <span class="material-symbols-outlined ua-icon ua-icon-scouter">smart_toy</span>
                                        <div class="ua-select-text">
                                            <span class="ua-select-name">Scouter</span>
                                            <span class="ua-select-desc">User-Agent par défaut</span>
                                        </div>
                                    </div>
                                    <span class="material-symbols-outlined ua-select-arrow">expand_more</span>
                                </div>
                                <div class="ua-select-dropdown" id="uaDropdown">
                                    <div class="ua-select-option selected" data-value="scouter" onclick="selectUAOption('scouter', 'Scouter', 'User-Agent par défaut', 'smart_toy')">
                                        <span class="material-symbols-outlined ua-icon ua-icon-scouter">smart_toy</span>
                                        <div class="ua-select-text">
                                            <span class="ua-select-name">Scouter</span>
                                            <span class="ua-select-desc">User-Agent par défaut</span>
                                        </div>
                                    </div>
                                    <div class="ua-select-option" data-value="googlebot-mobile" onclick="selectUAOption('googlebot-mobile', 'Googlebot Smartphone', 'Bot mobile de Google', 'phone_android')">
                                        <span class="material-symbols-outlined ua-icon ua-icon-googlebot">phone_android</span>
                                        <div class="ua-select-text">
                                            <span class="ua-select-name">Googlebot Smartphone</span>
                                            <span class="ua-select-desc">Bot mobile de Google</span>
                                        </div>
                                    </div>
                                    <div class="ua-select-option" data-value="googlebot-desktop" onclick="selectUAOption('googlebot-desktop', 'Googlebot Desktop', 'Bot desktop de Google', 'computer')">
                                        <span class="material-symbols-outlined ua-icon ua-icon-googlebot">computer</span>
                                        <div class="ua-select-text">
                                            <span class="ua-select-name">Googlebot Desktop</span>
                                            <span class="ua-select-desc">Bot desktop de Google</span>
                                        </div>
                                    </div>
                                    <div class="ua-select-option" data-value="chrome" onclick="selectUAOption('chrome', 'Chrome Utilisateur', 'Navigateur Chrome standard', 'person')">
                                        <span class="material-symbols-outlined ua-icon ua-icon-chrome">person</span>
                                        <div class="ua-select-text">
                                            <span class="ua-select-name">Chrome Utilisateur</span>
                                            <span class="ua-select-desc">Navigateur Chrome standard</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="ua-custom-input">
                                <label>User-Agent personnalisé :</label>
                                <input type="text" id="custom_ua_input" placeholder="Personnaliser..." onchange="applyCustomUA()">
                            </div>
                        </div>

                        <div class="advanced-section">
                            <h4 class="advanced-section-title">
                                <span class="material-symbols-outlined">http</span>
                                Headers HTTP personnalisés
                            </h4>
                            <div class="headers-container">
                                <div id="headersList" class="headers-list">
                                    <!-- Les headers seront ajoutés ici -->
                                </div>
                                <button type="button" class="btn-add-header" onclick="addHeader()">
                                    <span class="material-symbols-outlined">add</span>
                                    Ajouter un header
                                </button>
                                <div class="headers-hint">
                                    <strong>Headers courants :</strong> Authorization, Cookie, X-API-Key
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer avec actions -->
                <div class="crawl-modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeNewProjectModal()">
                        Annuler
                    </button>
                    <button type="submit" class="btn btn-launch" id="submitBtn">
                        <span class="material-symbols-outlined">rocket_launch</span>
                        Lancer le crawl
                    </button>
                </div>

                <div id="formMessage" class="form-message"></div>
            </form>
        </div>
    </div>

    <!-- Modal Gestion des Catégories -->
    <div id="categoriesModal" class="modal category-modal">
        <div class="cat-modal-container">
            <!-- Header -->
            <div class="cat-modal-header">
                <div class="cat-modal-title">
                    <span class="material-symbols-outlined">category</span>
                    <h2>Gérer les catégories</h2>
                </div>
                <button class="cat-modal-close" onclick="closeCategoriesModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <!-- Contenu -->
            <div class="cat-modal-body">
                <!-- Formulaire d'ajout -->
                <div class="cat-add-form">
                    <input type="color" id="newCategoryColor" value="#4ECDC4" class="cat-color-input">
                    <input type="text" id="newCategoryName" class="cat-name-input" placeholder="Nom de la catégorie...">
                    <button type="button" class="cat-add-btn" onclick="createCategory()">
                        <span class="material-symbols-outlined">add</span>
                        Créer
                    </button>
                </div>

                <!-- Liste des catégories -->
                <div id="categoriesList" class="cat-list">
                    <!-- Categories will be loaded here -->
                </div>
            </div>

            <!-- Footer -->
            <div class="cat-modal-footer">
                <button type="button" class="btn btn-primary" onclick="closeCategoriesModal()">
                    <span class="material-symbols-outlined">check</span>
                    Valider
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Quick Add Category -->
    <div id="quickAddCategoryModal" class="modal category-modal">
        <div class="cat-modal-container" style="max-width: 450px;">
            <div class="cat-modal-header">
                <div class="cat-modal-title">
                    <span class="material-symbols-outlined">add_circle</span>
                    <h2>Nouvelle catégorie</h2>
                </div>
                <button class="cat-modal-close" onclick="closeQuickAddCategoryModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            
            <div class="cat-modal-body">
                <div class="cat-add-form" style="border-bottom: none; padding-bottom: 0; margin-bottom: 0;">
                    <input type="color" id="quickCategoryColor" value="#4ECDC4" class="cat-color-input">
                    <input type="text" id="quickCategoryName" class="cat-name-input" placeholder="Nom de la catégorie...">
                </div>
            </div>

            <div class="cat-modal-footer" style="gap: 0.75rem;">
                <button type="button" class="btn btn-secondary" onclick="closeQuickAddCategoryModal()">Annuler</button>
                <button type="button" class="cat-add-btn" onclick="quickCreateCategory()">
                    <span class="material-symbols-outlined">add</span>
                    Créer
                </button>
            </div>
        </div>
    </div>

    <script>
        let extractorCounter = 0;
        let headerCounter = 0;

        // ============================================
        // GESTION DE LA MODAL
        // ============================================
        
        function openNewProjectModal() {
            document.getElementById('newProjectModal').style.display = 'flex';
            // Focus sur le champ URL
            setTimeout(() => {
                document.getElementById('start_url').focus();
            }, 100);
            // Reset to first tab
            switchCrawlTab('general');
            // Add default extractors
            initDefaultExtractors();
        }
        
        function initDefaultExtractors() {
            // Clear existing
            document.getElementById('extractorsList').innerHTML = '';
            extractorCounter = 0;
            // Add default extractors
            addExtractorWithValues('count_h2', 'xpath', 'count(//h2)');
            addExtractorWithValues('google_analytics', 'regex', 'ua":"(UA-\\d{8}-\\d)');
            updateExtractorsEmptyState();
        }

        function closeNewProjectModal() {
            document.getElementById('newProjectModal').style.display = 'none';
            document.getElementById('newProjectForm').reset();
            document.getElementById('formMessage').innerHTML = '';
            document.getElementById('extractorsList').innerHTML = '';
            document.getElementById('headersList').innerHTML = '';
            extractorCounter = 0;
            headerCounter = 0;
            // Reset UI states
            updateExtractorsEmptyState();
            resetModalDefaults();
        }
        
        function resetModalDefaults() {
            // Reset speed selector
            document.querySelectorAll('.speed-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelector('.speed-btn[data-speed="fast"]')?.classList.add('active');
            document.getElementById('crawl_speed').value = 'fast';
            
            // Reset mode selector
            document.querySelectorAll('.mode-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelector('.mode-btn[data-mode="classic"]')?.classList.add('active');
            document.getElementById('crawl_mode').value = 'classic';
            
            // Reset user-agent
            document.querySelectorAll('.user-agent-option').forEach(opt => opt.classList.remove('active'));
            document.querySelector('.user-agent-option[data-ua="scouter"]')?.classList.add('active');
            document.getElementById('user_agent').value = 'Scouter/0.3 (Crawler developed by Lokoé SASU; +https://lokoe.fr/scouter-crawler)';
            document.getElementById('custom_ua_input').value = '';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const newProjectModal = document.getElementById('newProjectModal');
            const categoriesModal = document.getElementById('categoriesModal');
            const quickAddModal = document.getElementById('quickAddCategoryModal');
            
            if (event.target == newProjectModal) {
                closeNewProjectModal();
            }
            if (event.target == categoriesModal) {
                closeCategoriesModal();
            }
            if (event.target == quickAddModal) {
                closeQuickAddCategoryModal();
            }
        }

        // ============================================
        // GESTION DES ONGLETS
        // ============================================
        
        function switchCrawlTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.crawl-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelector(`.crawl-tab[data-tab="${tabName}"]`)?.classList.add('active');
            
            // Update tab content
            document.querySelectorAll('.crawl-tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });
            document.getElementById(`tab-${tabName}`)?.classList.add('active');
        }

        // ============================================
        // SÉLECTEURS (Vitesse, Mode, User-Agent)
        // ============================================
        
        // Custom Speed Dropdown
        function toggleSpeedDropdown(event) {
            event.stopPropagation();
            const select = document.getElementById('speedSelect');
            const dropdown = document.getElementById('speedDropdown');
            const trigger = select.querySelector('.speed-select-trigger');
            
            if (select.classList.contains('open')) {
                select.classList.remove('open');
                dropdown.style.display = 'none';
            } else {
                // Move dropdown to body if not already there
                if (dropdown.parentElement !== document.body) {
                    document.body.appendChild(dropdown);
                }
                // Position the dropdown
                const rect = trigger.getBoundingClientRect();
                dropdown.style.position = 'fixed';
                dropdown.style.top = (rect.bottom + 2) + 'px';
                dropdown.style.left = rect.left + 'px';
                dropdown.style.width = rect.width + 'px';
                dropdown.style.display = 'block';
                dropdown.style.zIndex = '2147483647';
                select.classList.add('open');
            }
        }
        
        function selectSpeedOption(value, name, desc, icon) {
            const select = document.getElementById('speedSelect');
            const dropdown = document.getElementById('speedDropdown');
            const trigger = select.querySelector('.speed-select-value');
            const hiddenInput = document.getElementById('crawl_speed');
            
            // Update hidden input
            hiddenInput.value = value;
            
            // Update trigger display
            trigger.innerHTML = `
                <span class="material-symbols-outlined speed-icon speed-icon-${value}">${icon}</span>
                <div class="speed-select-text">
                    <span class="speed-select-name">${name}</span>
                    <span class="speed-select-desc">${desc}</span>
                </div>
            `;
            
            // Update selected state in dropdown
            dropdown.querySelectorAll('.speed-select-option').forEach(opt => {
                opt.classList.remove('selected');
                if (opt.dataset.value === value) {
                    opt.classList.add('selected');
                }
            });
            
            // Close dropdown
            select.classList.remove('open');
            dropdown.style.display = 'none';
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const select = document.getElementById('speedSelect');
            const dropdown = document.getElementById('speedDropdown');
            if (select && dropdown && !select.contains(e.target) && !dropdown.contains(e.target)) {
                select.classList.remove('open');
                dropdown.style.display = 'none';
            }
        });
        
        function selectMode(mode, btn) {
            document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('crawl_mode').value = mode;
        }
        
        // Custom UA Dropdown
        const uaPresets = {
            'scouter': 'Scouter/0.3 (Crawler developed by Lokoé SASU; +https://lokoe.fr/scouter-crawler)',
            'googlebot-mobile': 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.96 Mobile Safari/537.36 (compatible; Googlebot/2.1; +https://www.google.com/bot.html)',
            'googlebot-desktop': 'Mozilla/5.0 (compatible; Googlebot/2.1; +https://www.google.com/bot.html)',
            'chrome': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'
        };
        
        function toggleUADropdown() {
            const select = document.getElementById('uaSelect');
            const dropdown = document.getElementById('uaDropdown');
            const trigger = select.querySelector('.ua-select-trigger');
            
            if (select.classList.contains('open')) {
                select.classList.remove('open');
            } else {
                // Position the dropdown
                const rect = trigger.getBoundingClientRect();
                dropdown.style.top = (rect.bottom + 2) + 'px';
                dropdown.style.left = rect.left + 'px';
                dropdown.style.width = rect.width + 'px';
                select.classList.add('open');
            }
        }
        
        function selectUAOption(value, name, desc, icon) {
            const select = document.getElementById('uaSelect');
            const trigger = select.querySelector('.ua-select-value');
            const hiddenInput = document.getElementById('user_agent');
            
            // Update hidden input
            hiddenInput.value = uaPresets[value];
            
            // Determine icon class
            let iconClass = 'ua-icon-scouter';
            if (value.includes('googlebot')) iconClass = 'ua-icon-googlebot';
            if (value === 'chrome') iconClass = 'ua-icon-chrome';
            
            // Update trigger display
            trigger.innerHTML = `
                <span class="material-symbols-outlined ua-icon ${iconClass}">${icon}</span>
                <div class="ua-select-text">
                    <span class="ua-select-name">${name}</span>
                    <span class="ua-select-desc">${desc}</span>
                </div>
            `;
            
            // Update selected state
            select.querySelectorAll('.ua-select-option').forEach(opt => {
                opt.classList.remove('selected');
                if (opt.dataset.value === value) {
                    opt.classList.add('selected');
                }
            });
            
            // Close dropdown
            select.classList.remove('open');
            
            // Clear custom input
            document.getElementById('custom_ua_input').value = '';
        }
        
        // Close UA dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const uaSelect = document.getElementById('uaSelect');
            if (uaSelect && !uaSelect.contains(e.target)) {
                uaSelect.classList.remove('open');
            }
        });
        
        function applyCustomUA() {
            const customValue = document.getElementById('custom_ua_input').value.trim();
            if (customValue) {
                document.getElementById('user_agent').value = customValue;
            }
        }
        
        // Toggle extraction help
        function toggleExtractionHelp(e) {
            e.preventDefault();
            const help = document.getElementById('extractionHelp');
            help.style.display = help.style.display === 'none' ? 'block' : 'none';
        }

        // ============================================
        // AUTHENTIFICATION
        // ============================================
        
        function toggleAuthFields() {
            const authFields = document.getElementById('authFields');
            const enableAuth = document.getElementById('enable_auth').checked;
            authFields.style.display = enableAuth ? 'block' : 'none';
        }

        // ============================================
        // HEADERS HTTP
        // ============================================

        function addHeader() {
            addHeaderWithValues('', '');
        }

        function addHeaderWithValues(name = '', value = '') {
            const id = headerCounter++;
            const headerDiv = document.createElement('div');
            headerDiv.id = `header-${id}`;
            headerDiv.className = 'header-item';
            
            headerDiv.innerHTML = `
                <div class="header-item-row">
                    <input type="text" class="header-name" placeholder="Clé" oninput="sanitizeHeaderName(this)">
                    <input type="text" class="header-value" placeholder="Valeur">
                    <button type="button" class="header-item-delete" onclick="removeHeader(${id})">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
            `;
            
            document.getElementById('headersList').appendChild(headerDiv);
            
            if (name) headerDiv.querySelector('.header-name').value = name;
            if (value) headerDiv.querySelector('.header-value').value = value;
        }

        function removeHeader(id) {
            const element = document.getElementById(`header-${id}`);
            if (element) element.remove();
        }
        
        function sanitizeHeaderName(input) {
            const cursorPos = input.selectionStart;
            const originalLength = input.value.length;
            const sanitized = input.value.replace(/\s+/g, '-').replace(/[^a-zA-Z0-9\-]/g, '');
            
            if (input.value !== sanitized) {
                input.value = sanitized;
                const newPos = cursorPos - (originalLength - sanitized.length);
                input.setSelectionRange(newPos, newPos);
            }
        }

        // ============================================
        // EXTRACTEURS
        // ============================================

        function addExtractor() {
            addExtractorWithValues('', 'xpath', '');
        }

        function addExtractorWithValues(name = '', type = 'xpath', pattern = '') {
            const id = extractorCounter++;
            const extractorDiv = document.createElement('div');
            extractorDiv.id = `extractor-${id}`;
            extractorDiv.className = 'extractor-item';
            const isRegex = type === 'regex';
            
            extractorDiv.innerHTML = `
                <input type="text" class="extractor-name" placeholder="Nom" oninput="sanitizeExtractorName(this)">
                <div class="extractor-type-dropdown" id="extractorType-${id}">
                    <div class="extractor-type-trigger" onclick="toggleExtractorType(${id})">
                        <span class="extractor-type-value">${isRegex ? 'Regex' : 'XPath'}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="extractor-type-options" id="extractorTypeOptions-${id}">
                        <div class="extractor-type-option ${!isRegex ? 'selected' : ''}" data-value="xpath" onclick="selectExtractorType(${id}, 'xpath')">XPath</div>
                        <div class="extractor-type-option ${isRegex ? 'selected' : ''}" data-value="regex" onclick="selectExtractorType(${id}, 'regex')">Regex</div>
                    </div>
                </div>
                <input type="hidden" class="extractor-type-value-hidden" value="${type}">
                <input type="text" class="extractor-pattern" id="extractor-pattern-${id}" placeholder="${isRegex ? 'price: (\\d+)' : '//h2'}">
                <button type="button" class="extractor-item-delete" onclick="removeExtractor(${id})">
                    <span class="material-symbols-outlined">close</span>
                </button>
            `;
            
            document.getElementById('extractorsList').appendChild(extractorDiv);
            
            if (name) extractorDiv.querySelector('.extractor-name').value = name;
            if (pattern) extractorDiv.querySelector('.extractor-pattern').value = pattern;
            
            updateExtractorsEmptyState();
        }
        
        function toggleExtractorType(id) {
            const extractor = document.getElementById(`extractor-${id}`);
            const checkbox = extractor.querySelector('.extractor-type-checkbox');
            const patternInput = extractor.querySelector('.extractor-pattern');
            const labels = extractor.querySelectorAll('.type-label');
            
            if (checkbox.checked) {
                // Regex
                patternInput.placeholder = 'price: (\\d+)';
                labels[0].classList.remove('active');
                labels[1].classList.add('active');
            } else {
                // XPath
                patternInput.placeholder = '//h2';
                labels[0].classList.add('active');
                labels[1].classList.remove('active');
            }
        }

        function removeExtractor(id) {
            const element = document.getElementById(`extractor-${id}`);
            if (element) {
                element.remove();
                updateExtractorsEmptyState();
            }
        }
        
        function updateExtractorsEmptyState() {
            const list = document.getElementById('extractorsList');
            const empty = document.getElementById('extractorsEmpty');
            if (list && empty) {
                empty.style.display = list.children.length === 0 ? 'block' : 'none';
            }
        }
        
        function toggleExtractorType(id) {
            const dropdown = document.getElementById(`extractorType-${id}`);
            const options = document.getElementById(`extractorTypeOptions-${id}`);
            const trigger = dropdown.querySelector('.extractor-type-trigger');
            
            // Close all other dropdowns first
            document.querySelectorAll('.extractor-type-dropdown.open').forEach(d => {
                if (d.id !== `extractorType-${id}`) d.classList.remove('open');
            });
            
            if (dropdown.classList.contains('open')) {
                dropdown.classList.remove('open');
            } else {
                const rect = trigger.getBoundingClientRect();
                options.style.top = (rect.bottom + 2) + 'px';
                options.style.left = rect.left + 'px';
                options.style.minWidth = rect.width + 'px';
                dropdown.classList.add('open');
            }
        }
        
        function selectExtractorType(id, type) {
            const dropdown = document.getElementById(`extractorType-${id}`);
            const valueSpan = dropdown.querySelector('.extractor-type-value');
            const hiddenInput = document.getElementById(`extractor-${id}`).querySelector('.extractor-type-value-hidden');
            const patternInput = document.getElementById(`extractor-pattern-${id}`);
            
            valueSpan.textContent = type === 'xpath' ? 'XPath' : 'Regex';
            hiddenInput.value = type;
            patternInput.placeholder = type === 'xpath' ? '//h2' : 'price: (\\d+)';
            
            // Update selected state
            dropdown.querySelectorAll('.extractor-type-option').forEach(opt => {
                opt.classList.toggle('selected', opt.dataset.value === type);
            });
            
            dropdown.classList.remove('open');
        }
        
        // Close extractor type dropdowns on click outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.extractor-type-dropdown')) {
                document.querySelectorAll('.extractor-type-dropdown.open').forEach(d => d.classList.remove('open'));
            }
        });

        
        function sanitizeExtractorName(input) {
            const cursorPos = input.selectionStart;
            const originalLength = input.value.length;
            const sanitized = input.value.replace(/\s+/g, '_').replace(/[^a-zA-Z0-9_]/g, '');
            
            if (input.value !== sanitized) {
                input.value = sanitized;
                const newPos = cursorPos - (originalLength - sanitized.length);
                input.setSelectionRange(newPos, newPos);
            }
        }

        // Auto-remplir les domaines autorisés depuis l'URL de départ
        document.getElementById('start_url').addEventListener('input', function(e) {
            const url = e.target.value.trim();
            
            if (!url) {
                document.getElementById('allowed_domains').value = '';
                return;
            }
            
            try {
                const urlObj = new URL(url);
                const hostname = urlObj.hostname;
                
                // Créer la liste des domaines
                const domains = [hostname];
                
                // Si le domaine ne commence pas par www., ajouter la version avec www.
                if (!hostname.startsWith('www.')) {
                    domains.push('www.' + hostname);
                }
                // Si le domaine commence par www., ajouter aussi la version sans www.
                else {
                    const withoutWww = hostname.replace(/^www\./, '');
                    domains.unshift(withoutWww); // Ajouter au début
                }
                
                // Mettre à jour le textarea
                document.getElementById('allowed_domains').value = domains.join('\n');
            } catch (error) {
                // URL invalide, ne rien faire
            }
        });

        // Create project
        async function createProject(event) {
            event.preventDefault();
            
            const submitBtn = document.getElementById('submitBtn');
            const formMessage = document.getElementById('formMessage');
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="material-symbols-outlined spinning">progress_activity</span> Création en cours...';
            formMessage.innerHTML = '';
            
            // Récupérer les domaines autorisés
            const allowedDomainsText = document.getElementById('allowed_domains').value.trim();
            const allowedDomains = allowedDomainsText ? allowedDomainsText.split('\n').map(d => d.trim()).filter(d => d) : [];
            
            // Récupérer les headers HTTP personnalisés
            const customHeaders = {};
            document.querySelectorAll('#headersList > div').forEach(headerDiv => {
                const name = headerDiv.querySelector('.header-name').value.trim();
                const value = headerDiv.querySelector('.header-value').value.trim();
                
                if (name && value) {
                    // Sanitize le nom du header : espaces -> tiret, supprimer caractères spéciaux
                    const sanitizedName = name
                        .replace(/\s+/g, '-')
                        .replace(/[^a-zA-Z0-9\-]/g, '');
                    
                    customHeaders[sanitizedName] = value;
                }
            });
            
            // Récupérer les extracteurs
            const extractors = [];
            document.querySelectorAll('#extractorsList > div').forEach(extractorDiv => {
                const name = extractorDiv.querySelector('.extractor-name').value.trim();
                const typeHidden = extractorDiv.querySelector('.extractor-type-value-hidden');
                const type = typeHidden ? typeHidden.value : 'xpath';
                const pattern = extractorDiv.querySelector('.extractor-pattern').value.trim();
                
                if (name && pattern) {
                    // Sanitize le nom : remplacer espaces par _, supprimer caractères spéciaux (garde majuscules)
                    const sanitizedName = name
                        .replace(/\s+/g, '_')
                        .replace(/[^a-zA-Z0-9_]/g, '');
                    
                    extractors.push({ name: sanitizedName, type, pattern });
                }
            });
            
            // Récupérer les informations d'authentification
            const enableAuth = document.getElementById('enable_auth').checked;
            const httpAuth = enableAuth ? {
                username: document.getElementById('auth_username').value.trim(),
                password: document.getElementById('auth_password').value.trim()
            } : null;
            
            const formData = {
                start_url: document.getElementById('start_url').value,
                depth_max: document.getElementById('depth_max').value,
                user_agent: document.getElementById('user_agent').value,
                allowed_domains: allowedDomains,
                custom_headers: customHeaders,
                http_auth: httpAuth,
                extractors: extractors,
                respect_robots: document.getElementById('respect_robots').checked,
                respect_nofollow: document.getElementById('respect_nofollow').checked,
                respect_canonical: document.getElementById('respect_canonical').checked,
                crawl_speed: document.getElementById('crawl_speed').value,
                crawl_mode: document.getElementById('crawl_mode').value
            };
            
            try {
                // Create project
                const response = await fetch('api/projects', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                
                const result = await response.json();
                
                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'Erreur lors de la création du projet');
                }
                
                formMessage.innerHTML = '<div class="alert alert-success">✓ Projet créé avec succès! Lancement du crawl...</div>';
                
                // Start crawl
                const crawlResponse = await fetch('api/crawls/start', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ project_dir: result.project_dir })
                });
                
                const crawlResult = await crawlResponse.json();
                
                if (!crawlResponse.ok || !crawlResult.success) {
                    throw new Error(crawlResult.error || 'Erreur lors du lancement du crawl');
                }
                
                formMessage.innerHTML = '<div class="alert alert-success">✓ Crawl lancé avec succès!</div>';
                
                // Fermer la modal et ouvrir le panel de monitoring
                closeNewProjectModal();
                
                // Extraire le nom du domaine depuis l'URL
                const startUrl = document.getElementById('start_url').value;
                let projectName = 'Crawl';
                try {
                    projectName = new URL(startUrl).hostname;
                } catch (e) {}
                
                // Démarrer le monitoring dans le panel latéral
                CrawlPanel.start(result.project_dir, projectName, result.crawl_id);
                
            } catch (error) {
                formMessage.innerHTML = `<div class="alert alert-error">✗ ${error.message}</div>`;
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span class="material-symbols-outlined">rocket_launch</span> Créer et lancer le crawl';
            }
        }

        // ============================================
        // GESTION DES ONGLETS DE PROJETS
        // ============================================
        
        // Variable pour tracker l'onglet actif
        let currentProjectsTab = 'my';
        
        function switchProjectsTab(tabName, saveToStorage = true) {
            currentProjectsTab = tabName;
            
            // Sauvegarder l'onglet actif dans sessionStorage
            if (saveToStorage) {
                sessionStorage.setItem('activeProjectsTab', tabName);
            }
            
            // Mettre à jour les boutons d'onglets
            document.querySelectorAll('.projects-tab').forEach(tab => {
                if (tab.dataset.tab === tabName) {
                    tab.classList.add('active');
                    tab.style.fontWeight = '600';
                    tab.style.color = 'var(--primary-color)';
                    tab.style.borderBottomColor = 'var(--primary-color)';
                } else {
                    tab.classList.remove('active');
                    tab.style.fontWeight = '500';
                    tab.style.color = 'var(--text-secondary)';
                    tab.style.borderBottomColor = 'transparent';
                }
            });
            
            // Afficher/masquer les panneaux
            document.querySelectorAll('.projects-pane').forEach(pane => {
                pane.style.display = 'none';
            });
            const activePane = document.getElementById(`projectsPane-${tabName}`);
            if (activePane) {
                activePane.style.display = 'block';
            }
            
            // Recalculer le message "aucun résultat" pour le nouveau panneau actif
            if (activePane) {
                const activePaneCards = activePane.querySelectorAll('.domain-card');
                let visibleCount = 0;
                activePaneCards.forEach(card => {
                    if (card.style.display !== 'none') visibleCount++;
                });
                updateNoResultsMessage(visibleCount);
            }
        }

        // Toggle domain accordion
        function toggleDomain(domainName) {
            const domainCrawls = document.getElementById(`domain-${domainName}`);
            const header = document.querySelector(`#domain-${domainName}`)?.previousElementSibling;
            const expandIcon = header?.querySelector('.expand-icon');
            
            if (!domainCrawls) return;
            
            if (domainCrawls.style.display === 'none') {
                domainCrawls.style.display = 'block';
                if (expandIcon) expandIcon.textContent = 'expand_less';
            } else {
                domainCrawls.style.display = 'none';
                if (expandIcon) expandIcon.textContent = 'expand_more';
            }
        }

        // Category management
        let activeCategory = 'all';
        let currentSortOption = 'date-desc';

        function toggleSortDropdown() {
            const dropdown = document.getElementById('sortDropdown');
            if (dropdown) dropdown.classList.toggle('show');
        }

        function changeSortOption(option) {
            currentSortOption = option;
            
            // Update label
            const labels = {
                'date-desc': 'Du plus récent au plus ancien',
                'date-asc': 'Du plus ancien au plus récent',
                'alpha-asc': 'Par ordre alphabétique (A → Z)',
                'alpha-desc': 'Par ordre alphabétique (Z → A)'
            };
            document.getElementById('currentSortLabel').textContent = labels[option];
            
            // Update active item
            document.querySelectorAll('.sort-dropdown-item').forEach(item => {
                item.classList.remove('active');
                if (item.dataset.sort === option) {
                    item.classList.add('active');
                }
            });
            
            // Close dropdown
            document.getElementById('sortDropdown')?.classList.remove('show');
            
            // Sort domains
            sortDomains();
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.sort-dropdown-wrapper')) {
                document.getElementById('sortDropdown')?.classList.remove('show');
            }
        });

        function sortDomains() {
            // Trier les cartes dans CHAQUE panneau séparément
            const allDomainsLists = document.querySelectorAll('.domains-list');
            
            allDomainsLists.forEach(domainsList => {
                const domainCards = Array.from(domainsList.querySelectorAll('.domain-card'));
                
                domainCards.sort((a, b) => {
                    let valueA, valueB;
                    const [type, direction] = currentSortOption.split('-');
                    
                    if (type === 'date') {
                        // Sort by timestamp (date + time)
                        const dateTimeA = a.querySelector('.domain-meta').textContent.match(/Dernier:\s*(\d{2}\/\d{2}\/\d{4})\s*(\d{2}:\d{2})/);
                        const dateTimeB = b.querySelector('.domain-meta').textContent.match(/Dernier:\s*(\d{2}\/\d{2}\/\d{4})\s*(\d{2}:\d{2})/);
                        
                        if (dateTimeA && dateTimeB) {
                            // Convert to comparable format: YYYYMMDDHHMM
                            const [dayA, monthA, yearA] = dateTimeA[1].split('/');
                            const [hourA, minA] = dateTimeA[2].split(':');
                            valueA = parseInt(`${yearA}${monthA}${dayA}${hourA}${minA}`);
                            
                            const [dayB, monthB, yearB] = dateTimeB[1].split('/');
                            const [hourB, minB] = dateTimeB[2].split(':');
                            valueB = parseInt(`${yearB}${monthB}${dayB}${hourB}${minB}`);
                        } else {
                            valueA = 0;
                            valueB = 0;
                        }
                    } else {
                        // Sort alphabetically by domain name
                        valueA = a.querySelector('.domain-name').textContent.trim().toLowerCase();
                        valueB = b.querySelector('.domain-name').textContent.trim().toLowerCase();
                    }
                    
                    if (direction === 'asc') {
                        return valueA > valueB ? 1 : -1;
                    } else {
                        return valueA < valueB ? 1 : -1;
                    }
                });
                
                // Reorder DOM dans ce panneau uniquement
                domainCards.forEach(card => domainsList.appendChild(card));
            });
        }

        function filterByCategory(categoryId) {
            // Trouver l'élément cliqué par son data-category (plus robuste)
            const clickedItem = document.querySelector(`.category-filter-item[data-category="${categoryId}"]`);
            if (!clickedItem) return;
            
            const isAlreadyActive = clickedItem.classList.contains('active');
            
            // Si on clique sur le filtre déjà actif, on revient à "Tout"
            if (isAlreadyActive && categoryId !== 'all') {
                categoryId = 'all';
                activeCategory = 'all';
                
                // Update active chip
                document.querySelectorAll('.category-filter-item').forEach(chip => {
                    chip.classList.remove('active');
                });
                document.querySelector('.category-filter-item[data-category="all"]')?.classList.add('active');
            } else {
                activeCategory = categoryId;
                
                // Update active chip
                document.querySelectorAll('.category-filter-item').forEach(chip => {
                    chip.classList.remove('active');
                });
                clickedItem.classList.add('active');
            }
            
            // Filter domains
            const domainCards = document.querySelectorAll('.domain-card');
            let visibleCount = 0;
            
            domainCards.forEach(card => {
                const cardCategory = card.getAttribute('data-category');
                
                if (categoryId === 'all') {
                    card.style.display = 'block';
                    visibleCount++;
                } else if (categoryId === 'uncategorized') {
                    if (cardCategory === 'uncategorized' || !cardCategory) {
                        card.style.display = 'block';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                } else {
                    if (cardCategory == categoryId) {
                        card.style.display = 'block';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                }
            });
            
            // Show/hide no results message
            updateNoResultsMessage(visibleCount);
        }

        function toggleCategoryDropdown(domainName) {
            const dropdown = document.getElementById(`cat-dropdown-${domainName}`);
            if (!dropdown) return;
            const card = dropdown.closest('.domain-card');
            const badge = card?.querySelector('.category-badge-simple');
            const isVisible = dropdown.classList.contains('show');
            
            // Close all other dropdowns
            document.querySelectorAll('.category-dropdown-menu').forEach(d => {
                d.classList.remove('show');
            });
            document.querySelectorAll('.kebab-dropdown-menu').forEach(d => {
                d.classList.remove('show');
            });
            
            // Toggle current dropdown
            if (!isVisible) {
                // Position the dropdown relative to the badge
                const rect = badge.getBoundingClientRect();
                const dropdownHeight = 400; // max-height du dropdown
                const spaceBelow = window.innerHeight - rect.bottom;
                const spaceAbove = rect.top;
                
                // Si pas assez de place en bas ET plus de place en haut, afficher au-dessus
                if (spaceBelow < dropdownHeight && spaceAbove > spaceBelow) {
                    dropdown.style.bottom = `${window.innerHeight - rect.top + 8}px`;
                    dropdown.style.top = 'auto';
                } else {
                    dropdown.style.top = `${rect.bottom + 8}px`;
                    dropdown.style.bottom = 'auto';
                }
                
                dropdown.style.left = `${rect.left}px`;
                dropdown.classList.add('show');
            }
        }

        // Toggle Kebab Menu
        function toggleKebabMenu(projectId) {
            const dropdown = document.getElementById(`kebab-dropdown-${projectId}`);
            const wrapper = document.querySelector(`#kebab-dropdown-${projectId}`).closest('.kebab-menu-wrapper');
            const btn = wrapper ? wrapper.querySelector('.btn-kebab') : null;
            if (!dropdown || !btn) return;
            const isVisible = dropdown.classList.contains('show');
            
            // Close all other dropdowns (category + kebab)
            document.querySelectorAll('.category-dropdown-menu').forEach(d => {
                d.classList.remove('show');
            });
            document.querySelectorAll('.kebab-dropdown-menu').forEach(d => {
                d.classList.remove('show');
            });
            
            // Toggle current dropdown avec positionnement fixed
            if (!isVisible) {
                const rect = btn.getBoundingClientRect();
                const viewportHeight = window.innerHeight;
                const menuHeight = 200;
                
                // Position horizontally
                dropdown.style.right = (window.innerWidth - rect.right) + 'px';
                dropdown.style.left = 'auto';
                
                // Check if menu would go below viewport
                if (rect.bottom + menuHeight > viewportHeight) {
                    // Show above the button
                    dropdown.style.bottom = (viewportHeight - rect.top + 8) + 'px';
                    dropdown.style.top = 'auto';
                } else {
                    // Show below the button
                    dropdown.style.top = (rect.bottom + 8) + 'px';
                    dropdown.style.bottom = 'auto';
                }
                dropdown.classList.add('show');
            }
        }
        
        // Close kebab menus on scroll
        window.addEventListener('scroll', function() {
            document.querySelectorAll('.kebab-dropdown-menu.show').forEach(d => {
                d.classList.remove('show');
            });
        }, true);

        async function assignCategory(projectId, categoryId) {
            // Empêcher la propagation du clic
            if (event) {
                event.stopPropagation();
            }
            
            try {
                const response = await fetch('api/categories/assign', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        project_id: projectId, 
                        category_id: categoryId 
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Reload page to update UI
                    location.reload();
                } else {
                    alert('Erreur: ' + (result.error || 'Erreur inconnue'));
                }
            } catch (error) {
                alert('Erreur: ' + error.message);
            }
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            // Close category dropdowns
            if (!event.target.closest('.category-badge-clickable') && !event.target.closest('.category-dropdown-menu')) {
                document.querySelectorAll('.category-dropdown-menu').forEach(d => {
                    d.classList.remove('show');
                });
            }
            // Close kebab menus
            if (!event.target.closest('.btn-kebab') && !event.target.closest('.kebab-dropdown-menu')) {
                document.querySelectorAll('.kebab-dropdown-menu').forEach(d => {
                    d.classList.remove('show');
                });
            }
        });

        // Filter domains in real-time - applique le filtre à TOUS les panneaux
        function filterDomains() {
            const searchInput = document.getElementById('domainSearch');
            const searchTerm = searchInput.value.toLowerCase().trim();
            
            // Appliquer le filtre à toutes les cartes de tous les panneaux
            const allDomainCards = document.querySelectorAll('.domain-card');
            
            allDomainCards.forEach(card => {
                const domainName = card.querySelector('.domain-name').textContent.toLowerCase();
                const cardCategory = card.getAttribute('data-category');
                
                // Check both search term and category filter
                let matchesSearch = domainName.includes(searchTerm);
                let matchesCategory = true;
                
                if (activeCategory !== 'all') {
                    if (activeCategory === 'uncategorized') {
                        matchesCategory = (cardCategory === 'uncategorized' || !cardCategory);
                    } else {
                        matchesCategory = (cardCategory == activeCategory);
                    }
                }
                
                if (matchesSearch && matchesCategory) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Compter les résultats visibles dans le panneau actif uniquement
            const activePane = document.querySelector('.projects-pane[style*="display: block"]') || 
                               document.querySelector('.projects-pane.active') ||
                               document.querySelector('.projects-section');
            
            let visibleCount = 0;
            if (activePane) {
                const activePaneCards = activePane.querySelectorAll('.domain-card');
                activePaneCards.forEach(card => {
                    if (card.style.display !== 'none') visibleCount++;
                });
            }
            
            // Show/hide no results message
            updateNoResultsMessage(visibleCount);
        }

        function updateNoResultsMessage(visibleCount) {
            // Gérer le message "aucun résultat" dans le panneau actif
            const activePane = document.querySelector('.projects-pane[style*="display: block"]') || 
                               document.querySelector('.projects-pane.active') ||
                               document.querySelector('.projects-section');
            if (!activePane) return;
            
            const domainsList = activePane.querySelector('.domains-list');
            if (!domainsList) return;
            
            let noResultsMsg = activePane.querySelector('.noResultsMessage');
            
            if (visibleCount === 0) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.className = 'noResultsMessage loading';
                    noResultsMsg.innerHTML = '<p>Aucun domaine ne correspond à votre recherche.</p>';
                    domainsList.appendChild(noResultsMsg);
                }
                noResultsMsg.style.display = 'block';
            } else if (noResultsMsg) {
                noResultsMsg.style.display = 'none';
            }
        }

        // Auto-refresh status badges every 5 seconds
        setInterval(async () => {
            const badges = document.querySelectorAll('.project-badge[data-project-dir]');
            
            for (const badge of badges) {
                const projectDir = badge.getAttribute('data-project-dir');
                
                try {
                    const response = await fetch(`api/jobs/status?project_dir=${encodeURIComponent(projectDir)}`);
                    const data = await response.json();
                    
                    if (data.status) {
                        let newText = 'SEO';
                        let newClass = 'project-badge';
                        
                        if (data.status === 'running') {
                            newText = 'En cours';
                            newClass += ' badge-running';
                        } else if (data.status === 'queued' || data.status === 'pending') {
                            newText = 'En attente';
                            newClass += ' badge-queued';
                        } else if (data.status === 'failed') {
                            newText = 'Échoué';
                            newClass += ' badge-failed';
                        } else if (data.status === 'completed') {
                            newText = 'Terminé';
                            newClass += ' badge-completed';
                        }
                        
                        badge.textContent = newText;
                        badge.className = newClass;
                        badge.setAttribute('data-project-dir', projectDir);
                    }
                } catch (error) {
                    console.error('Error checking job status:', error);
                }
            }
        }, 5000);

        // Categories Modal Management
        async function openCategoriesModal() {
            document.getElementById('categoriesModal').style.display = 'flex';
            await loadCategories();
        }

        function closeCategoriesModal() {
            document.getElementById('categoriesModal').style.display = 'none';
            document.getElementById('newCategoryName').value = '';
            document.getElementById('newCategoryColor').value = '#4ECDC4';
            location.reload();
        }
        
        // Fermer la modal catégories en cliquant en dehors
        document.getElementById('categoriesModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCategoriesModal();
            }
        });

        // Quick Add Category Modal
        function openQuickAddCategoryModal() {
            document.getElementById('quickAddCategoryModal').style.display = 'flex';
        }

        function closeQuickAddCategoryModal() {
            document.getElementById('quickAddCategoryModal').style.display = 'none';
            document.getElementById('quickCategoryName').value = '';
            document.getElementById('quickCategoryColor').value = '#4ECDC4';
        }

        async function quickCreateCategory() {
            const name = document.getElementById('quickCategoryName').value.trim();
            const color = document.getElementById('quickCategoryColor').value;
            
            if (!name) {
                alert('Veuillez saisir un nom de catégorie');
                return;
            }
            
            try {
                const response = await fetch('api/categories', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, color, icon: 'folder' })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    closeQuickAddCategoryModal();
                    location.reload();
                } else {
                    alert('Erreur: ' + (result.error || 'Erreur inconnue'));
                }
            } catch (error) {
                alert('Erreur: ' + error.message);
            }
        }

        async function loadCategories() {
            try {
                const response = await fetch('api/categories');
                const result = await response.json();
                
                if (result.success) {
                    const categoriesList = document.getElementById('categoriesList');
                    
                    if (result.categories.length === 0) {
                        categoriesList.innerHTML = `
                            <div class="cat-empty">
                                <span class="material-symbols-outlined">category</span>
                                <p>Aucune catégorie créée</p>
                            </div>
                        `;
                    } else {
                        categoriesList.innerHTML = result.categories.map(cat => `
                            <div class="cat-item" id="cat-item-${cat.id}">
                                <div class="cat-item-color" style="background: ${cat.color}">
                                    <input type="color" value="${cat.color}" 
                                           onchange="updateCategoryColor(${cat.id}, this.value)"
                                           title="Changer la couleur">
                                </div>
                                <div class="cat-item-info">
                                    <input type="text" 
                                           class="cat-item-name" 
                                           value="${cat.name}" 
                                           onblur="updateCategoryName(${cat.id}, this.value)"
                                           onkeypress="if(event.key==='Enter') this.blur()">
                                    <div class="cat-item-count">${cat.project_count || 0} domaine${(cat.project_count || 0) > 1 ? 's' : ''}</div>
                                </div>
                                <button class="cat-item-delete" onclick="deleteCategory(${cat.id}, '${cat.name.replace(/'/g, "\\'")}')">
                                    <span class="material-symbols-outlined">delete</span>
                                </button>
                            </div>
                        `).join('');
                    }
                }
            } catch (error) {
                console.error('Erreur lors du chargement des catégories:', error);
            }
        }

        async function createCategory() {
            const name = document.getElementById('newCategoryName').value.trim();
            const color = document.getElementById('newCategoryColor').value;
            
            if (!name) {
                alert('Veuillez saisir un nom de catégorie');
                return;
            }
            
            try {
                const response = await fetch('api/categories', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, color, icon: 'folder' })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Reset form
                    document.getElementById('newCategoryName').value = '';
                    document.getElementById('newCategoryColor').value = '#4ECDC4';
                    
                    // Reload categories list only
                    await loadCategories();
                } else {
                    alert('Erreur: ' + (result.error || 'Erreur inconnue'));
                }
            } catch (error) {
                alert('Erreur: ' + error.message);
            }
        }

        async function updateCategoryName(id, newName) {
            const trimmedName = newName.trim();
            if (!trimmedName) {
                alert('Le nom ne peut pas être vide');
                await loadCategories();
                return;
            }
            
            try {
                const response = await fetch('api/categories', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, name: trimmedName, color: '', icon: 'folder' })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    alert('Erreur: ' + (result.error || 'Erreur inconnue'));
                    await loadCategories();
                }
            } catch (error) {
                alert('Erreur: ' + error.message);
                await loadCategories();
            }
        }

        async function updateCategoryColor(id, newColor) {
            try {
                const response = await fetch('api/categories', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, name: '', color: newColor, icon: 'folder' })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Update the color in the UI
                    const colorDiv = document.querySelector(`#cat-item-${id} .cat-item-color`);
                    if (colorDiv) {
                        colorDiv.style.background = newColor;
                    }
                } else {
                    alert('Erreur: ' + (result.error || 'Erreur inconnue'));
                }
            } catch (error) {
                alert('Erreur: ' + error.message);
            }
        }

        async function deleteCategory(id, name) {
            if (!await customConfirm(`Êtes-vous sûr de vouloir supprimer la catégorie "${name}" ?`, 'Supprimer la catégorie', 'Supprimer', 'danger')) {
                return;
            }
            
            try {
                const response = await fetch('api/categories', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    await loadCategories();
                } else {
                    alert('Erreur: ' + (result.error || 'Erreur inconnue'));
                }
            } catch (error) {
                alert('Erreur: ' + error.message);
            }
        }

        // Dupliquer et lancer un nouveau crawl
        // targetUserId: ID du propriétaire du projet (pour admin qui crée un crawl sur le projet d'un autre)
        async function duplicateAndStart(projectDir, targetUserId = null) {
            if (!await customConfirm('Lancer un nouveau crawl pour ce domaine (la configuration du dernier crawl sera reprise) ?', 'Nouveau crawl', 'Lancer', 'primary')) {
                return;
            }
            
            const button = event.target.closest('button');
            const originalHTML = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="material-symbols-outlined" style="font-size: 16px;">hourglass_empty</span> Création...';
            
            try {
                const payload = { project: projectDir };
                // Si un targetUserId est fourni (admin sur projet d'un autre), le passer à l'API
                if (targetUserId) {
                    payload.target_user_id = targetUserId;
                }
                
                const response = await fetch('api/projects/duplicate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Fermer le menu kebab
                    document.querySelectorAll('.kebab-dropdown-menu').forEach(m => m.classList.remove('show'));
                    
                    // Utiliser le domaine retourné par l'API
                    const projectName = data.domain || 'Crawl';
                    
                    // Démarrer le monitoring dans le panel latéral
                    CrawlPanel.start(data.project_dir, projectName, data.crawl_id);
                    
                    // Restaurer le bouton
                    button.disabled = false;
                    button.innerHTML = originalHTML;
                    return;
                } else {
                    alert('Erreur: ' + (data.error || 'Erreur inconnue'));
                    button.disabled = false;
                    button.innerHTML = originalHTML;
                }
            } catch (error) {
                alert('Erreur: ' + error.message);
                button.disabled = false;
                button.innerHTML = originalHTML;
            }
        }
        
        // Dropdown administration
        function toggleAdminDropdown() {
            document.getElementById('adminDropdownMenu')?.classList.toggle('show');
        }
        
        window.addEventListener('click', function(e) {
            if (!e.target.closest('.admin-dropdown')) {
                document.getElementById('adminDropdownMenu')?.classList.remove('show');
            }
        });
    </script>
    <script src="assets/confirm-modal.js"></script>
    <script src="assets/crawl-panel.js?v=<?= time() ?>"></script>
    
    <?php include 'components/crawl-panel.php'; ?>
    
    <style>
        /* User Dropdown Styles */
        .user-dropdown {
            position: relative;
            width: 100%;
        }
        
        .user-dropdown-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s ease;
        }
        
        .user-dropdown-trigger:hover {
            border-color: var(--primary-color);
        }
        
        .user-dropdown.open .user-dropdown-trigger {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(78, 205, 196, 0.15);
        }
        
        .user-dropdown-trigger > .material-symbols-outlined:last-child {
            font-size: 20px;
            color: var(--text-secondary);
            transition: transform 0.2s ease;
        }
        
        .user-dropdown.open .user-dropdown-trigger > .material-symbols-outlined:last-child {
            transform: rotate(180deg);
        }
        
        .user-dropdown-value {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--text-primary);
        }
        
        .user-dropdown-value.placeholder {
            color: var(--text-secondary);
        }
        
        .user-dropdown-options {
            position: fixed;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            z-index: 2147483647;
            display: none;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            overflow: hidden;
            max-height: 250px;
            overflow-y: auto;
        }
        
        .user-dropdown-option {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: background 0.15s ease;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .user-dropdown-option:last-child {
            border-bottom: none;
        }
        
        .user-dropdown-option:hover {
            background: #f8fafc;
        }
        
        .user-dropdown-option.selected {
            background: rgba(78, 205, 196, 0.08);
        }
        
        .user-dropdown-option .user-avatar-small {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .user-dropdown-option .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .user-dropdown-option .user-email {
            font-weight: 500;
            color: var(--text-primary);
        }
        
        .user-dropdown-option .user-role {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        
        .user-dropdown-empty {
            padding: 1rem;
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
    </style>
    
    <!-- Modal Gestion du Projet -->
    <div id="projectSettingsModal" class="modal category-modal">
        <div class="cat-modal-container" style="max-width: 500px;">
            <input type="hidden" id="projectSettingsId">
            
            <!-- Header -->
            <div class="cat-modal-header">
                <div class="cat-modal-title">
                    <span class="material-symbols-outlined">settings</span>
                    <h2 id="projectSettingsTitle">Paramètres du projet</h2>
                </div>
                <button class="cat-modal-close" onclick="closeProjectSettingsModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            
            <!-- Onglets -->
            <div class="project-settings-tabs" style="display: flex; border-bottom: 1px solid var(--border-color); padding: 0 1.5rem;">
                <button type="button" class="project-tab active" onclick="switchProjectTab('share')" data-tab="share" style="flex: 1; padding: 0.75rem; background: none; border: none; cursor: pointer; font-weight: 500; color: var(--text-secondary); border-bottom: 2px solid transparent; margin-bottom: -1px;">
                    <span class="material-symbols-outlined" style="font-size: 18px; vertical-align: middle;">group</span>
                    Partage
                </button>
                <button type="button" class="project-tab" onclick="switchProjectTab('danger')" data-tab="danger" style="flex: 1; padding: 0.75rem; background: none; border: none; cursor: pointer; font-weight: 500; color: var(--text-secondary); border-bottom: 2px solid transparent; margin-bottom: -1px;">
                    <span class="material-symbols-outlined" style="font-size: 18px; vertical-align: middle;">warning</span>
                    Zone de danger
                </button>
            </div>
            
            <!-- Body -->
            <div class="cat-modal-body">
                <!-- Onglet Partage -->
                <div id="projectTab-share" class="project-tab-pane active">
                    <div style="margin-bottom: 1.5rem;">
                        <h4 style="margin-bottom: 0.75rem; color: var(--text-primary); font-size: 0.9rem;">Ajouter un utilisateur</h4>
                        <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
                            <input type="hidden" id="shareUserSelect" value="">
                            <div class="user-dropdown" id="shareUserDropdown" style="flex: 1;">
                                <div class="user-dropdown-trigger" onclick="toggleUserDropdown()">
                                    <div class="user-dropdown-value">
                                        <span class="material-symbols-outlined" style="color: var(--text-secondary);">person_add</span>
                                        <span id="shareUserLabel">Sélectionner un utilisateur</span>
                                    </div>
                                    <span class="material-symbols-outlined">expand_more</span>
                                </div>
                                <div class="user-dropdown-options" id="shareUserOptions">
                                    <!-- Options seront ajoutées dynamiquement -->
                                </div>
                            </div>
                            <button type="button" class="cat-add-btn" onclick="shareProjectWithUser()" style="white-space: nowrap;">
                                <span class="material-symbols-outlined">person_add</span>
                                Partager
                            </button>
                        </div>
                    </div>
                    
                    <div>
                        <h4 style="margin-bottom: 0.75rem; color: var(--text-primary); font-size: 0.9rem;">Utilisateurs ayant accès</h4>
                        <div id="projectSharesList" style="max-height: 200px; overflow-y: auto;">
                            <!-- Liste des utilisateurs partagés -->
                        </div>
                    </div>
                    <div id="projectShareMessage" class="form-message" style="margin-top: 1rem;"></div>
                </div>
                
                <!-- Onglet Zone de danger -->
                <div id="projectTab-danger" class="project-tab-pane" style="display: none;">
                    <div style="background: #FEF2F2; border: 1px solid #FECACA; border-radius: 8px; padding: 1.25rem;">
                        <h4 style="color: #DC2626; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.95rem;">
                            <span class="material-symbols-outlined">warning</span>
                            Supprimer ce projet
                        </h4>
                        <p style="color: #7F1D1D; margin-bottom: 1rem; font-size: 0.85rem;">
                            Cette action est irréversible. Tous les crawls associés seront supprimés.
                        </p>
                        <button type="button" class="btn" onclick="deleteProject()" style="background: #DC2626; color: white; border: none; cursor: pointer;">
                            <span class="material-symbols-outlined">delete_forever</span>
                            Supprimer le projet
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="cat-modal-footer" style="gap: 0.75rem;">
                <button type="button" class="btn btn-secondary" onclick="closeProjectSettingsModal()">Fermer</button>
            </div>
        </div>
    </div>
    
    <script>
        // ============================================
        // GESTION DE LA MODAL PARAMÈTRES PROJET
        // ============================================
        
        let currentProjectId = null;
        let currentProjectName = '';
        
        function openProjectSettingsModal(projectId, projectName) {
            currentProjectId = projectId;
            currentProjectName = projectName;
            
            document.getElementById('projectSettingsId').value = projectId;
            document.getElementById('projectSettingsTitle').textContent = projectName;
            
            // Reset tabs - démarre sur l'onglet Partage
            switchProjectTab('share');
            
            // Reset messages
            document.getElementById('projectShareMessage').innerHTML = '';
            
            // Charger les partages
            loadProjectShares(projectId);
            
            document.getElementById('projectSettingsModal').style.display = 'flex';
        }
        
        function closeProjectSettingsModal() {
            document.getElementById('projectSettingsModal').style.display = 'none';
            currentProjectId = null;
            currentProjectName = '';
        }
        
        // Ouvre le modal directement sur l'onglet Partage
        function openShareModal(projectId, projectName) {
            openProjectSettingsModal(projectId, projectName);
            // L'onglet 'share' est déjà sélectionné par défaut dans openProjectSettingsModal
        }
        
        // Confirmation de suppression de projet (depuis menu kebab)
        async function confirmDeleteProject(projectId, projectName) {
            const confirmed = await customConfirm(
                `Êtes-vous sûr de vouloir supprimer le projet "${projectName}" ? Cette action est irréversible et supprimera tous les crawls associés.`,
                'Supprimer le projet',
                'Supprimer',
                'danger'
            );
            if (confirmed) {
                executeProjectDeletion(projectId);
            }
        }
        
        async function executeProjectDeletion(projectId) {
            try {
                const response = await fetch('api/projects', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ project_id: projectId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    location.reload();
                } else {
                    alert('Erreur: ' + (result.error || 'Erreur lors de la suppression'));
                }
            } catch (error) {
                alert('Erreur: ' + error.message);
            }
        }
        
        function switchProjectTab(tabName) {
            // Mettre à jour les boutons
            document.querySelectorAll('.project-tab').forEach(tab => {
                tab.classList.remove('active');
                tab.style.borderBottomColor = 'transparent';
                tab.style.color = 'var(--text-secondary)';
            });
            const activeTab = document.querySelector(`.project-tab[data-tab="${tabName}"]`);
            if (activeTab) {
                activeTab.classList.add('active');
                activeTab.style.borderBottomColor = 'var(--primary-color)';
                activeTab.style.color = 'var(--primary-color)';
            }
            
            // Mettre à jour les panneaux
            document.querySelectorAll('.project-tab-pane').forEach(pane => {
                pane.style.display = 'none';
            });
            const activePane = document.getElementById(`projectTab-${tabName}`);
            if (activePane) {
                activePane.style.display = 'block';
            }
        }
        
        // Données des utilisateurs disponibles
        let availableUsers = [];
        
        async function loadProjectShares(projectId) {
            try {
                const response = await fetch(`api/projects/${projectId}/shares`);
                const result = await response.json();
                
                if (result.success) {
                    // Stocker les utilisateurs disponibles
                    availableUsers = result.available_users;
                    
                    // Remplir le dropdown stylisé
                    const optionsContainer = document.getElementById('shareUserOptions');
                    if (availableUsers.length === 0) {
                        optionsContainer.innerHTML = '<div class="user-dropdown-empty">Aucun utilisateur disponible</div>';
                    } else {
                        optionsContainer.innerHTML = availableUsers.map(user => `
                            <div class="user-dropdown-option" data-user-id="${user.id}" data-user-email="${user.email}" onclick="selectUser(${user.id}, '${user.email.replace(/'/g, "\\'")}')">
                                <div class="user-avatar-small">${user.email.charAt(0).toUpperCase()}</div>
                                <div class="user-info">
                                    <span class="user-email">${user.email}</span>
                                    <span class="user-role">${user.role || 'user'}</span>
                                </div>
                            </div>
                        `).join('');
                    }
                    
                    // Réinitialiser la sélection
                    document.getElementById('shareUserSelect').value = '';
                    document.getElementById('shareUserLabel').textContent = 'Sélectionner un utilisateur';
                    
                    // Afficher la liste des partages
                    const sharesList = document.getElementById('projectSharesList');
                    if (result.shares.length === 0) {
                        sharesList.innerHTML = '<p style="color: var(--text-secondary); text-align: center; padding: 1rem;">Aucun partage pour ce projet</p>';
                    } else {
                        sharesList.innerHTML = result.shares.map(share => `
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: var(--bg-secondary); border-radius: 6px; margin-bottom: 0.5rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <div style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 0.85rem;">
                                        ${share.email.charAt(0).toUpperCase()}
                                    </div>
                                    <div>
                                        <div style="font-weight: 500;">${share.email}</div>
                                        <div style="font-size: 0.8rem; color: var(--text-secondary);">${share.role || 'user'}</div>
                                    </div>
                                </div>
                                <button class="btn btn-sm btn-danger-light" onclick="removeShare(${share.id})" title="Retirer l'accès">
                                    <span class="material-symbols-outlined" style="font-size: 16px;">person_remove</span>
                                </button>
                            </div>
                        `).join('');
                    }
                }
            } catch (error) {
                console.error('Erreur lors du chargement des partages:', error);
            }
        }
        
        // ============================================
        // DROPDOWN UTILISATEUR STYLISÉ
        // ============================================
        
        function toggleUserDropdown() {
            const dropdown = document.getElementById('shareUserDropdown');
            if (!dropdown) return;
            const trigger = dropdown.querySelector('.user-dropdown-trigger');
            const options = document.getElementById('shareUserOptions');
            
            if (dropdown.classList.contains('open')) {
                dropdown.classList.remove('open');
                if (options) options.style.display = 'none';
            } else {
                // Positionner le dropdown avec position fixed
                const rect = trigger?.getBoundingClientRect();
                if (options && rect) {
                    options.style.position = 'fixed';
                    options.style.top = (rect.bottom + 4) + 'px';
                    options.style.left = rect.left + 'px';
                    options.style.width = rect.width + 'px';
                    options.style.display = 'block';
                }
                dropdown.classList.add('open');
            }
        }
        
        function selectUser(userId, userEmail) {
            const userSelect = document.getElementById('shareUserSelect');
            const userLabel = document.getElementById('shareUserLabel');
            if (userSelect) userSelect.value = userId;
            if (userLabel) userLabel.textContent = userEmail;
            
            // Mettre à jour les états selected
            document.querySelectorAll('.user-dropdown-option').forEach(opt => {
                opt.classList.toggle('selected', opt.dataset.userId == userId);
            });
            
            // Fermer le dropdown
            const dropdown = document.getElementById('shareUserDropdown');
            if (dropdown) dropdown.classList.remove('open');
            const options = document.getElementById('shareUserOptions');
            if (options) options.style.display = 'none';
        }
        
        // Fermer le dropdown utilisateur si clic en dehors
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.user-dropdown')) {
                const dropdown = document.getElementById('shareUserDropdown');
                const options = document.getElementById('shareUserOptions');
                if (dropdown) dropdown.classList.remove('open');
                if (options) options.style.display = 'none';
            }
        });
        
        async function shareProjectWithUser() {
            const userId = document.getElementById('shareUserSelect').value;
            if (!userId) {
                showProjectMessage('projectShareMessage', 'Sélectionnez un utilisateur', 'error');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'share');
                formData.append('project_id', currentProjectId);
                formData.append('user_id', userId);
                
                const response = await fetch('api/projects', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    showProjectMessage('projectShareMessage', 'Projet partagé avec succès', 'success');
                    loadProjectShares(currentProjectId);
                } else {
                    showProjectMessage('projectShareMessage', result.error || 'Erreur', 'error');
                }
            } catch (error) {
                showProjectMessage('projectShareMessage', 'Erreur de connexion', 'error');
            }
        }
        
        async function removeShare(userId) {
            if (!confirm('Êtes-vous sûr de vouloir retirer l\'accès à cet utilisateur ?')) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'unshare');
                formData.append('project_id', currentProjectId);
                formData.append('user_id', userId);
                
                const response = await fetch('api/projects', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    showProjectMessage('projectShareMessage', 'Partage retiré', 'success');
                    loadProjectShares(currentProjectId);
                } else {
                    showProjectMessage('projectShareMessage', result.error || 'Erreur', 'error');
                }
            } catch (error) {
                showProjectMessage('projectShareMessage', 'Erreur de connexion', 'error');
            }
        }
        
        async function deleteProject() {
            const confirmed = await customConfirm(
                `Êtes-vous sûr de vouloir supprimer le projet "${currentProjectName}" ? Cette action est irréversible et supprimera tous les crawls associés.`,
                'Supprimer le projet',
                'Supprimer',
                'danger'
            );
            
            if (!confirmed) return;
            
            try {
                const response = await fetch(`api/projects/${currentProjectId}`, {
                    method: 'DELETE'
                });
                
                const result = await response.json();
                if (result.success) {
                    closeProjectSettingsModal();
                    location.reload();
                } else {
                    alert('Erreur: ' + (result.error || 'Impossible de supprimer le projet'));
                }
            } catch (error) {
                alert('Erreur de connexion au serveur');
            }
        }
        
        function showProjectMessage(elementId, message, type) {
            const element = document.getElementById(elementId);
            element.innerHTML = message;
            element.className = 'form-message ' + (type === 'success' ? 'success' : 'error');
            element.style.display = 'block';
        }
        
        // Fermer la modal projet sur clic extérieur
        document.getElementById('projectSettingsModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeProjectSettingsModal();
            }
        });
        
        // Restaurer l'onglet actif après rechargement
        (function() {
            const savedTab = sessionStorage.getItem('activeProjectsTab');
            if (savedTab && savedTab !== 'my') {
                // Vérifier que l'onglet existe
                const tabButton = document.querySelector(`.projects-tab[data-tab="${savedTab}"]`);
                if (tabButton) {
                    switchProjectsTab(savedTab, false);
                }
            }
        })();
    </script>
</body>
</html>