<?php
/**
 * Migration : Ajout des colonnes simhash et is_html
 * 
 * - simhash : Empreinte 64-bit pour détecter les contenus dupliqués/near-duplicates
 * - is_html : Booléen pour distinguer les vraies pages HTML des ressources (images, PDF, etc.)
 * 
 * Date : 2025-12-05
 */

require_once __DIR__ . '/../app/Database/PostgresDatabase.php';

use App\Database\PostgresDatabase;

echo "=== Migration : Ajout colonnes simhash et is_html ===\n\n";

try {
    $pdo = PostgresDatabase::getInstance()->getConnection();
    
    // Vérifier si les colonnes existent déjà
    $stmt = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'pages' AND column_name IN ('simhash', 'is_html')
    ");
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Ajouter simhash si non existant
    if (!in_array('simhash', $existing)) {
        echo "Ajout de la colonne simhash...\n";
        $pdo->exec("ALTER TABLE pages ADD COLUMN simhash BIGINT");
        echo "  ✓ Colonne simhash ajoutée\n";
    } else {
        echo "  ⊘ Colonne simhash existe déjà\n";
    }
    
    // Ajouter is_html si non existant
    if (!in_array('is_html', $existing)) {
        echo "Ajout de la colonne is_html...\n";
        $pdo->exec("ALTER TABLE pages ADD COLUMN is_html BOOLEAN DEFAULT NULL");
        echo "  ✓ Colonne is_html ajoutée\n";
    } else {
        echo "  ⊘ Colonne is_html existe déjà\n";
    }
    
    // Ajouter les index sur les partitions existantes
    echo "\nAjout des index sur les partitions existantes...\n";
    
    $stmt = $pdo->query("SELECT id FROM crawls ORDER BY id");
    $crawlIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($crawlIds as $crawlId) {
        // Vérifier si la partition existe
        $stmt = $pdo->prepare("
            SELECT 1 FROM information_schema.tables 
            WHERE table_name = :table_name
        ");
        $stmt->execute([':table_name' => "pages_{$crawlId}"]);
        
        if ($stmt->fetch()) {
            // Index pour simhash (pour trouver les duplicates)
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pages_{$crawlId}_simhash ON pages_{$crawlId}(simhash) WHERE simhash IS NOT NULL");
            
            // Index pour is_html (pour filtrer rapidement les pages HTML)
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pages_{$crawlId}_is_html ON pages_{$crawlId}(is_html)");
            
            echo "  ✓ Index ajoutés pour partition pages_{$crawlId}\n";
        }
    }
    
    echo "\n=== Migration terminée avec succès ! ===\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur : " . $e->getMessage() . "\n";
    exit(1);
}
