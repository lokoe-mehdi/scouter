<?php
/**
 * Migration: ajouter la colonne critical_errors à crawls.
 * Nombre de pages crawlées renvoyant un code HTTP >= 400 (4xx + 5xx).
 * Renseigné en fin de crawl par UpdateCrawlStats (crawler Go) ; sert de KPI
 * "Erreurs critiques" sur la homepage. Backfill best-effort pour les crawls
 * dont la frontier PG est encore présente (les crawls migrés/purgés vers
 * ClickHouse resteront à 0 jusqu'au prochain crawl).
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("ALTER TABLE crawls ADD COLUMN IF NOT EXISTS critical_errors INTEGER DEFAULT 0");
    echo "(added critical_errors column to crawls) ";

    // Backfill depuis les pages encore présentes en PG.
    $pdo->exec("
        UPDATE crawls c SET critical_errors = sub.n
        FROM (
            SELECT crawl_id, COUNT(*) AS n
            FROM pages
            WHERE code >= 400 AND crawled = TRUE AND in_crawl = TRUE
            GROUP BY crawl_id
        ) sub
        WHERE sub.crawl_id = c.id
    ");
    echo "(backfilled from PG pages) ";
    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
