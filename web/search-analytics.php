<?php
/**
 * Search Analytics — project-level Google Search Console view.
 *
 * Project-scoped (the crawl dashboard is crawl-scoped; GSC data isn't tied to a
 * crawl). Reuses the site's design tokens + the KPI-card / table / chart idioms.
 * Data is loaded via /api/gsc/* (see App\Http\Controllers\GscController); the
 * connect/disconnect actions go through web/gsc.php.
 */

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/config/i18n.php');

use App\Auth\Auth;
use App\Database\ProjectRepository;
use App\Google\GoogleOAuthClient;
use App\Gsc\ConnectorRepository;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}

$currentUserId = $auth->getCurrentUserId();
$currentEmail  = $auth->getCurrentEmail();
$isAdmin       = $auth->isAdmin();

$projectId = isset($_GET['project']) ? (int) $_GET['project'] : 0;
if (!$projectId || !$auth->canAccessProject($projectId)) {
    header('Location: index.php');
    exit;
}

$projects = new ProjectRepository();
$project  = $projects->getById($projectId);
if (!$project) {
    header('Location: index.php');
    exit;
}
$domainName = $project->name ?? 'Unknown';
$canManage  = $auth->canManageProject($projectId);

$repo      = new ConnectorRepository();
$connector = $repo->getByProject($projectId);
$configured = GoogleOAuthClient::isConfigured();

// Project URL-categorization rules → reusable as a "category" URL filter + a
// coloured badge column in the URL views (same rules as the crawl category).
$gscCategories = $connector ? \App\Gsc\GscCategories::list($projectId) : [];

$csrf = $_SESSION['csrf_token'] ?? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32)));

// Flash message from the OAuth flow.
$flash = $_GET['gsc'] ?? null;
$flashMsg = $_GET['gsc_msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getInstance()->getLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scouter - Search Analytics - <?= htmlspecialchars($domainName) ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/responsive.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/project-redesign.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/data-table.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/filter-bar.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/gsc.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/vendor/material-symbols/material-symbols.css" />
    <script src="assets/i18n.js"></script>
    <script>ScouterI18n.init(<?= I18n::getInstance()->getJsTranslations() ?>, <?= json_encode(I18n::getInstance()->getLang()) ?>);</script>
    <script src="assets/highcharts.js"></script>
    <script src="assets/filter-bar.js?v=<?= time() ?>"></script>
    <script src="assets/downloads.js?v=<?= time() ?>"></script>
    <script src="assets/confirm-modal.js?v=<?= time() ?>"></script>
</head>
<body style="background: #f4f5f7;">
    <?php $headerContext = 'project'; include 'components/top-header.php'; ?>

    <div class="pj">
        <nav class="pj-breadcrumb">
            <a href="index.php"><?= __('project.breadcrumb_projects') ?></a>
            <span class="material-symbols-outlined">chevron_right</span>
            <a href="project.php?id=<?= $projectId ?>"><?= htmlspecialchars($domainName) ?></a>
            <span class="material-symbols-outlined">chevron_right</span>
            <span class="pj-breadcrumb-current">Search Analytics</span>
        </nav>

        <?php if ($flash === 'error'): ?>
            <div class="gsc-flash gsc-flash--error"><span class="material-symbols-outlined">error</span><?= htmlspecialchars($flashMsg ?: __('gsc.error')) ?></div>
        <?php elseif ($flash === 'disconnecting'): ?>
            <div class="gsc-flash gsc-flash--info"><span class="material-symbols-outlined">info</span><?= htmlspecialchars($flashMsg ?: __('gsc.disconnecting')) ?></div>
        <?php endif; ?>

        <?php if (!$connector): ?>
            <!-- ============ NOT CONNECTED ============ -->
            <div class="gsc-connect-card">
                <div class="gsc-connect-icon"><span class="material-symbols-outlined">search_insights</span></div>
                <h1><?= __('gsc.connect_title') ?></h1>
                <p><?= __('gsc.connect_desc') ?></p>
                <?php if (!$configured): ?>
                    <p class="gsc-connect-warn"><?= __('gsc.oauth_not_configured') ?></p>
                <?php elseif (!$canManage): ?>
                    <p class="gsc-connect-warn"><?= __('gsc.connect_manager_only') ?></p>
                <?php else: ?>
                    <a class="gsc-btn gsc-btn--primary" href="/gsc/connect?project=<?= $projectId ?>">
                        <span class="material-symbols-outlined">link</span> <?= __('gsc.connect_button') ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- ============ CONNECTED ============ -->
            <div class="gsc-header">
                <div class="gsc-header-info">
                    <h1>Search Analytics</h1>
                    <div class="gsc-prop">
                        <span class="material-symbols-outlined">public</span>
                        <code><?= htmlspecialchars($connector->site_url) ?></code>
                        <span class="gsc-badge gsc-badge--<?= htmlspecialchars($connector->status) ?>" id="gscStatusBadge"><?= htmlspecialchars($connector->status) ?></span>
                    </div>
                    <div class="gsc-sub" id="gscSubInfo">
                        <?php if ($connector->google_email): ?><?= __('gsc.account') ?> <?= htmlspecialchars($connector->google_email) ?> · <?php endif; ?>
                        <span id="gscLastSync"><?= $connector->last_synced_date ? __('gsc.data_until', ['date' => htmlspecialchars($connector->last_synced_date)]) : __('gsc.backfill_running_short') ?></span>
                    </div>
                </div>
                <div class="gsc-header-actions">
                    <!-- Single date-range control (top-right) → custom calendar picker -->
                    <div class="gsc-daterange" id="gscDaterange">
                        <button type="button" class="gsc-daterange-btn" id="gscDateBtn">
                            <span class="material-symbols-outlined">calendar_month</span>
                            <span id="gscDateLabel"><?= __('gsc.range_28d') ?></span>
                            <span class="material-symbols-outlined gsc-caret">expand_more</span>
                        </button>
                        <div class="gsc-datepicker" id="gscDateMenu">
                            <div class="gsc-dp-presets" id="gscDpPresets">
                                <button type="button" class="gsc-dp-preset active" data-days="7"><?= __('gsc.range_7d') ?></button>
                                <button type="button" class="gsc-dp-preset" data-days="28"><?= __('gsc.range_28d') ?></button>
                                <button type="button" class="gsc-dp-preset" data-days="90"><?= __('gsc.range_90d') ?></button>
                                <button type="button" class="gsc-dp-preset" data-days="180"><?= __('gsc.range_180d') ?></button>
                                <button type="button" class="gsc-dp-preset" data-days="365"><?= __('gsc.range_365d') ?></button>
                            </div>
                            <div class="gsc-dp-cal">
                                <div class="gsc-dp-head">
                                    <button type="button" class="gsc-dp-nav" id="gscDpPrev"><span class="material-symbols-outlined">chevron_left</span></button>
                                    <span class="gsc-dp-month" id="gscDpMonth"></span>
                                    <button type="button" class="gsc-dp-nav" id="gscDpNext"><span class="material-symbols-outlined">chevron_right</span></button>
                                </div>
                                <div class="gsc-dp-grid" id="gscDpGrid"></div>
                                <div class="gsc-dp-compare">
                                    <span class="gsc-dp-compare-label"><span class="material-symbols-outlined">compare_arrows</span> <?= __('gsc.compare_to') ?></span>
                                    <div class="gsc-segmented gsc-segmented--sm" id="gscCompare">
                                        <button type="button" data-cmp="none" class="active"><?= __('gsc.compare_none') ?></button>
                                        <button type="button" data-cmp="previous"><?= __('gsc.compare_previous') ?></button>
                                        <button type="button" data-cmp="year"><?= __('gsc.compare_year') ?></button>
                                    </div>
                                </div>
                                <div class="gsc-dp-foot">
                                    <span class="gsc-dp-range" id="gscDpRange">—</span>
                                    <button type="button" class="gsc-btn gsc-btn--primary gsc-btn--sm" id="gscDateApply"><?= __('gsc.apply') ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php if ($canManage): ?>
                    <form method="post" action="/gsc/disconnect" id="gscDisconnectForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="project_id" value="<?= $projectId ?>">
                        <button type="submit" class="gsc-btn gsc-btn--ghost gsc-btn--icon" title="<?= htmlspecialchars(__('gsc.disconnect')) ?>"><span class="material-symbols-outlined">link_off</span></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (in_array($connector->status, ['backfilling', 'connecting'], true)): ?>
                <div class="gsc-flash gsc-flash--info"><span class="material-symbols-outlined">hourglass_top</span>
                    <?= __('gsc.backfill_running') ?></div>
            <?php elseif ($connector->status === 'error'): ?>
                <div class="gsc-flash gsc-flash--error"><span class="material-symbols-outlined">error</span>
                    <?= htmlspecialchars($connector->last_error ?: __('gsc.sync_error')) ?>
                    <?php if ($canManage): ?><a href="/gsc/connect?project=<?= $projectId ?>"><?= __('gsc.reconnect') ?></a><?php endif; ?></div>
            <?php endif; ?>

            <!-- Mode toggle + anon toggle -->
            <div class="gsc-controls">
                <div class="gsc-segmented" id="gscModes">
                    <button type="button" data-mode="keywords" class="active"><?= __('gsc.mode_keywords') ?></button>
                    <button type="button" data-mode="urls"><?= __('gsc.mode_urls') ?></button>
                    <button type="button" data-mode="both"><?= __('gsc.mode_both') ?></button>
                </div>
                <label class="gsc-switch" title="<?= htmlspecialchars(__('gsc.include_anon_title')) ?>">
                    <input type="checkbox" id="gscIncludeAnon" checked>
                    <span class="gsc-switch-track"><span class="gsc-switch-thumb"></span></span>
                    <span class="gsc-switch-label"><?= __('gsc.include_anon') ?></span>
                </label>
            </div>

            <!-- Filter bar (FilterBar — same engine as the URL Explorer) -->
            <div class="filter-bar-container">
                <span class="gsc-filter-label"><span class="material-symbols-outlined">filter_alt</span> <?= __('gsc.filter_label') ?></span>
                <div class="filter-chips-container" id="filterChipsContainer"></div>
                <button class="btn-add-filter" onclick="openFieldSelector(event)">
                    <span class="material-symbols-outlined">add</span> <?= __('gsc.add_filter') ?>
                </button>
                <button class="btn-clear-filters" id="btnClearAll" style="display:none" onclick="clearFilters()">
                    <span class="material-symbols-outlined">close</span> <?= __('gsc.clear_all') ?>
                </button>
            </div>

            <!-- KPI cards -->
            <div class="gsc-kpis" id="gscKpis">
                <div class="gsc-kpi" data-kpi="clicks"><span class="gsc-kpi-label"><?= __('gsc.metric_clicks') ?></span><span class="gsc-kpi-val">—</span><span class="gsc-kpi-delta" style="display:none"></span></div>
                <div class="gsc-kpi" data-kpi="impressions"><span class="gsc-kpi-label"><?= __('gsc.metric_impressions') ?></span><span class="gsc-kpi-val">—</span><span class="gsc-kpi-delta" style="display:none"></span></div>
                <div class="gsc-kpi" data-kpi="ctr"><span class="gsc-kpi-label"><?= __('gsc.metric_ctr') ?></span><span class="gsc-kpi-val">—</span><span class="gsc-kpi-delta" style="display:none"></span></div>
                <div class="gsc-kpi" data-kpi="position"><span class="gsc-kpi-label"><?= __('gsc.metric_position_avg') ?></span><span class="gsc-kpi-val">—</span><span class="gsc-kpi-delta" style="display:none"></span></div>
            </div>

            <!-- Time series -->
            <div class="gsc-card">
                <div class="gsc-card-head">
                    <h2><?= __('gsc.evolution') ?></h2>
                    <div class="gsc-segmented gsc-segmented--sm" id="gscGranularity">
                        <button type="button" data-gran="day" class="active"><?= __('gsc.gran_day') ?></button>
                        <button type="button" data-gran="week"><?= __('gsc.gran_week') ?></button>
                        <button type="button" data-gran="month"><?= __('gsc.gran_month') ?></button>
                    </div>
                </div>
                <div id="gscChart" class="gsc-chart"></div>
            </div>

            <!-- Data table (dashboard look) -->
            <div class="gsc-card">
                <div class="gsc-card-head">
                    <h2 id="gscTableTitle"><?= __('gsc.mode_keywords') ?></h2>
                    <button type="button" class="gsc-btn gsc-btn--ghost gsc-btn--sm" id="gscExport"><span class="material-symbols-outlined">download</span> <?= __('gsc.export_csv') ?></button>
                </div>
                <div class="gsc-toolbar" id="gscToolbarTop"></div>
                <div class="gsc-table-wrap">
                    <table class="data-table gsc-table" id="gscTable">
                        <thead id="gscThead"></thead>
                        <tbody id="gscTbody"></tbody>
                    </table>
                    <div class="gsc-table-empty" id="gscEmpty" style="display:none"><?= __('gsc.no_data') ?></div>
                </div>
                <div class="gsc-toolbar" id="gscToolbarBottom"></div>
            </div>

            <!-- FilterBar popovers scaffolding -->
            <div class="filter-popover-overlay" id="popoverOverlay" onclick="closeAllPopovers()"></div>
            <div class="filter-popover" id="fieldSelectorPopover">
                <div class="popover-header">
                    <span class="popover-title"><?= __('gsc.add_filter') ?></span>
                    <button class="popover-close" onclick="closeAllPopovers()"><span class="material-symbols-outlined">close</span></button>
                </div>
                <div class="popover-field-list">
                    <div class="popover-field-item" onclick="selectField('query')">
                        <span class="material-symbols-outlined">search</span> <?= __('gsc.col_keyword') ?>
                    </div>
                    <div class="popover-field-item" onclick="selectField('url')">
                        <span class="material-symbols-outlined">link</span> <?= __('gsc.col_url') ?>
                    </div>
                    <?php if (!empty($gscCategories)): ?>
                    <div class="popover-field-item" onclick="selectField('category')">
                        <span class="material-symbols-outlined">label</span> <?= __('url_explorer.field_category') ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="filter-popover" id="filterConfigPopover">
                <div class="popover-header">
                    <span class="popover-title" id="configPopoverTitle"><?= __('gsc.configure') ?></span>
                    <button class="popover-close" onclick="closeAllPopovers()"><span class="material-symbols-outlined">close</span></button>
                </div>
                <div class="popover-config" id="popoverConfigContent"></div>
            </div>
        <?php endif; ?>
    </div>

    <script>
      window.GSC = {
        projectId: <?= $projectId ?>,
        status: <?= json_encode($connector->status ?? null) ?>,
        connected: <?= $connector ? 'true' : 'false' ?>,
        // Anchor date ranges on the last day that actually has data (like GSC),
        // NOT on today-2 — otherwise the window is shifted and drops the oldest day.
        lastSynced: <?= json_encode($connector->last_synced_date ?? null) ?>,
        locale: <?= json_encode(I18n::getInstance()->getLocale()) ?>,
        categories: <?= json_encode($gscCategories, JSON_UNESCAPED_UNICODE) ?>
      };
    </script>
    <script src="assets/gsc.js?v=<?= time() ?>"></script>
</body>
</html>
