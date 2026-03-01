-- Scouter PostgreSQL Schema
-- Tables partitionnées par crawl_id pour pages, links, html

-- ============================================
-- TABLES PRINCIPALES
-- ============================================

CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'user' CHECK (role IN ('admin', 'user', 'viewer')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des migrations exécutées
CREATE TABLE migrations (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des projets (regroupement de crawls par domaine)
CREATE TABLE projects (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    categorization_config TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_projects_user_id ON projects(user_id);
CREATE INDEX idx_projects_has_config ON projects(id) WHERE categorization_config IS NOT NULL;

-- Table des partages de projets (lecture seule)
CREATE TABLE project_shares (
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, user_id)
);

CREATE INDEX idx_project_shares_user_id ON project_shares(user_id);

-- Catégories de projets (chaque utilisateur a ses propres catégories)
CREATE TABLE project_categories (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#4ECDC4',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_project_categories_user_id ON project_categories(user_id);

-- Table de liaison Many-to-Many : Projets <-> Catégories
-- Un projet peut être dans plusieurs catégories (de son propriétaire ou d'un utilisateur avec partage)
CREATE TABLE project_category_links (
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    category_id INTEGER NOT NULL REFERENCES project_categories(id) ON DELETE CASCADE,
    PRIMARY KEY (project_id, category_id)
);

CREATE INDEX idx_project_category_links_project ON project_category_links(project_id);
CREATE INDEX idx_project_category_links_category ON project_category_links(category_id);

CREATE TABLE crawls (
    id SERIAL PRIMARY KEY,
    project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,
    domain VARCHAR(255) NOT NULL,
    path TEXT UNIQUE,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'queued', 'running', 'stopping', 'stopped', 'finished', 'error', 'failed')),
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP,
    config JSONB,
    urls INTEGER DEFAULT 0,
    crawled INTEGER DEFAULT 0,
    compliant INTEGER DEFAULT 0,
    duplicates INTEGER DEFAULT 0,
    response_time FLOAT DEFAULT 0,
    depth_max INTEGER DEFAULT 0,
    in_progress INTEGER DEFAULT 0,
    compliant_duplicate INTEGER DEFAULT 0,
    clusters_duplicate INTEGER DEFAULT 0
);

CREATE INDEX idx_crawls_path ON crawls(path);
CREATE INDEX idx_crawls_project_id ON crawls(project_id);

-- Configuration de catégorisation par crawl (contenu YAML du cat.yml)
CREATE TABLE categorization_config (
    id SERIAL PRIMARY KEY,
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    config TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(crawl_id)
);

-- ============================================
-- TABLES PARTITIONNÉES
-- ============================================

-- Table categories partitionnée par crawl_id
CREATE TABLE categories (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    id SERIAL,
    cat VARCHAR(255) NOT NULL,
    color VARCHAR(7) DEFAULT '#aaaaaa',
    PRIMARY KEY (crawl_id, id)
) PARTITION BY LIST (crawl_id);

-- Table pages partitionnée par crawl_id
CREATE TABLE pages (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    id CHAR(8) NOT NULL,
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    cat_id INTEGER, -- Référence à categories.id (même crawl_id)
    domain VARCHAR(255),
    url TEXT,
    depth INTEGER DEFAULT 0,
    code INTEGER,
    response_time FLOAT,
    inlinks INTEGER DEFAULT 0,
    outlinks INTEGER DEFAULT 0,
    pri FLOAT DEFAULT 0,
    content_type VARCHAR(100),
    redirect_to TEXT,
    crawled BOOLEAN DEFAULT FALSE,
    compliant BOOLEAN DEFAULT FALSE,
    noindex BOOLEAN DEFAULT FALSE,
    nofollow BOOLEAN DEFAULT FALSE,
    canonical BOOLEAN DEFAULT TRUE,
    canonical_value TEXT,
    external BOOLEAN DEFAULT FALSE,
    blocked BOOLEAN DEFAULT FALSE,
    title TEXT,
    title_status VARCHAR(50),
    h1 TEXT,
    h1_status VARCHAR(50),
    metadesc TEXT,
    metadesc_status VARCHAR(50),
    extracts JSONB,
    simhash BIGINT,
    is_html BOOLEAN DEFAULT NULL,
    h1_multiple BOOLEAN DEFAULT FALSE,
    headings_missing BOOLEAN DEFAULT FALSE,
    schemas TEXT[] DEFAULT '{}',
    word_count INTEGER DEFAULT 0,
    PRIMARY KEY (crawl_id, id)
) PARTITION BY LIST (crawl_id);

-- Table links partitionnée par crawl_id
CREATE TABLE links (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    src CHAR(8) NOT NULL,
    target CHAR(8) NOT NULL,
    anchor TEXT,
    external BOOLEAN DEFAULT FALSE,
    nofollow BOOLEAN DEFAULT FALSE,
    type VARCHAR(50),
    PRIMARY KEY (crawl_id, src, target)
) PARTITION BY LIST (crawl_id);

-- Table html partitionnée par crawl_id
CREATE TABLE html (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    id CHAR(8) NOT NULL,
    html TEXT,
    PRIMARY KEY (crawl_id, id)
) PARTITION BY LIST (crawl_id);

-- Table page_schemas partitionnée par crawl_id
-- Table de liaison pour stats rapides sur les types de données structurées
CREATE TABLE page_schemas (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    page_id CHAR(8) NOT NULL,
    schema_type VARCHAR(100) NOT NULL,
    PRIMARY KEY (crawl_id, page_id, schema_type)
) PARTITION BY LIST (crawl_id);

-- Table duplicate_clusters partitionnée par crawl_id
-- Stocke les clusters de duplication pour éviter les calculs à la volée
CREATE TABLE duplicate_clusters (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    id SERIAL,
    similarity INTEGER NOT NULL DEFAULT 100, -- 100 = exact, <100 = near-duplicate
    page_count INTEGER NOT NULL DEFAULT 0,
    page_ids TEXT[] NOT NULL DEFAULT '{}', -- Liste des IDs de pages CHAR(8) (type ARRAY PostgreSQL)
    PRIMARY KEY (crawl_id, id)
) PARTITION BY LIST (crawl_id);

-- ============================================
-- INDEX
-- ============================================

-- Index sur crawls
CREATE INDEX idx_crawls_domain ON crawls(domain);
CREATE INDEX idx_crawls_status ON crawls(status);

-- Note: Les index sur les tables partitionnées seront créés 
-- automatiquement sur chaque partition lors de leur création

-- ============================================
-- FONCTION POUR CRÉER LES PARTITIONS
-- ============================================

CREATE OR REPLACE FUNCTION create_crawl_partitions(p_crawl_id INTEGER)
RETURNS VOID AS $$
BEGIN
    -- Advisory lock pour sérialiser la création des partitions
    -- Évite les erreurs 55P03 (lock_not_available) quand plusieurs crawls démarrent simultanément
    -- Le lock 12345 est un identifiant arbitraire unique pour cette opération
    PERFORM pg_advisory_lock(12345);
    
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
        
        -- Index page_schemas
        EXECUTE format('CREATE INDEX IF NOT EXISTS idx_page_schemas_%s_schema_type ON page_schemas_%s(schema_type)', p_crawl_id, p_crawl_id);
        EXECUTE format('CREATE INDEX IF NOT EXISTS idx_page_schemas_%s_page_id ON page_schemas_%s(page_id)', p_crawl_id, p_crawl_id);
        
        -- Partition pour duplicate_clusters
        EXECUTE format('CREATE TABLE IF NOT EXISTS duplicate_clusters_%s PARTITION OF duplicate_clusters FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
        
    EXCEPTION WHEN OTHERS THEN
        -- Libérer le lock même en cas d'erreur
        PERFORM pg_advisory_unlock(12345);
        RAISE;
    END;
    
    -- Libérer le lock
    PERFORM pg_advisory_unlock(12345);
END;
$$ LANGUAGE plpgsql;

-- ============================================
-- FONCTION POUR SUPPRIMER LES PARTITIONS
-- ============================================

CREATE OR REPLACE FUNCTION drop_crawl_partitions(p_crawl_id INTEGER)
RETURNS VOID AS $$
BEGIN
    EXECUTE format('DROP TABLE IF EXISTS categories_%s', p_crawl_id);
    EXECUTE format('DROP TABLE IF EXISTS pages_%s', p_crawl_id);
    EXECUTE format('DROP TABLE IF EXISTS links_%s', p_crawl_id);
    EXECUTE format('DROP TABLE IF EXISTS html_%s', p_crawl_id);
    EXECUTE format('DROP TABLE IF EXISTS page_schemas_%s', p_crawl_id);
    EXECUTE format('DROP TABLE IF EXISTS duplicate_clusters_%s', p_crawl_id);
END;
$$ LANGUAGE plpgsql;
