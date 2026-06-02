<?php
/**
 * Migration: ajouter la colonne data_store à crawls.
 * Indique où vivent les données du crawl : 'pg' (legacy) ou 'clickhouse'.
 * Le crawler Go la passe à 'clickhouse' au démarrage quand CLICKHOUSE_URL est
 * défini ; la couche de lecture route alors les requêtes vers ClickHouse.
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("
        ALTER TABLE crawls
        ADD COLUMN IF NOT EXISTS data_store VARCHAR(16) DEFAULT 'pg'
        CHECK (data_store IN ('pg', 'clickhouse'))
    ");
    echo "(added data_store column to crawls) ";
    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
