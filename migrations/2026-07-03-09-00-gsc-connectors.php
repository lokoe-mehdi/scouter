<?php
/**
 * Migration: Google Search Console connector.
 *
 * One connector per project links a Scouter project to a Search Console
 * property. It holds the metadata + the OAuth refresh token (encrypted at rest
 * with SCOUTER_ENCRYPTION_KEY, same AES-256-GCM scheme as app_settings — see
 * App\Util\SecretCrypto). The analytics data itself lives in ClickHouse
 * (gsc_site_daily / gsc_page_daily / gsc_query_daily / gsc_page_query_daily),
 * partitioned by project_id.
 *
 * Status lifecycle: connecting → backfilling → active (→ error on failure,
 * disconnecting while the delete job purges ClickHouse).
 *
 * Idempotent.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    echo "   → Creating gsc_connectors... ";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gsc_connectors (
            id                  SERIAL PRIMARY KEY,
            project_id          INTEGER NOT NULL UNIQUE REFERENCES projects(id) ON DELETE CASCADE,
            site_url            TEXT NOT NULL,
            property_type       VARCHAR(16) NOT NULL DEFAULT 'url_prefix',
            google_email        TEXT,
            google_sub          TEXT,
            refresh_token_enc   TEXT NOT NULL,
            scope               TEXT,
            status              VARCHAR(24) NOT NULL DEFAULT 'connecting',
            backfill_cursor     DATE,
            backfill_months     SMALLINT NOT NULL DEFAULT 16,
            backfill_started_at TIMESTAMP,
            last_synced_date    DATE,
            last_sync_at        TIMESTAMP,
            last_error          TEXT,
            created_by          INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "OK\n";

    echo "   → Creating index on status... ";
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gsc_connectors_status ON gsc_connectors(status)");
    echo "OK\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
