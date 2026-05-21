<?php
/**
 * Migration: api_keys — per-user API tokens for the public REST API.
 *
 * Tokens are HASHED (SHA-256), never stored in clear and never reversible — we
 * only ever verify a presented token, like a password. The plaintext is shown
 * exactly once at creation. `prefix` (first chars of the token) is indexed for
 * an O(1) candidate lookup; `token_hash` is then compared in constant time.
 *
 * See API.md §2 for the full design.
 *
 * Idempotent.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    $exists = (bool)$pdo->query("
        SELECT 1 FROM information_schema.tables WHERE table_name = 'api_keys'
    ")->fetchColumn();

    if ($exists) {
        echo "   → Table api_keys already exists, skipping\n";
        echo "   ✓ Migration completed successfully\n";
        return true;
    }

    echo "   → Creating api_keys... ";
    $pdo->exec("
        CREATE TABLE api_keys (
            id            SERIAL PRIMARY KEY,
            user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name          VARCHAR(100) NOT NULL,
            prefix        VARCHAR(16) NOT NULL,        -- e.g. 'sctr_8mK2qZ' for lookup + UI display
            token_hash    CHAR(64) NOT NULL,           -- hash('sha256', token)
            last_used_at  TIMESTAMP,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at    TIMESTAMP                    -- NULL = active
        )
    ");
    echo "OK\n";

    echo "   → Creating indexes... ";
    // Hot path: look up the active key by its prefix on every API request.
    $pdo->exec("CREATE INDEX idx_api_keys_prefix ON api_keys(prefix) WHERE revoked_at IS NULL");
    $pdo->exec("CREATE INDEX idx_api_keys_user ON api_keys(user_id, created_at DESC)");
    echo "OK\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
