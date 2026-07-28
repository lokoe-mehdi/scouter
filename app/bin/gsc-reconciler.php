<?php
/**
 * GSC reconciler — the connector's self-healing loop.
 *
 * Runs every few minutes via cron (Dockerfile). For EVERY connector it compares
 * the intended state with reality and enqueues whatever is missing:
 *
 *   backfilling  → a backfill job, unless one is genuinely alive
 *   error        → the same, once the backoff has elapsed (we never give up:
 *                  a connector broken by a transient 403/429/network blip heals
 *                  by itself, and one broken by a revoked grant retries every
 *                  6 h in case access came back)
 *   active       → a sync job when ClickHouse is missing days, or when the last
 *                  attempt is old enough that fresh GSC data is expected
 *
 * It replaces app/bin/gsc-sync-scheduler.php, which only ever looked at
 * status='active' connectors and skipped any connector with a `jobs` row in
 * pending/queued/running. Between the two, a connector whose job had died (or
 * whose job row was left behind by a crash / by the watchdog) was invisible to
 * every automatic path — which is exactly how one property sat in "backfilling"
 * for 20 days and three others silently stopped updating for 8.
 *
 * Liveness is decided on the connector's heartbeat (bumped after each ingested
 * day), NOT on the `jobs` row: a row saying 'running' proves nothing once the
 * process behind it has been OOM-killed or lost to a container restart.
 *
 * Safe to run often and concurrently: enqueuing is guarded, and the jobs
 * themselves are idempotent (ReplacingMergeTree by version).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database\PostgresDatabase;
use App\Gsc\ConnectorRepository;
use App\Gsc\GscCoverage;
use App\Gsc\GscJobRunner;
use App\Gsc\GscSchema;
use App\Job\JobManager;

/** A 'running' job whose connector hasn't moved for this long is a dead process. */
const STALE_JOB_MINUTES = 30;

/** Warn (don't act) when a job has been waiting for a free worker this long. */
const QUEUE_WARN_HOURS = 2;

/** Re-sync an idle-but-healthy connector at least this often (GSC publishes ~daily). */
const SYNC_MAX_IDLE_HOURS = 6;

/** Freshness lag mirrored from GscJobRunner: today-2 is the newest usable day. */
const FRESHNESS_LAG_DAYS = 2;

/** Days the sync always re-pulls; mirrored from GscJobRunner. */
const REFRESH_WINDOW_DAYS = 7;

$verbose = in_array('--verbose', $argv, true);
$dryRun  = in_array('--dry-run', $argv, true);

echo '[GSC-Reconciler] ' . date('Y-m-d H:i:s') . " — reconciling connectors\n";

try {
    $db = PostgresDatabase::getInstance()->getConnection();
} catch (Exception $e) {
    echo '[GSC-Reconciler] DB connection failed: ' . $e->getMessage() . "\n";
    exit(1);
}

$repo = new ConnectorRepository();
$jobs = new JobManager();

$connectors = $repo->getReconcilable();
if (empty($connectors)) {
    echo "[GSC-Reconciler] No connectors\n";
    exit(0);
}

// The coverage queries below read the gsc_* tables; make sure they exist on a
// fresh install where no ingestion has run yet.
try {
    GscSchema::ensure();
} catch (Throwable $e) {
    echo '[GSC-Reconciler] ClickHouse unavailable: ' . $e->getMessage() . "\n";
    exit(1);
}

$liveJob = $db->prepare("
    SELECT id, status, started_at,
           EXTRACT(EPOCH FROM (NOW() - COALESCE(started_at, created_at))) AS age_seconds
    FROM jobs
    WHERE project_dir = :dir AND status IN ('pending', 'queued', 'running')
    ORDER BY id DESC
    LIMIT 1
");

$end = date('Y-m-d', strtotime('-' . FRESHNESS_LAG_DAYS . ' days'));
$enqueued = 0;
$reaped   = 0;

foreach ($connectors as $c) {
    $id     = (int) $c->id;
    $label  = "#{$id} ({$c->site_url})";
    $status = (string) $c->status;

    // The user authenticated but never picked a property — nothing to run.
    if ($status === 'connecting') {
        if ($verbose) {
            echo "[GSC-Reconciler] {$label}: awaiting property selection, skipping\n";
        }
        continue;
    }

    // ---- is a job genuinely alive for this connector? ----------------------
    $liveJob->execute([':dir' => 'gsc-' . $id]);
    $job = $liveJob->fetch(PDO::FETCH_OBJ);

    if ($job) {
        $heartbeat = !empty($c->heartbeat_at) ? strtotime($c->heartbeat_at) : 0;
        $stale     = (time() - $heartbeat) > (STALE_JOB_MINUTES * 60);
        $ageMin    = round(((float) $job->age_seconds) / 60);

        if ($job->status === 'running' && $stale && $ageMin >= STALE_JOB_MINUTES) {
            // The row claims it is running but nothing has moved: the process is
            // gone (OOM, container restart, hard kill). Close it out so the
            // connector stops being shielded by a job that no longer exists.
            echo "[GSC-Reconciler] {$label}: job #{$job->id} running for {$ageMin} min with no heartbeat → reaping\n";
            if (!$dryRun) {
                $jobs->setJobError($job->id, 'Reaped by the GSC reconciler: no heartbeat for ' . $ageMin . ' min');
                $jobs->addLog($job->id, '💀 No heartbeat — process presumed dead, job reaped and re-queued', 'warning');
            }
            $reaped++;
            // fall through: we now decide what to enqueue in its place
        } else {
            if ($job->status !== 'running' && $ageMin > QUEUE_WARN_HOURS * 60) {
                echo "[GSC-Reconciler] {$label}: job #{$job->id} has been {$job->status} for {$ageMin} min (workers saturated?)\n";
            } elseif ($verbose) {
                echo "[GSC-Reconciler] {$label}: job #{$job->id} {$job->status}, alive — skipping\n";
            }
            continue;
        }
    }

    // ---- backoff -----------------------------------------------------------
    if (!empty($c->next_retry_at) && strtotime($c->next_retry_at) > time()) {
        echo "[GSC-Reconciler] {$label}: backing off until {$c->next_retry_at}"
            . ' (' . (int) $c->consecutive_failures . " consecutive failures)\n";
        continue;
    }

    // ---- decide ------------------------------------------------------------
    // backfill_cursor is cleared by markActive(), so a non-null cursor means the
    // 16-month walk never reached its horizon → resume it rather than sync.
    $backfillPending = $status === 'backfilling' || !empty($c->backfill_cursor);

    $reason = null;
    $kind   = null;

    if ($backfillPending) {
        $kind = 'backfill';
        $done = (int) ($c->backfill_days_done ?? 0);
        $tot  = (int) ($c->backfill_days_total ?? 0);
        $reason = $tot > 0 ? "backfill incomplete ({$done}/{$tot} days)" : 'backfill incomplete';
    } elseif ($status === 'error') {
        $kind   = 'sync';
        $reason = 'retrying after error: ' . trim((string) ($c->last_error ?? 'unknown'));
    } else {
        // Active: sync when data is actually missing, or when enough time has
        // passed that Google is expected to have published something new.
        $idleHours = !empty($c->last_attempt_at)
            ? (time() - strtotime($c->last_attempt_at)) / 3600
            : PHP_INT_MAX;

        try {
            $hasGap = (new GscCoverage((int) $c->project_id))->hasGap($end, REFRESH_WINDOW_DAYS);
        } catch (Throwable $e) {
            echo "[GSC-Reconciler] {$label}: coverage check failed ({$e->getMessage()}) — syncing anyway\n";
            $hasGap = true;
        }

        if ($hasGap) {
            $kind = 'sync';
            $reason = 'missing days in ClickHouse';
        } elseif ($idleHours >= SYNC_MAX_IDLE_HOURS) {
            $kind = 'sync';
            $reason = 'refreshing the freshness window (' . round($idleHours) . 'h since last attempt)';
        } elseif ($verbose) {
            echo "[GSC-Reconciler] {$label}: up to date, nothing to do\n";
        }
    }

    if ($kind === null) {
        continue;
    }

    if ($dryRun) {
        echo "[GSC-Reconciler] {$label}: would enqueue {$kind} — {$reason}\n";
        continue;
    }

    if ($kind === 'backfill') {
        // Keep the cursor: the job must resume, not restart 16 months of history.
        $repo->resumeBackfill($id);
        $jobId = GscJobRunner::enqueueBackfill($id, (string) $c->site_url);
    } else {
        $jobId = GscJobRunner::enqueueSync($id, (string) $c->site_url);
    }

    $enqueued++;
    echo "[GSC-Reconciler] {$label}: {$kind} job #{$jobId} queued — {$reason}\n";
}

echo "[GSC-Reconciler] Done — {$enqueued} job(s) queued, {$reaped} dead job(s) reaped\n";
