<?php
/**
 * Migration: Ajout de la colonne word_count pour le comptage de mots
 * 
 * - Colonne word_count INTEGER dans pages pour stocker le nombre de mots du contenu principal
 */

require_once __DIR__ . '/../app/Database/PostgresDatabase.php';

use App\Database\PostgresDatabase;

echo "=== Migration: Ajout colonne word_count ===\n\n";

try {
    $pdo = PostgresDatabase::getInstance()->getConnection();
    
    // 1. Ajouter la colonne word_count à la table pages (table mère)
    echo "1. Ajout de la colonne word_count à la table pages...\n";
    
    $checkColumn = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'pages' AND column_name = 'word_count'
    ")->fetch();
    
    if (!$checkColumn) {
        $pdo->exec("ALTER TABLE pages ADD COLUMN word_count INTEGER DEFAULT 0");
        echo "   ✓ Colonne word_count ajoutée\n";
    } else {
        echo "   - Colonne word_count existe déjà\n";
    }
    
    echo "\n✓ Migration terminée avec succès\n";
    echo "\nNote: Le word_count sera calculé lors des prochains crawls.\n";
    
} catch (Exception $e) {
    echo "\n✗ Erreur : " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
