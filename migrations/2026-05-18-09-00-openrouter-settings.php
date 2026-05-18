<?php
/**
 * Migration: switch AI provider from Gemini to OpenRouter.
 *
 * Drops the old `ai.gemini.*` rows (the Gemini API key isn't valid against
 * OpenRouter and the model id format is different — "gemini-2.5-flash" vs
 * "google/gemini-2.5-flash" — so a rename wouldn't work anyway). The admin
 * needs to re-enter their OpenRouter key from the Settings page after this
 * migration runs.
 *
 * The new settings keys are:
 *   - ai.openrouter.api_key       (encrypted, sensitive)
 *   - ai.openrouter.model_light   (for categorization, filter suggestions, NL→SQL)
 *   - ai.openrouter.model_strong  (for Dr. Brief chatbot — needs tool support)
 *
 * Idempotent.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    // Only act if the app_settings table exists at all — if it doesn't, the
    // earlier 2026-05-17-10-00-app-settings migration hasn't run yet, in
    // which case there's nothing to clean up.
    $exists = (bool)$pdo->query("
        SELECT 1 FROM information_schema.tables
        WHERE table_name = 'app_settings'
    ")->fetchColumn();

    if (!$exists) {
        echo "   → app_settings table not present, skipping\n";
        echo "   ✓ Migration completed successfully\n";
        return true;
    }

    echo "   → Removing old ai.gemini.* settings (provider switch)... ";
    $stmt = $pdo->prepare("DELETE FROM app_settings WHERE key LIKE 'ai.gemini.%' OR key = 'ai.provider'");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    echo "OK ({$deleted} row(s) removed)\n";

    echo "   ℹ Re-enter your OpenRouter key from /settings to re-enable AI features.\n";
    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
