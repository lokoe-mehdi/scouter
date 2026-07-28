<?php

namespace App\Gsc;

use App\Database\PostgresDatabase;
use App\Util\SecretCrypto;
use PDO;

/**
 * CRUD for gsc_connectors (one Search Console connector per project).
 *
 * The Google refresh token is encrypted at rest (SecretCrypto / AES-256-GCM);
 * it never leaves this class in plaintext except via decryptRefreshToken(),
 * called by the jobs right before refreshing an access token.
 *
 * @package    Scouter
 * @subpackage Gsc
 */
class ConnectorRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    public function getByProject(int $projectId): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM gsc_connectors WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    public function getById(int $id): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM gsc_connectors WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /** All connectors eligible for the daily sync. */
    public function getActive(): array
    {
        $stmt = $this->db->query("SELECT * FROM gsc_connectors WHERE status = 'active' ORDER BY id ASC");
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Every connector the reconciler is responsible for — i.e. all of them except
     * the ones being torn down.
     *
     * `getActive()` deliberately only returns status='active'; using it as the
     * scheduler's entry point is what made a connector stuck in 'backfilling' (or
     * flipped to 'error') invisible to every automatic path, forever. The
     * reconciler must see those precisely because they are the broken ones.
     */
    public function getReconcilable(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM gsc_connectors WHERE status <> 'disconnecting' ORDER BY id ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Project IDs with an established Search Console connector (active or still
     * backfilling). Used to badge + float GSC-connected projects on the home
     * list. One cheap query for the whole page.
     *
     * @return int[]
     */
    public function getConnectedProjectIds(): array
    {
        $stmt = $this->db->query("SELECT project_id FROM gsc_connectors WHERE status IN ('active', 'backfilling')");
        return array_map(static fn($r) => (int) $r->project_id, $stmt->fetchAll(PDO::FETCH_OBJ));
    }

    /**
     * Reset a connector so a full backfill restarts from scratch: clears the
     * resumable cursor + the last-synced watermark and flags it backfilling.
     * Used after a schema rebuild (e.g. adding country/device).
     */
    public function resetBackfill(int $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE gsc_connectors
                SET status = 'backfilling', backfill_cursor = NULL, last_synced_date = NULL,
                    backfill_started_at = CURRENT_TIMESTAMP, last_error = NULL, updated_at = CURRENT_TIMESTAMP
              WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
    }

    /**
     * Create (or replace) the connector for a project after OAuth. Because a
     * project has at most one connector (UNIQUE project_id), we upsert.
     *
     * @return int connector id
     */
    public function upsert(int $projectId, string $refreshTokenPlain, array $data): int
    {
        $enc = SecretCrypto::encrypt($refreshTokenPlain);
        if ($enc === null) {
            throw new \RuntimeException('SCOUTER_ENCRYPTION_KEY is required to store the Google refresh token.');
        }

        $stmt = $this->db->prepare("
            INSERT INTO gsc_connectors
                (project_id, site_url, property_type, google_email, google_sub,
                 refresh_token_enc, scope, status, backfill_months, created_by, updated_at)
            VALUES
                (:pid, :site, :ptype, :email, :sub, :rt, :scope, :status, :months, :uid, CURRENT_TIMESTAMP)
            ON CONFLICT (project_id) DO UPDATE SET
                site_url          = EXCLUDED.site_url,
                property_type     = EXCLUDED.property_type,
                google_email      = EXCLUDED.google_email,
                google_sub        = EXCLUDED.google_sub,
                refresh_token_enc = EXCLUDED.refresh_token_enc,
                scope             = EXCLUDED.scope,
                status            = EXCLUDED.status,
                backfill_months   = EXCLUDED.backfill_months,
                created_by        = EXCLUDED.created_by,
                last_error        = NULL,
                updated_at        = CURRENT_TIMESTAMP
            RETURNING id
        ");
        $stmt->execute([
            ':pid'    => $projectId,
            ':site'   => $data['site_url'],
            ':ptype'  => $data['property_type'] ?? 'url_prefix',
            ':email'  => $data['google_email'] ?? null,
            ':sub'    => $data['google_sub'] ?? null,
            ':rt'     => $enc,
            ':scope'  => $data['scope'] ?? null,
            ':status' => $data['status'] ?? 'connecting',
            ':months' => (int) ($data['backfill_months'] ?? 16),
            ':uid'    => $data['created_by'] ?? null,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Store the pending refresh token during the OAuth handshake, BEFORE the
     * property is chosen (site_url unknown yet). Used when the callback lands.
     */
    public function upsertPending(int $projectId, string $refreshTokenPlain, array $meta): int
    {
        return $this->upsert($projectId, $refreshTokenPlain, [
            'site_url'      => $meta['site_url'] ?? '',
            'property_type' => 'url_prefix',
            'google_email'  => $meta['google_email'] ?? null,
            'google_sub'    => $meta['google_sub'] ?? null,
            'scope'         => $meta['scope'] ?? null,
            'status'        => 'connecting',
            'created_by'    => $meta['created_by'] ?? null,
        ]);
    }

    public function decryptRefreshToken(object $connector): ?string
    {
        if (empty($connector->refresh_token_enc)) {
            return null;
        }
        return SecretCrypto::decrypt((string) $connector->refresh_token_enc);
    }

    public function setStatus(int $id, string $status, ?string $error = null): void
    {
        $stmt = $this->db->prepare("
            UPDATE gsc_connectors SET status = :s, last_error = :e, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':s' => $status, ':e' => $error, ':id' => $id]);
    }

    /**
     * Set the chosen property + backfill window and flag for backfilling.
     *
     * The cursor is only reset when the property or the window actually changed.
     * Re-picking the SAME property is what a user does to unstick a connector —
     * throwing the cursor away there would restart 16 months of import from zero
     * for nothing.
     */
    public function startBackfill(int $id, string $siteUrl, string $propertyType, int $months): void
    {
        $stmt = $this->db->prepare("
            UPDATE gsc_connectors
            SET site_url = :site, property_type = :ptype, backfill_months = :months,
                status = 'backfilling', backfill_started_at = CURRENT_TIMESTAMP,
                backfill_cursor = CASE
                    WHEN site_url = :site2 AND backfill_months = :months2 THEN backfill_cursor
                    ELSE NULL END,
                last_error = NULL, consecutive_failures = 0, next_retry_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':site'    => $siteUrl,
            ':site2'   => $siteUrl,
            ':ptype'   => $propertyType,
            ':months'  => $months,
            ':months2' => $months,
            ':id'      => $id,
        ]);
    }

    public function setBackfillCursor(int $id, string $date): void
    {
        $stmt = $this->db->prepare("
            UPDATE gsc_connectors SET backfill_cursor = :d, updated_at = CURRENT_TIMESTAMP WHERE id = :id
        ");
        $stmt->execute([':d' => $date, ':id' => $id]);
    }

    // -- Liveness / retry bookkeeping ------------------------------------------

    /**
     * Proof of life, written by the running job after every ingested day.
     *
     * This is what lets the reconciler tell "job row says running and the process
     * really is chewing through days" from "job row says running but the process
     * died two days ago" — without which a crashed job blocks its connector
     * forever behind the "a job is already running" guard.
     */
    public function heartbeat(int $id, ?int $daysDone = null, ?int $daysTotal = null): void
    {
        $set = ['heartbeat_at = CURRENT_TIMESTAMP', 'updated_at = CURRENT_TIMESTAMP'];
        $params = [':id' => $id];
        if ($daysDone !== null) {
            $set[] = 'backfill_days_done = :done';
            $params[':done'] = $daysDone;
        }
        if ($daysTotal !== null) {
            $set[] = 'backfill_days_total = :total';
            $params[':total'] = $daysTotal;
        }
        $stmt = $this->db->prepare("UPDATE gsc_connectors SET " . implode(', ', $set) . " WHERE id = :id");
        $stmt->execute($params);
    }

    /** Stamp the start of an attempt (success or failure — unlike last_sync_at). */
    public function markAttempt(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE gsc_connectors
            SET last_attempt_at = CURRENT_TIMESTAMP, heartbeat_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Record a failed attempt and arm the exponential backoff.
     *
     * The error is kept on the connector (visible in the UI) instead of dying
     * silently in a `jobs` row nobody reads. We never give up: the delay is
     * capped, so a connector broken by a transient 403/429 heals on its own the
     * moment Google answers again.
     */
    public function noteFailure(int $id, string $error, int $baseDelaySeconds = 300, int $maxDelaySeconds = 21600): void
    {
        $stmt = $this->db->prepare("
            UPDATE gsc_connectors
            SET consecutive_failures = consecutive_failures + 1,
                last_error      = :err,
                last_attempt_at = CURRENT_TIMESTAMP,
                next_retry_at   = CURRENT_TIMESTAMP + (LEAST(CAST(:maxd AS double precision),
                                       CAST(:based AS double precision) * POWER(2, LEAST(consecutive_failures, 8))) * INTERVAL '1 second'),
                updated_at      = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':err'   => mb_substr($error, 0, 1000),
            ':based' => $baseDelaySeconds,
            ':maxd'  => $maxDelaySeconds,
            ':id'    => $id,
        ]);
    }

    /** A run that got somewhere: clear the error state and the backoff. */
    public function noteSuccess(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE gsc_connectors
            SET consecutive_failures = 0, last_error = NULL, next_retry_at = NULL,
                last_attempt_at = CURRENT_TIMESTAMP, heartbeat_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }

    /** Push the next attempt out (used for auth errors: retrying fast is pointless). */
    public function deferRetry(int $id, int $seconds): void
    {
        $stmt = $this->db->prepare("
            UPDATE gsc_connectors
            SET next_retry_at = CURRENT_TIMESTAMP + (CAST(:s AS double precision) * INTERVAL '1 second'),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':s' => $seconds, ':id' => $id]);
    }

    /**
     * Put a connector back into 'backfilling' WITHOUT touching the cursor, so the
     * next job resumes where the dead one stopped instead of redoing 16 months.
     */
    public function resumeBackfill(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE gsc_connectors
            SET status = 'backfilling', updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND status <> 'disconnecting'
        ");
        $stmt->execute([':id' => $id]);
    }

    /** Record the newest day we actually have data for. */
    public function setLastSynced(int $id, ?string $date): void
    {
        $stmt = $this->db->prepare("
            UPDATE gsc_connectors
            SET last_synced_date = COALESCE(:d, last_synced_date),
                last_sync_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':d' => $date, ':id' => $id]);
    }

    public function markActive(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE gsc_connectors
            SET status = 'active', backfill_cursor = NULL, last_error = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }

    /**
     * How many OTHER connectors are linked to the same Google account (google_sub).
     * Used before revoking a token on disconnect: revoking a refresh token revokes
     * the whole (user, app) grant on Google's side, which would break every other
     * connector using that same account.
     */
    public function countOthersWithSameAccount(int $excludeId, ?string $sub): int
    {
        if ($sub === null || $sub === '') {
            return 0;
        }
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM gsc_connectors WHERE google_sub = :sub AND id <> :id
        ");
        $stmt->execute([':sub' => $sub, ':id' => $excludeId]);
        return (int) $stmt->fetchColumn();
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM gsc_connectors WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
}
