<?php
require_once(__DIR__ . '/init.php');
$auth->requireAdmin(false);
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

// Server info
$serverTime = date('Y-m-d H:i:s');
$serverTimezone = date_default_timezone_get();
$serverUtcOffset = date('P');
$serverIp = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());

// Global stats
$globalProjectCount = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL")->fetchColumn();
$globalCrawlCount = (int)$pdo->query("SELECT COUNT(*) FROM crawls WHERE status != 'deleting'")->fetchColumn();
$globalDbSize = (int)$pdo->query("SELECT pg_database_size(current_database()) AS s")->fetchColumn();

function monitorFormatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}

// Projects with sizes
$projectsQuery = $pdo->query("
    SELECT p.id, p.name, u.email AS owner,
           COUNT(DISTINCT cr.id) AS crawl_count
    FROM projects p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN crawls cr ON cr.project_id = p.id AND cr.status != 'deleting'
    WHERE p.deleted_at IS NULL
    GROUP BY p.id, p.name, u.email
    ORDER BY p.id
");
$projects = $projectsQuery->fetchAll(PDO::FETCH_OBJ);

// Build a single query to get all partition sizes (avoids errors on missing tables)
$sizeStmt = $pdo->query("
    SELECT tablename, pg_total_relation_size(tablename::regclass) AS size_bytes
    FROM pg_tables
    WHERE schemaname = 'public'
      AND tablename ~ '^(pages|links|html|page_schemas|duplicate_clusters|redirect_chains)_[0-9]+$'
");
$partitionSizes = [];
foreach ($sizeStmt->fetchAll(PDO::FETCH_OBJ) as $row) {
    // Extract crawl_id from table name (e.g. pages_123 → 123)
    preg_match('/_(\d+)$/', $row->tablename, $m);
    if ($m) {
        $partitionSizes[(int)$m[1]] = ($partitionSizes[(int)$m[1]] ?? 0) + (int)$row->size_bytes;
    }
}

foreach ($projects as $proj) {
    $proj->size_bytes = 0;
    $cids = $pdo->prepare("SELECT id FROM crawls WHERE project_id = :pid AND status != 'deleting'");
    $cids->execute([':pid' => $proj->id]);
    foreach ($cids->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $proj->size_bytes += $partitionSizes[(int)$cid] ?? 0;
    }
}
usort($projects, fn($a, $b) => $b->size_bytes <=> $a->size_bytes);
?>
<!DOCTYPE html>
<html lang="<?= I18n::getInstance()->getLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Monitor - Scouter</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/responsive.css">
    <link rel="icon" type="image/png" href="/logo.png">
    <link rel="stylesheet" href="../assets/vendor/material-symbols/material-symbols.css" />
    <style>
    /* Bento Monitor Layout */
    .mon-page { max-width: 1200px; margin: 0 auto; padding: 1.5rem 2rem 3rem; }

    .mon-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
    .mon-header h1 { font-size: 1.4rem; font-weight: 700; color: var(--text-primary); margin: 0; }
    .mon-header p { color: var(--text-secondary); font-size: 0.85rem; margin: 0.25rem 0 0; }

    .mon-live { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; color: var(--text-secondary); }
    .mon-live-dot { width: 8px; height: 8px; background: #4ade80; border-radius: 50%; animation: monBlink 2s infinite; }
    @keyframes monBlink { 0%,100% { opacity:1; } 50% { opacity:0.3; } }

    /* Utility bar */
    .mon-utility { display: flex; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
    .mon-utility-badge {
        display: inline-flex; align-items: center; gap: 0.4rem;
        padding: 0.4rem 0.75rem; border-radius: 8px;
        background: var(--background, #f5f7fa); font-size: 0.75rem; color: var(--text-secondary);
    }
    .mon-utility-badge .material-symbols-outlined { font-size: 15px; color: var(--text-tertiary); }
    .mon-utility-badge strong { color: var(--text-primary); font-family: monospace; }

    /* Bento grid */
    .mon-bento {
        display: grid;
        grid-template-columns: 280px 1fr;
        gap: 1.25rem;
        margin-bottom: 1.5rem;
    }
    @media (max-width: 860px) { .mon-bento { grid-template-columns: 1fr; } }

    /* Cards (reusing pj-card style) */
    .mon-card {
        background: var(--card-bg, #fff);
        border-radius: 16px;
        border: 1px solid rgba(0,0,0,0.06);
        box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 12px rgba(0,0,0,0.02);
        padding: 1.25rem;
        display: flex; flex-direction: column;
    }
    .mon-card-title {
        font-size: 0.7rem; font-weight: 600; color: var(--text-tertiary);
        text-transform: uppercase; letter-spacing: 0.6px; margin: 0 0 1rem;
        display: flex; align-items: center; gap: 0.4rem;
    }
    .mon-card-title .material-symbols-outlined { font-size: 16px; }

    /* Sidebar stats */
    .mon-sidebar { display: flex; flex-direction: column; gap: 1.25rem; }

    .mon-big-stat { display: flex; flex-direction: column; align-items: center; gap: 0.15rem; padding: 0.75rem 0; }
    .mon-big-stat-value { font-size: 2rem; font-weight: 800; color: var(--text-primary); line-height: 1; }
    .mon-big-stat-label { font-size: 0.7rem; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; }
    .mon-big-stat + .mon-big-stat { border-top: 1px solid rgba(0,0,0,0.05); }

    .mon-db-size { display: flex; flex-direction: column; gap: 0.5rem; }
    .mon-db-value { font-size: 1.6rem; font-weight: 800; color: var(--text-primary); text-align: center; }
    .mon-db-bar { height: 6px; border-radius: 3px; background: #e5e7eb; overflow: hidden; }
    .mon-db-bar-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, var(--primary-color), #a78bfa); transition: width 0.6s ease; }
    .mon-db-hint { font-size: 0.7rem; color: var(--text-tertiary); text-align: center; }

    /* Job health gauges */
    .mon-gauges { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; }
    .mon-gauge-item { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; padding: 1rem 0.5rem; }
    .mon-gauge-ring { position: relative; width: 64px; height: 64px; }
    .mon-gauge-ring svg { width: 64px; height: 64px; transform: rotate(-90deg); }
    .mon-gauge-bg { fill: none; stroke: #e5e7eb; stroke-width: 4; }
    .mon-gauge-fill { fill: none; stroke-width: 4; stroke-linecap: round; transition: stroke-dasharray 0.8s ease; }
    .mon-gauge-center {
        position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
        font-size: 1.1rem; font-weight: 800; color: var(--text-primary);
    }
    .mon-gauge-label { font-size: 0.65rem; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }

    /* Active jobs list */
    .mon-job-list { display: flex; flex-direction: column; gap: 0; }
    .mon-job-row {
        display: grid; grid-template-columns: 50px 1fr auto 100px 90px;
        align-items: center; gap: 1rem; padding: 0.75rem 0;
        border-bottom: 1px solid rgba(0,0,0,0.05); font-size: 0.85rem;
    }
    .mon-job-row:last-child { border-bottom: none; }
    .mon-job-id { font-family: monospace; font-weight: 700; color: var(--text-secondary); font-size: 0.8rem; }
    .mon-job-name { font-weight: 600; color: var(--text-primary); }
    .mon-job-dir { font-size: 0.7rem; color: var(--text-tertiary); }
    .mon-job-urls { font-family: monospace; font-weight: 700; color: #2563eb; text-align: right; }
    .mon-job-dur { font-family: monospace; font-size: 0.8rem; color: var(--text-secondary); text-align: right; }
    .mon-empty { padding: 2.5rem; text-align: center; color: var(--text-tertiary); font-size: 0.85rem; }
    .mon-empty .material-symbols-outlined { font-size: 2.5rem; opacity: 0.25; display: block; margin-bottom: 0.5rem; }

    /* Status badges */
    .mon-badge {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 8px; border-radius: 6px;
        font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;
    }
    .mon-badge-running { background: #dbeafe; color: #1e40af; }
    .mon-badge-queued { background: #fef3c7; color: #92400e; }
    .mon-badge-stopping { background: #fee2e2; color: #991b1b; }
    .mon-badge-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .mon-badge-running .mon-badge-dot { animation: monBlink 1.5s infinite; }

    /* Projects table */
    .mon-proj-list { display: flex; flex-direction: column; }
    .mon-proj-row {
        display: grid; grid-template-columns: 1fr 1fr 70px 90px 80px;
        align-items: center; gap: 1rem; padding: 0.7rem 0;
        border-bottom: 1px solid rgba(0,0,0,0.05); font-size: 0.85rem;
    }
    .mon-proj-row:last-child { border-bottom: none; }
    .mon-proj-head {
        display: grid; grid-template-columns: 1fr 1fr 70px 90px 80px;
        gap: 1rem; padding: 0 0 0.5rem;
        font-size: 0.65rem; font-weight: 700; color: var(--text-tertiary);
        text-transform: uppercase; letter-spacing: 0.5px;
        border-bottom: 2px solid rgba(0,0,0,0.06);
    }
    .mon-proj-name { font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mon-proj-owner { font-size: 0.8rem; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mon-proj-count { font-family: monospace; font-weight: 700; color: var(--text-primary); text-align: center; }
    .mon-proj-size { font-family: monospace; font-weight: 700; text-align: right; }
    .mon-proj-link {
        display: inline-flex; align-items: center; gap: 0.25rem; justify-content: center;
        padding: 0.3rem 0.6rem; border-radius: 8px; border: 1px solid var(--border-color);
        background: var(--card-bg, #fff); color: var(--text-primary);
        font-size: 0.75rem; font-weight: 600; text-decoration: none; cursor: pointer;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .mon-proj-link:hover { border-color: var(--primary-color); box-shadow: 0 2px 8px rgba(78,205,196,0.15); }
    .mon-proj-link .material-symbols-outlined { font-size: 14px; color: var(--primary-color); }
    </style>
</head>
<body>
    <?php $headerContext = 'admin'; $isInSubfolder = true; include(__DIR__ . '/../components/top-header.php'); ?>

    <div class="mon-page">
        <!-- Header -->
        <div class="mon-header">
            <div>
                <h1><?= __('monitor.page_title') ?></h1>
                <p><?= __('monitor.subtitle') ?></p>
            </div>
            <div class="mon-live">
                <span class="mon-live-dot"></span>
                <?= __('monitor.auto_refresh') ?>
            </div>
        </div>

        <!-- Utility bar -->
        <div class="mon-utility">
            <span class="mon-utility-badge">
                <span class="material-symbols-outlined">dns</span>
                <?= __('monitor.ip_address') ?> <strong><?= htmlspecialchars($serverIp) ?></strong>
            </span>
            <span class="mon-utility-badge">
                <span class="material-symbols-outlined">schedule</span>
                <?= __('monitor.server_time') ?> <strong><?= $serverTime ?></strong>
            </span>
            <span class="mon-utility-badge">
                <span class="material-symbols-outlined">public</span>
                <?= __('monitor.timezone') ?> <strong><?= $serverTimezone ?> (UTC<?= $serverUtcOffset ?>)</strong>
            </span>
        </div>

        <!-- Bento grid: sidebar + main -->
        <div class="mon-bento">
            <!-- Sidebar -->
            <div class="mon-sidebar">
                <!-- Global stats card -->
                <div class="mon-card">
                    <div class="mon-card-title"><span class="material-symbols-outlined">insights</span> <?= __('monitor.overview') ?></div>
                    <div class="mon-big-stat">
                        <div class="mon-big-stat-value"><?= $globalProjectCount ?></div>
                        <div class="mon-big-stat-label"><?= __('monitor.projects') ?></div>
                    </div>
                    <div class="mon-big-stat">
                        <div class="mon-big-stat-value"><?= $globalCrawlCount ?></div>
                        <div class="mon-big-stat-label"><?= __('monitor.crawls') ?></div>
                    </div>
                </div>

                <!-- Database card -->
                <div class="mon-card">
                    <div class="mon-card-title"><span class="material-symbols-outlined">database</span> <?= __('monitor.storage') ?></div>
                    <div class="mon-db-size">
                        <div class="mon-db-value"><?= monitorFormatBytes($globalDbSize) ?></div>
                        <div class="mon-db-bar"><div class="mon-db-bar-fill" style="width: <?= min(100, round($globalDbSize / (50 * 1073741824) * 100)) ?>%;"></div></div>
                        <div class="mon-db-hint"><?= __('monitor.db_size_hint') ?></div>
                    </div>
                </div>

            </div>

            <!-- Main content -->
            <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                <!-- Job Health -->
                <div class="mon-card">
                    <div class="mon-card-title"><span class="material-symbols-outlined">monitor_heart</span> <?= __('monitor.job_health') ?></div>
                    <div class="mon-gauges">
                        <div class="mon-gauge-item">
                            <div class="mon-gauge-ring">
                                <svg viewBox="0 0 36 36">
                                    <circle class="mon-gauge-bg" cx="18" cy="18" r="15.9"/>
                                    <circle class="mon-gauge-fill" cx="18" cy="18" r="15.9" stroke="#3b82f6" stroke-dasharray="0, 100" id="gauge-running"/>
                                </svg>
                                <span class="mon-gauge-center" id="gauge-running-val">-</span>
                            </div>
                            <span class="mon-gauge-label"><?= __('monitor.running') ?></span>
                        </div>
                        <div class="mon-gauge-item">
                            <div class="mon-gauge-ring">
                                <svg viewBox="0 0 36 36">
                                    <circle class="mon-gauge-bg" cx="18" cy="18" r="15.9"/>
                                    <circle class="mon-gauge-fill" cx="18" cy="18" r="15.9" stroke="#f59e0b" stroke-dasharray="0, 100" id="gauge-queued"/>
                                </svg>
                                <span class="mon-gauge-center" id="gauge-queued-val">-</span>
                            </div>
                            <span class="mon-gauge-label"><?= __('monitor.queued') ?></span>
                        </div>
                        <div class="mon-gauge-item">
                            <div class="mon-gauge-ring">
                                <svg viewBox="0 0 36 36">
                                    <circle class="mon-gauge-bg" cx="18" cy="18" r="15.9"/>
                                    <circle class="mon-gauge-fill" cx="18" cy="18" r="15.9" stroke="#22c55e" stroke-dasharray="0, 100" id="gauge-completed"/>
                                </svg>
                                <span class="mon-gauge-center" id="gauge-completed-val">-</span>
                            </div>
                            <span class="mon-gauge-label"><?= __('monitor.completed') ?></span>
                        </div>
                        <div class="mon-gauge-item">
                            <div class="mon-gauge-ring">
                                <svg viewBox="0 0 36 36">
                                    <circle class="mon-gauge-bg" cx="18" cy="18" r="15.9"/>
                                    <circle class="mon-gauge-fill" cx="18" cy="18" r="15.9" stroke="#ef4444" stroke-dasharray="0, 100" id="gauge-failed"/>
                                </svg>
                                <span class="mon-gauge-center" id="gauge-failed-val" style="color: #ef4444;">-</span>
                            </div>
                            <span class="mon-gauge-label"><?= __('monitor.failed') ?></span>
                        </div>
                        <div class="mon-gauge-item">
                            <div class="mon-gauge-ring">
                                <svg viewBox="0 0 36 36">
                                    <circle class="mon-gauge-bg" cx="18" cy="18" r="15.9"/>
                                    <circle class="mon-gauge-fill" cx="18" cy="18" r="15.9" stroke="#a855f7" stroke-dasharray="0, 100" id="gauge-workers"/>
                                </svg>
                                <span class="mon-gauge-center" id="gauge-workers-val">-</span>
                            </div>
                            <span class="mon-gauge-label"><?= __('monitor.workers_busy') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Active Jobs -->
                <div class="mon-card">
                    <div class="mon-card-title"><span class="material-symbols-outlined">bolt</span> <?= __('monitor.active_jobs') ?></div>
                    <div class="mon-job-list" id="jobs-body">
                        <div class="mon-empty">
                            <span class="material-symbols-outlined">hourglass_empty</span>
                            <?= __('common.loading') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Projects table -->
        <div class="mon-card">
            <div class="mon-card-title">
                <span class="material-symbols-outlined">folder</span>
                <?= __('monitor.projects') ?>
                <span style="font-size: 0.7rem; background: var(--background, #f5f7fa); color: var(--text-secondary); padding: 2px 8px; border-radius: 10px; font-weight: 600; margin-left: 0.25rem;"><?= count($projects) ?></span>
            </div>
            <div class="mon-proj-head">
                <span>Project</span>
                <span>Owner</span>
                <span style="text-align: center;">Crawls</span>
                <span style="text-align: right;">Size</span>
                <span></span>
            </div>
            <div class="mon-proj-list">
                <?php if (empty($projects)): ?>
                <div class="mon-empty">
                    <span class="material-symbols-outlined">folder_off</span>
                    No projects
                </div>
                <?php else: foreach ($projects as $proj):
                    $sizeColor = $proj->size_bytes >= 1073741824 ? '#ef4444' : ($proj->size_bytes >= 104857600 ? '#d97706' : 'var(--text-primary)');
                ?>
                <div class="mon-proj-row">
                    <span class="mon-proj-name" title="<?= htmlspecialchars($proj->name) ?>"><?= htmlspecialchars($proj->name) ?></span>
                    <span class="mon-proj-owner" title="<?= htmlspecialchars($proj->owner) ?>"><?= htmlspecialchars($proj->owner) ?></span>
                    <span class="mon-proj-count"><?= $proj->crawl_count ?></span>
                    <span class="mon-proj-size" style="color: <?= $sizeColor ?>;"><?= monitorFormatBytes($proj->size_bytes) ?></span>
                    <a href="../project.php?id=<?= $proj->id ?>" class="mon-proj-link">
                        <span class="material-symbols-outlined">open_in_new</span>
                        View
                    </a>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <script>
    const CIRC = 2 * Math.PI * 15.9; // ~99.9

    function setGauge(id, value, total) {
        const el = document.getElementById(id);
        const valEl = document.getElementById(id + '-val');
        if (!el || !valEl) return;
        valEl.textContent = value;
        const pct = total > 0 ? Math.min(100, (value / total) * 100) : 0;
        el.setAttribute('stroke-dasharray', `${pct} ${100 - pct}`);
    }

    async function refresh() {
        try {
            const res = await fetch('../api/monitor/system');
            const data = await res.json();
            if (!data.success) return;

            const s = data.stats;
            const total = s.running + s.queued + s.completed + s.failed;

            setGauge('gauge-running', s.running, Math.max(total, 1));
            setGauge('gauge-queued', s.queued, Math.max(total, 1));
            setGauge('gauge-completed', s.completed, Math.max(total, 1));
            setGauge('gauge-failed', s.failed, Math.max(total, 1));
            setGauge('gauge-workers', data.workers_occupied, data.workers_total || 10);

            const container = document.getElementById('jobs-body');
            if (data.active_jobs.length === 0) {
                container.innerHTML = `<div class="mon-empty"><span class="material-symbols-outlined">check_circle</span><?= __('monitor.no_active_jobs') ?></div>`;
            } else {
                container.innerHTML = data.active_jobs.map(j => `
                    <div class="mon-job-row">
                        <span class="mon-job-id">#${j.id}</span>
                        <div>
                            <div class="mon-job-name">${j.project_name || j.project_dir}</div>
                            <div class="mon-job-dir">${j.project_dir}</div>
                        </div>
                        <span class="mon-badge mon-badge-${j.status}"><span class="mon-badge-dot"></span>${j.status}</span>
                        <span class="mon-job-urls">${j.progress.toLocaleString()}</span>
                        <span class="mon-job-dur">${j.duration}</span>
                    </div>
                `).join('');
            }
        } catch(e) { console.error(e); }
    }

    refresh();
    setInterval(refresh, 5000);
    </script>
</body>
</html>
