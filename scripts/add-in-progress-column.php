<?php
/**
 * Script de migration pour ajouter la colonne in_progress à la table crawls
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\PostgresDatabase;

echo "=== Migration: Ajout de la colonne in_progress ===\n\n";

try {
    $db = PostgresDatabase::getInstance()->getConnection();
    
    // Vérifier si la colonne existe déjà
    $columns = $db->query("PRAGMA table_info(crawls)")->fetchAll(PDO::FETCH_ASSOC);
    $columnExists = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'in_progress') {
            $columnExists = true;
            break;
        }
    }
    
    if ($columnExists) {
        echo "✓ La colonne 'in_progress' existe déjà.\n";
    } else {
        echo "Ajout de la colonne 'in_progress' à la table crawls...\n";
        $db->exec("ALTER TABLE crawls ADD COLUMN in_progress INTEGER DEFAULT 0");
        echo "✓ Colonne ajoutée avec succès!\n";
    }
    
    // Mettre à jour tous les crawls existants pour les marquer comme terminés
    echo "\nMise à jour des crawls existants...\n";
    $result = $db->exec("UPDATE crawls SET in_progress = 0 WHERE in_progress IS NULL");
    echo "✓ $result crawls mis à jour.\n";
    
    echo "\n=== Migration terminée avec succès! ===\n";
    
} catch (Exception $e) {
    echo "✗ ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}
