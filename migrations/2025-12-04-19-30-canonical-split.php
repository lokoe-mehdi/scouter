<?php
/**
 * Migration: Séparation du champ canonical en canonical (bool) et canonical_value (text)
 * 
 * Contexte: Une URL comme https://www.jamzone.com/blog/?page=2 peut avoir
 * canonical -> https://www.jamzone.com/blog/ (URL différente).
 * Actuellement, seule la balise canonical (URL) est stockée.
 * 
 * Cette migration:
 * 1. Renomme canonical → canonical_value (TEXT)
 * 2. Ajoute canonical (BOOLEAN, TRUE par défaut)
 * 3. Met à jour les index pour les partitions existantes
 * 4. Migre les données: canonical = (canonical_value IS NULL OR canonical_value = url)
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->beginTransaction();

    // ============================================
    // 1. Renommer canonical → canonical_value
    // ============================================
    
    $stmt = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'pages' AND column_name = 'canonical_value'
    ");
    
    if (!$stmt->fetch()) {
        echo "   → Renommage de 'canonical' en 'canonical_value'... ";
        $pdo->exec("ALTER TABLE pages RENAME COLUMN canonical TO canonical_value");
        echo "OK\n";
    } else {
        echo "   → Colonne 'canonical_value' déjà existante\n";
    }

    // ============================================
    // 2. Ajouter la colonne canonical (BOOLEAN)
    // ============================================
    
    $stmt = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'pages' AND column_name = 'canonical'
    ");
    
    if (!$stmt->fetch()) {
        echo "   → Ajout de la colonne 'canonical' (BOOLEAN)... ";
        $pdo->exec("ALTER TABLE pages ADD COLUMN canonical BOOLEAN DEFAULT TRUE");
        echo "OK\n";
    } else {
        echo "   → Colonne 'canonical' déjà existante\n";
    }

    // ============================================
    // 3. Migration des données existantes
    // ============================================
    
    echo "   → Migration des données canonical... ";
    // canonical = TRUE si canonical_value est NULL ou égal à l'URL
    $stmt = $pdo->exec("
        UPDATE pages 
        SET canonical = (canonical_value IS NULL OR canonical_value = url)
        WHERE crawled = true
    ");
    echo "OK ($stmt lignes)\n";

    // ============================================
    // 4. Mettre à jour les index sur les partitions
    // ============================================
    
    echo "   → Mise à jour des index sur les partitions existantes...\n";
    
    // Récupérer toutes les partitions pages_*
    $stmt = $pdo->query("
        SELECT tablename 
        FROM pg_tables 
        WHERE schemaname = 'public' 
        AND tablename LIKE 'pages_%'
        AND tablename ~ '^pages_[0-9]+$'
    ");
    $partitions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($partitions as $partition) {
        $crawlId = str_replace('pages_', '', $partition);
        
        // Supprimer l'ancien index canonical (qui était sur TEXT)
        $oldIndexName = "idx_pages_{$crawlId}_canonical";
        $pdo->exec("DROP INDEX IF EXISTS {$oldIndexName}");
        
        // Créer le nouvel index sur canonical (BOOLEAN)
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pages_{$crawlId}_canonical ON pages_{$crawlId}(canonical)");
        
        // Créer un index sur canonical_value pour les recherches
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pages_{$crawlId}_canonical_value ON pages_{$crawlId}(canonical_value) WHERE canonical_value IS NOT NULL");
        
        echo "     → Partition pages_{$crawlId}: index mis à jour\n";
    }

    // ============================================
    // 5. Mettre à jour la fonction create_crawl_partitions
    // ============================================
    
    echo "   → Mise à jour de la fonction create_crawl_partitions... ";
    $pdo->exec("
        CREATE OR REPLACE FUNCTION create_crawl_partitions(p_crawl_id INTEGER)
        RETURNS VOID AS \$\$
        BEGIN
            EXECUTE format('CREATE TABLE IF NOT EXISTS categories_%s PARTITION OF categories FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE TABLE IF NOT EXISTS pages_%s PARTITION OF pages FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_id ON pages_%s(id)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_url ON pages_%s(url)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_code ON pages_%s(code)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_depth ON pages_%s(depth)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_cat_id ON pages_%s(cat_id)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_crawled ON pages_%s(crawled)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_compliant ON pages_%s(compliant)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_noindex ON pages_%s(noindex)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_nofollow ON pages_%s(nofollow)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_external ON pages_%s(external)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_blocked ON pages_%s(blocked)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_canonical ON pages_%s(canonical)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_canonical_value ON pages_%s(canonical_value) WHERE canonical_value IS NOT NULL', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_title_status ON pages_%s(title_status)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_h1_status ON pages_%s(h1_status)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_metadesc_status ON pages_%s(metadesc_status)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_inlinks ON pages_%s(inlinks)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_response_time ON pages_%s(response_time)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE TABLE IF NOT EXISTS links_%s PARTITION OF links FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_src ON links_%s(src)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_target ON links_%s(target)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE TABLE IF NOT EXISTS html_%s PARTITION OF html FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
        END;
        \$\$ LANGUAGE plpgsql;
    ");
    echo "OK\n";

    $pdo->commit();
    echo "   ✓ Migration terminée avec succès\n";
    
    return true;

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n   ✗ Erreur: " . $e->getMessage() . "\n";
    return false;
}
