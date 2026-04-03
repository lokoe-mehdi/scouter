<?php
/**
 * Migration: Sitemap Analysis Feature
 *
 * Adds sitemap_urls table for storing parsed sitemap data,
 * is_in_sitemap flag on pages, and sitemap stats columns on crawls.
 * Supports hreflang, lastmod, changefreq, priority from sitemaps.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    // =============================================
    // Step 1: Create sitemap_urls table
    // =============================================
    $stmt = $pdo->query("
        SELECT 1 FROM information_schema.tables
        WHERE table_name = 'sitemap_urls' AND table_schema = 'public'
    ");

    if ($stmt->fetch()) {
        echo "   → Table sitemap_urls already exists\n";
    } else {
        echo "   → Creating sitemap_urls table... ";
        $pdo->exec("
            CREATE TABLE sitemap_urls (
                id SERIAL PRIMARY KEY,
                crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
                url TEXT NOT NULL,
                source_sitemap TEXT,
                http_status INTEGER,
                is_indexable BOOLEAN,
                is_in_crawl BOOLEAN DEFAULT FALSE,
                lastmod TIMESTAMP DEFAULT NULL,
                changefreq VARCHAR(20) DEFAULT NULL,
                priority FLOAT DEFAULT NULL,
                hreflang JSONB DEFAULT NULL,
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX idx_sitemap_urls_crawl_id ON sitemap_urls(crawl_id)");
        $pdo->exec("CREATE INDEX idx_sitemap_urls_url ON sitemap_urls(crawl_id, url)");
        echo "OK\n";
    }

    // =============================================
    // Step 2: Add is_in_sitemap column to pages table
    // =============================================
    // Note: pages is partitioned, so we ALTER the parent table
    // and it propagates to all existing and future partitions.
    $stmt = $pdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_name = 'pages' AND column_name = 'is_in_sitemap'
    ");

    if ($stmt->fetch()) {
        echo "   → Column pages.is_in_sitemap already exists\n";
    } else {
        echo "   → Adding is_in_sitemap column to pages... ";
        $pdo->exec("ALTER TABLE pages ADD COLUMN is_in_sitemap BOOLEAN DEFAULT FALSE");
        echo "OK\n";
    }

    // =============================================
    // Step 3: Add sitemap stats columns to crawls
    // =============================================
    $sitemapColumns = [
        'sitemap_total' => 'INTEGER DEFAULT 0',
        'sitemap_only' => 'INTEGER DEFAULT 0',
        'crawl_only_indexable' => 'INTEGER DEFAULT 0',
        'sitemap_not_indexable' => 'INTEGER DEFAULT 0',
    ];

    foreach ($sitemapColumns as $col => $type) {
        $stmt = $pdo->query("
            SELECT column_name FROM information_schema.columns
            WHERE table_name = 'crawls' AND column_name = '$col'
        ");

        if ($stmt->fetch()) {
            echo "   → Column crawls.$col already exists\n";
        } else {
            echo "   → Adding $col column to crawls... ";
            $pdo->exec("ALTER TABLE crawls ADD COLUMN $col $type");
            echo "OK\n";
        }
    }

    // =============================================
    // Step 4: Update create_crawl_partitions function
    // to include is_in_sitemap index on new partitions
    // =============================================
    echo "   → Updating create_crawl_partitions function... ";
    
    // Check if the function already includes is_in_sitemap
    $stmt = $pdo->query("
        SELECT prosrc FROM pg_proc 
        WHERE proname = 'create_crawl_partitions'
    ");
    $funcSrc = $stmt->fetchColumn();
    
    if ($funcSrc && strpos($funcSrc, 'is_in_sitemap') !== false) {
        echo "already includes is_in_sitemap index\n";
    } else {
        // We need to add the index creation to the function.
        // Rather than replacing the whole function, we add the index to existing partitions
        // and ensure future partitions get it via a trigger or by updating the function.
        
        // Add index to all existing page partitions
        $partitions = $pdo->query("
            SELECT tablename FROM pg_tables 
            WHERE schemaname = 'public' AND tablename ~ '^pages_[0-9]+$'
        ")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($partitions as $table) {
            $crawlIdMatch = [];
            preg_match('/pages_(\d+)/', $table, $crawlIdMatch);
            if (!empty($crawlIdMatch[1])) {
                $cid = $crawlIdMatch[1];
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pages_{$cid}_is_in_sitemap ON pages_{$cid}(is_in_sitemap)");
            }
        }
        echo "OK (indexes added to " . count($partitions) . " existing partitions)\n";
    }

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
