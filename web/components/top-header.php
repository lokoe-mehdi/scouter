<?php
/**
 * Composant Top Header
 * 
 * Header principal unifié pour toute l'application
 * 
 * Variables optionnelles :
 * - $headerContext : 'dashboard' | 'index' | 'admin' | 'monitor' (défaut: 'index')
 * - $projectName : Nom du projet/domaine (pour dashboard)
 * - $projectDir : Répertoire du projet (pour dashboard)
 * - $crawlId : ID du crawl actuel (pour dashboard)
 * - $domainCrawls : Liste des crawls du domaine (pour dashboard)
 * - $page : Page actuelle (pour dashboard)
 * - $isInSubfolder : bool - si on est dans un sous-dossier (pages/)
 */

// Déterminer le contexte
$headerContext = $headerContext ?? 'index';
$isInSubfolder = $isInSubfolder ?? false;
$basePath = $isInSubfolder ? '../' : '';

// Auth pour le dropdown profil
use App\Auth\Auth;
if (!isset($auth)) {
    $auth = new Auth();
}
$isAdmin = $auth->isAdmin();
$currentUserEmail = $auth->getCurrentEmail() ?? 'user@example.com';

/**
 * Génère les initiales à partir de l'email
 * - prenom.nom@... ou prenom-nom@... → PN
 * - contact@... → CO
 */
if (!function_exists('getUserInitials')) {
    function getUserInitials($email) {
        // Extraire la partie avant le @
        $localPart = explode('@', $email)[0];
        
        // Chercher un séparateur (. ou -)
        if (strpos($localPart, '.') !== false) {
            $parts = explode('.', $localPart);
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[1] ?? '', 0, 1));
        } elseif (strpos($localPart, '-') !== false) {
            $parts = explode('-', $localPart);
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[1] ?? '', 0, 1));
        } else {
            // Pas de séparateur : 2 premières lettres
            return strtoupper(substr($localPart, 0, 2));
        }
    }
}

$userInitials = getUserInitials($currentUserEmail);
?>
<header class="header">
    <a href="<?= $basePath ?>index.php" class="header-brand" style="text-decoration: none; color: inherit;">
        <div class="header-brand-icon">
            <img src="<?= $basePath ?>logo.png" alt="Logo Scouter">
        </div>
        <span>Scouter</span>
    </a>
    
    <?php if ($headerContext === 'dashboard' && isset($projectName)): ?>
    <!-- Centre : Nom du projet + Sélecteur de crawl (Dashboard uniquement) -->
    <div class="header-center">
        <span class="material-symbols-outlined" style="color: var(--primary-color);">analytics</span>
        <span style="color: white; font-weight: 600; font-size: 1.1rem;"><?= htmlspecialchars($projectName) ?></span>
        
        <?php if (isset($domainCrawls) && !empty($domainCrawls)): ?>
        <!-- Séparateur vertical -->
        <span class="header-divider"></span>
        
        <div class="crawl-selector">
            <button class="crawl-selector-btn" onclick="toggleCrawlDropdown(event)">
                <span class="material-symbols-outlined">schedule</span>
                <?php
                $currentDate = DateTime::createFromFormat('Ymd-His', substr($projectDir ?? '', -15));
                echo $currentDate ? $currentDate->format('d/m/Y H:i') : 'Crawl actuel';
                ?>
                <span class="material-symbols-outlined">expand_more</span>
            </button>
            
            <div class="crawl-dropdown" id="crawlDropdown">
                <?php foreach ($domainCrawls as $crawl): ?>
                    <?php
                    // Ne montrer que les crawls terminés ou arrêtés
                    $crawlStatus = $crawl['status'] ?? $crawl['job_status'] ?? 'finished';
                    if (!in_array($crawlStatus, ['finished', 'stopped', 'error', 'completed'])) {
                        continue;
                    }
                    $crawlDate = DateTime::createFromFormat('Y-m-d H:i:s', $crawl['date']);
                    $isActiveCrawl = ($crawl['crawl_id'] == ($crawlId ?? 0));
                    ?>
                    <a href="?crawl=<?= $crawl['crawl_id'] ?>&page=<?= urlencode($page ?? 'home') ?>" 
                       class="crawl-dropdown-item <?= $isActiveCrawl ? 'active' : '' ?>">
                        <div class="crawl-item-main">
                            <div class="crawl-item-date">
                                <?= $crawlDate ? $crawlDate->format('d/m/Y H:i') : 'Date inconnue' ?>
                            </div>
                            <?php if ($isActiveCrawl): ?>
                                <span class="crawl-item-badge">Actuel</span>
                            <?php endif; ?>
                        </div>
                        <div class="crawl-item-row">
                            <div class="crawl-item-stats">
                                <span><?= number_format($crawl['stats']['urls']) ?> URLs</span>
                                <span>•</span>
                                <span><?= number_format($crawl['stats']['crawled']) ?> crawlées</span>
                            </div>
                            <?php if (!empty($crawl['config'])): ?>
                            <div class="crawl-item-config">
                                <span class="material-symbols-outlined config-mini <?= ($crawl['config']['general']['crawl_mode'] ?? 'classic') === 'javascript' ? 'active' : '' ?>" title="Mode JavaScript">javascript</span>
                                <span class="material-symbols-outlined config-mini <?= !empty($crawl['config']['advanced']['respect']['robots']) ? 'active' : '' ?>" title="Respect du robots.txt">smart_toy</span>
                                <span class="material-symbols-outlined config-mini <?= !empty($crawl['config']['advanced']['respect']['canonical']) ? 'active' : '' ?>" title="Respect des canonicals">content_copy</span>
                                <span class="material-symbols-outlined config-mini <?= !empty($crawl['config']['advanced']['nofollow']) ? 'active' : 'inactive' ?>" title="Respect du nofollow">link_off</span>
                                <span class="config-depth-mini" title="Profondeur max"><?= $crawl['config']['general']['depthMax'] ?? '-' ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Actions à droite -->
    <div class="header-actions">
        <?php if ($headerContext === 'dashboard' || $headerContext === 'monitor'): ?>
        <!-- Ghost link : Retour aux projets -->
        <a href="<?= $basePath ?>index.php" class="header-back-link">
            <span class="material-symbols-outlined">arrow_back</span>
            Projets
        </a>
        <?php endif; ?>
        
        <?php if ($headerContext === 'admin'): ?>
        <!-- Ghost link : Retour à l'accueil -->
        <a href="<?= $basePath ?>index.php" class="header-back-link">
            <span class="material-symbols-outlined">arrow_back</span>
            Accueil
        </a>
        <?php endif; ?>
        
        <!-- Avatar Utilisateur avec Dropdown -->
        <div class="user-avatar-dropdown">
            <button class="user-avatar-btn" onclick="toggleHeaderUserDropdown()" title="<?= htmlspecialchars($currentUserEmail) ?>">
                <?= $userInitials ?>
            </button>
            <div class="user-dropdown-menu" id="headerUserDropdownMenu">
                <div class="user-dropdown-header">
                    <div class="user-dropdown-avatar"><?= $userInitials ?></div>
                    <div class="user-dropdown-info">
                        <span class="user-dropdown-email"><?= htmlspecialchars($currentUserEmail) ?></span>
                        <span class="user-dropdown-role"><?= $isAdmin ? 'Administrateur' : 'Utilisateur' ?></span>
                    </div>
                </div>
                <div class="user-dropdown-divider"></div>
                <?php if ($isAdmin): ?>
                <a href="<?= $basePath ?>pages/admin.php" class="user-dropdown-item">
                    <span class="material-symbols-outlined">manage_accounts</span>
                    Gérer les utilisateurs
                </a>
                <a href="<?= $basePath ?>pages/monitor.php" class="user-dropdown-item">
                    <span class="material-symbols-outlined">monitoring</span>
                    System Monitor
                </a>
                <?php endif; ?>
                <a href="<?= $basePath ?>api/logout" class="user-dropdown-item user-dropdown-item-danger">
                    <span class="material-symbols-outlined">logout</span>
                    Déconnexion
                </a>
            </div>
        </div>
    </div>
</header>

<style>
/* Séparateur vertical dans le header */
.header-divider {
    width: 1px;
    height: 24px;
    background: rgba(255, 255, 255, 0.3);
    margin: 0 0.75rem;
    align-self: center;
}

/* Correction du margin du crawl-selector pour qu'il soit proche du séparateur */
.header-center .crawl-selector {
    margin-left: 0;
}

/* Ghost link retour */
.header-back-link {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    color: rgba(255, 255, 255, 0.7);
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.header-back-link:hover {
    color: rgba(255, 255, 255, 1);
    background: rgba(255, 255, 255, 0.1);
}

.header-back-link .material-symbols-outlined {
    font-size: 18px;
}

/* Avatar utilisateur */
.user-avatar-dropdown {
    position: relative;
}

.user-avatar-btn {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--primary-color);
    border: 2px solid rgba(255, 255, 255, 0.3);
    color: white;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    text-transform: uppercase;
}

.user-avatar-btn:hover {
    border-color: rgba(255, 255, 255, 0.6);
    transform: scale(1.05);
}

/* Dropdown menu utilisateur */
.user-dropdown-menu {
    position: absolute;
    top: calc(100% + 0.5rem);
    right: 0;
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    min-width: 260px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.2s ease;
    z-index: 1000;
    overflow: hidden;
}

.user-dropdown-menu.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.user-dropdown-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    background: var(--bg-secondary);
}

.user-dropdown-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: var(--primary-color);
    color: white;
    font-size: 0.95rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.user-dropdown-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.user-dropdown-email {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-dropdown-role {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

.user-dropdown-divider {
    height: 1px;
    background: var(--border-color);
}

.user-dropdown-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
    color: var(--text-primary);
    text-decoration: none;
    transition: background 0.2s ease;
}

.user-dropdown-item:hover {
    background: var(--bg-hover);
}

.user-dropdown-item .material-symbols-outlined {
    font-size: 20px;
    color: var(--text-secondary);
}

.user-dropdown-item:hover .material-symbols-outlined {
    color: var(--primary-color);
}

.user-dropdown-item-danger:hover {
    background: rgba(220, 53, 69, 0.08);
}

.user-dropdown-item-danger:hover .material-symbols-outlined {
    color: #dc3545;
}
</style>

<script>
// Toggle crawl dropdown
function toggleCrawlDropdown(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('crawlDropdown');
    if (dropdown) {
        dropdown.classList.toggle('show');
    }
}

// Toggle header user dropdown
function toggleHeaderUserDropdown() {
    const menu = document.getElementById('headerUserDropdownMenu');
    if (menu) {
        menu.classList.toggle('show');
    }
}

// Fermer les dropdowns si on clique ailleurs
document.addEventListener('click', function(e) {
    // Crawl dropdown
    const crawlDropdown = document.getElementById('crawlDropdown');
    const crawlSelector = document.querySelector('.crawl-selector');
    if (crawlDropdown && crawlSelector && !crawlSelector.contains(e.target)) {
        crawlDropdown.classList.remove('show');
    }
    
    // Header user dropdown
    const headerUserMenu = document.getElementById('headerUserDropdownMenu');
    const userDropdown = document.querySelector('.user-avatar-dropdown');
    if (headerUserMenu && userDropdown && !userDropdown.contains(e.target)) {
        headerUserMenu.classList.remove('show');
    }
});
</script>
