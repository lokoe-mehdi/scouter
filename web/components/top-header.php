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
    <a href="<?= $basePath ?>index.php" class="header-brand" style="text-decoration: none; color: inherit;" hx-boost="false"><?php /* index.php pas encore swap-safe → nav pleine */ ?>
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
                echo $currentDate ? $currentDate->format('d/m/Y H:i') : __('header.current_crawl');
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
                                <?= $crawlDate ? $crawlDate->format('d/m/Y H:i') : __('header.date_unknown') ?>
                                <span class="crawl-item-id">#<?= $crawl['crawl_id'] ?></span>
                            </div>
                            <?php if ($isActiveCrawl): ?>
                                <span class="crawl-item-badge"><?= __('header.badge_current') ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="crawl-item-row">
                            <div class="crawl-item-stats">
                                <span><?= number_format($crawl['stats']['urls']) ?> URLs</span>
                                <span>•</span>
                                <span><?= number_format($crawl['stats']['crawled']) ?> <?= __('header.crawled') ?></span>
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
        <!-- Ghost link : Retour au projet -->
        <a href="<?= $basePath ?>project.php?id=<?= $crawlRecord->project_id ?? '' ?>" class="header-back-link" hx-boost="false">
            <span class="material-symbols-outlined">arrow_back</span>
            <?= __('header.project') ?>
        </a>
        <?php endif; ?>
        
        <?php if ($headerContext === 'admin'): ?>
        <!-- Ghost link : Retour à l'accueil -->
        <a href="<?= $basePath ?>index.php" class="header-back-link" hx-boost="false">
            <span class="material-symbols-outlined">arrow_back</span>
            <?= __('header.home') ?>
        </a>
        <?php endif; ?>
        
        <!-- Centre de téléchargements (exports CSV asynchrones) -->
        <!-- hx-preserve : sous hx-boost (nav hub) htmx garde ce nœud vivant
             (listeners + polling intacts) au lieu de le recréer. Voir htmx.md §4bis. -->
        <div class="notif-bell" id="dlBell" hx-preserve="true">
            <button class="notif-bell-btn" id="dlBellBtn" type="button"
                    aria-label="<?= __('downloads.title') ?>">
                <span class="material-symbols-outlined">download</span>
                <span class="notif-bell-badge" id="dlBellBadge" hidden>0</span>
            </button>
            <div class="notif-dropdown" id="dlDropdown">
                <div class="notif-dropdown-header">
                    <span class="notif-dropdown-title"><?= __('downloads.title') ?></span>
                </div>
                <div class="notif-list" id="dlList">
                    <div class="notif-empty" id="dlEmpty">
                        <span class="material-symbols-outlined">cloud_download</span>
                        <?= __('downloads.empty') ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cloche de notifications -->
        <div class="notif-bell" id="notifBell" hx-preserve="true">
            <button class="notif-bell-btn" id="notifBellBtn" type="button"
                    aria-label="<?= __('notifications.title') ?>">
                <span class="material-symbols-outlined">notifications</span>
                <span class="notif-bell-badge" id="notifBellBadge" hidden>0</span>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-dropdown-header">
                    <span class="notif-dropdown-title"><?= __('notifications.title') ?></span>
                </div>
                <div class="notif-list" id="notifList">
                    <div class="notif-empty" id="notifEmpty">
                        <span class="material-symbols-outlined">notifications_off</span>
                        <?= __('notifications.empty') ?>
                    </div>
                </div>
            </div>
        </div>

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
                        <span class="user-dropdown-role"><?= $isAdmin ? __('header.admin_role') : __('header.user_role') ?></span>
                    </div>
                </div>
                <div class="user-dropdown-divider"></div>
                <a href="<?= $basePath ?>profile.php" class="user-dropdown-item">
                    <span class="material-symbols-outlined">account_circle</span>
                    <?= __('header.profile') ?>
                </a>
                <?php if ($isAdmin): ?>
                <a href="<?= $basePath ?>pages/settings.php?tab=team" class="user-dropdown-item" hx-boost="false">
                    <span class="material-symbols-outlined">manage_accounts</span>
                    <?= __('header.manage_users') ?>
                </a>
                <a href="<?= $basePath ?>pages/monitor.php" class="user-dropdown-item">
                    <span class="material-symbols-outlined">monitoring</span>
                    <?= __('header.system_monitor') ?>
                </a>
                <a href="<?= $basePath ?>pages/settings.php" class="user-dropdown-item" hx-boost="false">
                    <span class="material-symbols-outlined">settings</span>
                    <?= __('header.settings') ?>
                </a>
                <?php else: ?>
                <!-- Non-admins get self-service access to their own API keys & MCP setup. -->
                <a href="<?= $basePath ?>pages/settings.php?tab=api" class="user-dropdown-item" hx-boost="false">
                    <span class="material-symbols-outlined">vpn_key</span>
                    <?= __('header.api_mcp') ?>
                </a>
                <?php endif; ?>
                <div class="user-dropdown-divider"></div>
                <div style="display: flex; gap: 0.5rem; padding: 0.5rem 1rem;">
                    <?php foreach (I18n::getInstance()->getSupportedLanguages() as $lang): ?>
                        <a href="?lang=<?= $lang ?>&<?= http_build_query(array_diff_key($_GET, ['lang' => ''])) ?>"
                           style="padding: 0.3rem 0.6rem; border-radius: 4px; text-decoration: none; font-size: 0.85rem; font-weight: <?= $lang === I18n::getInstance()->getLang() ? '600' : '400' ?>; color: <?= $lang === I18n::getInstance()->getLang() ? 'var(--primary-color)' : 'var(--text-secondary)' ?>; background: <?= $lang === I18n::getInstance()->getLang() ? 'rgba(78,205,196,0.1)' : 'transparent' ?>; text-transform: uppercase;">
                            <?= $lang ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <a href="<?= $basePath ?>api/logout" class="user-dropdown-item user-dropdown-item-danger" hx-boost="false"
                   onclick="try{Object.keys(localStorage).filter(function(k){return k.indexOf('dr-brief:')===0;}).forEach(function(k){localStorage.removeItem(k);});}catch(e){}">
                    <span class="material-symbols-outlined">logout</span>
                    <?= __('header.logout') ?>
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

/* ===== Cloche de notifications ===== */
.notif-bell {
    position: relative;
    display: flex;
    align-items: center;
}

.notif-bell-btn {
    position: relative;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: rgba(255, 255, 255, 0.85);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.notif-bell-btn:hover {
    background: rgba(255, 255, 255, 0.16);
    border-color: rgba(255, 255, 255, 0.35);
    color: #fff;
}

.notif-bell-btn .material-symbols-outlined {
    font-size: 21px;
}

.notif-bell.has-unread .notif-bell-btn .material-symbols-outlined {
    /* léger swing une fois quand il y a du nouveau */
    animation: notif-bell-swing 0.6s ease;
}

@keyframes notif-bell-swing {
    0%, 100% { transform: rotate(0); }
    20% { transform: rotate(14deg); }
    40% { transform: rotate(-10deg); }
    60% { transform: rotate(6deg); }
    80% { transform: rotate(-4deg); }
}

.notif-bell-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-sizing: content-box;
    min-width: 14px;
    height: 14px;
    padding: 2px 1px;
    border-radius: 999px;
    background: var(--danger, #E74C3C);
    color: #fff;
    font-size: 0.68rem;
    font-weight: 700;
    line-height: 1;
    text-align: center;
    border: 2px solid var(--sidebar-bg, #2C3E50);
    box-shadow: 0 0 0 1px rgba(231, 76, 60, 0.4);
}
.notif-bell-badge[hidden] { display: none; }

.notif-dropdown {
    position: absolute;
    top: calc(100% + 0.6rem);
    right: 0;
    width: 380px;
    max-width: calc(100vw - 2rem);
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 14px;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.2s ease;
    z-index: 1100;
    overflow: hidden;
}

.notif-dropdown.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.notif-dropdown-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.9rem 1.1rem;
    border-bottom: 1px solid var(--border-color);
}

.notif-dropdown-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--text-primary);
}

.notif-list {
    max-height: min(440px, 70vh);
    overflow-y: auto;
    overscroll-behavior: contain;
}

.notif-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 2.5rem 1rem;
    color: var(--text-secondary);
    font-size: 0.88rem;
    text-align: center;
}

.notif-empty .material-symbols-outlined {
    font-size: 34px;
    opacity: 0.5;
}

.notif-item {
    display: flex;
    gap: 0.75rem;
    padding: 0.8rem 1.1rem;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: background 0.15s ease;
    position: relative;
}

.notif-item:last-child { border-bottom: none; }

.notif-item:hover { background: var(--bg-hover, #f5f7fa); }

.notif-item.is-unread { background: rgba(78, 205, 196, 0.07); }

.notif-item.is-unread::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--primary-color);
}

.notif-item-icon {
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-top: 2px;
}

.notif-item-icon .material-symbols-outlined { font-size: 19px; }

.notif-icon-started  { background: rgba(52, 152, 219, 0.12); color: var(--info, #3498DB); }
.notif-icon-finished { background: rgba(46, 204, 113, 0.13); color: var(--success, #2ECC71); }
.notif-icon-failed   { background: rgba(231, 76, 60, 0.12);  color: var(--danger, #E74C3C); }
.notif-icon-job      { background: rgba(78, 205, 196, 0.14); color: var(--primary-color, #4ECDC4); }
.notif-icon-shared   { background: rgba(155, 89, 182, 0.13); color: #9B59B6; }

.notif-item-body { min-width: 0; flex: 1; }

.notif-item-title {
    font-size: 0.86rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 1px;
}

.notif-item-text {
    font-size: 0.82rem;
    color: var(--text-secondary);
    line-height: 1.35;
    word-break: break-word;
}

.notif-item-text strong { color: var(--text-primary); font-weight: 600; }

.notif-item-meta {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin-top: 4px;
    font-size: 0.72rem;
    color: var(--text-secondary);
}

.notif-item-id {
    font-family: 'Roboto Mono', monospace;
    background: var(--bg-secondary, #eef2f6);
    color: var(--text-secondary);
    padding: 0 5px;
    border-radius: 4px;
    font-size: 0.68rem;
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

/* Centre de téléchargements — réutilise les styles .notif-* (dropdown/list/item).
   Quelques variantes propres aux exports (états + bouton télécharger). */
.notif-icon-export   { background: rgba(78, 205, 196, 0.15); color: #4ECDC4; }
/* En cours : fond blanc + vrai spinner de chargement (progress_activity) en teal. */
.notif-icon-pending  { background: #fff; color: var(--primary-color, #4ECDC4); }
.notif-icon-failed   { background: rgba(220, 53, 69, 0.15);  color: #ff6b6b; }
.dl-item .material-symbols-outlined.spinning { animation: dl-spin 1s linear infinite; }
@keyframes dl-spin { to { transform: rotate(360deg); } }
.dl-item-actions { margin-left: auto; display: flex; align-items: center; }
.dl-download-btn {
    display: inline-flex; align-items: center; gap: 4px;
    background: var(--primary-color); color: #fff; border: none; border-radius: 6px;
    padding: 5px 10px; font-size: 12px; font-weight: 600; cursor: pointer;
    text-decoration: none; white-space: nowrap;
}
.dl-download-btn:hover { background: var(--primary-dark); }
.dl-download-btn .material-symbols-outlined { font-size: 16px; color: #fff; }
.dl-item-status { font-size: 11px; opacity: 0.75; }
.dl-item-status.is-failed { color: #ff6b6b; opacity: 1; }
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
<script src="<?= $basePath ?>assets/notifications.js?v=<?= filemtime(__DIR__ . '/../assets/notifications.js') ?>"></script>
<script src="<?= $basePath ?>assets/downloads.js?v=<?= filemtime(__DIR__ . '/../assets/downloads.js') ?>"></script>
