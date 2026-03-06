<?php
/**
 * Migration: ajouter la colonne crawl_type a la table crawls
 * Permet de distinguer les crawls 'spider' (defaut) des crawls 'list' (liste d'URLs)
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("
        ALTER TABLE crawls
        ADD COLUMN IF NOT EXISTS crawl_type VARCHAR(10) DEFAULT 'spider'
        CHECK (crawl_type IN ('spider', 'list'))
    ");
    echo "(added crawl_type column to crawls) ";
    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
