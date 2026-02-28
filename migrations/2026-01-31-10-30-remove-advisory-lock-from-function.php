<?php
/**
 * Migration: Supprimer l'advisory lock de la fonction create_crawl_partitions
 * 
 * L'advisory lock est maintenant géré côté PHP (CrawlDatabase::createPartitions)
 * pour permettre une coordination shared/exclusive avec les crawls en cours.
 * 
 * Cela évite les deadlocks entre CREATE PARTITION et UPDATE pages.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    echo "   → Mise à jour de create_crawl_partitions (lock géré côté PHP)... ";
    
    $pdo->exec("
        CREATE OR REPLACE FUNCTION create_crawl_partitions(p_crawl_id INTEGER)
        RETURNS VOID AS \$\$
        BEGIN
            -- NOTE: L'advisory lock est maintenant géré côté PHP (CrawlDatabase::createPartitions)
            -- pour permettre une coordination shared/exclusive avec les crawls en cours
            
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
            
            -- Index pages: canonical
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_canonical ON pages_%s(canonical)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_canonical_value ON pages_%s(canonical_value) WHERE canonical_value IS NOT NULL', p_crawl_id, p_crawl_id);
            
            -- Index pages: statuts SEO
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_title_status ON pages_%s(title_status)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_h1_status ON pages_%s(h1_status)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_metadesc_status ON pages_%s(metadesc_status)', p_crawl_id, p_crawl_id);
            
            -- Index pages: métriques
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_inlinks ON pages_%s(inlinks)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_response_time ON pages_%s(response_time)', p_crawl_id, p_crawl_id);
            
            -- Index pages: simhash et is_html
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_simhash ON pages_%s(simhash) WHERE simhash IS NOT NULL', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_is_html ON pages_%s(is_html)', p_crawl_id, p_crawl_id);
            
            -- Partition pour links
            EXECUTE format('CREATE TABLE IF NOT EXISTS links_%s PARTITION OF links FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            
            -- Index links
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_src ON links_%s(src)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_target ON links_%s(target)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_external ON links_%s(external)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_nofollow ON links_%s(nofollow)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_type ON links_%s(type)', p_crawl_id, p_crawl_id);
            
            -- Partition pour html
            EXECUTE format('CREATE TABLE IF NOT EXISTS html_%s PARTITION OF html FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            
            -- Partition pour page_schemas
            EXECUTE format('CREATE TABLE IF NOT EXISTS page_schemas_%s PARTITION OF page_schemas FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_page_schemas_%s_schema_type ON page_schemas_%s(schema_type)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_page_schemas_%s_page_id ON page_schemas_%s(page_id)', p_crawl_id, p_crawl_id);
            
            -- Partition pour duplicate_clusters
            EXECUTE format('CREATE TABLE IF NOT EXISTS duplicate_clusters_%s PARTITION OF duplicate_clusters FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
        END;
        \$\$ LANGUAGE plpgsql;
    ");
    
    echo "OK\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Erreur: " . $e->getMessage() . "\n";
    return false;
}
