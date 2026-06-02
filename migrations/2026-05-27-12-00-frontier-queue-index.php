<?php
/**
 * Migration: file d'attente frontier robuste pour les très gros crawls.
 *
 * Ajoute pages.claimed_at (marqueur de lease posé par ClaimUrlsToCrawl côté
 * crawler Go) et un index partiel idx_pages_frontier. Avant, le crawler relisait à
 * chaque batch la tranche `WHERE crawled=false ... ORDER BY id` sans index
 * composite : le coût croissait avec la taille de la partition (O(N) par batch →
 * O(N²) sur le crawl), d'où le gel observé sur des sites à plusieurs millions
 * d'URL. L'index partiel ne contient que le frontier restant (les lignes crawlées
 * en sortent), donc le lease ET le COUNT de fin de profondeur redeviennent
 * O(frontier restant) au lieu de O(partition entière).
 *
 * pages est partitionnée par LIST(crawl_id) : ALTER TABLE / CREATE INDEX sur le
 * parent se propage à toutes les partitions existantes et futures. Idempotent.
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("ALTER TABLE pages ADD COLUMN IF NOT EXISTS claimed_at TIMESTAMP");
    echo "(added pages.claimed_at) ";

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_pages_frontier ON pages (crawl_id, depth, claimed_at, id)
        WHERE crawled = false AND external = false AND in_crawl = true
    ");
    echo "(created idx_pages_frontier) ";

    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
