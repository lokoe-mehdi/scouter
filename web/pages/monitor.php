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

// Espace disque réel (jauge de stockage adaptative).
// On mesure le FS root du conteneur web : dans un setup Docker classique le volume
// Postgres vit sur le même disque physique que l'image, donc l'ordre de grandeur
// est correct. Si la fonction échoue (sandbox restreinte), on retombe sur l'ancien
// comportement à 50 Go.
//
// La jauge montre la part de la DB sur le disque total (fraction généralement
// petite). La couleur passe en orange/rouge quand le disque arrive à saturation
// — utile comme alerte indépendamment de la taille de la DB elle-même.
$diskTotalSpace = @disk_total_space('/');
$diskFreeSpace = @disk_free_space('/');
$diskKnown = ($diskTotalSpace !== false && $diskTotalSpace > 0 && $diskFreeSpace !== false);
if (!$diskKnown) {
    $diskTotalSpace = 50 * 1073741824;
    $diskFreeSpace = max(0, $diskTotalSpace - $globalDbSize);
}
$dbBarPct = $diskTotalSpace > 0 ? min(100, ($globalDbSize / $diskTotalSpace) * 100) : 0;
$diskFreePct = $diskTotalSpace > 0 ? ($diskFreeSpace / $diskTotalSpace) * 100 : 100;
if ($diskFreePct < 10) {
    $diskBarBg = 'linear-gradient(90deg, #ef4444, #dc2626)';
} elseif ($diskFreePct < 25) {
    $diskBarBg = 'linear-gradient(90deg, #f59e0b, #d97706)';
} else {
    $diskBarBg = 'linear-gradient(90deg, var(--primary-color), #a78bfa)';
}

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
$partitionSizes = [];      // crawl_id => PG crawl-data bytes
foreach ($sizeStmt->fetchAll(PDO::FETCH_OBJ) as $row) {
    // Extract crawl_id from table name (e.g. pages_123 → 123)
    preg_match('/_(\d+)$/', $row->tablename, $m);
    if ($m) {
        $partitionSizes[(int)$m[1]] = ($partitionSizes[(int)$m[1]] ?? 0) + (int)$row->size_bytes;
    }
}
$pgCrawlDataSize = array_sum($partitionSizes); // total PG crawl-data (partitions)

// ClickHouse storage (compressed, on-disk) from system.parts. Deux familles de
// tables, partitionnées différemment :
//   - tables de crawl  → PARTITION BY crawl_id   → `partition` EST le crawl id
//   - tables gsc_*     → PARTITION BY project_id → `partition` EST le project id
// Il faut donc les séparer : sinon les octets GSC d'un projet sont attribués au
// crawl qui porte le même id.
$chPartitionSizes = [];   // crawl_id   => bytes (tables de crawl)
$gscProjectSizes  = [];   // project_id => bytes (tables gsc_*)
$clickhouseSize = 0;
$clickhouseEnabled = \App\Database\ClickHouseDatabase::enabled();
if ($clickhouseEnabled) {
    try {
        $ch = \App\Database\ClickHouseDatabase::getInstance();
        foreach ($ch->select("SELECT startsWith(table, 'gsc_') AS is_gsc, partition, sum(bytes_on_disk) AS bytes FROM system.parts WHERE database = {db:String} AND active = 1 GROUP BY is_gsc, partition", ['db' => $ch->getDatabase()]) as $row) {
            $bytes = (int)($row['bytes'] ?? 0);
            $key   = (int)($row['partition'] ?? 0);
            if ((int)($row['is_gsc'] ?? 0) === 1) {
                $gscProjectSizes[$key] = ($gscProjectSizes[$key] ?? 0) + $bytes;
            } else {
                $chPartitionSizes[$key] = ($chPartitionSizes[$key] ?? 0) + $bytes;
            }
            $clickhouseSize += $bytes;
        }
    } catch (\Throwable $e) {
        $clickhouseEnabled = false;
    }
}
$gscSize = array_sum($gscProjectSizes); // total GSC, toutes projets confondus

// Storage totals: PG database + ClickHouse, and the combined global figure.
$storageGlobal = $globalDbSize + $clickhouseSize;

foreach ($projects as $proj) {
    $proj->size_bytes = 0;     // combined (PG crawl data + ClickHouse + GSC)
    $proj->size_pg = 0;
    $proj->size_ch = 0;
    $proj->size_gsc = $gscProjectSizes[(int)$proj->id] ?? 0;
    $cids = $pdo->prepare("SELECT id FROM crawls WHERE project_id = :pid AND status != 'deleting'");
    $cids->execute([':pid' => $proj->id]);
    foreach ($cids->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $proj->size_pg += $partitionSizes[(int)$cid] ?? 0;
        $proj->size_ch += $chPartitionSizes[(int)$cid] ?? 0;
    }
    $proj->size_bytes = $proj->size_pg + $proj->size_ch + $proj->size_gsc;
}
usort($projects, fn($a, $b) => $b->size_bytes <=> $a->size_bytes);

// Per-crawl storage (PG vs CH) + migration state — lets the admin verify what's
// been backfilled to ClickHouse and what's still on PostgreSQL.
// Crawls are grouped by project (a project = a domain): each group sums the PG &
// ClickHouse storage of its crawls and exposes a combined "total". Groups are
// sorted by that total (heaviest first); within a group, crawls stay newest-first.
$migCh = 0; $migPg = 0;
$storageGroups = [];   // project_id => group bucket (name, domain, crawls, sums)
foreach ($pdo->query("
    SELECT cr.id, cr.domain, cr.path, COALESCE(cr.data_store,'pg') AS data_store, cr.status, cr.crawled,
           cr.project_id, p.name AS project_name
    FROM crawls cr
    LEFT JOIN projects p ON p.id = cr.project_id
    WHERE cr.status != 'deleting'
    ORDER BY cr.id DESC
")->fetchAll(PDO::FETCH_OBJ) as $c) {
    $c->size_pg = $partitionSizes[(int)$c->id] ?? 0;
    $c->size_ch = $chPartitionSizes[(int)$c->id] ?? 0;
    if ($c->data_store === 'clickhouse') { $migCh++; } else { $migPg++; }

    $pid = (int)$c->project_id;
    if (!isset($storageGroups[$pid])) {
        $storageGroups[$pid] = (object)[
            'project_id'   => $pid,
            'project_name' => $c->project_name ?: ('#' . $pid),
            'domain'       => $c->domain,
            'crawls'       => [],
            'size_pg'      => 0,
            'size_ch'      => 0,
            'size_crawl'   => 0,   // PG + CH = tout le poids issu des crawls
            'size_gsc'     => $gscProjectSizes[$pid] ?? 0,
            'size_total'   => 0,
        ];
    }
    $g = $storageGroups[$pid];
    $g->crawls[] = $c;
    $g->size_pg += $c->size_pg;
    $g->size_ch += $c->size_ch;
    $g->size_crawl = $g->size_pg + $g->size_ch;
    $g->size_total = $g->size_crawl + $g->size_gsc;
}

// Un projet peut avoir des données GSC sans aucun crawl (connexion Search Console
// seule) : sans ça son poids serait invisible dans le tableau.
foreach ($gscProjectSizes as $pid => $bytes) {
    if ($bytes > 0 && !isset($storageGroups[$pid])) {
        $proj = null;
        foreach ($projects as $p) { if ((int)$p->id === (int)$pid) { $proj = $p; break; } }
        $storageGroups[$pid] = (object)[
            'project_id'   => (int)$pid,
            'project_name' => ($proj && $proj->name) ? $proj->name : ('#' . $pid),
            'domain'       => '',
            'crawls'       => [],
            'size_pg'      => 0,
            'size_ch'      => 0,
            'size_crawl'   => 0,
            'size_gsc'     => $bytes,
            'size_total'   => $bytes,
        ];
    }
}
$storageGroups = array_values($storageGroups);
usort($storageGroups, fn($a, $b) => $b->size_total <=> $a->size_total);

$migTotal = $migCh + $migPg;
$migPct = $migTotal > 0 ? round(100 * $migCh / $migTotal) : 100;
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
    <?php $assetBase = '../'; include __DIR__ . '/../partials/icon-font.php'; ?>
    <!-- customConfirm() : même modal de confirmation que search analytics / project -->
    <script src="../assets/i18n.js"></script>
    <script>ScouterI18n.init(<?= I18n::getInstance()->getJsTranslations() ?>, <?= json_encode(I18n::getInstance()->getLang()) ?>);</script>
    <script src="../assets/confirm-modal.js?v=<?= time() ?>"></script>
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

    /* ── Storage by project (grouped, collapsible) ─────────────────────────── */
    /* Tight table: rows glued together, white background, thin gray borders. */
    .mon-store { display: flex; flex-direction: column; }
    .mon-store-grid {
        display: grid;
        grid-template-columns: 22px 1fr 116px 90px 90px 90px 90px 100px 34px;
        gap: 0.5rem; align-items: center;
        padding: 0.5rem 0.6rem;
    }
    .mon-store-head {
        font-size: 0.65rem; font-weight: 700; color: var(--text-tertiary);
        text-transform: uppercase; letter-spacing: 0.5px;
        border-bottom: 2px solid var(--border-color);
        padding-top: 0; padding-bottom: 0.55rem;
    }
    .mon-store-body { max-height: 520px; overflow-y: auto; }

    /* Project group = just rows, no card / no gap */
    .mon-store-proj {
        cursor: pointer; user-select: none;
        border-bottom: 1px solid var(--border-color);
        transition: background 0.12s;
    }
    .mon-store-proj:hover { background: rgba(78,205,196,0.06); }
    .mon-store-group.is-open .mon-store-proj { background: rgba(78,205,196,0.05); }
    .mon-store-proj:focus-visible { outline: 2px solid var(--primary-color); outline-offset: -2px; }

    .mon-store-chevron { font-size: 18px; color: var(--text-tertiary); transition: transform 0.2s ease, color 0.15s; }
    .mon-store-group.is-open .mon-store-chevron { transform: rotate(90deg); color: var(--primary-color); }

    .mon-store-name { font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mon-store-count {
        font-weight: 600; color: var(--text-tertiary); font-size: 0.72rem;
        background: var(--background); padding: 1px 8px; border-radius: 999px; margin-left: 0.4rem;
    }

    /* Numeric cells */
    .mon-store-num { text-align: right; font-family: monospace; font-variant-numeric: tabular-nums; }
    .mon-store-pg    { color: #336791; font-weight: 700; }
    .mon-store-ch    { color: #b8860b; font-weight: 700; }
    .mon-store-crawl-size { color: var(--primary-dark, #3DB8AF); font-weight: 700; }
    .mon-store-gsc   { color: #3498DB; font-weight: 700; }
    .mon-store-total { color: var(--text-primary); font-weight: 800; }
    .mon-store-num.is-empty { color: var(--text-tertiary); font-weight: 500; }

    /* Crawl detail rows: indented, very light background, glued under project */
    .mon-store-crawl {
        background: var(--background);
        border-bottom: 1px solid var(--border-color);
        font-size: 0.82rem;
    }
    .mon-store-crawl-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-secondary); }
    .mon-store-crawl-id { font-family: monospace; font-variant-numeric: tabular-nums; color: var(--text-tertiary); margin-right: 0.4rem; }

    /* Store pill */
    .mon-store-pill {
        display: inline-flex; align-items: center; gap: 4px;
        font-size: 0.68rem; font-weight: 700; padding: 2px 9px; border-radius: 999px;
    }
    .mon-store-pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .mon-store-pill.is-ch { background: rgba(255,204,0,0.16); color: #b8860b; }
    .mon-store-pill.is-pg { background: rgba(51,103,145,0.12); color: #336791; }

    /* Suppression : discrète au repos, rouge au survol de la ligne */
    .mon-store-del {
        display: inline-flex; align-items: center; justify-content: center;
        width: 26px; height: 26px; padding: 0; margin-left: auto;
        border: none; border-radius: 6px; background: transparent; cursor: pointer;
        color: var(--text-tertiary); opacity: 0; transition: opacity 0.12s, background 0.12s, color 0.12s;
    }
    .mon-store-del .material-symbols-outlined { font-size: 17px; }
    .mon-store-proj:hover .mon-store-del,
    .mon-store-crawl:hover .mon-store-del,
    .mon-store-del:focus-visible { opacity: 1; }
    .mon-store-del:hover { background: rgba(231, 76, 60, 0.12); color: var(--danger, #E74C3C); }
    .mon-store-del:focus-visible { outline: 2px solid var(--danger, #E74C3C); outline-offset: 1px; }
    .mon-store-del[disabled] { opacity: 1; cursor: progress; color: var(--text-tertiary); }
    </style>
    <?php $assetBase = '../'; include __DIR__ . '/../partials/head-assets.php'; ?>
</head>
<!-- hx-boost : navigation hub sans rechargement (voir htmx.md §4bis) -->
<body hx-boost="true">
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
                        <?php $globalBarPct = $diskTotalSpace > 0 ? min(100, ($storageGlobal / $diskTotalSpace) * 100) : 0; ?>
                        <div class="mon-db-value"><?= monitorFormatBytes($storageGlobal) ?></div>
                        <div class="mon-db-bar"><div class="mon-db-bar-fill" style="width: <?= round($globalBarPct, 1) ?>%; background: <?= $diskBarBg ?>;"></div></div>
                        <!-- PostgreSQL vs ClickHouse breakdown -->
                        <?php
                            $pgPct = $storageGlobal > 0 ? round(100 * $globalDbSize / $storageGlobal) : 0;
                            $chPct = $storageGlobal > 0 ? round(100 * $clickhouseSize / $storageGlobal) : 0;
                        ?>
                        <div style="display:flex; gap:1rem; margin-top:0.75rem; flex-wrap:wrap;">
                            <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.85rem;">
                                <span style="width:10px;height:10px;border-radius:2px;background:#336791;display:inline-block;"></span>
                                <strong>PostgreSQL</strong>&nbsp;<?= monitorFormatBytes($globalDbSize) ?> <span style="opacity:.6;">(<?= $pgPct ?>%)</span>
                            </div>
                            <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.85rem;">
                                <span style="width:10px;height:10px;border-radius:2px;background:#ffcc00;display:inline-block;"></span>
                                <strong>ClickHouse</strong>&nbsp;<?php if ($clickhouseEnabled): ?><?= monitorFormatBytes($clickhouseSize) ?> <span style="opacity:.6;">(<?= $chPct ?>%)</span><?php else: ?><span style="opacity:.6;">désactivé</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="mon-db-hint" style="margin-top:0.6rem;">
                            Total = PostgreSQL (métadonnées + frontier) + ClickHouse (données de crawl). PG fond après migration/purge.
                            <?php if ($clickhouseEnabled && $gscSize > 0): ?>
                                <br>Dont <strong><?= monitorFormatBytes($gscSize) ?></strong> de données Search Console (tables <code>gsc_*</code>, incluses dans ClickHouse).
                            <?php endif; ?>
                            <?php if ($diskKnown): ?>
                                <br>
                                <?= str_replace(':free', monitorFormatBytes($diskFreeSpace), __('monitor.disk_free_hint')) ?>
                            <?php endif; ?>
                        </div>
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

        <!-- Per-crawl storage (PG vs ClickHouse) + migration progress -->
        <div class="mon-card">
            <div class="mon-card-title">
                <span class="material-symbols-outlined">swap_horiz</span>
                Stockage par crawl (PostgreSQL → ClickHouse)
                <span style="font-size: 0.7rem; background: var(--background, #f5f7fa); color: var(--text-secondary); padding: 2px 8px; border-radius: 10px; font-weight: 600; margin-left: 0.25rem;"><?= $migCh ?>/<?= $migTotal ?> migrés</span>
            </div>
            <div style="margin-bottom: 0.75rem;">
                <div class="mon-db-bar"><div class="mon-db-bar-fill" style="width: <?= $migPct ?>%; background: linear-gradient(90deg,#336791,#ffcc00);"></div></div>
                <div class="mon-db-hint">
                    <?= $migCh ?> sur ClickHouse, <?= $migPg ?> encore sur PostgreSQL (backfill en cours). PG = lecture des rapports lente ; CH = rapide.
                    <br>Crawl = PostgreSQL + ClickHouse. GSC = données Search Console (rattachées au projet, pas à un crawl). Total = Crawl + GSC.
                </div>
            </div>
            <div class="mon-store">
                <!-- Header -->
                <div class="mon-store-grid mon-store-head">
                    <span></span>
                    <span>Projet / Crawl</span>
                    <span style="text-align:center;">Store</span>
                    <span style="text-align:right;">PostgreSQL</span>
                    <span style="text-align:right;">ClickHouse</span>
                    <span style="text-align:right;">Crawl</span>
                    <span style="text-align:right;">GSC</span>
                    <span style="text-align:right;">Total</span>
                    <span></span>
                </div>
                <div class="mon-store-body">
                    <?php foreach ($storageGroups as $gi => $g): ?>
                    <div class="mon-store-group" data-group="<?= $gi ?>">
                        <!-- Project row (clickable, collapsed by default) -->
                        <div class="mon-store-grid mon-store-proj" role="button" tabindex="0" aria-expanded="false">
                            <span class="material-symbols-outlined mon-store-chevron">chevron_right</span>
                            <span class="mon-store-name" title="<?= htmlspecialchars($g->project_name) ?>">
                                <?= htmlspecialchars($g->project_name) ?>
                                <span class="mon-store-count"><?= count($g->crawls) ?> crawl<?= count($g->crawls) > 1 ? 's' : '' ?></span>
                            </span>
                            <span></span>
                            <span class="mon-store-num mon-store-pg<?= $g->size_pg > 0 ? '' : ' is-empty' ?>"><?= $g->size_pg > 0 ? monitorFormatBytes($g->size_pg) : '—' ?></span>
                            <span class="mon-store-num mon-store-ch<?= $g->size_ch > 0 ? '' : ' is-empty' ?>"><?= $g->size_ch > 0 ? monitorFormatBytes($g->size_ch) : '—' ?></span>
                            <span class="mon-store-num mon-store-crawl-size<?= $g->size_crawl > 0 ? '' : ' is-empty' ?>"><?= $g->size_crawl > 0 ? monitorFormatBytes($g->size_crawl) : '—' ?></span>
                            <span class="mon-store-num mon-store-gsc<?= $g->size_gsc > 0 ? '' : ' is-empty' ?>"><?= $g->size_gsc > 0 ? monitorFormatBytes($g->size_gsc) : '—' ?></span>
                            <span class="mon-store-num mon-store-total<?= $g->size_total > 0 ? '' : ' is-empty' ?>"><?= $g->size_total > 0 ? monitorFormatBytes($g->size_total) : '—' ?></span>
                            <span>
                                <button type="button" class="mon-store-del"
                                        title="Supprimer le projet"
                                        aria-label="Supprimer le projet <?= htmlspecialchars($g->project_name) ?>"
                                        data-project-id="<?= $g->project_id ?>"
                                        data-project-name="<?= htmlspecialchars($g->project_name) ?>"
                                        data-crawl-count="<?= count($g->crawls) ?>"
                                        data-size="<?= $g->size_total > 0 ? monitorFormatBytes($g->size_total) : '0 B' ?>"
                                        data-gsc="<?= $g->size_gsc > 0 ? monitorFormatBytes($g->size_gsc) : '' ?>">
                                    <span class="material-symbols-outlined">delete</span>
                                </button>
                            </span>
                        </div>
                        <!-- Crawl detail rows (hidden until expanded) -->
                        <div class="mon-store-crawls" style="display:none;">
                            <?php foreach ($g->crawls as $c):
                                $isCh = $c->data_store === 'clickhouse';
                                $cTotal = $c->size_pg + $c->size_ch;
                            ?>
                            <div class="mon-store-grid mon-store-crawl">
                                <span></span>
                                <span class="mon-store-crawl-name" title="<?= htmlspecialchars($c->domain) ?>">
                                    <span class="mon-store-crawl-id">#<?= $c->id ?></span><?= htmlspecialchars($c->domain) ?>
                                </span>
                                <span style="text-align:center;">
                                    <span class="mon-store-pill <?= $isCh ? 'is-ch' : 'is-pg' ?>"><?= $isCh ? 'ClickHouse' : 'PostgreSQL' ?></span>
                                </span>
                                <span class="mon-store-num<?= $c->size_pg > 0 ? '' : ' is-empty' ?>" style="<?= $c->size_pg > 0 ? 'color:#336791;' : '' ?>"><?= $c->size_pg > 0 ? monitorFormatBytes($c->size_pg) : '—' ?></span>
                                <span class="mon-store-num<?= $c->size_ch > 0 ? '' : ' is-empty' ?>" style="<?= $c->size_ch > 0 ? 'color:#b8860b;' : '' ?>"><?= $c->size_ch > 0 ? monitorFormatBytes($c->size_ch) : '—' ?></span>
                                <span class="mon-store-num<?= $cTotal > 0 ? '' : ' is-empty' ?>"><?= $cTotal > 0 ? monitorFormatBytes($cTotal) : '—' ?></span>
                                <!-- La GSC est rattachée au projet, pas au crawl -->
                                <span class="mon-store-num is-empty" title="Données Search Console : rattachées au projet">—</span>
                                <span class="mon-store-num mon-store-total<?= $cTotal > 0 ? '' : ' is-empty' ?>"><?= $cTotal > 0 ? monitorFormatBytes($cTotal) : '—' ?></span>
                                <span>
                                    <?php if (!empty($c->path)): // l'API de suppression identifie le crawl par son path ?>
                                    <button type="button" class="mon-store-del"
                                            title="Supprimer le crawl"
                                            aria-label="Supprimer le crawl #<?= $c->id ?>"
                                            data-crawl-path="<?= htmlspecialchars($c->path) ?>"
                                            data-crawl-id="<?= $c->id ?>"
                                            data-domain="<?= htmlspecialchars((string)$c->domain) ?>"
                                            data-size="<?= $cTotal > 0 ? monitorFormatBytes($cTotal) : '0 B' ?>">
                                        <span class="material-symbols-outlined">delete</span>
                                    </button>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($g->crawls)): ?>
                            <div class="mon-store-grid mon-store-crawl">
                                <span></span>
                                <span class="mon-store-crawl-name">Aucun crawl — données Search Console uniquement</span>
                                <span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
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

    // Per-project storage groups: collapsed by default, click to expand/collapse.
    function toggleStoreGroup(row) {
        const group = row.closest('.mon-store-group');
        const detail = group && group.querySelector('.mon-store-crawls');
        if (!detail) return;
        const open = group.classList.toggle('is-open');
        detail.style.display = open ? '' : 'none';
        row.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    document.querySelectorAll('.mon-store-proj').forEach(row => {
        row.addEventListener('click', () => toggleStoreGroup(row));
        row.addEventListener('keydown', (e) => {
            if (e.target !== row) return; // Enter/Espace sur le bouton Supprimer : ne pas replier la ligne
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleStoreGroup(row); }
        });
    });

    // Suppression depuis le monitor. La confirmation passe par customConfirm()
    // (assets/confirm-modal.js), la même modal que search analytics.
    // Les deux endpoints font un soft-delete synchrone (status = 'deleting') puis
    // planifient un job de purge → un simple reload fait disparaître la ligne.
    function monStoreBusy(btn) {
        btn.disabled = true;
        btn.querySelector('.material-symbols-outlined').textContent = 'hourglass_top';
    }
    function monStoreIdle(btn) {
        btn.disabled = false;
        btn.querySelector('.material-symbols-outlined').textContent = 'delete';
    }

    async function monStoreDelete(btn, url, method, body, message, title) {
        if (!await customConfirm(message, title, __('common.delete'), 'danger')) return;
        monStoreBusy(btn);
        try {
            const resp = await fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const result = await resp.json();
            if (!resp.ok || !result.success) throw new Error(result.error || __('config.delete_error'));
            window.location.reload();
        } catch (e) {
            monStoreIdle(btn);
            alert(__('common.error') + ' : ' + e.message);
        }
    }

    document.querySelectorAll('.mon-store-del[data-project-id]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation(); // la ligne projet est elle-même cliquable (expand)
            const n = parseInt(btn.dataset.crawlCount, 10) || 0;
            const what = n > 0 ? ` et ses ${n} crawl${n > 1 ? 's' : ''}` : ' (aucun crawl)';
            let msg = `Supprimer le projet « ${btn.dataset.projectName} »${what} ? `
                    + `Cette action est irréversible et libérera ${btn.dataset.size}.`;
            if (btn.dataset.gsc) {
                msg += ` Les données Search Console (${btn.dataset.gsc}) seront elles aussi supprimées.`;
            }
            monStoreDelete(btn, '../api/projects', 'DELETE',
                { project_id: parseInt(btn.dataset.projectId, 10) },
                msg, __('index.confirm_delete_project_title'));
        });
    });

    document.querySelectorAll('.mon-store-del[data-crawl-path]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const msg = `Supprimer le crawl #${btn.dataset.crawlId} (${btn.dataset.domain}) ? `
                      + `Cette action est irréversible et libérera ${btn.dataset.size}.`;
            monStoreDelete(btn, '../api/crawls/delete', 'POST',
                { project_dir: btn.dataset.crawlPath },
                msg, __('config.delete_crawl'));
        });
    });
    </script>
</body>
</html>
