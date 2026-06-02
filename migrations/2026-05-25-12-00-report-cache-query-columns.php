<?php
/**
 * Migration: colonnes de recompute générique sur crawl_report_cache.
 *
 * Stocke, à côté du résultat, la requête qui l'a produit (query_sql + query_params)
 * et un flag category_dependent. Permet au worker de RECALCULER n'importe quel
 * fragment en ré-exécutant sa requête — sans registre central — donc chaque rapport
 * branche ses requêtes via ReportPrecompute::cached() sans toucher au code partagé.
 * Idempotent (ADD COLUMN IF NOT EXISTS).
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("ALTER TABLE crawl_report_cache ADD COLUMN IF NOT EXISTS query_sql TEXT");
    $pdo->exec("ALTER TABLE crawl_report_cache ADD COLUMN IF NOT EXISTS query_params JSONB");
    $pdo->exec("ALTER TABLE crawl_report_cache ADD COLUMN IF NOT EXISTS category_dependent SMALLINT DEFAULT 0");
    echo "(added query_sql/query_params/category_dependent to crawl_report_cache) ";
    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
