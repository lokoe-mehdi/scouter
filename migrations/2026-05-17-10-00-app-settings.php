<?php
/**
 * Migration: global app_settings table.
 *
 * Generic key/value store for app-wide configuration that doesn't belong to a
 * user or a project. First use case: Gemini API key + selected model for the
 * AI-assisted categorization feature.
 *
 * Sensitive values (API keys, tokens) are stored encrypted by App\Settings\AppSettings,
 * not at the DB layer — so column type is plain TEXT.
 *
 * Idempotent.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    $exists = (bool)$pdo->query("
        SELECT 1 FROM information_schema.tables
        WHERE table_name = 'app_settings'
    ")->fetchColumn();

    if ($exists) {
        echo "   → Table app_settings already exists, skipping\n";
        echo "   ✓ Migration completed successfully\n";
        return true;
    }

    echo "   → Creating app_settings... ";
    $pdo->exec("
        CREATE TABLE app_settings (
            key VARCHAR(100) PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    echo "OK\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
