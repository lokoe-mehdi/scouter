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
        'scheduled' => $crawl->scheduled ?? false,
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

// Project stats
$totalCrawls = count($crawls);
$completedCrawls = count(array_filter($crawls, fn($c) => in_array($c->job_status, ['completed', 'stopped'])));
$failedCrawls = count(array_filter($crawls, fn($c) => $c->job_status === 'failed'));
$runningCrawls = count(array_filter($crawls, fn($c) => in_array($c->job_status, ['running', 'queued', 'pending', 'processing'])));

// Project disk size (sum of all partition sizes for this project's crawls)
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();
$projectSize = '—';
try {
    $crawlIds = array_map(fn($c) => (int)$c->crawl_id, $crawls);
    if (!empty($crawlIds)) {
        $idsPattern = implode('|', $crawlIds);
        $sizeStmt = $pdo->query("
            SELECT pg_total_relation_size(tablename::regclass) AS s
            FROM pg_tables
            WHERE schemaname = 'public'
              AND tablename ~ '^(pages|links|html|page_schemas|duplicate_clusters|redirect_chains)_({$idsPattern})$'
        ");
        $totalBytes = 0;
        foreach ($sizeStmt->fetchAll(PDO::FETCH_OBJ) as $row) {
            $totalBytes += (int)$row->s;
        }
        if ($totalBytes >= 1073741824) $projectSize = round($totalBytes / 1073741824, 2) . ' GB';
        elseif ($totalBytes >= 1048576) $projectSize = round($totalBytes / 1048576, 1) . ' MB';
        elseif ($totalBytes >= 1024) $projectSize = round($totalBytes / 1024, 0) . ' KB';
        else $projectSize = $totalBytes . ' B';
    }
} catch (Exception $e) {
    $projectSize = '—';
}

// Load shares & admins
$sharesData = [];
$adminsData = [];
$availableUsers = [];
try {
    $stmt = $pdo->query("SELECT id, email, role FROM users WHERE role = 'admin' ORDER BY email");
    $adminsData = $stmt->fetchAll(PDO::FETCH_OBJ);

    $stmt = $pdo->prepare("SELECT u.id, u.email, u.role FROM project_shares ps JOIN users u ON u.id = ps.user_id WHERE ps.project_id = :pid ORDER BY u.email");
    $stmt->execute([':pid' => $projectId]);
    $sharesData = $stmt->fetchAll(PDO::FETCH_OBJ);

    $excludeIds = array_merge([$project->user_id], array_map(fn($a) => $a->id, $adminsData), array_map(fn($s) => $s->id, $sharesData));
    $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id NOT IN ({$placeholders}) ORDER BY email");
    $stmt->execute(array_values($excludeIds));
    $availableUsers = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch (Exception $e) {}

// Load existing schedule
$schedStmt = $pdo->prepare("SELECT * FROM crawl_schedules WHERE project_id = :pid");
$schedStmt->execute([':pid' => $projectId]);
$schedule = $schedStmt->fetch(PDO::FETCH_OBJ) ?: null;

$basePath = '';

// AJAX partial: return only the crawl history HTML
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history') {
    header('Content-Type: text/html; charset=utf-8');
    if (empty($crawls)) {
        echo '<div class="pj-empty"><span class="material-symbols-outlined">search_off</span><p>' . __('project.no_crawl_yet') . '</p></div>';
    } else {
        $ajaxIdx = 0;
        foreach ($crawls as $crawl) {
            $isInProgress = in_array($crawl->job_status, ['running', 'queued', 'pending', 'processing', 'stopping']);
            $isFinished = in_array($crawl->job_status, ['completed', 'stopped', 'failed']);
            $statusClass = 'pj-status--completed'; $statusText = __('index.status_completed');
            if ($crawl->job_status === 'running' || $crawl->job_status === 'stopping') { $statusClass = 'pj-status--running'; $statusText = __('index.status_running'); }
            elseif (in_array($crawl->job_status, ['queued', 'pending'])) { $statusClass = 'pj-status--queued'; $statusText = __('index.status_queued'); }
            elseif ($crawl->job_status === 'processing') { $statusClass = 'pj-status--processing'; $statusText = __('index.status_processing'); }
            elseif ($crawl->job_status === 'failed') { $statusClass = 'pj-status--failed'; $statusText = __('index.status_failed'); }
            elseif ($crawl->job_status === 'stopped') { $statusClass = 'pj-status--stopped'; $statusText = __('index.status_stopped'); }
            $typeIcon = $crawl->scheduled ? 'schedule' : (($crawl->crawl_type ?? 'spider') === 'list' ? 'list_alt' : 'bolt');
            $typeTitle = $crawl->scheduled ? __('project.scheduled_crawl') : (($crawl->crawl_type ?? 'spider') === 'list' ? __('index.mode_url_list') : 'Spider');
            echo '<div class="pj-crawl-row ' . ($isFinished ? 'pj-crawl-row--clickable' : '') . '" data-index="' . $ajaxIdx . '" ' . ($isFinished ? 'onclick="window.location.href=\'dashboard.php?crawl=' . $crawl->crawl_id . '\'"' : '') . '>';
            $ajaxIdx++;
            echo '<span class="pj-crawl-type" title="' . htmlspecialchars($typeTitle) . '"><span class="material-symbols-outlined">' . $typeIcon . '</span></span>';
            echo '<div class="pj-crawl-info"><span class="pj-crawl-date">' . $crawl->date . '</span><span class="pj-status ' . $statusClass . '">' . $statusText . '</span></div>';
            echo '<div class="pj-crawl-kpis">';
            if (!$isInProgress) {
                echo '<span class="pj-crawl-kpi"><strong>' . number_format($crawl->stats['urls']) . '</strong> URLs</span>';
                echo '<span class="pj-crawl-kpi"><strong>' . number_format($crawl->stats['crawled']) . '</strong> ' . __('header.crawled') . '</span>';
                echo '<span class="pj-crawl-kpi"><strong>' . number_format($crawl->stats['compliant']) . '</strong> ' . __('columns.indexable') . '</span>';
            } else {
                echo '<span class="pj-crawl-kpi" style="color:var(--text-tertiary);">--</span>';
            }
            echo '</div>';
            echo '<div class="pj-crawl-config">';
            echo '<span class="material-symbols-outlined config-icon ' . (($crawl->config['general']['crawl_mode'] ?? 'classic') === 'javascript' ? 'active' : 'inactive') . '" title="' . __('index.mode_javascript') . '">javascript</span>';
            echo '<span class="material-symbols-outlined config-icon ' . ((!empty($crawl->config['advanced']['respect']['robots']) || !empty($crawl->config['advanced']['respect_robots'])) ? 'active' : 'inactive') . '" title="' . __('index.respect_robots') . '">smart_toy</span>';
            echo '<span class="material-symbols-outlined config-icon ' . ((!empty($crawl->config['advanced']['respect']['canonical']) || !empty($crawl->config['advanced']['respect_canonical'])) ? 'active' : 'inactive') . '" title="' . __('index.respect_canonical') . '">content_copy</span>';
            echo '<span class="material-symbols-outlined config-icon ' . ((!empty($crawl->config['advanced']['respect']['nofollow']) || !empty($crawl->config['advanced']['respect_nofollow'])) ? 'active' : 'inactive') . '" title="' . __('index.respect_nofollow') . '">link_off</span>';
            echo '<span class="material-symbols-outlined config-icon ' . (($crawl->config['advanced']['follow_redirects'] ?? true) ? 'active' : 'inactive') . '" title="' . __('index.follow_redirects') . '">redo</span>';
            echo '<span class="material-symbols-outlined config-icon ' . (($crawl->config['advanced']['store_html'] ?? true) ? 'active' : 'inactive') . '" title="' . __('index.store_html') . '">code</span>';
            if (($crawl->crawl_type ?? 'spider') !== 'list') echo '<span class="config-depth-badge" title="' . __('index.max_depth') . '">' . ($crawl->config['general']['depthMax'] ?? '-') . '</span>';
            echo '</div>';
            echo '<div class="pj-crawl-actions" onclick="event.stopPropagation();">';
            if ($isFinished) echo '<a href="dashboard.php?crawl=' . $crawl->crawl_id . '" class="pj-icon-btn" title="' . __('project.view_report') . '"><span class="material-symbols-outlined">bar_chart</span></a>';
            if ($canManage) echo '<button class="pj-icon-btn" title="' . ($isInProgress ? __('index.monitoring') : __('index.view_logs')) . '" onclick="openCrawlPanel(\'' . htmlspecialchars($crawl->dir) . '\', \'' . htmlspecialchars($domainName) . '\', ' . $crawl->crawl_id . ')"><span class="material-symbols-outlined">terminal</span></button>';
            echo '</div></div>';
        }
    }
    exit;
}
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
          <div class="pj-col-left">
            <div class="pj-actions-float">
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
                <?php endif; ?>
            </div>

            <!-- Automation (owner or admin only) -->
            <?php if ($isOwner || $auth->isAdmin()): ?>
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
                        <div class="sched-time-custom" id="schedFreqPicker">
                            <button type="button" class="sched-time-trigger" onclick="toggleFreqPicker(event)">
                                <span class="material-symbols-outlined">replay</span>
                                <span id="schedFreqLabel"><?= __('project.sched_every_week') ?></span>
                            </button>
                            <div class="sched-time-dropdown sched-freq-dropdown" id="schedFreqDropdown">
                                <div class="sched-time-option" data-value="daily" onclick="selectFreq('daily')"><?= __('project.sched_every_day') ?></div>
                                <div class="sched-time-option sched-time-option--active" data-value="weekly" onclick="selectFreq('weekly')"><?= __('project.sched_every_week') ?></div>
                                <div class="sched-time-option" data-value="monthly" onclick="selectFreq('monthly')"><?= __('project.sched_every_month') ?></div>
                            </div>
                            <input type="hidden" id="schedFreqType" value="weekly">
                        </div>
                        <span id="schedMonthDayWrap" style="display: none;">
                            <?= __('project.sched_the') ?>
                            <div class="sched-time-custom" id="schedMonthDayPicker">
                                <button type="button" class="sched-time-trigger" onclick="toggleMonthDayPicker(event)">
                                    <span class="material-symbols-outlined">calendar_month</span>
                                    <span id="schedMonthDayLabel">1</span>
                                </button>
                                <div class="sched-time-dropdown sched-monthday-dropdown" id="schedMonthDayDropdown">
                                    <?php for ($d = 1; $d <= 28; $d++): ?>
                                    <div class="sched-time-option<?= $d === 1 ? ' sched-time-option--active' : '' ?>" data-value="<?= $d ?>" onclick="selectMonthDay(<?= $d ?>)"><?= $d ?></div>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" id="schedMonthDay" value="1">
                            </div>
                        </span>
                        <span id="schedTimeWrap">
                            <?= __('project.sched_at') ?>
                            <div class="sched-time-custom" id="schedTimePicker">
                                <button type="button" class="sched-time-trigger" onclick="toggleTimePicker(event)">
                                    <span class="material-symbols-outlined">schedule</span>
                                    <span id="schedTimeLabel">08:00</span>
                                </button>
                                <div class="sched-time-dropdown" id="schedTimeDropdown">
                                    <?php for ($h = 0; $h < 24; $h++): foreach ([0, 15, 30, 45] as $m): ?>
                                    <div class="sched-time-option<?= ($h === 8 && $m === 0) ? ' sched-time-option--active' : '' ?>" onclick="selectTime(<?= $h ?>, <?= $m ?>)">
                                        <?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>:<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>
                                    </div>
                                    <?php endforeach; endfor; ?>
                                </div>
                            </div>
                            <input type="hidden" id="schedHour" value="8">
                            <input type="hidden" id="schedMinute" value="0">
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
            <?php endif; ?>

            <!-- Project Info -->
            <div class="pj-card pj-card--info">
                <h2 class="pj-card-title" style="margin: 0 0 0.5rem;"><?= __('project.info') ?></h2>
                <div class="pj-info-list">
                    <div class="pj-info-row">
                        <span class="pj-info-dot pj-info-dot--neutral"></span>
                        <span class="pj-info-label"><?= __('project.info_total') ?></span>
                        <span class="pj-info-value"><?= $totalCrawls ?></span>
                    </div>
                    <div class="pj-info-row">
                        <span class="pj-info-dot pj-info-dot--success"></span>
                        <span class="pj-info-label"><?= __('index.status_completed') ?></span>
                        <span class="pj-info-value"><?= $completedCrawls ?></span>
                    </div>
                    <div class="pj-info-row">
                        <span class="pj-info-dot <?= $failedCrawls > 0 ? 'pj-info-dot--error' : 'pj-info-dot--neutral' ?>"></span>
                        <span class="pj-info-label"><?= __('index.status_failed') ?></span>
                        <span class="pj-info-value"><?= $failedCrawls ?></span>
                    </div>
                    <div class="pj-info-row">
                        <span class="pj-info-dot pj-info-dot--running <?= $runningCrawls > 0 ? 'pj-info-dot--pulse' : '' ?>"></span>
                        <span class="pj-info-label"><?= __('index.status_running') ?></span>
                        <span class="pj-info-value"><?= $runningCrawls ?></span>
                    </div>
                </div>
                <div class="pj-info-size">
                    <span class="material-symbols-outlined">database</span>
                    <span><?= __('project.info_size') ?></span>
                    <strong><?= $projectSize ?></strong>
                </div>
            </div>
          </div>

          <div class="pj-col-center">
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
                <a href="dashboard.php?crawl=<?= $lastFinished->crawl_id ?>" class="pj-btn-report">
                    <span><?= __('project.view_report') ?></span>
                    <span class="material-symbols-outlined">arrow_forward</span>
                </a>
                <?php else: ?>
                <p class="pj-empty-text"><?= __('project.no_crawl_yet') ?></p>
                <?php endif; ?>
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
                <div class="pj-crawl-list" id="pjCrawlList">
                    <?php foreach ($crawls as $crawlIdx => $crawl):
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
                    <div class="pj-crawl-row <?= $isFinished ? 'pj-crawl-row--clickable' : '' ?>" data-index="<?= $crawlIdx ?>" <?= $crawlIdx >= 10 ? 'style="display:none;"' : '' ?> <?= $isFinished ? 'onclick="window.location.href=\'dashboard.php?crawl=' . $crawl->crawl_id . '\'"' : '' ?>>
                        <span class="pj-crawl-type" title="<?= $crawl->scheduled ? __('project.scheduled_crawl') : (($crawl->crawl_type ?? 'spider') === 'list' ? __('index.mode_url_list') : 'Spider') ?>">
                            <span class="material-symbols-outlined"><?= $crawl->scheduled ? 'schedule' : (($crawl->crawl_type ?? 'spider') === 'list' ? 'list_alt' : 'bolt') ?></span>
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
                            <span class="material-symbols-outlined config-icon <?= ($crawl->config['general']['crawl_mode'] ?? 'classic') === 'javascript' ? 'active' : 'inactive' ?>" title="<?= __('index.mode_javascript') ?>">javascript</span>
                            <span class="material-symbols-outlined config-icon <?= (!empty($crawl->config['advanced']['respect']['robots']) || !empty($crawl->config['advanced']['respect_robots'])) ? 'active' : 'inactive' ?>" title="<?= __('index.respect_robots') ?>">smart_toy</span>
                            <span class="material-symbols-outlined config-icon <?= (!empty($crawl->config['advanced']['respect']['canonical']) || !empty($crawl->config['advanced']['respect_canonical'])) ? 'active' : 'inactive' ?>" title="<?= __('index.respect_canonical') ?>">content_copy</span>
                            <span class="material-symbols-outlined config-icon <?= (!empty($crawl->config['advanced']['respect']['nofollow']) || !empty($crawl->config['advanced']['respect_nofollow'])) ? 'active' : 'inactive' ?>" title="<?= __('index.respect_nofollow') ?>">link_off</span>
                            <span class="material-symbols-outlined config-icon <?= ($crawl->config['advanced']['follow_redirects'] ?? true) ? 'active' : 'inactive' ?>" title="<?= __('index.follow_redirects') ?>">redo</span>
                            <span class="material-symbols-outlined config-icon <?= ($crawl->config['advanced']['store_html'] ?? true) ? 'active' : 'inactive' ?>" title="<?= __('index.store_html') ?>">code</span>
                            <?php if (($crawl->crawl_type ?? 'spider') !== 'list'): ?>
                                <span class="config-depth-badge" title="<?= __('index.max_depth') ?>"><?= $crawl->config['general']['depthMax'] ?? '-' ?></span>
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
                <?php if (count($crawls) > 10): ?>
                <div class="pj-pagination" id="pjPagination">
                    <button class="pj-page-btn" id="pjPrevBtn" onclick="pjChangePage(-1)" disabled>
                        <span class="material-symbols-outlined">chevron_left</span>
                    </button>
                    <span class="pj-page-info" id="pjPageInfo">1 / <?= ceil(count($crawls) / 10) ?></span>
                    <button class="pj-page-btn" id="pjNextBtn" onclick="pjChangePage(1)">
                        <span class="material-symbols-outlined">chevron_right</span>
                    </button>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

                      </div>

          <div class="pj-col-right">
<!-- Share -->
            <div class="pj-card pj-card--share">
                <h2 class="pj-card-title" style="margin: 0 0 0.75rem;"><?= __('project.shared_with') ?></h2>

                <div class="pj-share-list" id="pjShareList">
                    <!-- Owner -->
                    <?php
                    $ownerStmt = $pdo->prepare("SELECT email FROM users WHERE id = :id");
                    $ownerStmt->execute([':id' => $project->user_id]);
                    $ownerEmail = $ownerStmt->fetchColumn();
                    ?>
                    <div class="pj-share-item">
                        <span class="pj-share-avatar pj-share-avatar--owner"><?= strtoupper(substr($ownerEmail, 0, 1)) ?></span>
                        <span class="pj-share-email"><?= htmlspecialchars($ownerEmail) ?></span>
                        <span class="pj-share-role pj-share-role--owner"><?= __('project.role_owner') ?></span>
                    </div>

                    <!-- Admins (except owner if also admin) -->
                    <?php foreach ($adminsData as $admin):
                        if ($admin->id == $project->user_id) continue;
                    ?>
                    <div class="pj-share-item">
                        <span class="pj-share-avatar pj-share-avatar--admin"><?= strtoupper(substr($admin->email, 0, 1)) ?></span>
                        <span class="pj-share-email"><?= htmlspecialchars($admin->email) ?></span>
                        <span class="pj-share-role pj-share-role--admin">Admin</span>
                    </div>
                    <?php endforeach; ?>

                    <!-- Shared users -->
                    <?php foreach ($sharesData as $share): ?>
                    <div class="pj-share-item" id="share-<?= $share->id ?>">
                        <span class="pj-share-avatar"><?= strtoupper(substr($share->email, 0, 1)) ?></span>
                        <span class="pj-share-email"><?= htmlspecialchars($share->email) ?></span>
                        <span class="pj-share-role pj-share-role--shared"><?= __('project.role_shared') ?></span>
                        <?php if ($isOwner || $auth->isAdmin()): ?>
                        <button class="pj-share-remove" onclick="removeShare(<?= $share->id ?>)" title="<?= __('project.remove_share') ?>">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($sharesData) && count($adminsData) <= 1): ?>
                    <p class="pj-share-empty"><?= __('project.no_shares') ?></p>
                    <?php endif; ?>

                    <!-- Add user (inline, right after the list) -->
                    <?php if (($isOwner || $auth->isAdmin()) && !empty($availableUsers)): ?>
                    <div class="pj-share-add-inline">
                        <div class="pj-share-picker" id="sharePicker">
                            <button class="pj-share-picker-btn" onclick="toggleSharePicker(event)">
                                <span class="material-symbols-outlined">person_add</span>
                                <span><?= __('project.add_share') ?></span>
                            </button>
                            <div class="pj-share-picker-dropdown" id="sharePickerDropdown">
                                <?php foreach ($availableUsers as $u): ?>
                                <div class="pj-share-picker-item" onclick="addShare(<?= $u->id ?>)">
                                    <span class="pj-share-avatar"><?= strtoupper(substr($u->email, 0, 1)) ?></span>
                                    <span class="pj-share-email"><?= htmlspecialchars($u->email) ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php elseif (($isOwner || $auth->isAdmin()) && empty($availableUsers)): ?>
                    <p class="pj-share-empty"><?= __('project.no_users_available') ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isOwner || $auth->isAdmin()): ?>
            <div class="pj-card pj-card--danger">
                <button class="pj-btn-delete" onclick="confirmDeleteProject(<?= $projectId ?>, '<?= htmlspecialchars($domainName, ENT_QUOTES) ?>')">
                    <span class="material-symbols-outlined">delete</span>
                    <?= __('project.delete_project') ?>
                </button>
            </div>
            <?php endif; ?>
          </div>
        </div><!-- /pj-bento -->
    </div><!-- /pj -->

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
    function toggleTimePicker(e) {
        e.stopPropagation();
        const dd = document.getElementById('schedTimeDropdown');
        dd.classList.toggle('show');
        if (dd.classList.contains('show')) {
            const active = dd.querySelector('.sched-time-option--active');
            if (active) active.scrollIntoView({ block: 'center' });
        }
    }
    function selectTime(h, m) {
        document.getElementById('schedHour').value = h;
        document.getElementById('schedMinute').value = m;
        document.getElementById('schedTimeLabel').textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0');
        document.getElementById('schedTimeDropdown').classList.remove('show');
        document.querySelectorAll('.sched-time-option').forEach(el => el.classList.remove('sched-time-option--active'));
        schedDirty(); updateScheduleSummary();
    }
    function toggleFreqPicker(e) {
        e.stopPropagation();
        document.getElementById('schedTimeDropdown')?.classList.remove('show');
        document.getElementById('schedMonthDayDropdown')?.classList.remove('show');
        document.getElementById('schedFreqDropdown').classList.toggle('show');
    }
    function selectFreq(value) {
        document.getElementById('schedFreqType').value = value;
        const label = document.querySelector('#schedFreqDropdown .sched-time-option[data-value="'+value+'"]').textContent.trim();
        document.getElementById('schedFreqLabel').textContent = label;
        document.getElementById('schedFreqDropdown').classList.remove('show');
        document.querySelectorAll('#schedFreqDropdown .sched-time-option').forEach(el => el.classList.remove('sched-time-option--active'));
        document.querySelector('#schedFreqDropdown .sched-time-option[data-value="'+value+'"]').classList.add('sched-time-option--active');
        schedDirty(); updateScheduleUI();
    }
    function toggleMonthDayPicker(e) {
        e.stopPropagation();
        document.getElementById('schedTimeDropdown')?.classList.remove('show');
        document.getElementById('schedFreqDropdown')?.classList.remove('show');
        const dd = document.getElementById('schedMonthDayDropdown');
        dd.classList.toggle('show');
        if (dd.classList.contains('show')) {
            const active = dd.querySelector('.sched-time-option--active');
            if (active) active.scrollIntoView({ block: 'center' });
        }
    }
    function selectMonthDay(day) {
        document.getElementById('schedMonthDay').value = day;
        document.getElementById('schedMonthDayLabel').textContent = day;
        document.getElementById('schedMonthDayDropdown').classList.remove('show');
        document.querySelectorAll('#schedMonthDayDropdown .sched-time-option').forEach(el => el.classList.remove('sched-time-option--active'));
        document.querySelector('#schedMonthDayDropdown .sched-time-option[data-value="'+day+'"]').classList.add('sched-time-option--active');
        schedDirty(); updateScheduleSummary();
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#schedTimePicker')) document.getElementById('schedTimeDropdown')?.classList.remove('show');
        if (!e.target.closest('#schedFreqPicker')) document.getElementById('schedFreqDropdown')?.classList.remove('show');
        if (!e.target.closest('#schedMonthDayPicker')) document.getElementById('schedMonthDayDropdown')?.classList.remove('show');
    });

    // Schedule
    function toggleSchedule(checked) {
        document.getElementById('scheduleConfig').style.display = checked ? '' : 'none';
        document.getElementById('scheduleOff').style.display = checked ? 'none' : '';
        if (checked) { updateScheduleUI(); updateScheduleSummary(); schedDirty(); }
        else { disableScheduleNow(); }
    }

    // Immediately disable schedule via API (no save button needed)
    async function disableScheduleNow() {
        try {
            await fetch('api/projects/<?= $projectId ?>/schedule', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ enabled: false })
            });
        } catch(e) {}
        document.getElementById('schedSaveBtn').style.display = 'none';
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
    async function saveSchedule() {
        const btn = document.getElementById('schedSaveBtn');
        btn.innerHTML = '<span class="material-symbols-outlined spinning">progress_activity</span> Saving...';
        btn.disabled = true;

        const enabled = document.getElementById('scheduleToggle').checked;
        const frequency = document.getElementById('schedFreqType').value;
        const days = [];
        document.querySelectorAll('.sched-day--active').forEach(d => days.push(d.dataset.day));
        const hour = parseInt(document.getElementById('schedHour').value) || 0;
        const minute = parseInt(document.getElementById('schedMinute').value) || 0;
        const dayOfMonth = parseInt(document.getElementById('schedMonthDay')?.value) || 1;

        try {
            const resp = await fetch('api/projects/<?= $projectId ?>/schedule', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    enabled, frequency, days_of_week: days,
                    day_of_month: dayOfMonth, hour, minute,
                    template_crawl_id: window.selectedModelCrawlId || null
                })
            });
            const data = await resp.json();
            if (data.success) {
                btn.innerHTML = '<span class="material-symbols-outlined">check_circle</span> Saved!';
                btn.classList.add('sched-save--done');
                setTimeout(() => { btn.style.display = 'none'; btn.disabled = false; btn.classList.remove('sched-save--done'); btn.innerHTML = '<span class="material-symbols-outlined">check</span> ' + __('common.save'); }, 1500);
            } else {
                alert(__('common.error') + ': ' + (data.error || ''));
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined">check</span> ' + __('common.save');
            }
        } catch (e) {
            alert(__('common.error') + ': ' + e.message);
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined">check</span> ' + __('common.save');
        }
    }

    // Model selector
    window.selectedModelCrawlId = <?= $lastFinished ? $lastFinished->crawl_id : 'null' ?>;

    function toggleModelDropdown(e) {
        e.stopPropagation();
        document.getElementById('modelDropdown').classList.toggle('show');
    }
    function selectModel(crawlId, dateStr) {
        window.selectedModelCrawlId = crawlId;
        document.getElementById('modelSelectorText').textContent = dateStr;
        document.getElementById('modelDropdown').classList.remove('show');
        schedDirty();
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
        const id = extractorCounter++;
        const div = document.createElement('div');
        div.id = 'extractor-' + id;
        div.className = 'extractor-item';
        const isRegex = type === 'regex';
        div.innerHTML = '<input type="text" class="extractor-name" placeholder="Nom" oninput="sanitizeExtractorName(this)">'
            + '<div class="extractor-type-dropdown" id="extractorType-'+id+'">'
            + '<div class="extractor-type-trigger" onclick="toggleExtractorType('+id+')">'
            + '<span class="extractor-type-value">'+(isRegex?'Regex':'XPath')+'</span>'
            + '<span class="material-symbols-outlined">expand_more</span></div>'
            + '<div class="extractor-type-options" id="extractorTypeOptions-'+id+'">'
            + '<div class="extractor-type-option '+(isRegex?'':'selected')+'" data-value="xpath" onclick="selectExtractorType('+id+',\'xpath\')">XPath</div>'
            + '<div class="extractor-type-option '+(isRegex?'selected':'')+'" data-value="regex" onclick="selectExtractorType('+id+',\'regex\')">Regex</div>'
            + '</div></div>'
            + '<input type="hidden" class="extractor-type-value-hidden" value="'+type+'">'
            + '<input type="text" class="extractor-pattern" id="extractor-pattern-'+id+'" placeholder="'+(isRegex?'price: (\\\\d+)':'//h2')+'">'
            + '<button type="button" class="extractor-item-delete" onclick="removeExtractor('+id+')">'
            + '<span class="material-symbols-outlined">close</span></button>';
        document.getElementById('extractorsList').appendChild(div);
        if (name) div.querySelector('.extractor-name').value = name;
        if (pattern) div.querySelector('.extractor-pattern').value = pattern;
        updateExtractorsEmptyState();
    }
    function removeExtractor(id) { const el = document.getElementById('extractor-'+id); if (el) el.remove(); updateExtractorsEmptyState(); }
    function sanitizeExtractorName(el) { el.value = el.value.replace(/[^a-zA-Z0-9_]/g, '_'); }
    function toggleExtractorType(id) {
        const dropdown = document.getElementById('extractorType-'+id);
        const options = document.getElementById('extractorTypeOptions-'+id);
        const trigger = dropdown.querySelector('.extractor-type-trigger');
        document.querySelectorAll('.extractor-type-dropdown.open').forEach(d => { if (d.id !== 'extractorType-'+id) d.classList.remove('open'); });
        if (dropdown.classList.contains('open')) { dropdown.classList.remove('open'); }
        else {
            const rect = trigger.getBoundingClientRect();
            options.style.top = (rect.bottom + 2) + 'px';
            options.style.left = rect.left + 'px';
            options.style.minWidth = rect.width + 'px';
            dropdown.classList.add('open');
        }
    }
    function selectExtractorType(id, type) {
        document.getElementById('extractorType-'+id).classList.remove('open');
        const item = document.getElementById('extractor-'+id);
        item.querySelector('.extractor-type-value').textContent = type === 'regex' ? 'Regex' : 'XPath';
        item.querySelector('.extractor-type-value-hidden').value = type;
        item.querySelector('.extractor-pattern').placeholder = type === 'regex' ? 'price: (\\d+)' : '//h2';
        item.querySelectorAll('.extractor-type-option').forEach(o => o.classList.toggle('selected', o.dataset.value === type));
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
            store_html: document.getElementById('store_html').checked,
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

    // Share management
    function toggleSharePicker(e) {
        e.stopPropagation();
        document.getElementById('sharePickerDropdown').classList.toggle('show');
    }
    document.addEventListener('click', function(e) {
        const dd = document.getElementById('sharePickerDropdown');
        if (dd && !e.target.closest('#sharePicker')) dd.classList.remove('show');
    });

    async function addShare(userId) {
        try {
            const resp = await fetch('api/projects/<?= $projectId ?>/share', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: userId })
            });
            const data = await resp.json();
            if (data.success) window.location.reload();
            else alert(__('common.error') + ': ' + (data.error || ''));
        } catch (e) { alert(__('common.error') + ': ' + e.message); }
    }

    async function removeShare(userId) {
        try {
            const resp = await fetch('api/projects/<?= $projectId ?>/unshare', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: userId })
            });
            const data = await resp.json();
            if (data.success) window.location.reload();
            else alert(__('common.error') + ': ' + (data.error || ''));
        } catch (e) { alert(__('common.error') + ': ' + e.message); }
    }

    // Delete project
    async function confirmDeleteProject(projectId, projectName) {
        if (!await customConfirm(__('index.confirm_delete_project'), __('index.confirm_delete_project_title'), __('common.delete'), 'danger')) return;
        try {
            const resp = await fetch('api/projects', { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ project_id: projectId }) });
            const result = await resp.json();
            if (result.success) { window.location.href = 'index.php'; }
            else { alert(__('common.error') + ': ' + (result.error || '')); }
        } catch (e) { alert(__('common.error') + ': ' + e.message); }
    }

    // Close modal on outside click
    document.addEventListener('click', function(e) {
        if (e.target.id === 'newProjectModal') closeNewProjectModal();
        const sd = document.getElementById('speedDropdown'); if (sd && !e.target.closest('.custom-speed-select')) sd.classList.remove('show');
        const ud = document.getElementById('uaDropdown'); if (ud && !e.target.closest('.custom-ua-select')) ud.classList.remove('show');
    });

    // Pagination
    const PJ_PER_PAGE = 10;
    let pjCurrentPage = 0;
    const pjTotalPages = <?= ceil(count($crawls) / 10) ?>;

    function pjChangePage(delta) {
        pjCurrentPage += delta;
        if (pjCurrentPage < 0) pjCurrentPage = 0;
        if (pjCurrentPage >= pjTotalPages) pjCurrentPage = pjTotalPages - 1;
        pjApplyPage();
    }

    function pjApplyPage() {
        const rows = document.querySelectorAll('#pjCrawlList .pj-crawl-row');
        const start = pjCurrentPage * PJ_PER_PAGE;
        const end = start + PJ_PER_PAGE;
        rows.forEach(r => {
            const idx = parseInt(r.dataset.index);
            r.style.display = (idx >= start && idx < end) ? '' : 'none';
        });
        const info = document.getElementById('pjPageInfo');
        if (info) info.textContent = (pjCurrentPage + 1) + ' / ' + pjTotalPages;
        const prev = document.getElementById('pjPrevBtn');
        const next = document.getElementById('pjNextBtn');
        if (prev) prev.disabled = pjCurrentPage === 0;
        if (next) next.disabled = pjCurrentPage >= pjTotalPages - 1;
    }

    // Auto-refresh crawl history every 20s (preserves current page)
    setInterval(() => {
        fetch('project.php?id=<?= $projectId ?>&ajax=history')
            .then(r => r.text())
            .then(html => {
                const el = document.getElementById('pjCrawlList');
                if (el) { el.innerHTML = html; pjApplyPage(); }
            })
            .catch(() => {});
    }, 20000);

    // Load existing schedule
    <?php if ($schedule && ($schedule->enabled === true || $schedule->enabled === 't' || $schedule->enabled === '1')): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('scheduleToggle');
        toggle.checked = true;
        toggleSchedule(true);

        selectFreq(<?= json_encode($schedule->frequency) ?>);

        <?php if ($schedule->frequency === 'weekly'):
            $savedDays = trim($schedule->days_of_week ?? '{mon}', '{}');
            $savedDaysArr = array_map('trim', explode(',', $savedDays));
        ?>
        // Reset all days, then activate saved ones
        document.querySelectorAll('.sched-day').forEach(d => d.classList.remove('sched-day--active'));
        <?php foreach ($savedDaysArr as $d): ?>
        document.querySelector('.sched-day[data-day="<?= $d ?>"]')?.classList.add('sched-day--active');
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($schedule->frequency === 'monthly'): ?>
        selectMonthDay(<?= (int)$schedule->day_of_month ?>);
        <?php endif; ?>

        document.getElementById('schedHour').value = <?= (int)$schedule->hour ?>;
        document.getElementById('schedMinute').value = <?= (int)$schedule->minute ?>;
        document.getElementById('schedTimeLabel').textContent = '<?= str_pad((int)$schedule->hour, 2, '0', STR_PAD_LEFT) ?>:<?= str_pad((int)$schedule->minute, 2, '0', STR_PAD_LEFT) ?>';

        updateScheduleSummary();
        // Don't show save button on initial load (no changes yet)
        document.getElementById('schedSaveBtn').style.display = 'none';
    });
    <?php endif; ?>
    </script>
</body>
</html>
