<?php
/**
 * Migration: table crawl_report_cache.
 *
 * Stocke les agrégats de rapport PRÉCALCULÉS (petits résultats GROUP BY lourds à
 * calculer en live sur les gros crawls : flux PageRank par catégorie, PR par
 * position de lien, etc.). Une ligne = (crawl_id, report_key) → payload JSON.
 *
 * Rempli par le worker `precompute-reports` (en fin de post-process et à chaque
 * sauvegarde de catégorisation), et en lazy-warm au premier affichage d'un crawl
 * pas encore précalculé. FK ON DELETE CASCADE → nettoyage auto à la suppression
 * du crawl.
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crawl_report_cache (
            crawl_id   INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
            report_key TEXT NOT NULL,
            payload    JSONB NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (crawl_id, report_key)
        )
    ");
    echo "(created crawl_report_cache table) ";
    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
