<?php
/**
 * Migration: Bulk AI Generator
 *
 * Two changes :
 *
 * 1. `pages.generation` (JSONB) — stocke les résultats de générations IA
 *    arbitraires sous forme de clés/valeurs typées natives (number, string,
 *    boolean). Même pattern que `pages.extracts`. La colonne est partagée
 *    par toutes les partitions de `pages` (propagation native PG ≥ 11).
 *
 * 2. `bulk_generation_jobs` (TABLE) — audit + status des jobs de génération
 *    en masse. Ne stocke JAMAIS les résultats par URL : ceux-ci vivent
 *    directement dans `pages.generation`. Cette table sert au polling de
 *    progress depuis l'UI et au diagnostic des erreurs.
 *
 * Idempotent.
 *
 * @see docs/bulk-ai-generator.md
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    // --- 1) pages.generation column + GIN index -------------------------
    $hasCol = (bool)$pdo->query("
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'pages' AND column_name = 'generation'
    ")->fetchColumn();

    if (!$hasCol) {
        echo "   → ALTER TABLE pages ADD COLUMN generation JSONB... ";
        // PG ≥ 11 propage automatiquement aux partitions existantes.
        $pdo->exec("ALTER TABLE pages ADD COLUMN generation JSONB");
        echo "OK\n";
    } else {
        echo "   → pages.generation already exists, skipping ADD COLUMN\n";
    }

    // GIN index propagé aux partitions (PG ≥ 11). Pas critique pour les
    // perfs sur ce volume mais accélère les `WHERE generation ? 'key'` et
    // les détections de keys via jsonb_object_keys.
    $hasIdx = (bool)$pdo->query("
        SELECT 1 FROM pg_indexes
        WHERE tablename = 'pages' AND indexname = 'idx_pages_generation_gin'
    ")->fetchColumn();
    if (!$hasIdx) {
        echo "   → CREATE INDEX idx_pages_generation_gin... ";
        $pdo->exec("CREATE INDEX idx_pages_generation_gin ON pages USING GIN (generation)");
        echo "OK\n";
    } else {
        echo "   → idx_pages_generation_gin already exists, skipping\n";
    }

    // --- 2) bulk_generation_jobs table ----------------------------------
    $hasTable = (bool)$pdo->query("
        SELECT 1 FROM information_schema.tables
        WHERE table_name = 'bulk_generation_jobs'
    ")->fetchColumn();

    if (!$hasTable) {
        echo "   → Creating bulk_generation_jobs... ";
        $pdo->exec("
            CREATE TABLE bulk_generation_jobs (
                id              SERIAL PRIMARY KEY,
                user_id         INTEGER REFERENCES users(id) ON DELETE SET NULL,
                crawl_id        INTEGER NOT NULL,
                items           JSONB NOT NULL,
                prompt_template TEXT NOT NULL,
                context_fields  TEXT[] NOT NULL,
                page_ids        TEXT[] NOT NULL,
                model           VARCHAR(100) NOT NULL,
                batch_size      SMALLINT NOT NULL DEFAULT 10,
                url_count       INTEGER NOT NULL,
                processed_count INTEGER NOT NULL DEFAULT 0,
                failed_count    INTEGER NOT NULL DEFAULT 0,
                status          VARCHAR(20) NOT NULL DEFAULT 'queued',
                input_tokens    INTEGER NOT NULL DEFAULT 0,
                output_tokens   INTEGER NOT NULL DEFAULT 0,
                estimated_cost  NUMERIC(10,6),
                actual_cost     NUMERIC(10,6),
                error_message   TEXT,
                errors_sample   JSONB,
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                started_at      TIMESTAMP,
                finished_at     TIMESTAMP
            )
        ");
        echo "OK\n";

        echo "   → Creating indexes... ";
        $pdo->exec("CREATE INDEX idx_bgj_user_time  ON bulk_generation_jobs(user_id, created_at DESC)");
        $pdo->exec("CREATE INDEX idx_bgj_crawl_time ON bulk_generation_jobs(crawl_id, created_at DESC)");
        // Partial index : seuls les jobs actifs sont lus en hot path
        // (polling depuis l'UI + reprise après crash worker).
        $pdo->exec("
            CREATE INDEX idx_bgj_active ON bulk_generation_jobs(status, created_at)
            WHERE status IN ('queued', 'running')
        ");
        echo "OK\n";
    } else {
        echo "   → bulk_generation_jobs already exists, skipping\n";
    }

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
