-- Scouter PostgreSQL Schema — BASELINE COMPLET
--
-- Ce fichier est le schéma de référence appliqué UNE SEULE FOIS sur un volume
-- PostgreSQL vierge (via /docker-entrypoint-initdb.d). Il reflète à 100 % l'état
-- final du schéma (équivalent à init.sql d'origine + toutes les migrations).
--
-- IMPORTANT : la table `migrations` est pré-remplie en fin de fichier avec le nom
-- de toutes les migrations déjà intégrées à ce baseline. Sur une installation
-- NEUVE, `migrate.php` les voit donc comme « déjà appliquées » et n'en rejoue
-- AUCUNE — pas de conflit, pas de rejeu inutile. Les fichiers migrations/ restent
-- le chemin de mise à jour des installations EXISTANTES (init.sql ne s'exécute
-- jamais sur un volume déjà initialisé).
--
-- Règle de maintenance : toute évolution du schéma se fait via une NOUVELLE
-- migration dans migrations/. Périodiquement, on peut régénérer ce baseline
-- (pg_dump --schema-only) et étendre la liste de seed des migrations.
--
-- Stockage des données de crawl : PostgreSQL conserve la frontier + les
-- métadonnées (projets, users, settings…) ; les pages/links/html volumineux des
-- nouveaux crawls vivent dans ClickHouse (voir clickhouse.md + crawler-go). Les
-- tables partitionnées pages/links/html ci-dessous restent utilisées pour les
-- crawls en mode PG (legacy / ClickHouse désactivé), routées par crawls.data_store.

-- ============================================
-- TABLES PRINCIPALES
-- ============================================

CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'user' CHECK (role IN ('admin', 'user', 'viewer')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Override par utilisateur du budget mensuel IA global (NULL = défaut global).
    ai_monthly_budget_usd NUMERIC(10,2)
);

-- Table des migrations exécutées (pré-remplie en fin de fichier).
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP DEFAULT NULL
);

CREATE INDEX idx_projects_user_id ON projects(user_id);
CREATE INDEX idx_projects_has_config ON projects(id) WHERE categorization_config IS NOT NULL;
CREATE INDEX idx_projects_deleted ON projects(id) WHERE deleted_at IS NOT NULL;

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
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'queued', 'running', 'stopping', 'stopped', 'finished', 'error', 'failed', 'deleting')),
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP,
    config JSONB,
    urls INTEGER DEFAULT 0,
    crawled INTEGER DEFAULT 0,
    compliant INTEGER DEFAULT 0,
    duplicates INTEGER DEFAULT 0,
    response_time FLOAT DEFAULT 0,
    depth_max INTEGER DEFAULT 0,
    crawl_type VARCHAR(10) DEFAULT 'spider' CHECK (crawl_type IN ('spider', 'list')),
    in_progress INTEGER DEFAULT 0,
    compliant_duplicate INTEGER DEFAULT 0,
    clusters_duplicate INTEGER DEFAULT 0,
    redirect_total INTEGER DEFAULT 0,
    redirect_chains_count INTEGER DEFAULT 0,
    redirect_chains_errors INTEGER DEFAULT 0,
    scheduled BOOLEAN DEFAULT FALSE,
    -- Où vivent les données de ce crawl : 'pg' (legacy) ou 'clickhouse'. Posé par
    -- le crawler Go au démarrage du crawl quand ClickHouse est actif ; route les lectures.
    data_store VARCHAR(16) DEFAULT 'pg' CHECK (data_store IN ('pg', 'clickhouse')),
    -- Nombre d'erreurs critiques (timeouts/erreurs réseau) rencontrées pendant le crawl.
    critical_errors INTEGER DEFAULT 0,
    -- Score santé SEO 0-100 calculé depuis ClickHouse et persisté (App\Analysis\CrawlStats).
    -- NULL = pas encore calculé (sentinelle du write-through home/page projet).
    health_score SMALLINT
);

CREATE INDEX idx_crawls_path ON crawls(path);
CREATE INDEX idx_crawls_project_id ON crawls(project_id);
CREATE INDEX idx_crawls_domain ON crawls(domain);
CREATE INDEX idx_crawls_status ON crawls(status);

-- File de jobs asynchrones (crawls, exports, suppressions, batch jobs).
-- Aussi créée en migration pour les volumes existants.
CREATE TABLE jobs (
    id SERIAL PRIMARY KEY,
    project_dir TEXT NOT NULL,
    project_name TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    progress INTEGER DEFAULT 0,
    pid INTEGER DEFAULT NULL,
    command TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP DEFAULT NULL,
    finished_at TIMESTAMP DEFAULT NULL,
    error TEXT DEFAULT NULL
);

CREATE TABLE job_logs (
    id SERIAL PRIMARY KEY,
    job_id INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
    message TEXT,
    type VARCHAR(20) DEFAULT 'info',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_jobs_project_dir ON jobs(project_dir);
CREATE INDEX idx_jobs_status ON jobs(status);
CREATE INDEX idx_job_logs_job_id ON job_logs(job_id);
CREATE INDEX idx_jobs_status_created ON jobs(status, created_at);

-- Configuration de catégorisation par crawl (contenu YAML du cat.yml)
CREATE TABLE categorization_config (
    id SERIAL PRIMARY KEY,
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    config TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(crawl_id)
);

-- Table de planification des crawls récurrents
CREATE TABLE crawl_schedules (
    id SERIAL PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    enabled BOOLEAN DEFAULT FALSE,
    frequency VARCHAR(10) NOT NULL DEFAULT 'weekly'
        CHECK (frequency IN ('minute', 'daily', 'weekly', 'monthly')),
    days_of_week TEXT[] DEFAULT '{mon}',
    day_of_month INTEGER DEFAULT 1 CHECK (day_of_month BETWEEN 1 AND 28),
    hour INTEGER DEFAULT 8 CHECK (hour BETWEEN 0 AND 23),
    minute INTEGER DEFAULT 0 CHECK (minute BETWEEN 0 AND 59),
    crawl_config JSONB NOT NULL DEFAULT '{}',
    crawl_type VARCHAR(10) DEFAULT 'spider',
    depth_max INTEGER DEFAULT 30,
    categorization_config TEXT DEFAULT NULL,
    last_triggered_at TIMESTAMP DEFAULT NULL,
    next_run_at TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id)
);

CREATE INDEX idx_crawl_schedules_due ON crawl_schedules(enabled, next_run_at);
CREATE INDEX idx_crawl_schedules_project ON crawl_schedules(project_id);

-- Requêtes SQL sauvegardées par l'utilisateur (saved snippets dans SQL Explorer)
CREATE TABLE user_saved_queries (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    query TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_user_saved_queries_user ON user_saved_queries(user_id);

-- Table catégories au niveau projet (partagée entre tous les crawls d'un projet)
CREATE TABLE crawl_categories (
    id SERIAL PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    cat VARCHAR(255) NOT NULL,
    color VARCHAR(7) DEFAULT '#aaaaaa',
    UNIQUE(project_id, cat)
);
CREATE INDEX idx_crawl_categories_project ON crawl_categories(project_id);

-- ============================================
-- PARAMÈTRES APPLICATIFS & IA
-- ============================================

-- Paramètres globaux clé/valeur (clés API chiffrées, modèles, budgets…).
CREATE TABLE app_settings (
    key VARCHAR(100) PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL
);

-- Clés API publiques (API v1). Le token n'est jamais stocké en clair (hash SHA-256).
CREATE TABLE api_keys (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    prefix VARCHAR(16) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    last_used_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP
);
CREATE INDEX idx_api_keys_user ON api_keys(user_id, created_at DESC);
CREATE INDEX idx_api_keys_prefix ON api_keys(prefix) WHERE revoked_at IS NULL;

-- Audit des runs de catégorisation IA (jamais le contenu — RGPD-safe).
CREATE TABLE ai_categorization_runs (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    crawl_id INTEGER NOT NULL,
    model VARCHAR(100),
    input_tokens INTEGER,
    output_tokens INTEGER,
    pages_sampled INTEGER,
    success BOOLEAN NOT NULL DEFAULT FALSE,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_ai_runs_crawl ON ai_categorization_runs(crawl_id, created_at DESC);
CREATE INDEX idx_ai_runs_user ON ai_categorization_runs(user_id, created_at DESC);

-- Audit des runs du chatbot Dr. Brief.
CREATE TABLE ai_chat_runs (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    crawl_id INTEGER NOT NULL,
    model VARCHAR(100),
    input_tokens INTEGER DEFAULT 0,
    output_tokens INTEGER DEFAULT 0,
    tool_calls INTEGER DEFAULT 0,
    success BOOLEAN NOT NULL DEFAULT FALSE,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_ai_chat_crawl ON ai_chat_runs(crawl_id, created_at DESC);
CREATE INDEX idx_ai_chat_user_time ON ai_chat_runs(user_id, created_at DESC);

-- Ledger unifié de consommation IA (source de vérité pour l'enforcement du budget).
CREATE TABLE ai_usage (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    feature VARCHAR(32) NOT NULL,
    model VARCHAR(100),
    input_tokens INTEGER DEFAULT 0,
    output_tokens INTEGER DEFAULT 0,
    cost_usd NUMERIC(12,6) NOT NULL DEFAULT 0,
    crawl_id INTEGER,
    success BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_ai_usage_user_time ON ai_usage(user_id, created_at DESC);
CREATE INDEX idx_ai_usage_feature_time ON ai_usage(feature, created_at DESC);

-- Jobs de génération de contenu IA en masse (Bulk AI Generator).
CREATE TABLE bulk_generation_jobs (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    crawl_id INTEGER NOT NULL,
    items JSONB NOT NULL,
    prompt_template TEXT NOT NULL,
    context_fields TEXT[] NOT NULL,
    page_ids TEXT[] NOT NULL,
    model VARCHAR(100) NOT NULL,
    batch_size SMALLINT NOT NULL DEFAULT 10,
    url_count INTEGER NOT NULL,
    processed_count INTEGER NOT NULL DEFAULT 0,
    failed_count INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    estimated_cost NUMERIC(10,6),
    actual_cost NUMERIC(10,6),
    error_message TEXT,
    errors_sample JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP,
    finished_at TIMESTAMP
);
CREATE INDEX idx_bgj_user_time ON bulk_generation_jobs(user_id, created_at DESC);
CREATE INDEX idx_bgj_crawl_time ON bulk_generation_jobs(crawl_id, created_at DESC);
CREATE INDEX idx_bgj_active ON bulk_generation_jobs(status, created_at)
    WHERE status IN ('queued', 'running');

-- ============================================
-- OAUTH (MCP avec OAuth)
-- ============================================

CREATE TABLE oauth_clients (
    id SERIAL PRIMARY KEY,
    client_id TEXT UNIQUE NOT NULL,
    client_name TEXT,
    redirect_uris JSONB NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE TABLE oauth_auth_codes (
    code TEXT PRIMARY KEY,
    client_id TEXT NOT NULL,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    redirect_uri TEXT NOT NULL,
    code_challenge TEXT NOT NULL,
    scope TEXT,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
);
CREATE INDEX idx_oauth_codes_expires ON oauth_auth_codes(expires_at);

-- Cache de rapports pré-calculés (query cache applicatif pour crawls terminés).
CREATE TABLE crawl_report_cache (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    report_key TEXT NOT NULL,
    payload JSONB NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    query_sql TEXT,
    query_params JSONB,
    category_dependent SMALLINT DEFAULT 0,
    PRIMARY KEY (crawl_id, report_key)
);

-- ============================================
-- TABLES PARTITIONNÉES PAR crawl_id (données de crawl, mode PG / legacy)
-- ============================================

-- Table pages partitionnée par crawl_id
CREATE TABLE pages (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    id CHAR(8) NOT NULL,
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    cat_id INTEGER, -- Référence à crawl_categories.id
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
    in_crawl BOOLEAN DEFAULT TRUE,
    in_sitemap BOOLEAN DEFAULT FALSE,
    claimed_at TIMESTAMP, -- frontier lease marker (ClaimUrlsToCrawl); NULL = unclaimed
    generation JSONB,
    PRIMARY KEY (crawl_id, id)
) PARTITION BY LIST (crawl_id);

-- Index au niveau de la table parente (propagés aux partitions).
CREATE INDEX idx_pages_in_sitemap ON pages (crawl_id, in_sitemap) WHERE in_sitemap = TRUE;
CREATE INDEX idx_pages_not_in_crawl ON pages (crawl_id, in_crawl) WHERE in_crawl = FALSE;
CREATE INDEX idx_pages_generation_gin ON pages USING GIN (generation);
-- Frontier queue index: keeps the uncrawled-URL lease (ClaimUrlsToCrawl) and the
-- end-of-depth count O(remaining frontier) instead of O(whole partition). The
-- partial predicate means crawled rows leave the index, so it shrinks as the crawl
-- advances — the fix for the freeze on multi-million-URL crawls.
CREATE INDEX idx_pages_frontier ON pages (crawl_id, depth, claimed_at, id)
    WHERE crawled = false AND external = false AND in_crawl = true;

-- Table links partitionnée par crawl_id
-- Pas de PRIMARY KEY volontairement : on stocke TOUS les <a> tels qu'ils
-- apparaissent dans le HTML (un même couple src/target peut exister plusieurs
-- fois avec des ancres / positions / xpath différents).
-- xpath    : XPath enrichi du <a> (tags + class/id des ancêtres) ; NULL si l'extraction a échoué
-- position : classification sémantique (Navigation, Header, Footer, Aside, Content)
CREATE TABLE links (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    src CHAR(8) NOT NULL,
    target CHAR(8) NOT NULL,
    anchor TEXT,
    external BOOLEAN DEFAULT FALSE,
    nofollow BOOLEAN DEFAULT FALSE,
    type VARCHAR(50),
    xpath TEXT,
    position VARCHAR(20) NOT NULL DEFAULT 'Content'
) PARTITION BY LIST (crawl_id);

-- Table html partitionnée par crawl_id
CREATE TABLE html (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    id CHAR(8) NOT NULL,
    html TEXT,
    PRIMARY KEY (crawl_id, id)
) PARTITION BY LIST (crawl_id);

-- Table page_schemas partitionnée par crawl_id (stats rapides sur les schémas)
CREATE TABLE page_schemas (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    page_id CHAR(8) NOT NULL,
    schema_type VARCHAR(100) NOT NULL,
    PRIMARY KEY (crawl_id, page_id, schema_type)
) PARTITION BY LIST (crawl_id);

-- Table duplicate_clusters partitionnée par crawl_id
CREATE TABLE duplicate_clusters (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    id SERIAL,
    similarity INTEGER NOT NULL DEFAULT 100, -- 100 = exact, <100 = near-duplicate
    page_count INTEGER NOT NULL DEFAULT 0,
    page_ids TEXT[] NOT NULL DEFAULT '{}',
    PRIMARY KEY (crawl_id, id)
) PARTITION BY LIST (crawl_id);

-- Table redirect_chains partitionnée par crawl_id (chaînes de redirection pré-calculées)
CREATE TABLE redirect_chains (
    crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
    id SERIAL,
    source_id CHAR(8) NOT NULL,
    source_url TEXT,
    final_id CHAR(8),
    final_url TEXT,
    final_code INTEGER,
    final_compliant BOOLEAN DEFAULT FALSE,
    hops INTEGER DEFAULT 0,
    is_loop BOOLEAN DEFAULT FALSE,
    chain_ids TEXT[] NOT NULL DEFAULT '{}',
    PRIMARY KEY (crawl_id, id)
) PARTITION BY LIST (crawl_id);

-- ============================================
-- FONCTION POUR CRÉER LES PARTITIONS
-- ============================================

CREATE OR REPLACE FUNCTION create_crawl_partitions(p_crawl_id INTEGER)
RETURNS VOID AS $$
BEGIN
    -- Advisory lock pour sérialiser la création des partitions
    -- Évite les erreurs 55P03 (lock_not_available) quand plusieurs crawls démarrent simultanément
    PERFORM pg_advisory_lock(12345);

    BEGIN
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
        EXECUTE format('CREATE INDEX IF NOT EXISTS idx_page_schemas_%s_schema_type ON page_schemas_%s(schema_type)', p_crawl_id, p_crawl_id);
        EXECUTE format('CREATE INDEX IF NOT EXISTS idx_page_schemas_%s_page_id ON page_schemas_%s(page_id)', p_crawl_id, p_crawl_id);

        -- Partition pour duplicate_clusters
        EXECUTE format('CREATE TABLE IF NOT EXISTS duplicate_clusters_%s PARTITION OF duplicate_clusters FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);

        -- Partition pour redirect_chains
        EXECUTE format('CREATE TABLE IF NOT EXISTS redirect_chains_%s PARTITION OF redirect_chains FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);

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
    EXECUTE format('DROP TABLE IF EXISTS pages_%s', p_crawl_id);
    EXECUTE format('DROP TABLE IF EXISTS links_%s', p_crawl_id);
    EXECUTE format('DROP TABLE IF EXISTS html_%s', p_crawl_id);
    EXECUTE format('DROP TABLE IF EXISTS page_schemas_%s', p_crawl_id);
    EXECUTE format('DROP TABLE IF EXISTS duplicate_clusters_%s', p_crawl_id);
    EXECUTE format('DROP TABLE IF EXISTS redirect_chains_%s', p_crawl_id);
END;
$$ LANGUAGE plpgsql;

-- ============================================
-- DONNÉES PAR DÉFAUT
-- ============================================

-- Budget IA mensuel global par défaut (USD), repris de la migration ai-budget.
INSERT INTO app_settings (key, value) VALUES ('ai.budget.monthly_usd', '10.00')
    ON CONFLICT (key) DO NOTHING;

-- ============================================
-- SEED DE LA TABLE migrations
-- ============================================
-- Toutes les migrations ci-dessous sont DÉJÀ intégrées au schéma de ce baseline.
-- On les enregistre comme appliquées pour que migrate.php n'en rejoue aucune sur
-- une installation neuve. Garder cette liste synchronisée avec migrations/ lors
-- d'une future régénération du baseline.
INSERT INTO migrations (name) VALUES
    ('2025-12-02-19-00-test-migration-system'),
    ('2025-12-04-00-30-migration-projets'),
    ('2025-12-04-03-00-categories-par-utilisateur'),
    ('2025-12-04-19-30-canonical-split'),
    ('2025-12-05-12-25-recalcul-stats-crawls'),
    ('2025-12-05-21-30-simhash-is-html'),
    ('2025-12-06-11-40-duplicate-clusters'),
    ('2025-12-06-13-00-headings-columns'),
    ('2025-12-06-14-30-structured-data-schemas'),
    ('2025-12-06-21-45-word-count'),
    ('2025-12-07-12-30-worker-status-constraint'),
    ('2026-01-31-09-00-advisory-lock-partitions'),
    ('2026-01-31-10-30-remove-advisory-lock-from-function'),
    ('2026-02-28-00-00-add-project-categorization'),
    ('2026-02-28-00-01-migrate-existing-configs'),
    ('2026-03-01-00-00-add-crawl-type'),
    ('2026-03-01-12-00-redirect-chains'),
    ('2026-03-01-13-00-fix-redirect-chains-loops'),
    ('2026-03-28-14-00-project-level-crawl-categories'),
    ('2026-03-28-15-00-crawl-schedules'),
    ('2026-03-29-00-00-async-deletion'),
    ('2026-05-16-12-00-sitemap-columns'),
    ('2026-05-16-18-00-drop-is-in-sitemap'),
    ('2026-05-16-19-00-cleanup-orphan-categories'),
    ('2026-05-16-20-00-add-link-xpath-position'),
    ('2026-05-16-21-00-drop-links-primary-key'),
    ('2026-05-16-22-00-user-saved-queries'),
    ('2026-05-17-10-00-app-settings'),
    ('2026-05-17-10-01-ai-categorization-runs'),
    ('2026-05-17-11-00-ai-chat-runs'),
    ('2026-05-18-09-00-openrouter-settings'),
    ('2026-05-18-12-00-bulk-generation'),
    ('2026-05-21-10-00-ai-budget'),
    ('2026-05-21-14-00-api-keys'),
    ('2026-05-21-18-00-oauth'),
    ('2026-05-24-09-00-crawl-data-store'),
    ('2026-05-24-16-00-crawl-critical-errors'),
    ('2026-05-25-10-00-report-precompute-cache'),
    ('2026-05-25-12-00-report-cache-query-columns'),
    ('2026-05-27-12-00-crawl-health-score'),
    ('2026-05-28-09-00-jobs-table')
ON CONFLICT (name) DO NOTHING;
