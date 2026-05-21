<?php
/**
 * Migration: OAuth 2.0 support for the MCP server (claude.ai connector).
 *
 * Scouter acts as a minimal OAuth Authorization Server so claude.ai can connect
 * the remote MCP server natively. We store:
 *   - oauth_clients     : clients registered via Dynamic Client Registration
 *                         (RFC 7591). claude.ai registers itself on first use.
 *   - oauth_auth_codes  : short-lived authorization codes (PKCE, single-use).
 *
 * The issued ACCESS TOKEN is a regular `sctr_` API key (see api_keys) minted for
 * the consenting user — so the data plane validates it with the existing Bearer
 * middleware, no new token store needed.
 *
 * Idempotent.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    echo "   → Creating oauth_clients... ";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS oauth_clients (
            id            SERIAL PRIMARY KEY,
            client_id     TEXT UNIQUE NOT NULL,
            client_name   TEXT,
            redirect_uris JSONB NOT NULL,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "OK\n";

    echo "   → Creating oauth_auth_codes... ";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS oauth_auth_codes (
            code           TEXT PRIMARY KEY,
            client_id      TEXT NOT NULL,
            user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            redirect_uri   TEXT NOT NULL,
            code_challenge TEXT NOT NULL,
            scope          TEXT,
            expires_at     TIMESTAMP NOT NULL,
            used           BOOLEAN NOT NULL DEFAULT FALSE,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "OK\n";

    echo "   → Creating index... ";
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_oauth_codes_expires ON oauth_auth_codes(expires_at)");
    echo "OK\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
