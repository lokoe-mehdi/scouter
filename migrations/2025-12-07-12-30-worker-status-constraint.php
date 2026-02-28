<?php
/**
 * Migration: Add 'queued', 'stopping', 'failed' to crawls status constraint
 * Required for the new worker architecture
 */

require_once __DIR__ . '/../app/Database/PostgresDatabase.php';

use App\Database\PostgresDatabase;

echo "=== Migration: Worker status constraint ===\n\n";

try {
    $pdo = PostgresDatabase::getInstance()->getConnection();
    
    // Drop the old constraint and add the new one with additional statuses
    echo "1. Suppression de l'ancienne contrainte...\n";
    $pdo->exec("ALTER TABLE crawls DROP CONSTRAINT IF EXISTS crawls_status_check");
    echo "   ✓ Contrainte supprimée\n";
    
    echo "2. Ajout de la nouvelle contrainte avec statuts supplémentaires...\n";
    $pdo->exec("
        ALTER TABLE crawls 
        ADD CONSTRAINT crawls_status_check 
        CHECK (status IN ('pending', 'queued', 'running', 'stopping', 'stopped', 'finished', 'error', 'failed'))
    ");
    echo "   ✓ Nouvelle contrainte ajoutée\n";
    
    echo "\n✓ Migration terminée avec succès\n";
    echo "\nStatuts autorisés: pending, queued, running, stopping, stopped, finished, error, failed\n";
    
} catch (Exception $e) {
    echo "\n✗ Erreur : " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
