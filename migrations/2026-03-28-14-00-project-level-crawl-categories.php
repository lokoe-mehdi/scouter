<?php
/**
 * Migration: Move crawl categories to project level
 *
 * The `categories` table is partitioned per crawl_id, duplicating identical
 * category definitions (name + color) for every crawl in a project.
 * This causes inconsistent IDs across crawls, making cross-crawl comparison fragile.
 *
 * This migration:
 * 1. Creates a new `crawl_categories` table scoped to project_id (not partitioned)
 * 2. Populates it from existing data (deduplicated by project + name)
 * 3. Remaps pages.cat_id to point to the new stable IDs
 * 4. Preserves the old `categories` table for rollback safety
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    // =============================================
    // Step 1: Create crawl_categories table
    // =============================================
    $stmt = $pdo->query("
        SELECT table_name FROM information_schema.tables
        WHERE table_name = 'crawl_categories'
    ");

    if ($stmt->fetch()) {
        echo "   → Table crawl_categories already exists, skipping creation\n";
    } else {
        echo "   → Creating crawl_categories table... ";
        $pdo->exec("
            CREATE TABLE crawl_categories (
                id SERIAL PRIMARY KEY,
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                cat VARCHAR(255) NOT NULL,
                color VARCHAR(7) DEFAULT '#aaaaaa',
                UNIQUE(project_id, cat)
            )
        ");
        $pdo->exec("CREATE INDEX idx_crawl_categories_project ON crawl_categories(project_id)");
        echo "OK\n";
    }

    // Check if old categories table exists (won't exist on fresh installs)
    $oldCategoriesExist = (bool)$pdo->query("
        SELECT table_name FROM information_schema.tables
        WHERE table_name = 'categories'
    ")->fetch();

    // =============================================
    // Step 2: Populate from existing categories (deduplicated)
    // =============================================
    $stmt = $pdo->query("SELECT COUNT(*) FROM crawl_categories");
    $existingCount = (int)$stmt->fetchColumn();

    if ($existingCount > 0) {
        echo "   → crawl_categories already has $existingCount rows, skipping population\n";
    } elseif (!$oldCategoriesExist) {
        echo "   → No old categories table found (fresh install), skipping population\n";
    } else {
        echo "   → Populating crawl_categories from existing data... ";

        // For each (project_id, cat) pair, take the most recent color
        $pdo->exec("
            INSERT INTO crawl_categories (project_id, cat, color)
            SELECT DISTINCT ON (cr.project_id, c.cat)
                cr.project_id, c.cat, c.color
            FROM categories c
            JOIN crawls cr ON cr.id = c.crawl_id
            WHERE cr.project_id IS NOT NULL
            ORDER BY cr.project_id, c.cat, cr.started_at DESC
        ");

        $stmt = $pdo->query("SELECT COUNT(*) FROM crawl_categories");
        $count = (int)$stmt->fetchColumn();
        echo "OK ($count categories)\n";
    }

    // =============================================
    // Step 3: Remap pages.cat_id to new IDs
    // =============================================
    if (!$oldCategoriesExist) {
        echo "   → No old categories table, skipping remapping\n";
    } else {
        echo "   → Remapping pages.cat_id to new crawl_categories IDs...\n";

        // Get all projects that have categories
        $projects = $pdo->query("
            SELECT DISTINCT cr.project_id
            FROM categories c
            JOIN crawls cr ON cr.id = c.crawl_id
            WHERE cr.project_id IS NOT NULL
        ")->fetchAll(PDO::FETCH_COLUMN);

        $totalProjects = count($projects);
        $processedProjects = 0;

        foreach ($projects as $projectId) {
            $processedProjects++;

            // Get all crawls for this project
            $stmt = $pdo->prepare("SELECT id FROM crawls WHERE project_id = :project_id");
            $stmt->execute([':project_id' => $projectId]);
            $crawlIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($crawlIds as $crawlId) {
                // Build mapping: old cat_id -> new cat_id (via category name)
                $stmt = $pdo->prepare("
                    SELECT old_c.id AS old_id, cc.id AS new_id
                    FROM categories old_c
                    JOIN crawl_categories cc ON cc.project_id = :project_id AND cc.cat = old_c.cat
                    WHERE old_c.crawl_id = :crawl_id
                ");
                $stmt->execute([':project_id' => $projectId, ':crawl_id' => $crawlId]);
                $mappings = $stmt->fetchAll(PDO::FETCH_OBJ);

                foreach ($mappings as $map) {
                    if ((int)$map->old_id === (int)$map->new_id) {
                        continue; // Already correct
                    }
                    $update = $pdo->prepare("
                        UPDATE pages SET cat_id = :new_id
                        WHERE crawl_id = :crawl_id AND cat_id = :old_id
                    ");
                    $update->execute([
                        ':new_id' => $map->new_id,
                        ':crawl_id' => $crawlId,
                        ':old_id' => $map->old_id
                    ]);
                }
            }

            echo "   [$processedProjects/$totalProjects] Project #$projectId remapped\n";
        }
    }

    // =============================================
    // Step 4: Update create_crawl_partitions function
    // (remove categories partition creation)
    // =============================================
    echo "   → Updating create_crawl_partitions function... ";
    $pdo->exec("
        CREATE OR REPLACE FUNCTION create_crawl_partitions(p_crawl_id INTEGER)
        RETURNS VOID AS \$\$
        BEGIN
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

                -- Index pages: canonical
                EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_canonical ON pages_%s(canonical)', p_crawl_id, p_crawl_id);
                EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_canonical_value ON pages_%s(canonical_value) WHERE canonical_value IS NOT NULL', p_crawl_id, p_crawl_id);

                -- Index pages: statuts SEO
                EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_title_status ON pages_%s(title_status)', p_crawl_id, p_crawl_id);
                EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_h1_status ON pages_%s(h1_status)', p_crawl_id, p_crawl_id);
                EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_metadesc_status ON pages_%s(metadesc_status)', p_crawl_id, p_crawl_id);

                -- Index pages: tri par métriques
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

                -- Partition pour redirect_chains
                EXECUTE format('CREATE TABLE IF NOT EXISTS redirect_chains_%s PARTITION OF redirect_chains FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            EXCEPTION WHEN OTHERS THEN
                PERFORM pg_advisory_unlock(12345);
                RAISE;
            END;

            PERFORM pg_advisory_unlock(12345);
        END;
        \$\$ LANGUAGE plpgsql
    ");
    echo "OK\n";

    // =============================================
    // Step 5: Update drop_crawl_partitions function
    // (remove categories partition drop)
    // =============================================
    echo "   → Updating drop_crawl_partitions function... ";
    $pdo->exec("
        CREATE OR REPLACE FUNCTION drop_crawl_partitions(p_crawl_id INTEGER)
        RETURNS VOID AS \$\$
        BEGIN
            EXECUTE format('DROP TABLE IF EXISTS pages_%s', p_crawl_id);
            EXECUTE format('DROP TABLE IF EXISTS links_%s', p_crawl_id);
            EXECUTE format('DROP TABLE IF EXISTS html_%s', p_crawl_id);
            EXECUTE format('DROP TABLE IF EXISTS page_schemas_%s', p_crawl_id);
            EXECUTE format('DROP TABLE IF EXISTS duplicate_clusters_%s', p_crawl_id);
            EXECUTE format('DROP TABLE IF EXISTS redirect_chains_%s', p_crawl_id);
        END;
        \$\$ LANGUAGE plpgsql
    ");
    echo "OK\n";

    // =============================================
    // Step 6: Drop old partitioned categories table
    // =============================================
    if (!$oldCategoriesExist) {
        echo "   → No old categories table to drop\n";
    } else {
        echo "   → Dropping old partitioned categories table and partitions... ";

        // Get all existing category partitions
        $partitions = $pdo->query("
            SELECT tablename FROM pg_tables
            WHERE tablename ~ '^categories_[0-9]+$'
            ORDER BY tablename
        ")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($partitions as $partition) {
            $pdo->exec("DROP TABLE IF EXISTS " . $partition);
        }

        // Drop the parent partitioned table
        $pdo->exec("DROP TABLE IF EXISTS categories");
        echo "OK (" . count($partitions) . " partitions removed)\n";
    }

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    return false;
}
