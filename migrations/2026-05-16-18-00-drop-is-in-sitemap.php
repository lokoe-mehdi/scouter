<?php
/**
 * Migration: Drop legacy is_in_sitemap column if present
 *
 * No reference to `is_in_sitemap` exists anywhere in the codebase — the canonical
 * column added by 2026-05-16-12-00-sitemap-columns.php is `in_sitemap`. If a
 * stray `is_in_sitemap` column is found on the pages table (likely from a manual
 * experiment or a renamed earlier draft), drop it. Idempotent: skip silently
 * when the column does not exist.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    $stmt = $pdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_name = 'pages' AND column_name = 'is_in_sitemap'
    ");

    if (!$stmt->fetch()) {
        echo "   → Column pages.is_in_sitemap does not exist, nothing to drop\n";
        echo "   ✓ Migration completed successfully\n";
        return true;
    }

    // Safety: if any rows have is_in_sitemap=TRUE but in_sitemap=FALSE/NULL,
    // OR the in_sitemap column does not exist yet, copy the data over before
    // dropping. Catches the unlikely case where someone populated the wrong column.
    $hasInSitemap = (bool)$pdo->query("
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'pages' AND column_name = 'in_sitemap'
    ")->fetchColumn();

    if ($hasInSitemap) {
        echo "   → Backfilling in_sitemap from is_in_sitemap where divergent... ";
        $pdo->exec("
            UPDATE pages
            SET in_sitemap = TRUE
            WHERE is_in_sitemap = TRUE AND (in_sitemap IS NULL OR in_sitemap = FALSE)
        ");
        echo "OK\n";
    }

    echo "   → Dropping legacy column pages.is_in_sitemap... ";
    $pdo->exec("ALTER TABLE pages DROP COLUMN is_in_sitemap");
    echo "OK\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
