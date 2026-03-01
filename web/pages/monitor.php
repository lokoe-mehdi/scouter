<?php
require_once(__DIR__ . '/init.php');
$auth->requireAdmin(false);
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
                <h1 class="page-title">System Monitor</h1>
                <p style="color: var(--text-secondary);">État des jobs et crawls en temps réel</p>
            </div>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <button id="btn-test-crawls" onclick="launchTestCrawls()" style="background: #8b5cf6; color: white; border: none; padding: 0.5rem 1rem; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                    <span class="material-symbols-outlined" style="font-size: 18px;">rocket_launch</span>
                    Test 5 Crawls
                </button>
                <div style="font-size: 0.85rem; color: var(--text-secondary);">
                    <span class="refresh-dot"></span>Auto-refresh (5s)
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
                    <p>Running</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #fef3c7; color: #d97706;">
                    <span class="material-symbols-outlined">schedule</span>
                </div>
                <div class="stat-info">
                    <h3 id="stat-queued">-</h3>
                    <p>Queued</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #dcfce7; color: #16a34a;">
                    <span class="material-symbols-outlined">check_circle</span>
                </div>
                <div class="stat-info">
                    <h3 id="stat-completed">-</h3>
                    <p>Completed</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #fee2e2; color: #dc2626;">
                    <span class="material-symbols-outlined">error</span>
                </div>
                <div class="stat-info">
                    <h3 id="stat-failed">-</h3>
                    <p>Failed</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #f3e8ff; color: #9333ea;">
                    <span class="material-symbols-outlined">memory</span>
                </div>
                <div class="stat-info">
                    <h3 id="stat-workers">-</h3>
                    <p>Workers Busy</p>
                </div>
            </div>
        </div>

        <!-- Active Jobs -->
        <h2 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-primary);">Active Jobs</h2>
        <table class="jobs-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Project</th>
                    <th>Status</th>
                    <th>URLs Crawled</th>
                    <th>Duration</th>
                </tr>
            </thead>
            <tbody id="jobs-body">
                <tr><td colspan="5" style="text-align:center; padding:2rem; color:#94a3b8;">Chargement...</td></tr>
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
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:2rem; color:#94a3b8;">Aucun job actif</td></tr>';
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
        
        async function launchTestCrawls() {
            const btn = document.getElementById('btn-test-crawls');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 18px;">hourglass_empty</span> Création...';
            
            try {
                const res = await fetch('../api/monitor/test-crawls', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ count: 5, url: 'https://lokoe.fr' })
                });
                const data = await res.json();
                
                if (data.success) {
                    btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 18px;">check</span> ' + data.message;
                    btn.style.background = '#16a34a';
                    refresh();
                    setTimeout(() => {
                        btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 18px;">rocket_launch</span> Test 5 Crawls';
                        btn.style.background = '#8b5cf6';
                        btn.disabled = false;
                    }, 3000);
                } else {
                    throw new Error(data.error || 'Erreur');
                }
            } catch(e) {
                btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 18px;">error</span> Erreur';
                btn.style.background = '#dc2626';
                console.error(e);
                setTimeout(() => {
                    btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 18px;">rocket_launch</span> Test 5 Crawls';
                    btn.style.background = '#8b5cf6';
                    btn.disabled = false;
                }, 3000);
            }
        }
    </script>
</body>
</html>
