<?php
/**
 * Migration: Sitemap support columns
 *
 * Adds in_crawl + in_sitemap booleans on pages table so we can distinguish
 * pages discovered by the classic crawl from pages added only via the sitemap
 * post-processing step. Existing rows default to in_crawl=true / in_sitemap=false
 * so report queries remain accurate without a backfill.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    // =============================================
    // Step 1: Add in_crawl column to pages
    // =============================================
    $stmt = $pdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_name = 'pages' AND column_name = 'in_crawl'
    ");

    if ($stmt->fetch()) {
        echo "   → Column pages.in_crawl already exists\n";
    } else {
        echo "   → Adding in_crawl column to pages... ";
        $pdo->exec("ALTER TABLE pages ADD COLUMN in_crawl BOOLEAN DEFAULT TRUE");
        echo "OK\n";
    }

    // =============================================
    // Step 2: Add in_sitemap column to pages
    // =============================================
    $stmt = $pdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_name = 'pages' AND column_name = 'in_sitemap'
    ");

    if ($stmt->fetch()) {
        echo "   → Column pages.in_sitemap already exists\n";
    } else {
        echo "   → Adding in_sitemap column to pages... ";
        $pdo->exec("ALTER TABLE pages ADD COLUMN in_sitemap BOOLEAN DEFAULT FALSE");
        echo "OK\n";
    }

    // =============================================
    // Step 3: Partial indexes for sitemap report queries
    // =============================================
    $stmt = $pdo->query("
        SELECT indexname FROM pg_indexes
        WHERE tablename = 'pages' AND indexname = 'idx_pages_in_sitemap'
    ");

    if ($stmt->fetch()) {
        echo "   → Index idx_pages_in_sitemap already exists\n";
    } else {
        echo "   → Creating partial index idx_pages_in_sitemap... ";
        $pdo->exec("CREATE INDEX idx_pages_in_sitemap ON pages (crawl_id, in_sitemap) WHERE in_sitemap = TRUE");
        echo "OK\n";
    }

    $stmt = $pdo->query("
        SELECT indexname FROM pg_indexes
        WHERE tablename = 'pages' AND indexname = 'idx_pages_not_in_crawl'
    ");

    if ($stmt->fetch()) {
        echo "   → Index idx_pages_not_in_crawl already exists\n";
    } else {
        echo "   → Creating partial index idx_pages_not_in_crawl... ";
        $pdo->exec("CREATE INDEX idx_pages_not_in_crawl ON pages (crawl_id, in_crawl) WHERE in_crawl = FALSE");
        echo "OK\n";
    }

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
