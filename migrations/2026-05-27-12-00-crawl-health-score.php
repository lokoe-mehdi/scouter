<?php
/**
 * Migration : colonne `health_score` sur la table `crawls`.
 *
 * Score santé SEO (0-100) calculé une fois depuis ClickHouse et persisté
 * (cf. App\Analysis\CrawlStats). NULL = pas encore calculé (sentinelle du
 * write-through : tant qu'il est NULL, la home/page projet le calcule en live
 * puis le stocke ; ensuite lecture directe de la ligne, zéro requête CH).
 * Idempotent (ADD COLUMN IF NOT EXISTS).
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("ALTER TABLE crawls ADD COLUMN IF NOT EXISTS health_score SMALLINT");
    echo "(added health_score to crawls) ";
    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
