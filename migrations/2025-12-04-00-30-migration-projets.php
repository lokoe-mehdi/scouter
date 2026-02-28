<?php
/**
 * Migration: Ajout du système de projets et permissions
 * 
 * Cette migration:
 * 1. Ajoute la colonne 'role' à la table users
 * 2. Crée les tables 'projects' et 'project_shares'
 * 3. Ajoute la colonne 'project_id' à la table crawls
 * 4. Migre les crawls existants vers des projets (groupés par domaine)
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->beginTransaction();

    // ============================================
    // 1. Ajouter la colonne 'role' à la table users
    // ============================================
    
    // Vérifier si la colonne existe déjà
    $stmt = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'users' AND column_name = 'role'
    ");
    
    if (!$stmt->fetch()) {
        echo "   → Ajout de la colonne 'role' à users... ";
        $pdo->exec("
            ALTER TABLE users 
            ADD COLUMN role VARCHAR(20) DEFAULT 'user' 
            CHECK (role IN ('admin', 'user', 'viewer'))
        ");
        echo "OK\n";
    } else {
        echo "   → Colonne 'role' déjà existante\n";
    }

    // ============================================
    // 2. Créer la table 'projects'
    // ============================================
    
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_name = 'projects'
    ");
    
    if (!$stmt->fetch()) {
        echo "   → Création de la table 'projects'... ";
        $pdo->exec("
            CREATE TABLE projects (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX idx_projects_user_id ON projects(user_id)");
        echo "OK\n";
    } else {
        echo "   → Table 'projects' déjà existante\n";
    }

    // ============================================
    // 3. Créer la table 'project_shares'
    // ============================================
    
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_name = 'project_shares'
    ");
    
    if (!$stmt->fetch()) {
        echo "   → Création de la table 'project_shares'... ";
        $pdo->exec("
            CREATE TABLE project_shares (
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (project_id, user_id)
            )
        ");
        $pdo->exec("CREATE INDEX idx_project_shares_user_id ON project_shares(user_id)");
        echo "OK\n";
    } else {
        echo "   → Table 'project_shares' déjà existante\n";
    }

    // ============================================
    // 4. Ajouter la colonne 'project_id' à crawls
    // ============================================
    
    $stmt = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'crawls' AND column_name = 'project_id'
    ");
    
    if (!$stmt->fetch()) {
        echo "   → Ajout de la colonne 'project_id' à crawls... ";
        $pdo->exec("
            ALTER TABLE crawls 
            ADD COLUMN project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE
        ");
        $pdo->exec("CREATE INDEX idx_crawls_project_id ON crawls(project_id)");
        echo "OK\n";
    } else {
        echo "   → Colonne 'project_id' déjà existante\n";
    }

    // ============================================
    // 5. Migration des données existantes
    // ============================================
    
    echo "   → Migration des crawls existants vers des projets...\n";
    
    // Récupérer le premier utilisateur (admin par défaut)
    $stmt = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
    $defaultUser = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$defaultUser) {
        echo "     ⚠ Aucun utilisateur trouvé. Migration des projets ignorée.\n";
    } else {
        $defaultUserId = $defaultUser->id;
        echo "     → Utilisateur par défaut: ID $defaultUserId\n";
        
        // Promouvoir le premier utilisateur en admin s'il ne l'est pas déjà
        $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ? AND role != 'admin'")
            ->execute([$defaultUserId]);
        
        // Récupérer tous les domaines uniques qui n'ont pas encore de projet
        $stmt = $pdo->query("
            SELECT DISTINCT domain 
            FROM crawls 
            WHERE project_id IS NULL
            ORDER BY domain
        ");
        $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($domains)) {
            echo "     → Aucun crawl orphelin à migrer\n";
        } else {
            echo "     → " . count($domains) . " domaine(s) à migrer\n";
            
            foreach ($domains as $domain) {
                // Créer le projet
                $stmt = $pdo->prepare("
                    INSERT INTO projects (user_id, name) 
                    VALUES (?, ?) 
                    RETURNING id
                ");
                $stmt->execute([$defaultUserId, $domain]);
                $projectId = $stmt->fetchColumn();
                
                // Associer tous les crawls de ce domaine au projet
                $stmt = $pdo->prepare("
                    UPDATE crawls 
                    SET project_id = ? 
                    WHERE domain = ? AND project_id IS NULL
                ");
                $stmt->execute([$projectId, $domain]);
                $updated = $stmt->rowCount();
                
                echo "     → Projet '$domain' (ID: $projectId) : $updated crawl(s)\n";
            }
        }
    }

    $pdo->commit();
    echo "   ✓ Migration terminée avec succès\n";
    
    return true;

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n   ✗ Erreur: " . $e->getMessage() . "\n";
    return false;
}
