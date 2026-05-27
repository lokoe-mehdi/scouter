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
        'started_at' => $crawl->started_at ?? null, 'finished_at' => $crawl->finished_at ?? null,
        'stats' => [
            'urls' => $crawl->urls ?? 0, 'crawled' => $crawl->crawled ?? 0, 'compliant' => $crawl->compliant ?? 0,
            'duplicates' => $crawl->duplicates ?? 0, 'critical_errors' => $crawl->critical_errors ?? 0,
            'response_time' => $crawl->response_time ?? 0, 'depth_max' => $crawl->depth_max ?? 0,
            // null = pas encore calculé (sentinelle write-through CrawlStats)
            'health_score' => isset($crawl->health_score) ? (int)$crawl->health_score : null,
        ],
        'job_status' => $jobStatus, 'in_progress' => $crawl->in_progress ?? 0,
        'config' => json_decode($crawl->config ?? '{}', true), 'crawl_type' => $crawl->crawl_type ?? 'spider',
        'scheduled' => $crawl->scheduled ?? false,
    ];
}
usort($crawls, fn($a, $b) => $b->crawl_id - $a->crawl_id);

require_once(__DIR__ . '/components/project-metrics.php');

// Stats dérivées (pages indexables + erreurs critiques + score santé) : write-through.
// On NE calcule en live QUE les crawls dont health_score n'est pas encore stocké
// (sentinelle NULL) ; les autres sont lus directement de la ligne crawls → zéro
// requête ClickHouse. La passe CH calcule les 3 d'un coup ET les persiste.
$needIds = [];
foreach ($crawls as $c) {
    if (($c->stats['health_score'] ?? null) === null) { $needIds[(int)$c->crawl_id] = true; }
}
if (!empty($needIds)) {
    $computed = \App\Analysis\CrawlStats::ensureFromClickHouse(array_keys($needIds));
    foreach ($crawls as $c) {
        if (($c->stats['health_score'] ?? null) !== null) { continue; }
        $id = (int)$c->crawl_id;
        $s = $c->stats;
        if (isset($computed[$id])) {
            $s['compliant'] = $computed[$id]['compliant'];
            $s['critical_errors'] = $computed[$id]['critical_errors'];
            $s['health_score'] = $computed[$id]['health_score'];
        } else {
            // crawl absent de ClickHouse → repli pcHealthScore (gratuit) + fige la sentinelle.
            $fallback = pcHealthScore($s);
            $s['health_score'] = $fallback;
            \App\Analysis\CrawlStats::persistHealthScore($id, $fallback);
        }
        $c->stats = $s;
    }
}

// Dernier crawl terminé + le précédent (pour les variations).
$lastFinished = null; $prevFinished = null;
$finishedList = array_values(array_filter($crawls, fn($c) => in_array($c->job_status, ['completed', 'stopped', 'failed'])));
$lastFinished = $finishedList[0] ?? null;
$prevFinished = $finishedList[1] ?? null;

$kpiUrls = $lastFinished ? $lastFinished->stats['urls'] : 0;
$kpiCrawled = $lastFinished ? $lastFinished->stats['crawled'] : 0;
$kpiCompliant = $lastFinished ? $lastFinished->stats['compliant'] : 0;
$kpiIndexableRate = $kpiCrawled > 0 ? round(($kpiCompliant / $kpiCrawled) * 100, 1) : 0;
$kpiHealth = $lastFinished ? (int)($lastFinished->stats['health_score'] ?? pcHealthScore($lastFinished->stats)) : 0;

// Séries de tendance (ancien→récent) pour les sparklines de l'aperçu.
$trendFin = array_reverse(array_slice($finishedList, 0, 12));
$trUrls = array_map(fn($c) => (int)$c->stats['urls'], $trendFin);
$trCrawled = array_map(fn($c) => (int)$c->stats['crawled'], $trendFin);
$trIdx = array_map(fn($c) => $c->stats['crawled'] > 0 ? round($c->stats['compliant'] / $c->stats['crawled'] * 100) : 0, $trendFin);
$trErr = array_map(fn($c) => (int)$c->stats['critical_errors'], $trendFin);

// Map crawl_id → crawl terminé précédent (pour les variations en ligne).
$prevOf = [];
for ($i = 0; $i < count($finishedList); $i++) {
    $prevOf[(int)$finishedList[$i]->crawl_id] = $finishedList[$i + 1] ?? null;
}

/** Jauge épaisse (anneau, extrémités arrondies) avec le score au centre. */
function pjxGauge($score, $color) {
    $score = max(0, min(100, (int)$score));
    $r = 52; $c = 2 * M_PI * $r; $off = $c * (1 - $score / 100);
    return '<svg class="pjx-gauge-svg" viewBox="0 0 120 120" aria-hidden="true">'
        . '<circle class="pjx-gauge-track" cx="60" cy="60" r="' . $r . '"/>'
        . '<circle class="pjx-gauge-arc" cx="60" cy="60" r="' . $r . '" stroke="' . $color . '"'
        . ' stroke-dasharray="' . round($c, 1) . '" stroke-dashoffset="' . round($off, 1) . '"/>'
        . '</svg>';
}

/** Variation absolue (↑12 / ↓14), couleur selon le sens "bon". */
function pjxAbsDelta($cur, $prev, $goodWhenUp, $suffix = '') {
    if ($prev === null) return '';
    $d = (int)$cur - (int)$prev;
    if ($d === 0) return '<span class="pc-delta flat">±0' . $suffix . '</span>';
    $up = $d > 0;
    $good = $goodWhenUp ? $up : !$up;
    return '<span class="pc-delta ' . ($good ? 'up' : 'down') . '">' . ($up ? '↑' : '↓') . ' ' . abs($d) . $suffix . '</span>';
}

/** Rend une ligne <tr> de l'historique des crawls (table). */
function pjxCrawlRow($crawl, $prev, $canManage, $domainName, $dataIndex, $hidden = false, $resumable = false) {
    $st = $crawl->job_status ?? 'finished';
    $inProgress = in_array($st, ['running', 'queued', 'pending', 'processing', 'stopping']);
    $finished = in_array($st, ['completed', 'stopped', 'failed']);
    if (in_array($st, ['running', 'stopping'])) { $badge = 'running'; $txt = __('index.status_running'); }
    elseif (in_array($st, ['queued', 'pending'])) { $badge = 'running'; $txt = __('index.status_queued'); }
    elseif ($st === 'processing') { $badge = 'running'; $txt = __('index.status_processing'); }
    elseif ($st === 'failed') { $badge = 'failed'; $txt = __('index.status_failed'); }
    elseif ($st === 'stopped') { $badge = 'stopped'; $txt = __('index.status_stopped'); }
    else { $badge = 'done'; $txt = __('index.status_completed'); }

    $s = (array)$crawl->stats;
    $ps = $prev ? (array)$prev->stats : null;

    $cid = (int)$crawl->crawl_id;
    $rowUrl = "dashboard.php?crawl=$cid";
    $click = $finished ? ' onclick="window.location.href=\'' . $rowUrl . '\'"' : '';
    $h = $hidden ? ' style="display:none;"' : '';
    $score = $finished ? pcHealthScore($s) : null;
    $idxRate = ($s['crawled'] > 0) ? round($s['compliant'] / $s['crawled'] * 100) : 0;
    $prevIdx = ($ps && $ps['crawled'] > 0) ? round($ps['compliant'] / $ps['crawled'] * 100) : null;

    $cells = '';
    if ($inProgress) {
        $cells = '<td class="pjx-num">—</td><td class="pjx-num">—</td><td class="pjx-num">—</td>';
    } else {
        $cells .= '<td class="pjx-num">' . number_format($s['crawled']) . ' ' . ($ps ? pcDelta($s['crawled'], $ps['crawled'], true) : '') . '</td>';
        $cells .= '<td class="pjx-num">' . $idxRate . '% ' . ($prevIdx !== null ? pcDelta((int)$idxRate, (int)$prevIdx, true) : '') . '</td>';
        $cells .= '<td class="pjx-num">' . number_format((int)$s['critical_errors']) . ' ' . ($ps ? pcDelta((int)$s['critical_errors'], (int)$ps['critical_errors'], false) : '') . '</td>';
    }

    $scoreCell = ($score !== null)
        ? '<span class="pjx-score">' . pcDonutSvg($score) . '<b>' . $score . '</b></span>'
        : '<span class="pjx-num">—</span>';
    $dur = $inProgress ? '—' : pcDuration($crawl->started_at ?? null, $crawl->finished_at ?? null);

    $actions = '';
    // "Reprendre" uniquement si la frontier PG existe encore ($resumable) : un
    // crawl stoppé puis purgé (CH seul) prend les mêmes options qu'un crawl terminé.
    if ($canManage && $st === 'stopped' && $resumable) {
        $actions .= '<button type="button" class="pjx-act" title="' . __('crawl_panel.confirm_resume_title') . '" onclick="event.stopPropagation(); quickResumeCrawl(\'' . htmlspecialchars($crawl->dir) . '\',\'' . htmlspecialchars($domainName) . '\',' . $cid . ', this)"><span class="material-symbols-outlined">play_circle</span></button>';
    }
    if ($finished) {
        $actions .= '<a href="' . $rowUrl . '" class="pjx-act" title="' . __('project.view_report') . '"><span class="material-symbols-outlined">bar_chart</span></a>';
    }
    if ($canManage) {
        $actions .= '<button type="button" class="pjx-act" title="' . ($inProgress ? __('index.monitoring') : __('index.view_logs')) . '" onclick="event.stopPropagation(); openCrawlPanel(\'' . htmlspecialchars($crawl->dir) . '\',\'' . htmlspecialchars($domainName) . '\',' . $cid . ')"><span class="material-symbols-outlined">terminal</span></button>';
    }

    return '<tr class="pjx-row' . ($finished ? ' pjx-row--clickable' : '') . '" data-index="' . $dataIndex . '"' . $h . $click . '>'
        . '<td><span class="pjx-date">' . htmlspecialchars($crawl->date) . '</span></td>'
        . '<td><span class="pc-badge ' . $badge . '">' . $txt . '</span></td>'
        . '<td>' . $scoreCell . '</td>'
        . $cells
        . '<td class="pjx-num">' . $dur . '</td>'
        . '<td class="pjx-acts" onclick="event.stopPropagation();">' . $actions . '</td>'
        . '</tr>';
}

// Project stats
$totalCrawls = count($crawls);
$completedCrawls = count(array_filter($crawls, fn($c) => in_array($c->job_status, ['completed', 'stopped'])));
$failedCrawls = count(array_filter($crawls, fn($c) => $c->job_status === 'failed'));
$runningCrawls = count(array_filter($crawls, fn($c) => in_array($c->job_status, ['running', 'queued', 'pending', 'processing'])));

// Taille PAR crawl = PG (partitions) + ClickHouse (system.parts), comme la page
// monitor. La "taille du projet" = somme de TOUS les crawls ; la "taille du
// crawl" (aperçu) = uniquement le dernier crawl. Les deux étaient identiques car
// l'ancien calcul ne sommait que PG (vide pour les crawls migrés sur ClickHouse).
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();
if (!function_exists('pjxFormatBytes')) {
    function pjxFormatBytes($b) {
        $b = (int)$b;
        if ($b <= 0) return '—';
        if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
        if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
        if ($b >= 1024) return round($b / 1024, 0) . ' KB';
        return $b . ' B';
    }
}
$crawlSizeBytes = []; // crawl_id => bytes (PG + CH)
$pgResumable = [];    // crawl_id => true si la partition PG `pages_<id>` existe encore (reprenable)
$crawlIds = array_map(fn($c) => (int)$c->crawl_id, $crawls);
if (!empty($crawlIds)) {
    try {
        $idsPattern = implode('|', $crawlIds);
        $sizeStmt = $pdo->query("
            SELECT tablename, pg_total_relation_size(tablename::regclass) AS s
            FROM pg_tables
            WHERE schemaname = 'public'
              AND tablename ~ '^(pages|links|html|page_schemas|duplicate_clusters|redirect_chains)_({$idsPattern})$'
        ");
        foreach ($sizeStmt->fetchAll(PDO::FETCH_OBJ) as $row) {
            if (preg_match('/_(\d+)$/', $row->tablename, $m)) {
                $cid = (int)$m[1];
                $crawlSizeBytes[$cid] = ($crawlSizeBytes[$cid] ?? 0) + (int)$row->s;
                // La frontier (URLs non crawlées) vit dans la partition `pages_<id>`.
                // Tant qu'elle existe, le crawl est reprenable ; purgée, il ne reste
                // que ClickHouse (lecture seule) → on masque "Reprendre".
                if (strpos($row->tablename, 'pages_') === 0) {
                    $pgResumable[$cid] = true;
                }
            }
        }
    } catch (\Throwable $e) {}
    if (\App\Database\ClickHouseDatabase::enabled()) {
        try {
            $ch = \App\Database\ClickHouseDatabase::getInstance();
            $wanted = array_flip($crawlIds);
            foreach ($ch->select("SELECT partition AS crawl_id, sum(bytes_on_disk) AS bytes FROM system.parts WHERE database = {db:String} AND active = 1 GROUP BY partition", ['db' => $ch->getDatabase()]) as $row) {
                $cid = (int)$row['crawl_id'];
                if (isset($wanted[$cid])) {
                    $crawlSizeBytes[$cid] = ($crawlSizeBytes[$cid] ?? 0) + (int)($row['bytes'] ?? 0);
                }
            }
        } catch (\Throwable $e) {}
    }
}
$projectSize = pjxFormatBytes(array_sum($crawlSizeBytes));                       // tous les crawls
$lastCrawlSize = $lastFinished ? pjxFormatBytes($crawlSizeBytes[(int)$lastFinished->crawl_id] ?? 0) : '—';  // dernier crawl

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
        echo '<tr><td colspan="8" class="pjx-empty-cell">' . __('project.no_crawl_yet') . '</td></tr>';
    } else {
        $ajaxIdx = 0;
        foreach ($crawls as $crawl) {
            echo pjxCrawlRow($crawl, $prevOf[(int)$crawl->crawl_id] ?? null, $canManage, $domainName, $ajaxIdx, false, $pgResumable[(int)$crawl->crawl_id] ?? false);
            $ajaxIdx++;
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
    <link rel="stylesheet" href="assets/responsive.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/crawl-panel.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/project-redesign.css?v=<?= time() ?>">
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
            <div class="pjx-accordion">
                <button type="button" class="pjx-acc-head" onclick="pjxToggleAcc(this)">
                    <?= __('project.info') ?>
                    <span class="material-symbols-outlined pjx-acc-chevron">expand_more</span>
                </button>
                <div class="pjx-acc-body">
                    <div class="pjx-acc-row"><span><?= __('index.col_status') ?></span><b><?= htmlspecialchars($domainName) ?></b></div>
                    <div class="pjx-acc-row"><span><?= __('project.info_total') ?></span><b><?= $totalCrawls ?></b></div>
                    <div class="pjx-acc-row"><span><?= __('index.status_completed') ?></span><b><?= $completedCrawls ?></b></div>
                    <div class="pjx-acc-row"><span><?= __('index.status_failed') ?></span><b><?= $failedCrawls ?></b></div>
                    <div class="pjx-acc-row"><span><?= __('index.status_running') ?></span><b><?= $runningCrawls ?></b></div>
                </div>
            </div>

            <div class="pjx-projsize">
                <span class="material-symbols-outlined">database</span>
                <span><?= __('project.info_size') ?></span>
                <strong><?= $projectSize ?></strong>
            </div>
          </div>

          <div class="pj-col-center">
<!-- Last Report -->
            <div class="pj-card pjx-overview">
                <?php
                if ($lastFinished) {
                    $ovStatus = $lastFinished->job_status;
                    $ovBadge = 'done'; $ovBadgeText = __('index.status_completed');
                    if ($ovStatus === 'stopped') { $ovBadge = 'stopped'; $ovBadgeText = __('index.status_stopped'); }
                    elseif ($ovStatus === 'failed') { $ovBadge = 'failed'; $ovBadgeText = __('index.status_failed'); }

                    $durSec = ($lastFinished->started_at && $lastFinished->finished_at)
                        ? max(0, strtotime($lastFinished->finished_at) - strtotime($lastFinished->started_at)) : 0;
                    $pps = $durSec > 0 ? round($kpiCrawled / $durSec, 2) : 0;
                    $lastErr = (int)$lastFinished->stats['critical_errors'];
                    $prevIdxRate = ($prevFinished && $prevFinished->stats['crawled'] > 0)
                        ? round($prevFinished->stats['compliant'] / $prevFinished->stats['crawled'] * 100, 1) : null;
                    $healthPrev = $prevFinished ? pcHealthScore($prevFinished->stats) : null;
                    $scoreCls = pcScoreClass($kpiHealth);
                    $scoreLabel = $kpiHealth >= 75 ? __('project.score_excellent') : ($kpiHealth >= 50 ? __('project.score_watch') : __('project.score_critical'));
                ?>
                <div class="pjx-ov-head">
                    <h2 class="pjx-ov-title"><?= __('project.overview_title') ?></h2>
                    <span class="pc-badge <?= $ovBadge ?>"><?= $ovBadgeText ?></span>
                    <span class="pjx-ov-date"><?= date('d/m/Y', $lastFinished->timestamp) . ' à ' . date('H:i', $lastFinished->timestamp) ?></span>
                </div>

                <?php
                    $pvU = $prevFinished ? (int)$prevFinished->stats['urls'] : null;
                    $pvC = $prevFinished ? (int)$prevFinished->stats['crawled'] : null;
                    $pvE = $prevFinished ? (int)$prevFinished->stats['critical_errors'] : null;
                ?>
                <div class="pjx-kpis">
                    <div class="pjx-kpi-health">
                        <span class="pjx-kpi-label"><?= __('project.health_score') ?></span>
                        <div class="pjx-gauge-wrap">
                            <?= pjxGauge($kpiHealth, pcScoreColor($kpiHealth)) ?>
                            <div class="pjx-gauge-center">
                                <span class="pjx-gauge-num"><?= $kpiHealth ?></span>
                                <span class="pjx-gauge-state pjx-score-<?= $scoreCls ?>"><?= $scoreLabel ?></span>
                            </div>
                        </div>
                        <?php if ($healthPrev !== null): ?>
                        <span class="pjx-kpi-sub"><?= pjxAbsDelta($kpiHealth, $healthPrev, true, ' pts') ?> <?= __('project.vs_prev') ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="pjx-kpi">
                        <span class="pjx-kpi-label">URLs</span>
                        <div class="pjx-kpi-val"><?= number_format($kpiUrls) ?></div>
                        <span class="pjx-kpi-sub"><?= pjxAbsDelta($kpiUrls, $pvU, true) ?> <?= __('project.vs_prev') ?></span>
                        <?= pcSparklineSvg($trUrls, '#4ECDC4') ?>
                    </div>
                    <div class="pjx-kpi">
                        <span class="pjx-kpi-label"><?= __('header.crawled') ?></span>
                        <div class="pjx-kpi-val"><?= number_format($kpiCrawled) ?></div>
                        <span class="pjx-kpi-sub"><?= pjxAbsDelta($kpiCrawled, $pvC, true) ?> <?= __('project.vs_prev') ?></span>
                        <?= pcSparklineSvg($trCrawled, '#3B82F6') ?>
                    </div>
                    <div class="pjx-kpi">
                        <span class="pjx-kpi-label"><?= __('columns.indexable') ?></span>
                        <div class="pjx-kpi-val"><?= $kpiIndexableRate ?>%</div>
                        <span class="pjx-kpi-sub"><?= $prevIdxRate !== null ? pjxAbsDelta((int)round($kpiIndexableRate), (int)round($prevIdxRate), true, '%') : '' ?> <?= __('project.vs_prev') ?></span>
                        <?= pcSparklineSvg($trIdx, '#2ECC71', [0, 100]) ?>
                    </div>
                    <div class="pjx-kpi">
                        <span class="pjx-kpi-label"><?= __('project.errors') ?></span>
                        <div class="pjx-kpi-val"><?= number_format($lastErr) ?></div>
                        <span class="pjx-kpi-sub"><?= pjxAbsDelta($lastErr, $pvE, false) ?> <?= __('project.vs_prev') ?></span>
                        <?= pcSparklineSvg($trErr, '#E0816F') ?>
                    </div>
                </div>

                <div class="pjx-ministats">
                    <div class="pjx-mini"><span class="material-symbols-outlined">schedule</span><div><span class="pjx-mini-label"><?= __('project.crawl_time') ?></span><span class="pjx-mini-val"><?= $durSec > 0 ? pcDuration($lastFinished->started_at, $lastFinished->finished_at) : '—' ?></span></div></div>
                    <div class="pjx-mini"><span class="material-symbols-outlined">speed</span><div><span class="pjx-mini-label"><?= __('project.pages_per_sec') ?></span><span class="pjx-mini-val"><?= $pps ?: '—' ?></span></div></div>
                    <div class="pjx-mini"><span class="material-symbols-outlined">account_tree</span><div><span class="pjx-mini-label"><?= __('project.max_depth') ?></span><span class="pjx-mini-val"><?= (int)$lastFinished->stats['depth_max'] ?: '—' ?></span></div></div>
                    <div class="pjx-mini"><span class="material-symbols-outlined">database</span><div><span class="pjx-mini-label"><?= __('project.crawl_size') ?></span><span class="pjx-mini-val"><?= $lastCrawlSize ?></span></div></div>
                </div>

                <div class="pjx-cta">
                    <a href="dashboard.php?crawl=<?= $lastFinished->crawl_id ?>" class="pjx-cta-primary">
                        <?= __('project.access_report') ?>
                        <span class="material-symbols-outlined">arrow_forward</span>
                    </a>
                </div>
                <?php } else { ?>
                <div class="pjx-ov-head"><h2 class="pjx-ov-title"><?= __('project.overview_title') ?></h2></div>
                <p class="pj-empty-text"><?= __('project.no_crawl_yet') ?></p>
                <?php } ?>
            </div>

            <!-- Crawl History -->
            <div class="pj-card pjx-history">
                <div class="pjx-hist-head">
                    <h2 class="pjx-hist-title"><?= __('project.crawl_history') ?> <span class="pj-card-count"><?= count($crawls) ?></span></h2>
                    <?php if ($lastFinished && $prevFinished): ?>
                    <a class="pjx-hist-compare" href="dashboard.php?crawl=<?= $lastFinished->crawl_id ?>&compare=<?= $prevFinished->crawl_id ?>&page=comparison-overview">
                        <span class="material-symbols-outlined">compare_arrows</span> <?= __('project.compare_crawls') ?>
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($crawls)): ?>
                <div class="pj-empty">
                    <span class="material-symbols-outlined">search_off</span>
                    <p><?= __('project.no_crawl_yet') ?></p>
                </div>
                <?php else: ?>
                <div class="pjx-table-wrap">
                <table class="pjx-table">
                    <thead>
                        <tr>
                            <th><?= __('index.col_date') ?></th>
                            <th><?= __('index.col_status') ?></th>
                            <th><?= __('project.col_score') ?></th>
                            <th><?= __('header.crawled') ?></th>
                            <th><?= __('columns.indexable') ?></th>
                            <th><?= __('project.errors') ?></th>
                            <th><?= __('index.col_time') ?></th>
                            <th><?= __('index.col_actions') ?></th>
                        </tr>
                    </thead>
                    <tbody id="pjCrawlList">
                        <?php foreach ($crawls as $crawlIdx => $crawl) {
                            echo pjxCrawlRow($crawl, $prevOf[(int)$crawl->crawl_id] ?? null, $canManage, $domainName, $crawlIdx, $crawlIdx >= 10, $pgResumable[(int)$crawl->crawl_id] ?? false);
                        } ?>
                    </tbody>
                </table>
                </div>
                <?php if (count($crawls) > 10): ?>
                <button type="button" class="pjx-see-all" id="pjxSeeAll" onclick="pjxShowAllCrawls(this)">
                    <?= __('index.view_all_crawls', ['count' => count($crawls)]) ?>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
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

            <?php if ($canManage): ?>
            <div class="pj-card pjx-quick-card">
                <h2 class="pjx-quick-title"><?= __('project.quick_actions') ?></h2>
                <?php if ($lastFinished): ?>
                <button type="button" class="pjx-quick-item" onclick="duplicateAndStart('<?= htmlspecialchars($lastFinished->dir) ?>', <?= $project->user_id ?>)">
                    <span class="material-symbols-outlined">restart_alt</span><?= __('project.duplicate_relaunch') ?>
                </button>
                <?php endif; ?>
                <button type="button" class="pjx-quick-item" onclick="openNewProjectModal()">
                    <span class="material-symbols-outlined">tune</span><?= __('project.configure_crawl') ?>
                </button>
                <?php if ($lastFinished): ?>
                <a class="pjx-quick-item" href="dashboard.php?crawl=<?= $lastFinished->crawl_id ?>&page=url-explorer">
                    <span class="material-symbols-outlined">download</span><?= __('project.export_data') ?>
                </a>
                <?php endif; ?>
                <?php if ($isOwner || $auth->isAdmin()): ?>
                <button type="button" class="pjx-quick-item pjx-quick-danger" onclick="confirmDeleteProject(<?= $projectId ?>, '<?= htmlspecialchars($domainName, ENT_QUOTES) ?>')">
                    <span class="material-symbols-outlined">delete</span><?= __('project.delete_project') ?>
                </button>
                <?php endif; ?>
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
        // Disable briefly to avoid a double-submit during the request, but NO
        // loading spinner on the label — the launch is async and the real
        // progress is shown by the CrawlPanel sidebar. The button is always
        // re-enabled in finally, so it can never get stuck after the crawl.
        button.disabled = true;
        try {
            const payload = { project: projectDir };
            if (targetUserId) payload.target_user_id = targetUserId;
            const resp = await fetch('api/projects/duplicate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const data = await resp.json();
            if (data.success) {
                CrawlPanel.start(data.project_dir, data.domain || 'Crawl', data.crawl_id);
                // Rafraîchit la liste des crawls sans recharger la page (sinon ça
                // fermerait la sidebar CrawlPanel qui vient de démarrer)
                setTimeout(() => refreshCrawlList(), 1500);
            } else {
                alert(__('common.error') + ': ' + (data.error || ''));
            }
        } catch (e) {
            alert(__('common.error') + ': ' + e.message);
        } finally {
            button.disabled = false;
        }
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
        // Auto-remplir le sitemap depuis /robots.txt (async, silencieux si échec)
        if (startUrl && startUrl.value) autoFillSitemapFromRobots(startUrl.value);
    }

    // Récupère l'instruction Sitemap du /robots.txt via l'endpoint backend (évite CORS)
    // et préremplit le textarea correspondant. Silencieux en cas d'échec.
    let _robotsSitemapLastOrigin = null;
    let _robotsSitemapController = null;
    async function autoFillSitemapFromRobots(url) {
        const sitemapField = document.getElementById('sitemap_urls');
        if (!sitemapField || sitemapField.value.trim()) return;
        let origin;
        try { origin = new URL(url.trim()).origin; } catch (err) { return; }
        if (origin === _robotsSitemapLastOrigin) return;
        _robotsSitemapLastOrigin = origin;
        if (_robotsSitemapController) _robotsSitemapController.abort();
        _robotsSitemapController = new AbortController();
        try {
            const resp = await fetch('api/crawls/fetch-sitemaps?url=' + encodeURIComponent(origin), {
                signal: _robotsSitemapController.signal,
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!resp.ok) return;
            const data = await resp.json();
            const sitemaps = (data && Array.isArray(data.sitemaps)) ? data.sitemaps : [];
            if (sitemaps.length && !sitemapField.value.trim()) {
                sitemapField.value = sitemaps.join('\n');
            }
        } catch (error) {
            // Silencieux
        }
    }

    // Si l'utilisateur modifie le start_url, on retente le fetch (débouncé)
    (function() {
        const startUrlEl = document.getElementById('start_url');
        if (!startUrlEl) return;
        let debounceTimer = null;
        startUrlEl.addEventListener('input', function(e) {
            const v = e.target.value.trim();
            if (!v) return;
            if (debounceTimer) clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => autoFillSitemapFromRobots(v), 600);
        });
    })();
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
    function toggleSpeedDropdown(event) {
        event.stopPropagation();
        const select = document.getElementById('speedSelect');
        const dropdown = document.getElementById('speedDropdown');
        const trigger = select.querySelector('.speed-select-trigger');

        if (select.classList.contains('open')) {
            select.classList.remove('open');
            dropdown.style.display = 'none';
        } else {
            // Déplacer le dropdown dans le body pour échapper aux overflow:hidden parents
            if (dropdown.parentElement !== document.body) {
                document.body.appendChild(dropdown);
            }
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
        document.getElementById('crawl_speed').value = value;
        const triggerValue = select.querySelector('.speed-select-value');
        triggerValue.innerHTML = '<span class="material-symbols-outlined speed-icon speed-icon-' + value + '">' + icon + '</span>'
            + '<div class="speed-select-text"><span class="speed-select-name">' + name + '</span><span class="speed-select-desc">' + desc + '</span></div>';
        dropdown.querySelectorAll('.speed-select-option').forEach(opt => {
            opt.classList.toggle('selected', opt.dataset.value === value);
        });
        select.classList.remove('open');
        dropdown.style.display = 'none';
    }
    // Presets UA (alignés sur index.php) : utilisés par selectUAOption et applyCustomUA
    const uaPresets = {
        'scouter': 'Scouter/0.6 (Crawler developed by Lokoe SASU; +https://lokoe.fr/scouter-crawler)',
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
        document.getElementById('user_agent').value = uaPresets[value] || uaPresets['scouter'];
        let iconClass = 'ua-icon-scouter';
        if (value.includes('googlebot')) iconClass = 'ua-icon-googlebot';
        if (value === 'chrome') iconClass = 'ua-icon-chrome';
        trigger.innerHTML = '<span class="material-symbols-outlined ua-icon ' + iconClass + '">' + icon + '</span>'
            + '<div class="ua-select-text"><span class="ua-select-name">' + name + '</span><span class="ua-select-desc">' + desc + '</span></div>';
        select.querySelectorAll('.ua-select-option').forEach(opt => {
            opt.classList.toggle('selected', opt.dataset.value === value);
        });
        select.classList.remove('open');
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
    // Rafraîchit la liste des crawls (#pjCrawlList) sans recharger toute la page.
    // Fetch la page courante, extrait juste le div #pjCrawlList et remplace.
    // Simple, pas d'endpoint partial à maintenir.
    async function refreshCrawlList() {
        try {
            const resp = await fetch(window.location.href, { credentials: 'same-origin' });
            if (!resp.ok) return;
            const html = await resp.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const fresh = doc.getElementById('pjCrawlList');
            const current = document.getElementById('pjCrawlList');
            if (fresh && current) current.innerHTML = fresh.innerHTML;
        } catch (e) { /* silencieux */ }
    }

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
            sitemap_urls: document.getElementById('sitemap_urls').value.trim().split('\n').map(u=>u.trim()).filter(u=>u),
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
            // Rafraîchit la liste des crawls sans recharger toute la page
            // (le crawl s'affiche dans l'historique au bout de quelques secondes)
            setTimeout(() => refreshCrawlList(), 1500);
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
        const ss = document.getElementById('speedSelect');
        const sd = document.getElementById('speedDropdown');
        if (ss && sd && !ss.contains(e.target) && !sd.contains(e.target)) {
            ss.classList.remove('open');
            sd.style.display = 'none';
        }
        const us = document.getElementById('uaSelect');
        if (us && !us.contains(e.target)) us.classList.remove('open');
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

    // Accordéons de la colonne gauche (Informations / Paramètres avancés).
    function pjxToggleAcc(btn) {
        btn.closest('.pjx-accordion').classList.toggle('open');
    }

    // "Voir tous les crawls" : révèle toutes les lignes masquées.
    function pjxShowAllCrawls(btn) {
        document.querySelectorAll('#pjCrawlList .pjx-row').forEach(r => { r.style.display = ''; });
        if (btn) btn.style.display = 'none';
    }

    function pjApplyPage() {
        const rows = document.querySelectorAll('#pjCrawlList .pjx-row');
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
