-- ============================================================================
-- Scouter — ClickHouse schema for crawl data (migration from PostgreSQL).
--
-- One database, one table per data type, PARTITIONED BY crawl_id so that
-- `ALTER TABLE ... DROP PARTITION <crawl_id>` deletes a crawl instantly
-- (replaces PostgreSQL's drop_crawl_partitions / per-crawl partition tables).
--
-- pages / links / html / page_schemas      : append-only (written during crawl).
-- page_metrics / duplicate_clusters /
-- redirect_chains                           : derived, rewritten by the
--                                             post-processing via DROP PARTITION
--                                             + INSERT (idempotent on resume).
-- page_generation                           : Bulk-AI output (ReplacingMergeTree).
--
-- No cat_id anywhere: categorization is computed live at query time from the
-- project's YAML rules (see app/Analysis/CategorizationService).
--
-- Run automatically by the clickhouse-server image (mounted into
-- /docker-entrypoint-initdb.d/). The `scouter` database/user are created by the
-- image from CLICKHOUSE_DB / CLICKHOUSE_USER / CLICKHOUSE_PASSWORD env vars.
--
-- NOTE: keep this file free of the semicolon character inside comments and
-- literals — the CI loader (.github/workflows/tests.yml) splits the file on
-- semicolons to POST each DDL over HTTP.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS scouter;

-- ---------------------------------------------------------------------------
-- pages — one row per crawled+parsed page (observed signals only — derived
-- fields live in page_metrics). Mirrors the observed columns of PG `pages`
-- minus cat_id / inlinks / pri / *_status / in_sitemap (derived).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scouter.pages
(
    crawl_id          Int32,
    id                FixedString(8),
    date              DateTime DEFAULT now(),
    domain            String,
    url               String,
    depth             Int32,
    code              Int32,
    response_time     Float64,
    outlinks          Int32,
    content_type      String,
    redirect_to       String,
    crawled           UInt8,
    compliant         UInt8,
    noindex           UInt8,
    nofollow          UInt8,
    canonical         UInt8,
    canonical_value   String,
    external          UInt8,
    blocked           UInt8,
    title             String,
    h1                String,
    metadesc          String,
    extracts          Map(String, String),
    simhash           Nullable(Int64),
    is_html           UInt8,
    h1_multiple       UInt8,
    headings_missing  UInt8,
    schemas           Array(String),
    word_count        Int32
)
-- ReplacingMergeTree(date): CH is append-only and a page may be written more
-- than once during a crawl (sitemap re-fetch, retries). Keep the latest by
-- `date`. Reads still dedup with LIMIT 1 BY id (merges are async).
ENGINE = ReplacingMergeTree(date)
PARTITION BY crawl_id
ORDER BY (crawl_id, id);

-- ---------------------------------------------------------------------------
-- links — every <a>/redirect/canonical edge as it appears (no dedup).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scouter.links
(
    crawl_id  Int32,
    src       FixedString(8),
    target    FixedString(8),
    anchor    String,
    external  UInt8,
    nofollow  UInt8,
    type      String,
    xpath     Nullable(String),
    position  String DEFAULT 'Content'
)
ENGINE = MergeTree
PARTITION BY crawl_id
ORDER BY (crawl_id, src);

-- Secondary sort order on `target` for incoming-edge analytics (inlinks count,
-- incoming PageRank, "who links to page X"): the base table is ordered by `src`,
-- so target-keyed reads otherwise scan the whole partition. NARROW on purpose —
-- only the small columns (no `anchor`/`xpath`) so the projection stays a fraction
-- of the base size. Idempotent. NB: ADD PROJECTION only affects parts written
-- AFTER it (new crawls), so it costs nothing on existing data until explicitly
-- materialized (opt-in: CLICKHOUSE_MATERIALIZE_PROJECTIONS, which doubles those
-- columns' storage for old crawls).
ALTER TABLE scouter.links ADD PROJECTION IF NOT EXISTS proj_by_target
(
    SELECT crawl_id, src, target, nofollow, external, type, position
    ORDER BY (crawl_id, target)
);

-- ---------------------------------------------------------------------------
-- html — raw page HTML (ZSTD-compressed, replaces PG base64+gzdeflate).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scouter.html
(
    crawl_id  Int32,
    id        FixedString(8),
    html      String CODEC(ZSTD(3))
)
ENGINE = ReplacingMergeTree
PARTITION BY crawl_id
ORDER BY (crawl_id, id);

-- ---------------------------------------------------------------------------
-- page_schemas — structured-data types found per page.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scouter.page_schemas
(
    crawl_id     Int32,
    page_id      FixedString(8),
    schema_type  String
)
-- ORDER BY includes schema_type so ReplacingMergeTree dedups exact (page,type)
-- rows without collapsing the several schema types a page can have.
ENGINE = ReplacingMergeTree
PARTITION BY crawl_id
ORDER BY (crawl_id, page_id, schema_type);

-- ---------------------------------------------------------------------------
-- page_metrics — DERIVED (post-processing). Rewritten per crawl via
-- DROP PARTITION + INSERT. Joined to pages on (crawl_id, id) at read time.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scouter.page_metrics
(
    crawl_id         Int32,
    id               FixedString(8),
    inlinks          Int32 DEFAULT 0,
    pri              Float64 DEFAULT 0,
    title_status     String DEFAULT '',
    h1_status        String DEFAULT '',
    metadesc_status  String DEFAULT '',
    in_sitemap       UInt8 DEFAULT 0
)
ENGINE = MergeTree
PARTITION BY crawl_id
ORDER BY (crawl_id, id);

-- ---------------------------------------------------------------------------
-- duplicate_clusters — DERIVED. cluster_id generated by the post-processor.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scouter.duplicate_clusters
(
    crawl_id    Int32,
    cluster_id  Int32,
    similarity  Int32 DEFAULT 100,
    page_count  Int32 DEFAULT 0,
    page_ids    Array(String)
)
ENGINE = MergeTree
PARTITION BY crawl_id
ORDER BY (crawl_id, cluster_id);

-- ---------------------------------------------------------------------------
-- redirect_chains — DERIVED.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scouter.redirect_chains
(
    crawl_id         Int32,
    chain_id         Int32,
    source_id        FixedString(8),
    source_url       String,
    final_id         String DEFAULT '',
    final_url        String DEFAULT '',
    final_code       Int32 DEFAULT 0,
    final_compliant  UInt8 DEFAULT 0,
    hops             Int32 DEFAULT 0,
    is_loop          UInt8 DEFAULT 0,
    chain_ids        Array(String)
)
ENGINE = MergeTree
PARTITION BY crawl_id
ORDER BY (crawl_id, chain_id);

-- ---------------------------------------------------------------------------
-- page_generation — Bulk-AI generated content (Phase 5). Latest write wins.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scouter.page_generation
(
    crawl_id    Int32,
    id          FixedString(8),
    generation  Map(String, String),
    version     UInt64 DEFAULT toUnixTimestamp(now())
)
ENGINE = ReplacingMergeTree(version)
PARTITION BY crawl_id
ORDER BY (crawl_id, id);
