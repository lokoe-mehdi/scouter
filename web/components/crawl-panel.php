<?php
/**
 * Composant Crawl Panel - Panneau latéral de monitoring des crawls
 * 
 * Ce composant affiche un drawer moderne qui glisse depuis la droite
 * pour afficher le monitoring des crawls en temps réel.
 * 
 * Supporte plusieurs crawls simultanés avec un sélecteur.
 */
?>

<!-- Overlay pour backdrop blur (optionnel) -->
<div class="crawl-panel-overlay" id="crawlPanelOverlay" onclick="CrawlPanel.minimize()"></div>

<!-- Panneau latéral de monitoring -->
<div class="crawl-panel" id="crawlPanel">
    <!-- Header fixe -->
    <div class="crawl-panel-header">
        <div class="crawl-panel-header-left">
            <div class="crawl-panel-status-dot" id="crawlPanelStatusDot"></div>
            <div class="crawl-panel-title">
                <span id="crawlPanelProjectName">Crawl en cours</span>
                <span class="crawl-panel-badge" id="crawlPanelBadge">En attente</span>
            </div>
        </div>
        <div class="crawl-panel-header-actions">
            <!-- Sélecteur de crawl (visible si plusieurs crawls) -->
            <button class="crawl-panel-btn-icon crawl-panel-btn-selector" id="crawlPanelSelectorBtn" onclick="CrawlPanel.toggleCrawlList()" title="Autres crawls en cours" style="display: none;">
                <span class="material-symbols-outlined">swap_vert</span>
                <span class="crawl-panel-selector-badge" id="crawlPanelSelectorBadge">2</span>
            </button>
            <button class="crawl-panel-btn-icon" onclick="CrawlPanel.minimize()" title="Réduire">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
    </div>
    
    <!-- Liste des crawls en cours (dropdown) -->
    <div class="crawl-panel-crawl-list" id="crawlPanelCrawlList" style="display: none;">
        <div class="crawl-panel-crawl-list-header">
            <span class="material-symbols-outlined">list</span>
            Crawls en cours
        </div>
        <div class="crawl-panel-crawl-list-items" id="crawlPanelCrawlListItems">
            <!-- Items dynamiques -->
        </div>
    </div>

    <!-- Zone KPI compacte -->
    <div class="crawl-panel-kpis">
        <div class="crawl-panel-kpi">
            <span class="material-symbols-outlined">link</span>
            <div class="crawl-panel-kpi-data">
                <span class="crawl-panel-kpi-value" id="crawlPanelUrlsFound">0</span>
                <span class="crawl-panel-kpi-label">URLs trouvées</span>
            </div>
        </div>
        <div class="crawl-panel-kpi">
            <span class="material-symbols-outlined">check_circle</span>
            <div class="crawl-panel-kpi-data">
                <span class="crawl-panel-kpi-value" id="crawlPanelUrlsCrawled">0</span>
                <span class="crawl-panel-kpi-label">URLs crawlées</span>
            </div>
        </div>
        <div class="crawl-panel-kpi">
            <span class="material-symbols-outlined">speed</span>
            <div class="crawl-panel-kpi-data">
                <span class="crawl-panel-kpi-value" id="crawlPanelSpeed">0</span>
                <span class="crawl-panel-kpi-label">URLs/sec</span>
            </div>
        </div>
        <div class="crawl-panel-kpi">
            <span class="material-symbols-outlined">percent</span>
            <div class="crawl-panel-kpi-data">
                <span class="crawl-panel-kpi-value" id="crawlPanelProgress">0%</span>
                <span class="crawl-panel-kpi-label">Progression</span>
            </div>
        </div>
    </div>

    <!-- Barre de progression globale -->
    <div class="crawl-panel-progress-bar">
        <div class="crawl-panel-progress-fill" id="crawlPanelProgressBar" style="width: 0%"></div>
    </div>

    <!-- Zone Terminal / Logs -->
    <div class="crawl-panel-terminal" id="crawlPanelTerminal">
        <div class="crawl-panel-log-line crawl-panel-log-system">
            En attente du démarrage du crawl...
        </div>
    </div>

    <!-- Bouton scroll to bottom (visible si on scroll manuellement) -->
    <button class="crawl-panel-scroll-btn" id="crawlPanelScrollBtn" onclick="CrawlPanel.scrollToBottom()" style="display: none;">
        <span class="material-symbols-outlined">keyboard_double_arrow_down</span>
    </button>

    <!-- Footer avec actions -->
    <div class="crawl-panel-footer">
        <button class="crawl-panel-btn-stop" id="crawlPanelStopBtn" onclick="CrawlPanel.stopCrawl()">
            <span class="material-symbols-outlined">stop_circle</span>
            Arrêter
        </button>
        <button class="crawl-panel-btn-resume" id="crawlPanelResumeBtn" onclick="CrawlPanel.resumeCrawl()" style="display: none;">
            <span class="material-symbols-outlined">play_arrow</span>
            Reprendre
        </button>
        <a href="#" class="crawl-panel-btn-dashboard" id="crawlPanelDashboardBtn" style="display: none;">
            <span class="material-symbols-outlined">bar_chart</span>
            Voir le rapport
        </a>
    </div>
</div>

<!-- Notification minimisée (badge flottant) -->
<div class="crawl-panel-minimized" id="crawlPanelMinimized">
    <div class="crawl-panel-minimized-content" onclick="CrawlPanel.open()">
        <div class="crawl-panel-minimized-dot"></div>
        <span class="crawl-panel-minimized-count" id="crawlPanelMinimizedCount" style="display: none;">2</span>
        <span class="crawl-panel-minimized-text" id="crawlPanelMinimizedText">Crawl en cours</span>
        <span class="crawl-panel-minimized-progress" id="crawlPanelMinimizedProgress">0%</span>
    </div>
    <button class="crawl-panel-minimized-close" onclick="CrawlPanel.hideNotification(event)" title="Masquer cette notification">
        <span class="material-symbols-outlined">close</span>
    </button>
</div>
