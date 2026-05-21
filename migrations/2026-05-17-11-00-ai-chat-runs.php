<?php
/**
 * Migration: ai_chat_runs audit / rate-limit table.
 *
 * Dr. Brief (chat assistant) doesn't persist conversation content — each user
 * session is ephemeral, the messages array lives in the browser only. But we
 * still want:
 *   - rate limiting (X messages per user per hour)
 *   - per-user token usage tracking (cost awareness)
 *   - error visibility for debugging
 *
 * So we log one row per assistant turn (NOT per user prompt) with metadata
 * only — never the actual question or answer, to keep this RGPD-safe.
 *
 * Idempotent.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    $exists = (bool)$pdo->query("
        SELECT 1 FROM information_schema.tables
        WHERE table_name = 'ai_chat_runs'
    ")->fetchColumn();

    if ($exists) {
        echo "   → Table ai_chat_runs already exists, skipping\n";
        echo "   ✓ Migration completed successfully\n";
        return true;
    }

    echo "   → Creating ai_chat_runs... ";
    $pdo->exec("
        CREATE TABLE ai_chat_runs (
            id            SERIAL PRIMARY KEY,
            user_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
            crawl_id      INTEGER NOT NULL,
            model         VARCHAR(100),
            input_tokens  INTEGER DEFAULT 0,
            output_tokens INTEGER DEFAULT 0,
            tool_calls    INTEGER DEFAULT 0,
            success       BOOLEAN NOT NULL DEFAULT FALSE,
            error_message TEXT,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "OK\n";

    echo "   → Creating indexes... ";
    // Used by the rate-limit check (per-user count over the last hour).
    $pdo->exec("CREATE INDEX idx_ai_chat_user_time ON ai_chat_runs(user_id, created_at DESC)");
    $pdo->exec("CREATE INDEX idx_ai_chat_crawl ON ai_chat_runs(crawl_id, created_at DESC)");
    echo "OK\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
