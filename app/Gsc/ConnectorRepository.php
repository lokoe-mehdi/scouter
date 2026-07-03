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

    /** Set the chosen property + backfill window and flag for backfilling. */
    public function startBackfill(int $id, string $siteUrl, string $propertyType, int $months): void
    {
        $stmt = $this->db->prepare("
            UPDATE gsc_connectors
            SET site_url = :site, property_type = :ptype, backfill_months = :months,
                status = 'backfilling', backfill_started_at = CURRENT_TIMESTAMP,
                backfill_cursor = NULL, last_error = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':site' => $siteUrl, ':ptype' => $propertyType, ':months' => $months, ':id' => $id,
        ]);
    }

    public function setBackfillCursor(int $id, string $date): void
    {
        $stmt = $this->db->prepare("
            UPDATE gsc_connectors SET backfill_cursor = :d, updated_at = CURRENT_TIMESTAMP WHERE id = :id
        ");
        $stmt->execute([':d' => $date, ':id' => $id]);
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
