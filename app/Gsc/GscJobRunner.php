<?php

namespace App\Gsc;

use App\Database\ClickHouseDatabase;
use App\Google\GoogleOAuthClient;
use App\Job\JobManager;

/**
 * Executes the three GSC background jobs (run by the PHP worker via scouter.php):
 *
 *   - gsc-backfill:<id>  — pull up to N months of history, day by day, resumable
 *                          via gsc_connectors.backfill_cursor.
 *   - gsc-sync:<id>      — daily: re-pull a rolling 7-day window (handles GSC's
 *                          2-3 day lag + late finalisation) with dataState=all.
 *                          ReplacingMergeTree supersedes prior rows by version →
 *                          no delete, no duplicate.
 *   - gsc-delete:<id>    — DROP PARTITION <project_id> on the 4 tables, revoke
 *                          the Google token, delete the connector row.
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

    /** Rolling window re-fetched by the daily sync (captures late finalisation). */
    private const SYNC_WINDOW_DAYS = 7;

    /** Refresh the Google access token when older than this (tokens last ~1h). */
    private const TOKEN_MAX_AGE = 3000; // 50 min

    private ConnectorRepository $repo;

    private string $accessToken = '';
    private int $tokenObtainedAt = 0;
    private string $refreshToken = '';

    public function __construct()
    {
        $this->repo = new ConnectorRepository();
    }

    // -------------------------------------------------------------------------
    // Enqueue helpers (called from the controller / scheduler)
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

    /** Initial backfill: N months of history, day by day, resumable. */
    public function runBackfill(int $connectorId): void
    {
        $c = $this->repo->getById($connectorId);
        if (!$c) {
            throw new \RuntimeException("GSC connector #{$connectorId} not found");
        }
        GscSchema::ensure();
        $this->initToken($c);

        $end = $this->latestUsableDate();
        $months = max(1, (int) ($c->backfill_months ?? 16));
        $start = $end->modify("-{$months} months");

        // Resume from the cursor (last processed, oldest day so far) if present.
        $cursor = !empty($c->backfill_cursor) ? new \DateTimeImmutable($c->backfill_cursor) : $end;
        if ($cursor > $end) {
            $cursor = $end;
        }

        $ingestor = fn() => new GscIngestor((int) $c->project_id, (string) $c->site_url, $this->accessToken());

        $day = $cursor;
        while ($day >= $start) {
            $dateStr = $day->format('Y-m-d');
            $this->refreshTokenIfStale($c);
            $ingestor()->ingestDay($dateStr, 'final');
            $this->repo->setBackfillCursor($connectorId, $dateStr);
            $day = $day->modify('-1 day');
            usleep(150000); // ~150ms between days — stay well under 1200 req/min/site
        }

        $this->repo->setLastSynced($connectorId, $end->format('Y-m-d'));
        $this->repo->markActive($connectorId);
    }

    /** Daily incremental: rolling 7-day window, freshest data. */
    public function runSync(int $connectorId): void
    {
        $c = $this->repo->getById($connectorId);
        if (!$c) {
            throw new \RuntimeException("GSC connector #{$connectorId} not found");
        }
        GscSchema::ensure();
        $this->initToken($c);

        $end = $this->latestUsableDate();
        // Start from 7 days before the newest day we already have (or before the
        // freshness horizon on a first sync), so late-finalised days get refreshed.
        $anchor = !empty($c->last_synced_date) ? new \DateTimeImmutable($c->last_synced_date) : $end;
        $start = $anchor->modify('-' . (self::SYNC_WINDOW_DAYS - 1) . ' days');
        if ($start > $end) {
            $start = $end;
        }

        $ingestor = fn() => new GscIngestor((int) $c->project_id, (string) $c->site_url, $this->accessToken());

        $newest = null;
        $day = $end;
        while ($day >= $start) {
            $dateStr = $day->format('Y-m-d');
            $this->refreshTokenIfStale($c);
            $res = $ingestor()->ingestDay($dateStr, 'all');
            if ($res['hadData'] && $newest === null) {
                $newest = $dateStr; // iterating newest-first, so first hit is the max
            }
            $day = $day->modify('-1 day');
            usleep(150000);
        }

        if ($newest !== null) {
            $this->repo->setLastSynced($connectorId, $newest);
        }
        // Recover from a transient error state on a successful sync.
        $this->repo->setStatus($connectorId, 'active', null);
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
    // Token management
    // -------------------------------------------------------------------------

    private function initToken(object $c): void
    {
        $rt = $this->repo->decryptRefreshToken($c);
        if (!$rt) {
            $this->repo->setStatus((int) $c->id, 'error', 'Missing refresh token — please reconnect.');
            throw new \RuntimeException('GSC connector has no usable refresh token');
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

    private function refreshAccessToken(object $c): void
    {
        $res = GoogleOAuthClient::refreshAccessToken($this->refreshToken);
        if (!$res['ok']) {
            if (!empty($res['invalid_grant'])) {
                $this->repo->setStatus((int) $c->id, 'error', 'Google access revoked — please reconnect.');
            } else {
                $this->repo->setStatus((int) $c->id, 'error', 'Token refresh failed: ' . ($res['error'] ?? 'unknown'));
            }
            throw new \RuntimeException('GSC token refresh failed: ' . ($res['error'] ?? 'unknown'));
        }
        $this->accessToken = $res['access_token'];
        $this->tokenObtainedAt = time();
    }

    private function accessToken(): string
    {
        return $this->accessToken;
    }

    private function latestUsableDate(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today'))->modify('-' . self::FRESHNESS_LAG_DAYS . ' days');
    }
}
