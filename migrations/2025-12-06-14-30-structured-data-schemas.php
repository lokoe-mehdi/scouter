<?php
/**
 * Migration: Ajout du support des données structurées (Schema.org)
 * 
 * - Colonne schemas TEXT[] dans pages pour filtrage direct
 * - Table page_schemas pour stats rapides (GROUP BY schema_type)
 * - Mise à jour de la fonction create_crawl_partitions
 */

require_once __DIR__ . '/../app/Database/PostgresDatabase.php';

use App\Database\PostgresDatabase;

echo "=== Migration: Données structurées (Schema.org) ===\n\n";

try {
    $pdo = PostgresDatabase::getInstance()->getConnection();
    
    // 0. Mettre à jour la fonction create_crawl_partitions pour inclure page_schemas
    echo "0. Mise à jour de la fonction create_crawl_partitions...\n";
    
    $pdo->exec("
        CREATE OR REPLACE FUNCTION create_crawl_partitions(p_crawl_id INTEGER)
        RETURNS VOID AS \$\$
        BEGIN
            -- Partition pour categories
            EXECUTE format('CREATE TABLE IF NOT EXISTS categories_%s PARTITION OF categories FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            
            -- Partition pour pages
            EXECUTE format('CREATE TABLE IF NOT EXISTS pages_%s PARTITION OF pages FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            
            -- Index pages: colonnes de base
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_id ON pages_%s(id)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_url ON pages_%s(url)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_code ON pages_%s(code)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_depth ON pages_%s(depth)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_cat_id ON pages_%s(cat_id)', p_crawl_id, p_crawl_id);
            
            -- Index pages: colonnes de filtrage/tri booléens
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_crawled ON pages_%s(crawled)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_compliant ON pages_%s(compliant)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_noindex ON pages_%s(noindex)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_nofollow ON pages_%s(nofollow)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_external ON pages_%s(external)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_blocked ON pages_%s(blocked)', p_crawl_id, p_crawl_id);
            
            -- Index pages: canonical (pour détection duplicates)
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_canonical ON pages_%s(canonical)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_canonical_value ON pages_%s(canonical_value) WHERE canonical_value IS NOT NULL', p_crawl_id, p_crawl_id);
            
            -- Index pages: statuts SEO (title, h1, metadesc)
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_title_status ON pages_%s(title_status)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_h1_status ON pages_%s(h1_status)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_metadesc_status ON pages_%s(metadesc_status)', p_crawl_id, p_crawl_id);
            
            -- Index pages: tri par métriques
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_inlinks ON pages_%s(inlinks)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_response_time ON pages_%s(response_time)', p_crawl_id, p_crawl_id);
            
            -- Index pages: simhash et is_html (duplicate detection)
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_simhash ON pages_%s(simhash) WHERE simhash IS NOT NULL', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_is_html ON pages_%s(is_html)', p_crawl_id, p_crawl_id);
            
            -- Partition pour links
            EXECUTE format('CREATE TABLE IF NOT EXISTS links_%s PARTITION OF links FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            
            -- Index links: colonnes de jointure
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_src ON links_%s(src)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_target ON links_%s(target)', p_crawl_id, p_crawl_id);
            
            -- Index links: colonnes de filtrage
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_external ON links_%s(external)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_nofollow ON links_%s(nofollow)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_type ON links_%s(type)', p_crawl_id, p_crawl_id);
            
            -- Partition pour html
            EXECUTE format('CREATE TABLE IF NOT EXISTS html_%s PARTITION OF html FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            
            -- Partition pour page_schemas
            EXECUTE format('CREATE TABLE IF NOT EXISTS page_schemas_%s PARTITION OF page_schemas FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            
            -- Index page_schemas: pour les GROUP BY sur schema_type
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_page_schemas_%s_schema_type ON page_schemas_%s(schema_type)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_page_schemas_%s_page_id ON page_schemas_%s(page_id)', p_crawl_id, p_crawl_id);
            
            -- Partition pour duplicate_clusters
            EXECUTE format('CREATE TABLE IF NOT EXISTS duplicate_clusters_%s PARTITION OF duplicate_clusters FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
        END;
        \$\$ LANGUAGE plpgsql
    ");
    echo "   ✓ Fonction create_crawl_partitions mise à jour\n";
    
    // 1. Ajouter la colonne schemas à la table pages (table mère)
    echo "\n1. Ajout de la colonne schemas à la table pages...\n";
    
    $checkColumn = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'pages' AND column_name = 'schemas'
    ")->fetch();
    
    if (!$checkColumn) {
        $pdo->exec("ALTER TABLE pages ADD COLUMN schemas TEXT[] DEFAULT '{}'");
        echo "   ✓ Colonne schemas ajoutée\n";
    } else {
        echo "   - Colonne schemas existe déjà\n";
    }
    
    // 2. Créer la table page_schemas partitionnée
    echo "\n2. Création de la table page_schemas...\n";
    
    $checkTable = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_name = 'page_schemas'
    ")->fetch();
    
    if (!$checkTable) {
        $pdo->exec("
            CREATE TABLE page_schemas (
                crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
                page_id CHAR(8) NOT NULL,
                schema_type VARCHAR(100) NOT NULL,
                PRIMARY KEY (crawl_id, page_id, schema_type)
            ) PARTITION BY LIST (crawl_id)
        ");
        echo "   ✓ Table page_schemas créée\n";
    } else {
        echo "   - Table page_schemas existe déjà\n";
    }
    
    // 3. Créer les partitions pour les crawls existants
    echo "\n3. Création des partitions pour les crawls existants...\n";
    
    $crawls = $pdo->query("SELECT id FROM crawls")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($crawls as $crawlId) {
        // Vérifier si la partition existe déjà
        $partitionExists = $pdo->query("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_name = 'page_schemas_{$crawlId}'
        ")->fetch();
        
        if (!$partitionExists) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS page_schemas_{$crawlId} PARTITION OF page_schemas FOR VALUES IN ({$crawlId})");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_schemas_{$crawlId}_schema_type ON page_schemas_{$crawlId}(schema_type)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_schemas_{$crawlId}_page_id ON page_schemas_{$crawlId}(page_id)");
            echo "   ✓ Partition page_schemas_{$crawlId} créée\n";
        } else {
            echo "   - Partition page_schemas_{$crawlId} existe déjà\n";
        }
    }
    
    if (empty($crawls)) {
        echo "   (aucun crawl existant)\n";
    }
    
    echo "\n✓ Migration terminée avec succès\n";
    echo "\nNote: Les données structurées seront extraites lors des prochains crawls.\n";
    echo "Pour les crawls existants, relancez-les pour extraire les schemas.\n";
    
} catch (Exception $e) {
    echo "\n✗ Erreur : " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
