<?php
/**
 * GSC daily sync scheduler.
 *
 * Runs once a day via cron (Dockerfile). For every active connector it enqueues
 * a `gsc-sync:<id>` job (the worker re-pulls a rolling 7-day window). Guards
 * against piling up jobs if a previous sync is still queued/running or if a
 * backfill is in progress for that connector.
 *
 * The sync job itself is idempotent (ReplacingMergeTree by version), so a
 * duplicate would be harmless — the guard is just to keep the queue clean.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database\PostgresDatabase;
use App\Gsc\ConnectorRepository;
use App\Gsc\GscJobRunner;

echo "[GSC-Sync] " . date('Y-m-d H:i:s') . " — checking connectors\n";

try {
    $db = PostgresDatabase::getInstance()->getConnection();
} catch (Exception $e) {
    echo "[GSC-Sync] DB connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

$repo = new ConnectorRepository();
$connectors = $repo->getActive();

if (empty($connectors)) {
    echo "[GSC-Sync] No active connectors\n";
    exit(0);
}

$check = $db->prepare("
    SELECT COUNT(*) FROM jobs
    WHERE project_dir = :dir
      AND status IN ('pending', 'queued', 'running')
");

$enqueued = 0;
foreach ($connectors as $c) {
    $dir = 'gsc-' . $c->id;
    $check->execute([':dir' => $dir]);
    if ((int) $check->fetchColumn() > 0) {
        echo "[GSC-Sync] Connector #{$c->id} ({$c->site_url}): a job is already pending/running, skipping\n";
        continue;
    }

    $jobId = GscJobRunner::enqueueSync((int) $c->id, (string) $c->site_url);
    $enqueued++;
    echo "[GSC-Sync] Connector #{$c->id} ({$c->site_url}): sync job #{$jobId} queued\n";
}

echo "[GSC-Sync] Done — {$enqueued} job(s) queued\n";
