<?php
/**
 * Migration: create the user_saved_queries table.
 *
 * Stores per-user SQL snippets saved from the SQL Explorer. Each query has an
 * optional free-form category (the user can group queries under any label —
 * "My audits", "Daily checks", etc.) and an optional description.
 *
 * Names are not i18n'd: they're user-provided, displayed as-is in every locale.
 *
 * Idempotent — creates only if absent.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    $exists = (bool)$pdo->query("
        SELECT 1 FROM information_schema.tables
        WHERE table_name = 'user_saved_queries'
    ")->fetchColumn();

    if ($exists) {
        echo "   → Table user_saved_queries already exists, skipping\n";
        echo "   ✓ Migration completed successfully\n";
        return true;
    }

    echo "   → Creating user_saved_queries... ";
    $pdo->exec("
        CREATE TABLE user_saved_queries (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            category VARCHAR(100),
            query TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "OK\n";

    echo "   → Creating index on user_id... ";
    $pdo->exec("CREATE INDEX idx_user_saved_queries_user ON user_saved_queries(user_id)");
    echo "OK\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
