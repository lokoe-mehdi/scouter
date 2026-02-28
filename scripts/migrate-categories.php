<?php
/**
 * Migration pour ajouter le système de catégorisation
 * Exécuter une seule fois : php migrate-categories.php
 */

require("vendor/autoload.php");

use App\Database\PostgresDatabase;

echo "\n========================================\n";
echo "  MIGRATION - Système de Catégories\n";
echo "========================================\n\n";

try {
    $pdo = PostgresDatabase::getInstance()->getConnection();
    
    echo "✓ Connexion à la base de données réussie\n";
    
    // Check if category_id column exists in domains table
    $result = $pdo->query("PRAGMA table_info(domains)")->fetchAll(PDO::FETCH_ASSOC);
    $hasCategory = false;
    
    foreach ($result as $column) {
        if ($column['name'] === 'category_id') {
            $hasCategory = true;
            break;
        }
    }
    
    if (!$hasCategory) {
        echo "→ Ajout de la colonne 'category_id' à la table 'domains'...\n";
        $pdo->exec("ALTER TABLE domains ADD COLUMN category_id INTEGER");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_domains_category_id ON domains(category_id)");
        echo "  ✓ Colonne 'category_id' ajoutée avec succès\n";
    } else {
        echo "  ℹ Colonne 'category_id' déjà présente\n";
    }
    
    // Check if categories table exists
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='categories'")->fetchAll();
    
    if (empty($tables)) {
        echo "→ Création de la table 'categories'...\n";
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                color TEXT NOT NULL DEFAULT '#4ECDC4',
                icon TEXT DEFAULT 'folder',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "  ✓ Table 'categories' créée avec succès\n";
    } else {
        echo "  ℹ Table 'categories' déjà présente\n";
    }
    
    echo "\n========================================\n";
    echo "  ✓ Migration terminée avec succès !\n";
    echo "========================================\n\n";
    echo "Vous pouvez maintenant utiliser le système de catégories.\n";
    echo "Accédez à l'interface web pour créer vos catégories.\n\n";
    
} catch (Exception $e) {
    echo "\n========================================\n";
    echo "  ✗ ERREUR lors de la migration\n";
    echo "========================================\n\n";
    echo "Message: " . $e->getMessage() . "\n\n";
    exit(1);
}
