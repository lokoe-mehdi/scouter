<?php
/**
 * Migration: Catégories par utilisateur avec relation Many-to-Many
 * 
 * Cette migration:
 * 1. Crée la table 'project_categories' (catégories liées à un utilisateur)
 * 2. Crée la table 'project_category_links' (relation many-to-many)
 * 3. Migre les données de 'crawls_categories' vers 'project_categories'
 * 4. Supprime l'ancienne table 'crawls_categories' et la colonne 'category_id' de crawls
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->beginTransaction();

    // ============================================
    // 1. Créer la table 'project_categories'
    // ============================================
    
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_name = 'project_categories'
    ");
    
    if (!$stmt->fetch()) {
        echo "   → Création de la table 'project_categories'... ";
        $pdo->exec("
            CREATE TABLE project_categories (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                name VARCHAR(100) NOT NULL,
                color VARCHAR(7) NOT NULL DEFAULT '#4ECDC4',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX idx_project_categories_user_id ON project_categories(user_id)");
        echo "OK\n";
    } else {
        echo "   → Table 'project_categories' déjà existante\n";
    }

    // ============================================
    // 2. Créer la table 'project_category_links'
    // ============================================
    
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_name = 'project_category_links'
    ");
    
    if (!$stmt->fetch()) {
        echo "   → Création de la table 'project_category_links'... ";
        $pdo->exec("
            CREATE TABLE project_category_links (
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                category_id INTEGER NOT NULL REFERENCES project_categories(id) ON DELETE CASCADE,
                PRIMARY KEY (project_id, category_id)
            )
        ");
        $pdo->exec("CREATE INDEX idx_project_category_links_project ON project_category_links(project_id)");
        $pdo->exec("CREATE INDEX idx_project_category_links_category ON project_category_links(category_id)");
        echo "OK\n";
    } else {
        echo "   → Table 'project_category_links' déjà existante\n";
    }

    // ============================================
    // 3. Migrer les données de 'crawls_categories'
    // ============================================
    
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_name = 'crawls_categories'
    ");
    
    if ($stmt->fetch()) {
        echo "   → Migration des données de 'crawls_categories'... ";
        
        // Récupérer le premier admin (ou premier user)
        $adminStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $admin = $adminStmt->fetch(PDO::FETCH_OBJ);
        
        if (!$admin) {
            $userStmt = $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1");
            $admin = $userStmt->fetch(PDO::FETCH_OBJ);
        }
        
        if ($admin) {
            // Migrer les catégories existantes vers le premier admin
            $pdo->exec("
                INSERT INTO project_categories (user_id, name, color, created_at)
                SELECT {$admin->id}, name, color, created_at
                FROM crawls_categories
            ");
            
            // Migrer les liens crawls.category_id vers project_category_links
            // On lie le projet (via crawls.project_id) à la nouvelle catégorie
            $pdo->exec("
                INSERT INTO project_category_links (project_id, category_id)
                SELECT DISTINCT c.project_id, pc.id
                FROM crawls c
                JOIN crawls_categories cc ON c.category_id = cc.id
                JOIN project_categories pc ON pc.name = cc.name AND pc.user_id = {$admin->id}
                WHERE c.project_id IS NOT NULL
                ON CONFLICT DO NOTHING
            ");
        }
        
        echo "OK\n";
    }

    // ============================================
    // 4. Supprimer la colonne 'category_id' de crawls
    // ============================================
    
    $stmt = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'crawls' AND column_name = 'category_id'
    ");
    
    if ($stmt->fetch()) {
        echo "   → Suppression de la colonne 'category_id' de crawls... ";
        $pdo->exec("ALTER TABLE crawls DROP COLUMN category_id");
        echo "OK\n";
    }

    // ============================================
    // 5. Supprimer l'ancienne table 'crawls_categories'
    // ============================================
    
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_name = 'crawls_categories'
    ");
    
    if ($stmt->fetch()) {
        echo "   → Suppression de la table 'crawls_categories'... ";
        $pdo->exec("DROP TABLE crawls_categories");
        echo "OK\n";
    }

    $pdo->commit();
    echo "   ✓ Migration des catégories terminée avec succès\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "   ✗ Erreur lors de la migration: " . $e->getMessage() . "\n";
    throw $e;
}
