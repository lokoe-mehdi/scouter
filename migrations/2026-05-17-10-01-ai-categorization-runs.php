<?php
/**
 * Migration: ai_categorization_runs audit table.
 *
 * Logs every call to the AI-assisted categorization feature: which user, which
 * crawl, which model, how many tokens consumed, whether it succeeded. Lets the
 * admin track Gemini API usage and diagnose failures.
 *
 * No content (URLs/H1/title) is stored — RGPD-safe.
 *
 * Idempotent.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    $exists = (bool)$pdo->query("
        SELECT 1 FROM information_schema.tables
        WHERE table_name = 'ai_categorization_runs'
    ")->fetchColumn();

    if ($exists) {
        echo "   → Table ai_categorization_runs already exists, skipping\n";
        echo "   ✓ Migration completed successfully\n";
        return true;
    }

    echo "   → Creating ai_categorization_runs... ";
    $pdo->exec("
        CREATE TABLE ai_categorization_runs (
            id SERIAL PRIMARY KEY,
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            crawl_id INTEGER NOT NULL,
            model VARCHAR(100),
            input_tokens INTEGER,
            output_tokens INTEGER,
            pages_sampled INTEGER,
            success BOOLEAN NOT NULL DEFAULT FALSE,
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "OK\n";

    echo "   → Creating indexes... ";
    $pdo->exec("CREATE INDEX idx_ai_runs_crawl ON ai_categorization_runs(crawl_id, created_at DESC)");
    $pdo->exec("CREATE INDEX idx_ai_runs_user ON ai_categorization_runs(user_id, created_at DESC)");
    echo "OK\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
