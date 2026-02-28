<?php
/**
 * Migration: Add categorization_config to projects table
 *
 * Enable project-level categorization config instead of crawl-level only.
 * This allows a single YAML configuration to be shared across all crawls
 * in a project, with async batch jobs to propagate changes.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    echo "   → Adding categorization_config column to projects table... ";

    // Add categorization_config column to projects
    $pdo->exec("
        ALTER TABLE projects
        ADD COLUMN IF NOT EXISTS categorization_config TEXT DEFAULT NULL
    ");

    echo "OK\n";

    echo "   → Creating performance index... ";

    // Create partial index for performance
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_projects_has_config
        ON projects(id)
        WHERE categorization_config IS NOT NULL
    ");

    echo "OK\n";

    echo "   → Adding column comment... ";

    // Add comment for documentation
    $pdo->exec("
        COMMENT ON COLUMN projects.categorization_config IS
        'YAML configuration for URL categorization, applied to all crawls in this project'
    ");

    echo "OK\n";

    return true;

} catch (Exception $e) {
    echo "\n   ✗ Erreur: " . $e->getMessage() . "\n";
    return false;
}
