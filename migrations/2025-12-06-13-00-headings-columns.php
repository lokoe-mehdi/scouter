<?php
/**
 * Migration: Ajout des colonnes h1_multiple et headings_missing
 * 
 * Ces colonnes permettent de flaguer les pages avec des problèmes de hiérarchie <hn>:
 * - h1_multiple: true si plusieurs <h1> sur la page
 * - headings_missing: true si un niveau de heading est sauté (ex: h2 -> h4 sans h3)
 */

require_once __DIR__ . '/../app/Database/PostgresDatabase.php';

use App\Database\PostgresDatabase;

echo "=== Migration: Colonnes headings ===\n\n";

try {
    $pdo = PostgresDatabase::getInstance()->getConnection();
    
    // 1. Ajouter les colonnes à la table pages (table mère)
    echo "1. Ajout des colonnes h1_multiple et headings_missing...\n";
    
    // Vérifier si les colonnes existent déjà
    $checkColumn = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'pages' AND column_name = 'h1_multiple'
    ")->fetch();
    
    if (!$checkColumn) {
        $pdo->exec("ALTER TABLE pages ADD COLUMN h1_multiple BOOLEAN DEFAULT FALSE");
        echo "   ✓ Colonne h1_multiple ajoutée\n";
    } else {
        echo "   - Colonne h1_multiple existe déjà\n";
    }
    
    $checkColumn = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'pages' AND column_name = 'headings_missing'
    ")->fetch();
    
    if (!$checkColumn) {
        $pdo->exec("ALTER TABLE pages ADD COLUMN headings_missing BOOLEAN DEFAULT FALSE");
        echo "   ✓ Colonne headings_missing ajoutée\n";
    } else {
        echo "   - Colonne headings_missing existe déjà\n";
    }
    
    // 2. Les colonnes sont par défaut FALSE, donc les anciens crawls
    // afficheront "pas de problème" ce qui est acceptable
    echo "\n2. Colonnes initialisées à FALSE par défaut\n";
    echo "   (les anciens crawls n'auront pas de problèmes signalés)\n";
    
    echo "\n✓ Migration terminée avec succès\n";
    
} catch (Exception $e) {
    echo "\n✗ Erreur : " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
