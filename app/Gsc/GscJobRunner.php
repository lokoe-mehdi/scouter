<?php

namespace App\Gsc;

use App\Database\ClickHouseDatabase;
use App\Google\GoogleOAuthClient;
use App\Job\JobManager;

/**
 * Executes the three GSC background jobs (run by the PHP worker via scouter.php):
 *
 *   - gsc-backfill:<id>  — pull up to N months of history, day by day, resumable
 *                          via gsc_connectors.backfill_cursor, CHUNKED: a job
 *                          stops after a bounded number of days / seconds and
 *                          re-enqueues itself.
 *   - gsc-sync:<id>      — incremental: re-pull the freshness window AND fill
 *                          every day missing from ClickHouse (see GscCoverage),
 *                          not a blind sliding window.
 *   - gsc-delete:<id>    — DROP PARTITION <project_id> on the 4 tables, revoke
 *                          the Google token, delete the connector row.
 *
 * Resilience rules, learned the hard way (a connector sat in "backfilling" for
 * 20 days and three others silently stopped syncing for 8):
 *
 *   1. NOTHING is all-or-nothing. A day that fails is logged and skipped; the
 *      run keeps going and whatever was ingested stays ingested. Only a run of
 *      consecutive failures (or a fatal auth/permission error) stops the job.
 *   2. Every job reports liveness (connector heartbeat + jobs.progress) after
 *      every day, so a dead process is distinguishable from a slow one.
 *   3. Every job is bounded in time. A 16-month backfill is a chain of small
 *      jobs, each restartable, none of which can be mistaken for stuck.
 *   4. Failures are recorded on the CONNECTOR (last_error / next_retry_at), not
 *      only in a `jobs` row nobody reads — and never leave the connector in a
 *      state no automatic path will ever pick up again.
 *
 * Jobs are enqueued as regular `jobs` rows (project_dir = "gsc-<id>") so the
 * existing worker picks them up. See enqueue*() below.
 *
 * @package    Scouter
 * @subpackage Gsc
 */
class GscJobRunner
{
    /** GSC's most recent day is incomplete; treat today-2 as the latest usable. */
    private const FRESHNESS_LAG_DAYS = 2;

    /** Days always re-fetched by a sync, so late finalisation lands. */
    private const SYNC_REFRESH_WINDOW = 7;

    /** Upper bound on the days one sync job handles (catch-up spans the rest). */
    private const SYNC_MAX_DAYS_PER_RUN = 40;

    /** Upper bound on the days one backfill chunk handles before re-enqueuing. */
    private const BACKFILL_MAX_DAYS_PER_JOB = 60;

    /** Wall-clock budget for a single job. Keeps every GSC job short-lived. */
    private const MAX_JOB_SECONDS = 2700; // 45 min

    /** Give up on a run after this many days failing back to back. */
    private const MAX_CONSECUTIVE_DAY_FAILURES = 5;

    /** Refresh the Google access token when older than this (tokens last ~1h). */
    private const TOKEN_MAX_AGE = 3000; // 50 min

    /** Backoff after an auth failure — retrying every 10 min is pointless. */
    private const AUTH_RETRY_SECONDS = 21600; // 6 h

    private ConnectorRepository $repo;
    private JobManager $jobs;
    private ?int $jobId;
    private int $startedAt;

    private string $accessToken = '';
    private int $tokenObtainedAt = 0;
    private string $refreshToken = '';

    public function __construct()
    {
        $this->repo      = new ConnectorRepository();
        $this->jobs      = new JobManager();
        $this->jobId     = ($j = getenv('JOB_ID')) ? (int) $j : null;
        $this->startedAt = time();
    }

    // -------------------------------------------------------------------------
    // Enqueue helpers (called from the controller / reconciler / self-chaining)
    // -------------------------------------------------------------------------

    public static function enqueueBackfill(int $connectorId, string $site): int
    {
        return self::enqueue("gsc-backfill:{$connectorId}", $connectorId, $site);
    }

    public static function enqueueSync(int $connectorId, string $site): int
    {
        return self::enqueue("gsc-sync:{$connectorId}", $connectorId, $site);
    }

    public static function enqueueDelete(int $connectorId, string $site): int
    {
        return self::enqueue("gsc-delete:{$connectorId}", $connectorId, $site);
    }

    /**
     * Create a worker job AND flip it to 'queued' — createJob() alone leaves it
     * 'pending', which the worker never claims (it polls status='queued'). This
     * is the same two-step the crawl/export/delete enqueue paths use.
     */
    private static function enqueue(string $command, int $connectorId, string $site): int
    {
        $jm = new JobManager();
        $jobId = $jm->createJob("gsc-{$connectorId}", $site, $command);
        $jm->updateJobStatus($jobId, 'queued');
        return $jobId;
    }

    // -------------------------------------------------------------------------
    // Jobs
    // -------------------------------------------------------------------------

    /**
     * Initial backfill: N months of history, day by day, oldest-ward, resumable
     * AND chunked — one job walks at most BACKFILL_MAX_DAYS_PER_JOB days (or
     * MAX_JOB_SECONDS) then re-enqueues its successor.
     *
     * Chunking is the difference between "a 20-hour job that any restart, OOM or
     * watchdog turns into a dead end" and "a chain of 45-minute jobs that always
     * resumes from the cursor".
     */
    public function runBackfill(int $connectorId): void
    {
        $c = $this->repo->getById($connectorId);
        if (!$c) {
            throw new \RuntimeException("GSC connector #{$connectorId} not found");
        }
        GscSchema::ensure();
        $this->repo->markAttempt($connectorId);
        $this->initToken($c);

        $end    = $this->latestUsableDate();
        $months = max(1, (int) ($c->backfill_months ?? 16));
        $start  = $end->modify("-{$months} months");

        // Resume from the cursor (last processed = oldest day so far) if present.
        $cursor = !empty($c->backfill_cursor) ? new \DateTimeImmutable($c->backfill_cursor) : $end;
        if ($cursor > $end) {
            $cursor = $end;
        }

        $total = (int) $start->diff($end)->days + 1;
        $done  = (int) $cursor->diff($end)->days;
        $this->repo->heartbeat($connectorId, $done, $total);

        $projectId   = (int) $c->project_id;
        $site        = (string) $c->site_url;
        $processed   = 0;
        $failures    = 0;
        $consecutive = 0;
        $lastError   = null;

        // A 16-month backfill walks BACKWARDS from today-2, so the days that
        // elapse while it runs are never picked up — on a multi-week backfill the
        // dashboard slowly goes stale even though the job is perfectly healthy.
        // Each chunk therefore starts by topping up the head.
        $processed += $this->catchUpHead($connectorId, $projectId, $site, $end->format('Y-m-d'));

        $day = $cursor;
        while ($day >= $start) {
            if ($processed >= self::BACKFILL_MAX_DAYS_PER_JOB || $this->outOfTime()) {
                break;
            }
            $dateStr = $day->format('Y-m-d');
            try {
                $this->refreshTokenIfStale($c);
                (new GscIngestor($projectId, $site, $this->accessToken))->ingestDay($dateStr, 'final');
                $this->repo->setBackfillCursor($connectorId, $dateStr);
                $consecutive = 0;
            } catch (\Throwable $e) {
                if ($this->isFatal($e)) {
                    throw $e; // auth / permission — no point walking 400 more days
                }
                $failures++;
                $consecutive++;
                $lastError = $e->getMessage();
                $this->log("Backfill {$dateStr} failed: {$lastError}", 'warning');
                if ($consecutive >= self::MAX_CONSECUTIVE_DAY_FAILURES) {
                    break;
                }
                // Move on: one bad day must not cost us the other 400.
                $this->repo->setBackfillCursor($connectorId, $dateStr);
            }

            $processed++;
            $done++;
            $this->repo->heartbeat($connectorId, $done, $total);
            $this->progress($done);
            $day = $day->modify('-1 day');
            usleep(150000); // ~150ms between days — stay well under 1200 req/min/site
        }

        $this->syncWatermark($connectorId, $projectId);

        $complete = $day < $start;
        if ($complete) {
            $this->repo->markActive($connectorId);
            $this->repo->noteSuccess($connectorId);
            $this->log("Backfill complete ({$total} days) — switching to incremental sync", 'success');
            // The days that elapsed WHILE the backfill ran are still missing; the
            // sync's gap detection picks them up. Kick it off now rather than
            // waiting for the next reconciler tick.
            self::enqueueSync($connectorId, $site);
            return;
        }

        if ($processed > 0 && $consecutive < self::MAX_CONSECUTIVE_DAY_FAILURES) {
            // Progress was made — chain the next chunk immediately. Skipped days
            // stay visible as an error but must not arm a long backoff: the
            // import is advancing, and the sync's gap detection will come back
            // for them once the walk is done.
            if ($failures > 0) {
                $this->repo->noteFailure($connectorId, $lastError ?? 'partial backfill chunk', 60, 600);
            } else {
                $this->repo->noteSuccess($connectorId);
            }
            $remaining = (int) $start->diff($day)->days + 1;
            $this->log("Backfill chunk done ({$processed} days, {$remaining} left) — chaining", 'info');
            self::enqueueBackfill($connectorId, $site);
            return;
        }

        // Nothing worked: arm the backoff and let the reconciler retry later.
        $msg = $lastError ?? 'backfill made no progress';
        $this->repo->noteFailure($connectorId, $msg);
        throw new \RuntimeException("GSC backfill stalled: {$msg}");
    }

    /**
     * Incremental sync: re-pull the freshness window AND every day ClickHouse is
     * missing (head gap + interior holes), newest first.
     *
     * The old version re-fetched `last_synced_date - 6 … today-2` and only moved
     * the watermark if EVERY day succeeded — so one failure froze the watermark,
     * the window drifted, and the hole was never filled. Coverage is now read
     * from the data itself, which makes catching up after N days of downtime the
     * normal path rather than a special case.
     */
    public function runSync(int $connectorId): void
    {
        $c = $this->repo->getById($connectorId);
        if (!$c) {
            throw new \RuntimeException("GSC connector #{$connectorId} not found");
        }
        GscSchema::ensure();
        $this->repo->markAttempt($connectorId);
        $this->initToken($c);

        $projectId = (int) $c->project_id;
        $site      = (string) $c->site_url;
        $end       = $this->latestUsableDate()->format('Y-m-d');

        $plan = (new GscCoverage($projectId))
            ->daysToSync($end, self::SYNC_REFRESH_WINDOW, self::SYNC_MAX_DAYS_PER_RUN);
        $days = $plan['days'];

        if (count($days) > self::SYNC_REFRESH_WINDOW) {
            $this->log('Catching up ' . count($days) . ' day(s) of missing data'
                . ($plan['remaining'] > 0 ? " ({$plan['remaining']} more queued for the next run)" : ''), 'info');
        }

        $ok = 0;
        $failures = 0;
        $consecutive = 0;
        $lastError = null;

        foreach ($days as $i => $dateStr) {
            if ($this->outOfTime()) {
                $plan['remaining'] += count($days) - $i;
                $this->log('Time budget reached — remaining days deferred to the next run', 'info');
                break;
            }
            try {
                $this->refreshTokenIfStale($c);
                (new GscIngestor($projectId, $site, $this->accessToken))->ingestDay($dateStr, 'all');
                $ok++;
                $consecutive = 0;
            } catch (\Throwable $e) {
                if ($this->isFatal($e)) {
                    throw $e;
                }
                $failures++;
                $consecutive++;
                $lastError = $e->getMessage();
                $this->log("Sync {$dateStr} failed: {$lastError}", 'warning');
                if ($consecutive >= self::MAX_CONSECUTIVE_DAY_FAILURES) {
                    break;
                }
            }
            $this->repo->heartbeat($connectorId);
            $this->progress($ok);
            usleep(150000);
        }

        $this->syncWatermark($connectorId, $projectId);

        if ($ok === 0 && $failures > 0) {
            $msg = $lastError ?? 'sync made no progress';
            $this->repo->noteFailure($connectorId, $msg);
            throw new \RuntimeException("GSC sync failed: {$msg}");
        }

        if ($failures > 0) {
            // Partial success: keep what we got, surface the error, retry soon.
            $this->repo->noteFailure($connectorId, $lastError ?? 'partial sync', 300, 3600);
        } else {
            $this->repo->noteSuccess($connectorId);
        }

        // Recover from a transient error state on a successful sync.
        if ($c->status !== 'backfilling') {
            $this->repo->setStatus($connectorId, 'active', $failures > 0 ? $lastError : null);
        }

        // Chain the catch-up ONLY when the run was clean. Self-enqueuing after a
        // failure would spin: a day that always fails stays a gap, which would
        // immediately justify another job, forever. On failure the backoff owns
        // the retry — the reconciler comes back once next_retry_at has passed.
        if ($plan['remaining'] > 0 && $failures === 0) {
            self::enqueueSync($connectorId, $site);
        }
    }

    /** Disconnect: purge ClickHouse, revoke the token, drop the row. */
    public function runDelete(int $connectorId): void
    {
        $c = $this->repo->getById($connectorId);
        if (!$c) {
            return; // already gone — nothing to do
        }
        $projectId = (int) $c->project_id;

        if (ClickHouseDatabase::enabled()) {
            // Make sure the tables exist (a connector deleted before any ingest
            // would otherwise ALTER a missing table). Dropping a partition that
            // holds no data is a harmless no-op in ClickHouse.
            GscSchema::ensure();
            $ch = ClickHouseDatabase::getInstance();
            foreach (GscSchema::tableNames() as $t) {
                // project_id is an int → safe to inline.
                $ch->exec("ALTER TABLE scouter.{$t} DROP PARTITION {$projectId}");
            }
        }

        // Custom timeline events live in Postgres (keyed by project_id), not in the
        // ClickHouse partitions we just dropped — remove them too so a disconnect
        // leaves no orphaned events behind.
        (new EventRepository())->deleteForProject($projectId);

        // Only revoke on Google's side if NO other connector uses the same Google
        // account — revoking kills the whole (user, app) grant, which would break
        // every other project connected with that account.
        $rt = $this->repo->decryptRefreshToken($c);
        if ($rt && $this->repo->countOthersWithSameAccount($connectorId, $c->google_sub ?? null) === 0) {
            GoogleOAuthClient::revoke($rt);
        }

        $this->repo->delete($connectorId);
    }

    // -------------------------------------------------------------------------
    // Bookkeeping
    // -------------------------------------------------------------------------

    /**
     * Ingest the days more recent than everything we hold (capped), so a long
     * backfill keeps the dashboard current instead of freezing it for weeks.
     * Best-effort: a failure here must never abort the historical walk.
     *
     * @return int days actually attempted (counted against the chunk budget)
     */
    private function catchUpHead(int $connectorId, int $projectId, string $site, string $end): int
    {
        $days = (new GscCoverage($projectId))->headGapDays($end, 10);
        if (empty($days)) {
            return 0;
        }
        $this->log('Topping up ' . count($days) . ' recent day(s) before resuming history', 'info');
        $n = 0;
        foreach ($days as $dateStr) {
            try {
                (new GscIngestor($projectId, $site, $this->accessToken))->ingestDay($dateStr, 'all');
            } catch (\Throwable $e) {
                if ($this->isFatal($e)) {
                    throw $e;
                }
                $this->log("Head catch-up {$dateStr} failed: " . $e->getMessage(), 'warning');
            }
            $n++;
            $this->repo->heartbeat($connectorId);
            usleep(150000);
        }
        return $n;
    }

    /**
     * Realign the Postgres watermark with what ClickHouse actually holds.
     *
     * last_synced_date drives the whole Search Analytics UI (date presets, the
     * calendar's upper bound). Deriving it from the data instead of from "the
     * last run that happened to finish cleanly" is what guarantees a day that was
     * ingested is a day the user can see.
     */
    private function syncWatermark(int $connectorId, int $projectId): void
    {
        try {
            $max = (new GscCoverage($projectId))->maxDate();
            if ($max !== null) {
                $this->repo->setLastSynced($connectorId, $max);
            }
        } catch (\Throwable $e) {
            $this->log('Could not refresh the data watermark: ' . $e->getMessage(), 'warning');
        }
    }

    private function outOfTime(): bool
    {
        return (time() - $this->startedAt) >= self::MAX_JOB_SECONDS;
    }

    private function progress(int $days): void
    {
        if ($this->jobId !== null) {
            try {
                $this->jobs->updateJobProgress($this->jobId, $days);
            } catch (\Throwable $e) {
                // Progress is telemetry — never let it break the ingestion.
            }
        }
    }

    private function log(string $message, string $type = 'info'): void
    {
        echo "[GSC] {$message}\n";
        if ($this->jobId !== null) {
            try {
                $this->jobs->addLog($this->jobId, $message, $type);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Errors that will hit EVERY day identically, so walking the calendar is a
     * waste of quota: revoked/expired grant, property no longer readable by the
     * connected account. They stop the run and surface on the connector.
     */
    private function isFatal(\Throwable $e): bool
    {
        if ($e instanceof GscAuthException) {
            return true;
        }
        $m = strtolower($e->getMessage());
        return str_contains($m, 'insufficient permission')
            || str_contains($m, 'does not have sufficient permission')
            || str_contains($m, 'user does not have')
            || str_contains($m, 'invalid_grant')
            || str_contains($m, 'unauthorized');
    }

    // -------------------------------------------------------------------------
    // Token management
    // -------------------------------------------------------------------------

    private function initToken(object $c): void
    {
        $rt = $this->repo->decryptRefreshToken($c);
        if (!$rt) {
            $this->repo->setStatus((int) $c->id, 'error', 'Missing refresh token — please reconnect.');
            $this->repo->deferRetry((int) $c->id, self::AUTH_RETRY_SECONDS);
            throw new GscAuthException('GSC connector has no usable refresh token');
        }
        $this->refreshToken = $rt;
        $this->refreshAccessToken($c);
    }

    private function refreshTokenIfStale(object $c): void
    {
        if (time() - $this->tokenObtainedAt >= self::TOKEN_MAX_AGE) {
            $this->refreshAccessToken($c);
        }
    }

    /**
     * A transient refresh failure (network hiccup, Google 5xx) used to flip the
     * connector to 'error' — which removed it from every automatic path for good,
     * since the scheduler only ever looked at status='active'. Only a genuinely
     * revoked grant justifies that state now; everything else is a retryable
     * failure with a backoff.
     */
    private function refreshAccessToken(object $c): void
    {
        $res = GoogleOAuthClient::refreshAccessToken($this->refreshToken);
        if (!$res['ok']) {
            $err = $res['error'] ?? 'unknown';
            if (!empty($res['invalid_grant'])) {
                $this->repo->setStatus((int) $c->id, 'error', 'Google access revoked — please reconnect.');
                $this->repo->deferRetry((int) $c->id, self::AUTH_RETRY_SECONDS);
                throw new GscAuthException('GSC token refresh failed (invalid_grant): ' . $err);
            }
            $this->repo->noteFailure((int) $c->id, 'Token refresh failed: ' . $err);
            throw new \RuntimeException('GSC token refresh failed: ' . $err);
        }
        $this->accessToken = $res['access_token'];
        $this->tokenObtainedAt = time();
    }

    private function latestUsableDate(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today'))->modify('-' . self::FRESHNESS_LAG_DAYS . ' days');
    }
}
