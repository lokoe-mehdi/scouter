<?php
/**
 * Composant Sidebar Navigation
 * 
 * Navigation latérale avec icon-rail et panneau secondaire
 * 
 * Variables requises :
 * - $crawlId : ID du crawl actuel
 * - $page : Page actuelle
 * - $activeSection : Section active (report, explorer, categorize, config)
 * - $canManageCurrentProject : Droits de gestion du projet
 */

// Déterminer si c'est une page directe (sans panneau latéral)
$isDirectPage = in_array($activeSection, ['categorize', 'config']);
?>

<!-- Icon Rail (barre d'icônes principale) -->
<aside class="icon-rail">
    <nav class="icon-rail-nav">
        <!-- Crawl Report -->
        <div class="icon-rail-item <?= $activeSection === 'report' ? 'active' : '' ?>" 
             data-section="report" 
             data-tooltip="<?= __('sidebar.crawl_report') ?>">
            <span class="material-symbols-outlined">assessment</span>
            <span class="icon-rail-label"><?= __('sidebar.report') ?></span>
        </div>

        <!-- Data Explorer -->
        <div class="icon-rail-item <?= $activeSection === 'explorer' ? 'active' : '' ?>" 
             data-section="explorer" 
             data-tooltip="<?= __('sidebar.data_explorer') ?>">
            <span class="material-symbols-outlined">manage_search</span>
            <span class="icon-rail-label"><?= __('sidebar.explorer') ?></span>
        </div>

        <!-- Crawl Comparison -->
        <div class="icon-rail-item <?= $activeSection === 'comparison' ? 'active' : '' ?>"
             data-section="comparison"
             data-tooltip="<?= __('sidebar.crawl_comparison') ?>">
            <span class="material-symbols-outlined">compare_arrows</span>
            <span class="icon-rail-label"><?= __('sidebar.comparison') ?></span>
        </div>

        <!-- Categorize (lien direct) -->
        <?php if ($canManageCurrentProject): ?>
        <a href="?crawl=<?= $crawlId ?>&page=categorize" 
           class="icon-rail-item icon-rail-link <?= $activeSection === 'categorize' ? 'active' : '' ?>" 
           data-tooltip="<?= __('sidebar.categorization') ?>">
            <span class="material-symbols-outlined">style</span>
            <span class="icon-rail-label"><?= __('sidebar.segments') ?></span>
        </a>
        <?php endif; ?>
    </nav>
    
    <!-- Config en bas, séparé -->
    <?php if ($canManageCurrentProject): ?>
    <div class="icon-rail-bottom">
        <a href="?crawl=<?= $crawlId ?>&page=config" 
           class="icon-rail-item icon-rail-link <?= $activeSection === 'config' ? 'active' : '' ?>" 
           data-tooltip="<?= __('sidebar.settings') ?>">
            <span class="material-symbols-outlined">settings</span>
            <span class="icon-rail-label"><?= __('sidebar.config') ?></span>
        </a>
    </div>
    <?php endif; ?>
</aside>

<!-- Panneau latéral secondaire (caché si page directe) -->
<aside class="sidebar-panel <?= !$isDirectPage ? 'open scrollable' : '' ?>" id="sidebarPanel">
    <!-- Section Crawl Report -->
    <div class="sidebar-panel-section" data-section="report" style="<?= $activeSection !== 'report' ? 'display: none;' : '' ?>">
        <div class="sidebar-panel-header">
            <span class="material-symbols-outlined">assessment</span>
            <span><?= __('sidebar.crawl_report') ?></span>
            <button class="sidebar-panel-close" onclick="closeSidebarPanel()">
                <span class="material-symbols-outlined">chevron_left</span>
            </button>
        </div>
        
        <!-- Vue d'ensemble -->
        <div class="sidebar-panel-group">
            <a href="?crawl=<?= $crawlId ?>&page=home" 
               class="sidebar-panel-item <?= $page === 'home' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">dashboard</span>
                <span><?= __('sidebar.overview') ?></span>
            </a>
        </div>
        
        <!-- Technique -->
        <div class="sidebar-panel-group">
            <div class="sidebar-panel-group-title"><?= __('sidebar.engine_accessibility') ?></div>
            <a href="?crawl=<?= $crawlId ?>&page=accessibility" 
               class="sidebar-panel-item <?= $page === 'accessibility' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">folder</span>
                <span><?= __('sidebar.indexability') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=codes" 
               class="sidebar-panel-item <?= $page === 'codes' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">http</span>
                <span><?= __('sidebar.response_codes') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=response-time" 
               class="sidebar-panel-item <?= $page === 'response-time' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">speed</span>
                <span><?= __('sidebar.response_time') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=depth"
               class="sidebar-panel-item <?= $page === 'depth' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">layers</span>
                <span><?= __('sidebar.depth_levels') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=redirect-chains"
               class="sidebar-panel-item <?= $page === 'redirect-chains' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">redo</span>
                <span><?= __('sidebar.redirect_chains') ?></span>
            </a>
        </div>
        
        <!-- Contenu -->
        <div class="sidebar-panel-group">
            <div class="sidebar-panel-group-title"><?= __('sidebar.content') ?></div>
            <a href="?crawl=<?= $crawlId ?>&page=seo-tags" 
               class="sidebar-panel-item <?= $page === 'seo-tags' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">label</span>
                <span><?= __('sidebar.seo_tags') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=headings" 
               class="sidebar-panel-item <?= $page === 'headings' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">format_h1</span>
                <span><?= __('sidebar.heading_hierarchy') ?></span>
            </a>
            
            <a href="?crawl=<?= $crawlId ?>&page=content-richness" 
               class="sidebar-panel-item <?= $page === 'content-richness' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">format_size</span>
                <span><?= __('sidebar.content_richness') ?></span>
            </a>

            <a href="?crawl=<?= $crawlId ?>&page=duplication" 
               class="sidebar-panel-item <?= $page === 'duplication' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">content_copy</span>
                <span><?= __('sidebar.duplication') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=structured-data" 
               class="sidebar-panel-item <?= $page === 'structured-data' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">data_object</span>
                <span><?= __('sidebar.structured_data') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=extractions" 
               class="sidebar-panel-item <?= $page === 'extractions' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">code</span>
                <span><?= __('sidebar.extractions') ?></span>
            </a>
        </div>
        
        <!-- Maillage -->
        <div class="sidebar-panel-group">
            <div class="sidebar-panel-group-title"><?= __('sidebar.linking') ?></div>
            <a href="?crawl=<?= $crawlId ?>&page=inlinks" 
               class="sidebar-panel-item <?= $page === 'inlinks' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">link</span>
                <span><?= __('sidebar.inlinks') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=outlinks" 
               class="sidebar-panel-item <?= $page === 'outlinks' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">open_in_new</span>
                <span><?= __('sidebar.outlinks') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=pagerank" 
               class="sidebar-panel-item <?= $page === 'pagerank' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">star</span>
                <span><?= __('sidebar.pagerank') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=pagerank-leak"
               class="sidebar-panel-item <?= $page === 'pagerank-leak' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">sprint</span>
                <span><?= __('sidebar.pagerank_leak') ?></span>
            </a>
        </div>
    </div>
    
    <!-- Section Crawl Comparison -->
    <div class="sidebar-panel-section" data-section="comparison" style="<?= $activeSection !== 'comparison' ? 'display: none;' : '' ?>">
        <div class="sidebar-panel-header">
            <span class="material-symbols-outlined">compare_arrows</span>
            <span><?= __('sidebar.crawl_comparison') ?></span>
            <button class="sidebar-panel-close" onclick="closeSidebarPanel()">
                <span class="material-symbols-outlined">chevron_left</span>
            </button>
        </div>

        <!-- Vue d'ensemble -->
        <div class="sidebar-panel-group">
            <a href="?crawl=<?= $crawlId ?>&page=comparison-overview<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'comparison-overview' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">dashboard</span>
                <span><?= __('sidebar.overview') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=new-urls<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'new-urls' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">add_circle</span>
                <span><?= __('sidebar.new_urls') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=lost-urls<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'lost-urls' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">remove_circle</span>
                <span><?= __('sidebar.lost_urls') ?></span>
            </a>
        </div>

        <!-- Accessibilité & Moteur -->
        <div class="sidebar-panel-group">
            <div class="sidebar-panel-group-title"><?= __('sidebar.engine_accessibility') ?></div>
            <a href="?crawl=<?= $crawlId ?>&page=accessibility-comparison<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'accessibility-comparison' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">folder</span>
                <span><?= __('sidebar.indexability') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=code-changes<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'code-changes' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">http</span>
                <span><?= __('sidebar.response_codes') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=depth-comparison<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'depth-comparison' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">layers</span>
                <span><?= __('sidebar.depth_levels') ?></span>
            </a>
        </div>

        <!-- Contenu -->
        <div class="sidebar-panel-group">
            <div class="sidebar-panel-group-title"><?= __('sidebar.content') ?></div>
            <a href="?crawl=<?= $crawlId ?>&page=seo-tags-comparison<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'seo-tags-comparison' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">label</span>
                <span><?= __('sidebar.seo_tags') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=headings-comparison<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'headings-comparison' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">format_h1</span>
                <span><?= __('sidebar.heading_hierarchy') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=content-richness-comparison<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'content-richness-comparison' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">format_size</span>
                <span><?= __('sidebar.content_richness') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=duplication-comparison<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'duplication-comparison' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">content_copy</span>
                <span><?= __('sidebar.duplication') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=structured-data-comparison<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'structured-data-comparison' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">data_object</span>
                <span><?= __('sidebar.structured_data') ?></span>
            </a>
        </div>

        <!-- Maillage -->
        <div class="sidebar-panel-group">
            <div class="sidebar-panel-group-title"><?= __('sidebar.linking') ?></div>
            <a href="?crawl=<?= $crawlId ?>&page=inlinks-comparison<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'inlinks-comparison' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">link</span>
                <span><?= __('sidebar.inlinks') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=outlinks-comparison<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'outlinks-comparison' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">open_in_new</span>
                <span><?= __('sidebar.outlinks') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=pagerank-comparison<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'pagerank-comparison' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">star</span>
                <span><?= __('sidebar.pagerank') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=pagerank-leak-comparison<?= $compareId ? '&compare=' . $compareId : '' ?>"
               class="sidebar-panel-item <?= $page === 'pagerank-leak-comparison' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">sprint</span>
                <span><?= __('sidebar.pagerank_leak') ?></span>
            </a>
        </div>
    </div>

    <!-- Section Data Explorer -->
    <div class="sidebar-panel-section" data-section="explorer" style="<?= $activeSection !== 'explorer' ? 'display: none;' : '' ?>">
        <div class="sidebar-panel-header">
            <span class="material-symbols-outlined">travel_explore</span>
            <span><?= __('sidebar.data_explorer') ?></span>
            <button class="sidebar-panel-close" onclick="closeSidebarPanel()">
                <span class="material-symbols-outlined">chevron_left</span>
            </button>
        </div>
        
        <div class="sidebar-panel-group">
            <a href="?crawl=<?= $crawlId ?>&page=url-explorer" 
               class="sidebar-panel-item <?= $page === 'url-explorer' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">link</span>
                <span><?= __('sidebar.url_explorer') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=link-explorer" 
               class="sidebar-panel-item <?= $page === 'link-explorer' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">hub</span>
                <span><?= __('sidebar.link_explorer') ?></span>
            </a>
            <a href="?crawl=<?= $crawlId ?>&page=sql-explorer" 
               class="sidebar-panel-item <?= $page === 'sql-explorer' ? 'active' : '' ?>">
                <span class="material-symbols-outlined">database</span>
                <span><?= __('sidebar.sql_explorer') ?></span>
            </a>
        </div>
    </div>
</aside>

<script>
// =============================================
// Navigation Icon Rail + Sidebar Panel
// =============================================

const sidebarPanel = document.getElementById('sidebarPanel');
const iconRailItems = document.querySelectorAll('.icon-rail-item[data-section]');

// Gérer le clic sur les éléments de l'icon rail
iconRailItems.forEach(item => {
    item.addEventListener('click', function(e) {
        // Ne pas gérer les liens directs
        if (this.classList.contains('icon-rail-link')) return;
        
        const section = this.dataset.section;
        
        // Si ce panneau est déjà ouvert, le fermer
        if (this.classList.contains('panel-open') && sidebarPanel.classList.contains('open')) {
            closeSidebarPanel();
            // Retirer panel-open de tous les éléments
            iconRailItems.forEach(i => i.classList.remove('panel-open'));
            return;
        }
        
        // Retirer panel-open de tous les éléments
        iconRailItems.forEach(i => i.classList.remove('panel-open'));
        
        // Ajouter panel-open à cet élément (pas active, car active = page courante)
        this.classList.add('panel-open');
        
        // Afficher la section correspondante dans le panneau
        showPanelSection(section);
        
        // Ouvrir le panneau
        openSidebarPanel();
        
        // Sauvegarder l'état
        localStorage.setItem('sidebar-active-section', section);
        localStorage.setItem('sidebar-panel-open', 'true');
    });
});

// Afficher une section spécifique du panneau
function showPanelSection(sectionName) {
    const sections = document.querySelectorAll('.sidebar-panel-section');
    sections.forEach(section => {
        if (section.dataset.section === sectionName) {
            section.style.display = 'block';
        } else {
            section.style.display = 'none';
        }
    });
}

// Fermer le panneau latéral
function closeSidebarPanel() {
    sidebarPanel.classList.remove('scrollable'); // Retirer scroll immédiatement
    sidebarPanel.classList.remove('open');
    // Retirer panel-open de tous les éléments
    document.querySelectorAll('.icon-rail-item').forEach(i => i.classList.remove('panel-open'));
    localStorage.setItem('sidebar-panel-open', 'false');
}

// Ouvrir le panneau avec délai pour le scroll
function openSidebarPanel() {
    sidebarPanel.classList.add('open');
    // Activer le scroll après l'animation (300ms)
    setTimeout(() => {
        if (sidebarPanel.classList.contains('open')) {
            sidebarPanel.classList.add('scrollable');
        }
    }, 300);
}

// Changer le crawl de comparaison
function changeCompareCrawl(compareId) {
    const url = new URL(window.location);
    if (compareId) {
        url.searchParams.set('compare', compareId);
    } else {
        url.searchParams.delete('compare');
    }
    const currentPage = url.searchParams.get('page');
    if (!['comparison-overview', 'new-urls', 'lost-urls'].includes(currentPage)) {
        url.searchParams.set('page', 'comparison-overview');
    }
    window.location = url.toString();
}

// Restaurer l'état au chargement
document.addEventListener('DOMContentLoaded', function() {
    // Vérifier si on est sur une page à lien direct (sans panneau)
    const directLinkActive = document.querySelector('.icon-rail-link.active');
    
    if (directLinkActive) {
        // On est sur une page à lien direct, ne pas ouvrir le panneau
        sidebarPanel.classList.remove('open');
    } else {
        // Par défaut, ouvrir le panneau sur la section active
        const activeItem = document.querySelector('.icon-rail-item.active[data-section]');
        if (activeItem && activeItem.dataset.section) {
            showPanelSection(activeItem.dataset.section);
            // Ouvrir le panneau par défaut si on a une section avec sous-menu active
            openSidebarPanel();
            // Ajouter panel-open aussi (même si active, pour la cohérence)
            activeItem.classList.add('panel-open');
        }
    }
});
</script>
