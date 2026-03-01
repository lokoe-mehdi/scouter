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
                <span id="crawlPanelProjectName"><?= __('crawl_panel.crawl_running') ?></span>
                <span class="crawl-panel-badge" id="crawlPanelBadge"><?= __('crawl_panel.status_pending') ?></span>
            </div>
        </div>
        <div class="crawl-panel-header-actions">
            <!-- Sélecteur de crawl (visible si plusieurs crawls) -->
            <button class="crawl-panel-btn-icon crawl-panel-btn-selector" id="crawlPanelSelectorBtn" onclick="CrawlPanel.toggleCrawlList()" title="<?= __('crawl_panel.other_crawls') ?>" style="display: none;">
                <span class="material-symbols-outlined">swap_vert</span>
                <span class="crawl-panel-selector-badge" id="crawlPanelSelectorBadge">2</span>
            </button>
            <button class="crawl-panel-btn-icon" onclick="CrawlPanel.minimize()" title="<?= __('simple_table.btn_collapse') ?>">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
    </div>
    
    <!-- Liste des crawls en cours (dropdown) -->
    <div class="crawl-panel-crawl-list" id="crawlPanelCrawlList" style="display: none;">
        <div class="crawl-panel-crawl-list-header">
            <span class="material-symbols-outlined">list</span>
            <?= __('crawl_panel.crawls_running') ?>
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
                <span class="crawl-panel-kpi-label"><?= __('crawl_panel.label_urls_found') ?></span>
            </div>
        </div>
        <div class="crawl-panel-kpi">
            <span class="material-symbols-outlined">check_circle</span>
            <div class="crawl-panel-kpi-data">
                <span class="crawl-panel-kpi-value" id="crawlPanelUrlsCrawled">0</span>
                <span class="crawl-panel-kpi-label"><?= __('crawl_panel.label_urls_crawled') ?></span>
            </div>
        </div>
        <div class="crawl-panel-kpi">
            <span class="material-symbols-outlined">speed</span>
            <div class="crawl-panel-kpi-data">
                <span class="crawl-panel-kpi-value" id="crawlPanelSpeed">0</span>
                <span class="crawl-panel-kpi-label"><?= __('crawl_panel.label_speed') ?></span>
            </div>
        </div>
        <div class="crawl-panel-kpi">
            <span class="material-symbols-outlined">percent</span>
            <div class="crawl-panel-kpi-data">
                <span class="crawl-panel-kpi-value" id="crawlPanelProgress">0%</span>
                <span class="crawl-panel-kpi-label"><?= __('crawl_panel.label_progress') ?></span>
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
            <?= __('crawl_panel.waiting_start') ?>
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
            <?= __('crawl_panel.btn_stop') ?>
        </button>
        <button class="crawl-panel-btn-resume" id="crawlPanelResumeBtn" onclick="CrawlPanel.resumeCrawl()" style="display: none;">
            <span class="material-symbols-outlined">play_arrow</span>
            <?= __('crawl_panel.btn_resume') ?>
        </button>
        <a href="#" class="crawl-panel-btn-dashboard" id="crawlPanelDashboardBtn" style="display: none;">
            <span class="material-symbols-outlined">bar_chart</span>
            <?= __('crawl_panel.btn_view_report') ?>
        </a>
    </div>
</div>

<!-- Notification minimisée (badge flottant) -->
<div class="crawl-panel-minimized" id="crawlPanelMinimized">
    <div class="crawl-panel-minimized-content" onclick="CrawlPanel.open()">
        <div class="crawl-panel-minimized-dot"></div>
        <span class="crawl-panel-minimized-count" id="crawlPanelMinimizedCount" style="display: none;">2</span>
        <span class="crawl-panel-minimized-text" id="crawlPanelMinimizedText"><?= __('crawl_panel.minimized_text') ?></span>
        <span class="crawl-panel-minimized-progress" id="crawlPanelMinimizedProgress">0%</span>
    </div>
    <button class="crawl-panel-minimized-close" onclick="CrawlPanel.hideNotification(event)" title="<?= __('crawl_panel.hide_notification') ?>">
        <span class="material-symbols-outlined">close</span>
    </button>
</div>
