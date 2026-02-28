<?php
/**
 * Migration: Migrate existing categorization configs to project level
 *
 * Strategy: Copy the most recent crawl's config to each project.
 * This ensures continuity when switching to project-level categorization.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    echo "   → Migrating existing categorization configs to projects... ";

    // For each project, copy the config from its most recent crawl that has a config
    $pdo->exec("
        WITH latest_configs AS (
            SELECT DISTINCT ON (c.project_id)
                c.project_id,
                cc.config
            FROM crawls c
            INNER JOIN categorization_config cc ON cc.crawl_id = c.id
            WHERE c.project_id IS NOT NULL
              AND cc.config IS NOT NULL
            ORDER BY c.project_id, c.started_at DESC
        )
        UPDATE projects p
        SET categorization_config = lc.config
        FROM latest_configs lc
        WHERE p.id = lc.project_id
    ");

    // Get migration count
    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM projects
        WHERE categorization_config IS NOT NULL
    ");
    $result = $stmt->fetch(PDO::FETCH_OBJ);
    $migratedCount = $result->count;

    echo "OK ($migratedCount projects)\n";

    return true;

} catch (Exception $e) {
    echo "\n   ✗ Erreur: " . $e->getMessage() . "\n";
    return false;
}
