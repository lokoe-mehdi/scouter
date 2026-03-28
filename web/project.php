<?php
/**
 * Project Detail Page — Bento Layout
 */
require_once(__DIR__ . '/init.php');

use App\Job\JobManager;
use App\Database\ProjectRepository;
use App\Database\CrawlRepository;
use App\Auth\Auth;

$jobManager = new JobManager();
$projects = new ProjectRepository();
$crawlRepo = new CrawlRepository();
$auth = new Auth();

$currentUserId = $auth->getCurrentUserId();
$canCreate = $auth->canCreate();

$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$projectId) { header('Location: index.php'); exit; }

$project = $projects->getById($projectId);
if (!$project) { header('Location: index.php'); exit; }

$canManage = $auth->canManageProject($projectId);
$isOwner = ($project->user_id ?? 0) == $currentUserId;
$domainName = $project->domain ?? $project->name ?? 'Unknown';

// Crawls
$projectCrawls = $crawlRepo->getByProjectId($projectId);
$crawls = [];
$hasRunningCrawl = false;
foreach ($projectCrawls as $crawl) {
    $dir = $crawl->path ?? $crawl->id;
    $job = $crawl->path ? $jobManager->getJobByProject($crawl->path) : null;
    $jobStatus = $job ? $job->status : ($crawl->status ?? 'finished');
    $timestamp = strtotime($crawl->started_at ?? 'now');
    if (in_array($jobStatus, ['running', 'queued', 'pending', 'processing'])) $hasRunningCrawl = true;
    $crawls[] = (object)[
        'dir' => $dir, 'crawl_id' => $crawl->id, 'domain' => $crawl->domain,
        'date' => date('d/m/Y H:i', $timestamp), 'timestamp' => $timestamp,
        'stats' => ['urls' => $crawl->urls ?? 0, 'crawled' => $crawl->crawled ?? 0, 'compliant' => $crawl->compliant ?? 0],
        'job_status' => $jobStatus, 'in_progress' => $crawl->in_progress ?? 0,
        'config' => json_decode($crawl->config ?? '{}', true), 'crawl_type' => $crawl->crawl_type ?? 'spider',
    ];
}
usort($crawls, fn($a, $b) => $b->crawl_id - $a->crawl_id);

$lastFinished = null;
foreach ($crawls as $c) {
    if (in_array($c->job_status, ['completed', 'stopped', 'failed'])) { $lastFinished = $c; break; }
}

$kpiUrls = $lastFinished ? $lastFinished->stats['urls'] : 0;
$kpiCrawled = $lastFinished ? $lastFinished->stats['crawled'] : 0;
$kpiCompliant = $lastFinished ? $lastFinished->stats['compliant'] : 0;
$kpiIndexableRate = $kpiCrawled > 0 ? round(($kpiCompliant / $kpiCrawled) * 100, 1) : 0;

$basePath = '';
?>
<!DOCTYPE html>
<html lang="<?= I18n::getInstance()->getLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scouter - <?= htmlspecialchars($domainName) ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/crawl-panel.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/vendor/material-symbols/material-symbols.css" />
    <script src="assets/i18n.js"></script>
    <script>ScouterI18n.init(<?= I18n::getInstance()->getJsTranslations() ?>, <?= json_encode(I18n::getInstance()->getLang()) ?>);</script>
    <script src="assets/tooltip.js?v=<?= time() ?>"></script>
    <script src="assets/confirm-modal.js"></script>
</head>
<body style="background: #f4f5f7;">
    <?php $headerContext = 'project'; include 'components/top-header.php'; ?>

    <div class="pj">
        <nav class="pj-breadcrumb">
            <a href="index.php"><?= __('project.breadcrumb_projects') ?></a>
            <span class="material-symbols-outlined">chevron_right</span>
            <span class="pj-breadcrumb-current">
                <img src="https://t3.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=TYPE,SIZE,URL&url=https://<?= htmlspecialchars($domainName) ?>&size=16" alt="" onerror="this.style.display='none'">
                <?= htmlspecialchars($domainName) ?>
            </span>
        </nav>

        <div class="pj-bento">

            <!-- Quick Action -->
            <div class="pj-card pj-card--action">
                <?php if ($canManage && $canCreate): ?>
                    <?php if ($lastFinished): ?>
                    <button class="pj-btn-launch" onclick="duplicateAndStart('<?= htmlspecialchars($lastFinished->dir) ?>', <?= $project->user_id ?>)" title="<?= __('project.quick_crawl_tooltip') ?>">
                        <span class="material-symbols-outlined">bolt</span>
                        <?= __('project.quick_crawl') ?>
                    </button>
                    <?php endif; ?>
                    <button class="pj-btn-newcrawl" onclick="openNewProjectModal()">
                        <span class="material-symbols-outlined">add</span>
                        <?= __('project.new_crawl') ?>
                    </button>
                <?php else: ?>
                    <div class="pj-btn-launch pj-btn-launch--disabled">
                        <span class="material-symbols-outlined">bolt</span>
                        <?= __('project.quick_crawl') ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Last Report -->
            <div class="pj-card pj-card--report">
                <h2 class="pj-card-title"><?= __('project.last_snapshot') ?></h2>
                <?php if ($lastFinished): ?>
                <div class="pj-gauges">
                    <div class="pj-gauge">
                        <svg viewBox="0 0 36 36" class="pj-gauge-svg">
                            <path class="pj-gauge-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            <path class="pj-gauge-fill pj-gauge-fill--primary" stroke-dasharray="100, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        </svg>
                        <div class="pj-gauge-value"><?= number_format($kpiUrls) ?></div>
                        <div class="pj-gauge-label">URLs</div>
                    </div>
                    <div class="pj-gauge">
                        <svg viewBox="0 0 36 36" class="pj-gauge-svg">
                            <path class="pj-gauge-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            <path class="pj-gauge-fill pj-gauge-fill--info" stroke-dasharray="<?= $kpiUrls > 0 ? round(($kpiCrawled/$kpiUrls)*100) : 0 ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        </svg>
                        <div class="pj-gauge-value"><?= number_format($kpiCrawled) ?></div>
                        <div class="pj-gauge-label"><?= __('header.crawled') ?></div>
                    </div>
                    <div class="pj-gauge">
                        <svg viewBox="0 0 36 36" class="pj-gauge-svg">
                            <path class="pj-gauge-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            <path class="pj-gauge-fill pj-gauge-fill--success" stroke-dasharray="<?= $kpiIndexableRate ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        </svg>
                        <div class="pj-gauge-value"><?= $kpiIndexableRate ?>%</div>
                        <div class="pj-gauge-label"><?= __('columns.indexable') ?></div>
                    </div>
                </div>
                <a href="dashboard.php?crawl=<?= $lastFinished->crawl_id ?>" class="pj-link-report">
                    <span><?= __('project.view_report') ?></span>
                    <span class="material-symbols-outlined">arrow_forward</span>
                </a>
                <?php else: ?>
                <p class="pj-empty-text"><?= __('project.no_crawl_yet') ?></p>
                <?php endif; ?>
            </div>

            <!-- Automation -->
            <div class="pj-card pj-card--schedule" style="position: relative;">
                <!-- Header -->
                <div class="sched-header">
                    <h2 class="pj-card-title" style="margin: 0;"><?= __('project.automation') ?></h2>
                    <label class="pj-toggle">
                        <input type="checkbox" id="scheduleToggle" onchange="toggleSchedule(this.checked)">
                        <span class="pj-toggle-track"><span class="pj-toggle-thumb"></span></span>
                    </label>
                </div>

                <!-- OFF state -->
                <div id="scheduleOff" class="sched-off">
                    <span class="material-symbols-outlined">schedule</span>
                    <span><?= __('project.no_schedule') ?></span>
                </div>

                <!-- ON state -->
                <div id="scheduleConfig" style="display: none;">
                    <!-- Template (above sentence) -->
                    <div class="sched-template">
                        <span class="sched-template-label"><?= __('project.schedule_model') ?></span>
                        <div class="pj-model-selector">
                            <button type="button" class="pj-model-btn" id="modelSelectorBtn" onclick="toggleModelDropdown(event)">
                                <span class="material-symbols-outlined">schedule</span>
                                <span id="modelSelectorText"><?= $lastFinished ? $lastFinished->date : '—' ?></span>
                                <span class="material-symbols-outlined">expand_more</span>
                            </button>
                            <div class="pj-model-dropdown" id="modelDropdown">
                                <?php foreach ($crawls as $c):
                                    if (!in_array($c->job_status, ['completed', 'stopped'])) continue;
                                ?>
                                <div class="pj-model-item" onclick="selectModel(<?= $c->crawl_id ?>, '<?= $c->date ?>')">
                                    <span class="pj-crawl-date"><?= $c->date ?></span>
                                    <span class="pj-crawl-kpi" style="font-size: 0.7rem;"><?= number_format($c->stats['urls']) ?> URLs</span>
                                    <?php if (($c->config['general']['crawl_mode'] ?? 'classic') === 'javascript'): ?>
                                    <span class="pj-pill pj-pill--active">JS</span>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sentence -->
                    <div class="sched-sentence">
                        <?= __('project.sched_run') ?>
                        <select id="schedFreqType" class="sched-pill-select" onchange="schedDirty(); updateScheduleUI()">
                            <option value="minute"><?= __('project.sched_every_minute') ?></option>
                            <option value="daily"><?= __('project.sched_every_day') ?></option>
                            <option value="weekly" selected><?= __('project.sched_every_week') ?></option>
                            <option value="monthly"><?= __('project.sched_every_month') ?></option>
                        </select>
                        <span id="schedMonthDayWrap" style="display: none;">
                            <?= __('project.sched_the') ?>
                            <input type="number" id="schedMonthDay" class="sched-pill-input" value="1" min="1" max="28" oninput="clampInput(this,1,28)" onchange="schedDirty(); updateScheduleSummary()">
                        </span>
                        <span id="schedTimeWrap">
                            <?= __('project.sched_at') ?>
                            <input type="number" id="schedHour" class="sched-pill-input" value="8" min="0" max="23" oninput="clampInput(this,0,23)" onchange="schedDirty(); updateScheduleSummary()">
                            <span style="font-weight: 600;">:</span>
                            <input type="number" id="schedMinute" class="sched-pill-input" value="0" min="0" max="59" step="15" oninput="clampInput(this,0,59)" onchange="schedDirty(); updateScheduleSummary()">
                        </span>
                    </div>

                    <!-- Day chips -->
                    <div id="schedDaysRow" class="sched-days">
                        <?php
                        $days = [
                            'mon' => __('project.day_mon'), 'tue' => __('project.day_tue'),
                            'wed' => __('project.day_wed'), 'thu' => __('project.day_thu'),
                            'fri' => __('project.day_fri'), 'sat' => __('project.day_sat'),
                            'sun' => __('project.day_sun'),
                        ];
                        foreach ($days as $key => $label): ?>
                        <button type="button" class="sched-day <?= $key === 'mon' ? 'sched-day--active' : '' ?>" data-day="<?= $key ?>" onclick="toggleDay(this); schedDirty()">
                            <?= $label ?>
                        </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Next run (summary) -->
                    <div class="sched-next" id="schedSummary">
                        <span class="material-symbols-outlined">event</span>
                        <span id="schedSummaryText"></span>
                    </div>

                    <!-- Save button (contextual) -->
                    <button class="sched-save" id="schedSaveBtn" style="display: none;" onclick="saveSchedule()">
                        <span class="material-symbols-outlined">check</span>
                        <?= __('common.save') ?>
                    </button>
                </div>
            </div>

            <!-- Crawl History -->
            <div class="pj-card pj-card--history">
                <div class="pj-card-header">
                    <h2 class="pj-card-title"><?= __('project.crawl_history') ?></h2>
                    <span class="pj-card-count"><?= count($crawls) ?></span>
                </div>

                <?php if (empty($crawls)): ?>
                <div class="pj-empty">
                    <span class="material-symbols-outlined">search_off</span>
                    <p><?= __('project.no_crawl_yet') ?></p>
                </div>
                <?php else: ?>
                <div class="pj-crawl-list">
                    <?php foreach ($crawls as $crawl):
                        $isInProgress = in_array($crawl->job_status, ['running', 'queued', 'pending', 'processing', 'stopping']);
                        $isFinished = in_array($crawl->job_status, ['completed', 'stopped', 'failed']);

                        $statusClass = 'pj-status--completed'; $statusText = __('index.status_completed');
                        if ($crawl->job_status === 'running' || $crawl->job_status === 'stopping') {
                            $statusClass = 'pj-status--running'; $statusText = __('index.status_running');
                        } elseif (in_array($crawl->job_status, ['queued', 'pending'])) {
                            $statusClass = 'pj-status--queued'; $statusText = __('index.status_queued');
                        } elseif ($crawl->job_status === 'processing') {
                            $statusClass = 'pj-status--processing'; $statusText = __('index.status_processing');
                        } elseif ($crawl->job_status === 'failed') {
                            $statusClass = 'pj-status--failed'; $statusText = __('index.status_failed');
                        } elseif ($crawl->job_status === 'stopped') {
                            $statusClass = 'pj-status--stopped'; $statusText = __('index.status_stopped');
                        }
                    ?>
                    <div class="pj-crawl-row <?= $isFinished ? 'pj-crawl-row--clickable' : '' ?>" <?= $isFinished ? 'onclick="window.location.href=\'dashboard.php?crawl=' . $crawl->crawl_id . '\'"' : '' ?>>
                        <span class="pj-crawl-type" title="<?= ($crawl->crawl_type ?? 'spider') === 'list' ? __('index.mode_url_list') : 'Spider' ?>">
                            <span class="material-symbols-outlined"><?= ($crawl->crawl_type ?? 'spider') === 'list' ? 'list_alt' : 'bolt' ?></span>
                        </span>
                        <div class="pj-crawl-info">
                            <span class="pj-crawl-date"><?= $crawl->date ?></span>
                            <span class="pj-status <?= $statusClass ?>"><?= $statusText ?></span>
                        </div>
                        <div class="pj-crawl-kpis">
                            <?php if (!$isInProgress): ?>
                            <span class="pj-crawl-kpi"><strong><?= number_format($crawl->stats['urls']) ?></strong> URLs</span>
                            <span class="pj-crawl-kpi"><strong><?= number_format($crawl->stats['crawled']) ?></strong> <?= __('header.crawled') ?></span>
                            <span class="pj-crawl-kpi"><strong><?= number_format($crawl->stats['compliant']) ?></strong> <?= __('columns.indexable') ?></span>
                            <?php else: ?>
                            <span class="pj-crawl-kpi" style="color: var(--text-tertiary);">--</span>
                            <?php endif; ?>
                        </div>
                        <div class="pj-crawl-config">
                            <?php if (($crawl->config['general']['crawl_mode'] ?? 'classic') === 'javascript'): ?>
                            <span class="pj-pill pj-pill--active" title="<?= __('index.mode_javascript') ?>">JS</span>
                            <?php endif; ?>
                            <?php if (!empty($crawl->config['advanced']['respect']['robots'])): ?>
                            <span class="pj-pill pj-pill--active" title="<?= __('index.respect_robots') ?>">robots</span>
                            <?php endif; ?>
                            <?php if (($crawl->crawl_type ?? 'spider') !== 'list'): ?>
                            <span class="pj-pill" title="<?= __('index.max_depth') ?>"><?= $crawl->config['general']['depthMax'] ?? '-' ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="pj-crawl-actions" onclick="event.stopPropagation();">
                            <?php if ($isFinished): ?>
                            <a href="dashboard.php?crawl=<?= $crawl->crawl_id ?>" class="pj-icon-btn" title="<?= __('project.view_report') ?>">
                                <span class="material-symbols-outlined">bar_chart</span>
                            </a>
                            <?php endif; ?>
                            <?php if ($canManage): ?>
                            <button class="pj-icon-btn" title="<?= $isInProgress ? __('index.monitoring') : __('index.view_logs') ?>" onclick="openCrawlPanel('<?= htmlspecialchars($crawl->dir) ?>', '<?= htmlspecialchars($domainName) ?>', <?= $crawl->crawl_id ?>)">
                                <span class="material-symbols-outlined">terminal</span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <?php include 'components/crawl-modal.php'; ?>

    <script src="assets/global-status.js"></script>
    <script src="assets/crawl-panel.js?v=<?= time() ?>"></script>
    <script src="assets/app.js"></script>
    <?php include 'components/crawl-panel.php'; ?>

    <script>
    // Quick crawl — no confirmation, instant launch
    async function duplicateAndStart(projectDir, targetUserId) {
        const button = event.target.closest('button');
        const originalHTML = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="material-symbols-outlined spinning">progress_activity</span>';
        try {
            const payload = { project: projectDir };
            if (targetUserId) payload.target_user_id = targetUserId;
            const resp = await fetch('api/projects/duplicate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const data = await resp.json();
            if (data.success) {
                CrawlPanel.start(data.project_dir, data.domain || 'Crawl', data.crawl_id);
                setTimeout(() => window.location.reload(), 2000);
            } else { alert(__('common.error') + ': ' + (data.error || '')); button.disabled = false; button.innerHTML = originalHTML; }
        } catch (e) { alert(__('common.error') + ': ' + e.message); button.disabled = false; button.innerHTML = originalHTML; }
    }

    // Clamp numeric inputs to min/max
    function clampInput(el, min, max) {
        let v = parseInt(el.value);
        if (isNaN(v)) return;
        if (v < min) el.value = min;
        if (v > max) el.value = max;
    }

    // Schedule
    function toggleSchedule(checked) {
        document.getElementById('scheduleConfig').style.display = checked ? '' : 'none';
        document.getElementById('scheduleOff').style.display = checked ? 'none' : '';
        if (checked) { updateScheduleUI(); updateScheduleSummary(); }
        schedDirty();
    }

    function updateScheduleUI() {
        const type = document.getElementById('schedFreqType').value;
        document.getElementById('schedDaysRow').style.display = type === 'weekly' ? '' : 'none';
        document.getElementById('schedMonthDayWrap').style.display = type === 'monthly' ? '' : 'none';
        document.getElementById('schedTimeWrap').style.display = type === 'minute' ? 'none' : '';
        updateScheduleSummary();
    }

    function toggleDay(btn) {
        btn.classList.toggle('sched-day--active');
        updateScheduleSummary();
    }

    function updateScheduleSummary() {
        const type = document.getElementById('schedFreqType').value;
        const h = String(document.getElementById('schedHour').value).padStart(2, '0');
        const m = String(document.getElementById('schedMinute').value).padStart(2, '0');
        const time = h + ':' + m;
        let text = '';
        if (type === 'minute') text = __('project.summary_minute');
        else if (type === 'daily') text = __('project.summary_daily').replace(':time', time);
        else if (type === 'weekly') {
            const days = []; document.querySelectorAll('.sched-day--active').forEach(d => days.push(d.textContent.trim()));
            text = days.length === 0 ? __('project.summary_no_day') : __('project.summary_weekly').replace(':days', days.join(', ')).replace(':time', time);
        } else if (type === 'monthly') {
            text = __('project.summary_monthly').replace(':day', document.getElementById('schedMonthDay').value).replace(':time', time);
        }
        document.getElementById('schedSummaryText').textContent = text;
    }

    // Dirty state — show/hide save button
    function schedDirty() {
        document.getElementById('schedSaveBtn').style.display = '';
    }
    function saveSchedule() {
        const btn = document.getElementById('schedSaveBtn');
        btn.innerHTML = '<span class="material-symbols-outlined spinning">progress_activity</span> Saving...';
        btn.disabled = true;
        // TODO: API call to persist schedule
        setTimeout(() => {
            btn.innerHTML = '<span class="material-symbols-outlined">check_circle</span> Saved!';
            btn.classList.add('sched-save--done');
            setTimeout(() => { btn.style.display = 'none'; btn.disabled = false; btn.classList.remove('sched-save--done'); btn.innerHTML = '<span class="material-symbols-outlined">check</span> ' + __('common.save'); }, 1500);
        }, 600);
    }

    // Model selector dropdown
    function toggleModelDropdown(e) {
        e.stopPropagation();
        document.getElementById('modelDropdown').classList.toggle('show');
    }
    function selectModel(crawlId, dateStr) {
        document.getElementById('modelSelectorText').textContent = dateStr;
        document.getElementById('modelDropdown').classList.remove('show');
    }
    document.addEventListener('click', function(e) {
        const dd = document.getElementById('modelDropdown');
        if (dd && !e.target.closest('#modelSelectorBtn') && !e.target.closest('#modelDropdown')) dd.classList.remove('show');
    });

    // Crawl modal functions
    let extractorCounter = 0;
    let headerCounter = 0;
    let uploadedFileContent = null;

    function openNewProjectModal() {
        document.getElementById('newProjectModal').style.display = 'flex';
        const startUrl = document.getElementById('start_url');
        if (startUrl && !startUrl.value) startUrl.value = 'https://<?= htmlspecialchars($domainName) ?>';
        setTimeout(() => { if (startUrl) startUrl.focus(); }, 100);
        switchCrawlTab('general');
        initDefaultExtractors();
    }
    function closeNewProjectModal() {
        document.getElementById('newProjectModal').style.display = 'none';
        document.getElementById('newProjectForm').reset();
        document.getElementById('formMessage').innerHTML = '';
    }
    function switchCrawlTab(tabName) {
        document.querySelectorAll('.crawl-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabName));
        document.querySelectorAll('.crawl-tab-pane').forEach(p => p.classList.toggle('active', p.id === 'tab-' + tabName));
    }
    function selectCrawlType(type, btn) {
        document.querySelectorAll('.segmented-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('crawl_type').value = type;
        document.getElementById('startUrlGroup').style.display = type === 'spider' ? '' : 'none';
        document.getElementById('urlListGroup').style.display = type === 'list' ? '' : 'none';
        document.getElementById('depthMaxRow').style.display = type === 'list' ? 'none' : '';
        const startUrl = document.getElementById('start_url');
        startUrl.required = type === 'spider';
        if (document.getElementById('allowedDomainsSection')) document.getElementById('allowedDomainsSection').style.display = type === 'list' ? 'none' : '';
    }
    function selectMode(mode, btn) {
        document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('crawl_mode').value = mode;
    }
    function toggleSpeedDropdown(e) { e.stopPropagation(); document.getElementById('speedDropdown').classList.toggle('show'); }
    function selectSpeedOption(value, name, desc, icon) {
        document.getElementById('crawl_speed').value = value;
        const trigger = document.querySelector('.speed-select-trigger');
        trigger.querySelector('.speed-select-name').textContent = name;
        trigger.querySelector('.speed-select-desc').textContent = desc;
        trigger.querySelector('.speed-icon').textContent = icon;
        trigger.querySelector('.speed-icon').className = 'material-symbols-outlined speed-icon speed-icon-' + value;
        document.getElementById('speedDropdown').classList.remove('show');
    }
    function toggleUADropdown() { document.getElementById('uaDropdown').classList.toggle('show'); }
    function selectUAOption(value, name, desc, icon) {
        const presets = { 'scouter': 'Scouter/0.3 (Crawler developed by Lokoé SASU; +https://lokoe.fr/scouter-crawler)', 'googlebot-mobile': 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.96 Mobile Safari/537.36 (compatible; Googlebot/2.1; +https://www.google.com/bot.html)', 'googlebot-desktop': 'Mozilla/5.0 (compatible; Googlebot/2.1; +https://www.google.com/bot.html)', 'chrome': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36' };
        document.getElementById('user_agent').value = presets[value] || presets['scouter'];
        const trigger = document.querySelector('.ua-select-trigger');
        trigger.querySelector('.ua-select-name').textContent = name;
        trigger.querySelector('.ua-select-desc').textContent = desc;
        trigger.querySelector('.ua-icon').textContent = icon;
        document.getElementById('uaDropdown').classList.remove('show');
    }
    function applyCustomUA() { const v = document.getElementById('custom_ua_input').value.trim(); if (v) document.getElementById('user_agent').value = v; }
    function toggleAuthFields() { document.getElementById('authFields').style.display = document.getElementById('enable_auth').checked ? '' : 'none'; }
    function toggleExtractionHelp(e) { e.preventDefault(); const h = document.getElementById('extractionHelp'); h.style.display = h.style.display === 'none' ? '' : 'none'; }
    function addExtractor() { addExtractorWithValues('', 'xpath', ''); }
    function addExtractorWithValues(name, type, pattern) {
        extractorCounter++;
        const div = document.createElement('div');
        div.className = 'extractor-row';
        div.innerHTML = '<input type="text" class="extractor-name" placeholder="Name" value="'+name+'"><input type="hidden" class="extractor-type-value-hidden" value="'+type+'"><input type="text" class="extractor-pattern" placeholder="'+type+' pattern" value="'+pattern+'"><button type="button" class="btn-remove-extractor" onclick="this.parentElement.remove(); updateExtractorsEmptyState();"><span class="material-symbols-outlined">close</span></button>';
        document.getElementById('extractorsList').appendChild(div);
        updateExtractorsEmptyState();
    }
    function updateExtractorsEmptyState() {
        const list = document.getElementById('extractorsList');
        const empty = document.getElementById('extractorsEmpty');
        if (empty) empty.style.display = list.children.length === 0 ? '' : 'none';
    }
    function initDefaultExtractors() {
        document.getElementById('extractorsList').innerHTML = '';
        extractorCounter = 0;
        addExtractorWithValues('count_h2', 'xpath', 'count(//h2)');
        addExtractorWithValues('google_analytics', 'regex', 'ua":"(UA-\\d{8}-\\d)');
        updateExtractorsEmptyState();
    }
    function addHeader() {
        headerCounter++;
        const div = document.createElement('div');
        div.className = 'header-row';
        div.innerHTML = '<input type="text" class="header-name" placeholder="Header name"><input type="text" class="header-value" placeholder="Value"><button type="button" onclick="this.parentElement.remove()"><span class="material-symbols-outlined">close</span></button>';
        document.getElementById('headersList').appendChild(div);
    }
    function handleUrlFileUpload(input) { /* simplified */ }
    function removeUrlFile() { uploadedFileContent = null; }
    function updateUrlCounter() { const t = document.getElementById('url_list'); const c = t.value.split('\n').filter(l => l.trim()).length; document.getElementById('urlCounter').textContent = c + ' URLs'; }

    // Create project (submit form)
    async function createProject(event) {
        event.preventDefault();
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="material-symbols-outlined spinning">progress_activity</span> ' + __('common.loading');

        const crawlType = document.getElementById('crawl_type').value;
        const extractors = [];
        document.querySelectorAll('#extractorsList > div').forEach(d => {
            const n = d.querySelector('.extractor-name').value.trim();
            const t = d.querySelector('.extractor-type-value-hidden')?.value || 'xpath';
            const p = d.querySelector('.extractor-pattern').value.trim();
            if (n && p) extractors.push({ name: n.replace(/\s+/g,'_').replace(/[^a-zA-Z0-9_]/g,''), type: t, pattern: p });
        });
        const customHeaders = {};
        document.querySelectorAll('#headersList > div').forEach(d => {
            const n = d.querySelector('.header-name').value.trim();
            const v = d.querySelector('.header-value').value.trim();
            if (n && v) customHeaders[n.replace(/\s+/g,'-').replace(/[^a-zA-Z0-9\-]/g,'')] = v;
        });
        const enableAuth = document.getElementById('enable_auth').checked;
        const formData = {
            crawl_type: crawlType,
            user_agent: document.getElementById('user_agent').value,
            allowed_domains: document.getElementById('allowed_domains').value.trim().split('\n').filter(d=>d.trim()),
            custom_headers: customHeaders,
            http_auth: enableAuth ? { username: document.getElementById('auth_username').value.trim(), password: document.getElementById('auth_password').value.trim() } : null,
            extractors: extractors,
            respect_robots: document.getElementById('respect_robots').checked,
            respect_nofollow: document.getElementById('respect_nofollow').checked,
            respect_canonical: document.getElementById('respect_canonical').checked,
            follow_redirects: document.getElementById('follow_redirects').checked,
            retry_failed_urls: document.getElementById('retry_failed_urls').checked,
            crawl_speed: document.getElementById('crawl_speed').value,
            crawl_mode: document.getElementById('crawl_mode').value
        };
        if (crawlType === 'list') {
            formData.url_list = document.getElementById('url_list').value;
        } else {
            formData.start_url = document.getElementById('start_url').value;
            formData.depth_max = document.getElementById('depth_max').value;
        }
        try {
            // Step 1: Create project
            const resp = await fetch('api/projects', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(formData) });
            const result = await resp.json();
            if (!resp.ok || !result.success) throw new Error(result.error || __('common.error'));

            // Step 2: Start crawl
            const crawlResp = await fetch('api/crawls/start', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ project_dir: result.project_dir }) });
            const crawlResult = await crawlResp.json();
            if (!crawlResp.ok || !crawlResult.success) throw new Error(crawlResult.error || __('common.error'));

            closeNewProjectModal();
            CrawlPanel.start(result.project_dir, '<?= htmlspecialchars($domainName) ?>', crawlResult.crawl_id);
            setTimeout(() => window.location.reload(), 2000);
        } catch(e) {
            document.getElementById('formMessage').innerHTML = '<div class="error">' + e.message + '</div>';
        }
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<span class="material-symbols-outlined">rocket_launch</span> ' + __('index.btn_launch_crawl');
    }

    // Close modal on outside click
    document.addEventListener('click', function(e) {
        if (e.target.id === 'newProjectModal') closeNewProjectModal();
        const sd = document.getElementById('speedDropdown'); if (sd && !e.target.closest('.custom-speed-select')) sd.classList.remove('show');
        const ud = document.getElementById('uaDropdown'); if (ud && !e.target.closest('.custom-ua-select')) ud.classList.remove('show');
    });

    // Auto-refresh if any crawl is running
    <?php if ($hasRunningCrawl): ?>
    setInterval(() => { window.location.reload(); }, 15000);
    <?php endif; ?>
    </script>
</body>
</html>
