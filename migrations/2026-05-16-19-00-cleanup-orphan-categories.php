<?php
/**
 * Migration: Cleanup orphan crawl_categories
 *
 * `crawl_categories` accumulated stale rows because `applyCategorization()`
 * upserts the new rules but never removed the old ones. After several
 * iterations on a project's categorization config, the filter dropdowns
 * in URL/Link Explorer show dozens of dead categories that don't tag any
 * page anymore.
 *
 * This one-shot migration deletes every row in `crawl_categories` that is
 * not referenced by any `pages.cat_id` (no FK exists between the two, just
 * a soft pointer). Safe: an orphan category by definition has no page
 * attached, so no data is lost.
 *
 * The companion fix in CategorizationService::applyCategorization() prevents
 * this from happening again going forward.
 *
 * Idempotent.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    echo "   → Counting orphan categories... ";
    $totalBefore = (int)$pdo->query("SELECT COUNT(*) FROM crawl_categories")->fetchColumn();
    $orphans = (int)$pdo->query("
        SELECT COUNT(*) FROM crawl_categories c
        WHERE NOT EXISTS (SELECT 1 FROM pages p WHERE p.cat_id = c.id)
    ")->fetchColumn();
    echo "$orphans orphan(s) out of $totalBefore total\n";

    if ($orphans === 0) {
        echo "   ✓ Nothing to clean — Migration completed successfully\n";
        return true;
    }

    echo "   → Deleting orphan rows... ";
    $deleted = $pdo->exec("
        DELETE FROM crawl_categories
        WHERE NOT EXISTS (SELECT 1 FROM pages p WHERE p.cat_id = crawl_categories.id)
    ");
    echo "$deleted deleted\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
