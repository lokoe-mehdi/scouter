<?php
/**
 * Migration: per-user AI budgets + unified usage ledger.
 *
 * Three changes:
 *
 * 1. `ai_usage` (TABLE) — ONE row per billed AI action, across ALL features
 *    (chatbot, categorization, bulk_generate, ai_filters). Stores the REAL
 *    cost returned by OpenRouter (usage.cost, USD), never the prompt/answer
 *    content — RGPD-safe, same spirit as ai_chat_runs. This is the single
 *    source of truth for budget enforcement + the profile/settings dashboards.
 *    Monthly windows are computed live from created_at (no cron reset).
 *
 * 2. `users.ai_monthly_budget_usd` (NUMERIC, nullable) — per-user override of
 *    the global monthly budget. NULL = use the global default.
 *
 * 3. `app_settings` key `ai.budget.monthly_usd` — global default monthly
 *    budget per user, in USD. Seeded to 10.00 if absent.
 *
 * Idempotent.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    // --- 1) ai_usage ledger ------------------------------------------------
    $hasTable = (bool)$pdo->query("
        SELECT 1 FROM information_schema.tables WHERE table_name = 'ai_usage'
    ")->fetchColumn();

    if ($hasTable) {
        echo "   → Table ai_usage already exists, skipping\n";
    } else {
        echo "   → Creating ai_usage... ";
        $pdo->exec("
            CREATE TABLE ai_usage (
                id            SERIAL PRIMARY KEY,
                user_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
                feature       VARCHAR(32) NOT NULL,          -- chatbot|categorization|bulk_generate|ai_filters
                model         VARCHAR(100),
                input_tokens  INTEGER DEFAULT 0,
                output_tokens INTEGER DEFAULT 0,
                cost_usd      NUMERIC(12,6) NOT NULL DEFAULT 0,  -- real OpenRouter cost (usage.cost) or computed fallback
                crawl_id      INTEGER,
                success       BOOLEAN NOT NULL DEFAULT TRUE,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "OK\n";

        echo "   → Creating indexes... ";
        // Monthly spend per user (the hot path: gate + profile).
        $pdo->exec("CREATE INDEX idx_ai_usage_user_time ON ai_usage(user_id, created_at DESC)");
        // Global view per feature.
        $pdo->exec("CREATE INDEX idx_ai_usage_feature_time ON ai_usage(feature, created_at DESC)");
        echo "OK\n";
    }

    // --- 2) users.ai_monthly_budget_usd override --------------------------
    $hasCol = (bool)$pdo->query("
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'users' AND column_name = 'ai_monthly_budget_usd'
    ")->fetchColumn();

    if ($hasCol) {
        echo "   → users.ai_monthly_budget_usd already exists, skipping\n";
    } else {
        echo "   → ALTER TABLE users ADD COLUMN ai_monthly_budget_usd... ";
        $pdo->exec("ALTER TABLE users ADD COLUMN ai_monthly_budget_usd NUMERIC(10,2)");
        echo "OK\n";
    }

    // --- 3) global default budget setting ---------------------------------
    echo "   → Seeding app_settings ai.budget.monthly_usd (default 10.00)... ";
    $stmt = $pdo->prepare("
        INSERT INTO app_settings (key, value, updated_at)
        VALUES ('ai.budget.monthly_usd', '10.00', CURRENT_TIMESTAMP)
        ON CONFLICT (key) DO NOTHING
    ");
    $stmt->execute();
    echo "OK\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
