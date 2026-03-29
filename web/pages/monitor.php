<?php
require_once(__DIR__ . '/init.php');
$auth->requireAdmin(false);
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Monitor - Scouter</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="icon" type="image/png" href="/logo.png">
    <link rel="stylesheet" href="../assets/vendor/material-symbols/material-symbols.css" />
    <style>
        .monitor-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
        }
        
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        
        .stat-info h3 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .stat-info p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .jobs-table {
            width: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .jobs-table th {
            text-align: left;
            padding: 1rem;
            background: #f8fafc;
            color: #64748b;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        
        .jobs-table td {
            padding: 1rem;
            border-top: 1px solid #e2e8f0;
            color: #334155;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-running { background: #dbeafe; color: #1e40af; }
        .status-queued { background: #fef3c7; color: #92400e; }
        .status-stopping { background: #fee2e2; color: #991b1b; }
        
        .pulse {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
            display: inline-block;
            margin-right: 4px;
        }
        
        .status-running .pulse { animation: pulse 1.5s infinite; }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        .refresh-dot {
            width: 8px;
            height: 8px;
            background: #4ade80;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
            animation: blink 2s infinite;
        }
        
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
    </style>
</head>
<body>
    <?php $headerContext = 'admin'; $isInSubfolder = true; include(__DIR__ . '/../components/top-header.php'); ?>

    <div class="container" style="max-width: 1100px; padding: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <div>
                <h1 class="page-title"><?= __('monitor.page_title') ?></h1>
                <p style="color: var(--text-secondary);"><?= __('monitor.subtitle') ?></p>
            </div>
            <div style="font-size: 0.85rem; color: var(--text-secondary);">
                <span class="refresh-dot"></span><?= __('monitor.auto_refresh') ?>
            </div>
        </div>

        <!-- Server Info -->
        <?php
        $serverTime = date('Y-m-d H:i:s');
        $serverTimezone = date_default_timezone_get();
        $serverUtcOffset = date('P');
        $serverIp = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
        ?>
        <div style="display: flex; gap: 1.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1rem; background: var(--card-bg, white); border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.8rem;">
                <span class="material-symbols-outlined" style="font-size: 18px; color: var(--text-tertiary);">schedule</span>
                <span style="color: var(--text-secondary);">Server time</span>
                <strong style="color: var(--text-primary);"><?= $serverTime ?></strong>
            </div>
            <div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1rem; background: var(--card-bg, white); border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.8rem;">
                <span class="material-symbols-outlined" style="font-size: 18px; color: var(--text-tertiary);">public</span>
                <span style="color: var(--text-secondary);">Timezone</span>
                <strong style="color: var(--text-primary);"><?= $serverTimezone ?> (UTC<?= $serverUtcOffset ?>)</strong>
            </div>
            <div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1rem; background: var(--card-bg, white); border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.8rem;">
                <span class="material-symbols-outlined" style="font-size: 18px; color: var(--text-tertiary);">dns</span>
                <span style="color: var(--text-secondary);">Server IP</span>
                <strong style="color: var(--text-primary); font-family: monospace;"><?= htmlspecialchars($serverIp) ?></strong>
            </div>
        </div>

        <!-- Global overview -->
        <?php
        $globalProjectCount = (int)$pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
        $globalCrawlCount = (int)$pdo->query("SELECT COUNT(*) FROM crawls")->fetchColumn();
        $globalDbSize = $pdo->query("SELECT pg_database_size(current_database()) AS s")->fetch(PDO::FETCH_OBJ)->s;
        $globalDbSizeFormatted = $globalDbSize >= 1073741824
            ? round($globalDbSize / 1073741824, 2) . ' GB'
            : ($globalDbSize >= 1048576 ? round($globalDbSize / 1048576, 1) . ' MB' : round($globalDbSize / 1024, 0) . ' KB');
        ?>
        <div class="monitor-grid" style="margin-bottom: 1.5rem;">
            <div class="stat-card">
                <div class="stat-icon" style="background: #e0f2fe; color: #0284c7;">
                    <span class="material-symbols-outlined">folder</span>
                </div>
                <div class="stat-info">
                    <h3><?= $globalProjectCount ?></h3>
                    <p>Projects</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #f0fdf4; color: #16a34a;">
                    <span class="material-symbols-outlined">travel_explore</span>
                </div>
                <div class="stat-info">
                    <h3><?= $globalCrawlCount ?></h3>
                    <p>Crawls</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #fdf4ff; color: #a855f7;">
                    <span class="material-symbols-outlined">database</span>
                </div>
                <div class="stat-info">
                    <h3><?= $globalDbSizeFormatted ?></h3>
                    <p>Database size</p>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="monitor-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #dbeafe; color: #2563eb;">
                    <span class="material-symbols-outlined">play_circle</span>
                </div>
                <div class="stat-info">
                    <h3 id="stat-running">-</h3>
                    <p><?= __('monitor.running') ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #fef3c7; color: #d97706;">
                    <span class="material-symbols-outlined">schedule</span>
                </div>
                <div class="stat-info">
                    <h3 id="stat-queued">-</h3>
                    <p><?= __('monitor.queued') ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #dcfce7; color: #16a34a;">
                    <span class="material-symbols-outlined">check_circle</span>
                </div>
                <div class="stat-info">
                    <h3 id="stat-completed">-</h3>
                    <p><?= __('monitor.completed') ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #fee2e2; color: #dc2626;">
                    <span class="material-symbols-outlined">error</span>
                </div>
                <div class="stat-info">
                    <h3 id="stat-failed">-</h3>
                    <p><?= __('monitor.failed') ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #f3e8ff; color: #9333ea;">
                    <span class="material-symbols-outlined">memory</span>
                </div>
                <div class="stat-info">
                    <h3 id="stat-workers">-</h3>
                    <p><?= __('monitor.workers_busy') ?></p>
                </div>
            </div>
        </div>

        <!-- Active Jobs -->
        <h2 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-primary);"><?= __('monitor.active_jobs') ?></h2>
        <table class="jobs-table">
            <thead>
                <tr>
                    <th><?= __('monitor.th_id') ?></th>
                    <th><?= __('monitor.th_project') ?></th>
                    <th><?= __('monitor.th_status') ?></th>
                    <th><?= __('monitor.th_urls_crawled') ?></th>
                    <th><?= __('monitor.th_duration') ?></th>
                </tr>
            </thead>
            <tbody id="jobs-body">
                <tr><td colspan="5" style="text-align:center; padding:2rem; color:#94a3b8;"><?= __('common.loading') ?></td></tr>
            </tbody>
        </table>

        <!-- Projects Overview -->
        <?php
        $projectsQuery = $pdo->query("
            SELECT p.id, p.name, u.email AS owner,
                   COUNT(DISTINCT cr.id) AS crawl_count
            FROM projects p
            JOIN users u ON u.id = p.user_id
            LEFT JOIN crawls cr ON cr.project_id = p.id
            GROUP BY p.id, p.name, u.email
            ORDER BY p.id
        ");
        $projects = $projectsQuery->fetchAll(PDO::FETCH_OBJ);

        // Compute size per project
        $tables = ['pages', 'links', 'html', 'page_schemas', 'duplicate_clusters', 'redirect_chains'];
        foreach ($projects as $proj) {
            $proj->size_bytes = 0;
            $crawlIdsStmt = $pdo->prepare("SELECT id FROM crawls WHERE project_id = :pid");
            $crawlIdsStmt->execute([':pid' => $proj->id]);
            $cids = $crawlIdsStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cids as $cid) {
                foreach ($tables as $t) {
                    try {
                        $s = $pdo->query("SELECT pg_total_relation_size('{$t}_{$cid}') AS s")->fetch(PDO::FETCH_OBJ);
                        if ($s) $proj->size_bytes += (int)$s->s;
                    } catch (Exception $e) {}
                }
            }
        }

        // Sort by size descending
        usort($projects, fn($a, $b) => $b->size_bytes <=> $a->size_bytes);

        function formatBytes($bytes) {
            if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
            if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
            if ($bytes >= 1024) return round($bytes / 1024, 0) . ' KB';
            return $bytes . ' B';
        }
        ?>

        <h2 style="font-size: 1rem; margin: 2rem 0 1rem; color: var(--text-primary);">
            <span class="material-symbols-outlined" style="font-size: 18px; vertical-align: text-bottom;">folder</span>
            Projects (<?= count($projects) ?>)
        </h2>
        <table class="jobs-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Owner</th>
                    <th>Crawls</th>
                    <th>Size</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($projects)): ?>
                <tr><td colspan="5" style="text-align:center; padding:2rem; color:#94a3b8;">No projects</td></tr>
                <?php else: foreach ($projects as $proj): ?>
                <tr>
                    <td style="font-weight: 600;"><?= htmlspecialchars($proj->name) ?></td>
                    <td style="color: var(--text-secondary); font-size: 0.85rem;"><?= htmlspecialchars($proj->owner) ?></td>
                    <td style="font-family: monospace; font-weight: 600;"><?= $proj->crawl_count ?></td>
                    <td style="font-family: monospace; font-weight: 600; color: <?= $proj->size_bytes >= 1073741824 ? '#dc2626' : ($proj->size_bytes >= 104857600 ? '#d97706' : '#16a34a') ?>;"><?= formatBytes($proj->size_bytes) ?></td>
                    <td><a href="../project.php?id=<?= $proj->id ?>" style="color: var(--primary-color); text-decoration: none; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.25rem;"><span class="material-symbols-outlined" style="font-size: 16px;">open_in_new</span>View</a></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        async function refresh() {
            try {
                const res = await fetch('../api/monitor/system');
                const data = await res.json();
                if (!data.success) return;
                
                document.getElementById('stat-running').textContent = data.stats.running;
                document.getElementById('stat-queued').textContent = data.stats.queued;
                document.getElementById('stat-completed').textContent = data.stats.completed;
                document.getElementById('stat-failed').textContent = data.stats.failed;
                document.getElementById('stat-workers').textContent = data.workers_occupied;
                
                const tbody = document.getElementById('jobs-body');
                if (data.active_jobs.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:2rem; color:#94a3b8;"><?= __('monitor.no_active_jobs') ?></td></tr>';
                } else {
                    tbody.innerHTML = data.active_jobs.map(j => `
                        <tr>
                            <td style="font-family:monospace;font-weight:600;">#${j.id}</td>
                            <td>
                                <div style="font-weight:500;">${j.project_name || j.project_dir}</div>
                                <div style="font-size:0.75rem;color:#64748b;">${j.project_dir}</div>
                            </td>
                            <td><span class="status-badge status-${j.status}"><span class="pulse"></span>${j.status}</span></td>
                            <td style="font-family:monospace;font-weight:600;color:#2563eb;">${j.progress.toLocaleString()}</td>
                            <td style="font-family:monospace;">${j.duration}</td>
                        </tr>
                    `).join('');
                }
            } catch(e) { console.error(e); }
        }
        
        refresh();
        setInterval(refresh, 5000);
    </script>
</body>
</html>
