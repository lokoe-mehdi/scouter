<?php

require_once __DIR__ . '/../app/Database/PostgresDatabase.php';

$pdo = App\Database\PostgresDatabase::getInstance()->getConnection();

$sql = <<<SQL
-- Add sitemap_urls column to crawls table for configuration
ALTER TABLE crawls ADD COLUMN sitemap_urls TEXT;

-- Create the new table for storing sitemap URLs and their status
CREATE TABLE sitemap_urls (
    id SERIAL PRIMARY KEY,
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    url TEXT NOT NULL,
    source_sitemap TEXT,
    http_status INTEGER,
    is_indexable BOOLEAN,
    is_in_crawl BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Add an index for faster lookups by crawl_id
CREATE INDEX idx_sitemap_urls_crawl_id ON sitemap_urls(crawl_id);

-- Add a column to pages to cross-reference with the sitemap
ALTER TABLE pages ADD COLUMN is_in_sitemap BOOLEAN DEFAULT FALSE;
SQL;

try {
    $pdo->exec($sql);
    echo "Migration executed successfully.\n";
} catch (PDOException $e) {
    echo "Error executing migration: " . $e->getMessage() . "\n";
    exit(1);
}
