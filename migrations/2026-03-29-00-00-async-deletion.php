<?php
/**
 * Migration: Async deletion support
 *
 * Adds soft-delete capability for projects (deleted_at column)
 * and a 'deleting' status for crawls, enabling async background
 * cleanup of large datasets without blocking the UI.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    // =============================================
    // Step 1: Add deleted_at to projects
    // =============================================
    $stmt = $pdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_name = 'projects' AND column_name = 'deleted_at'
    ");

    if ($stmt->fetch()) {
        echo "   → Column projects.deleted_at already exists\n";
    } else {
        echo "   → Adding deleted_at column to projects... ";
        $pdo->exec("ALTER TABLE projects ADD COLUMN deleted_at TIMESTAMP DEFAULT NULL");
        $pdo->exec("CREATE INDEX idx_projects_deleted ON projects(id) WHERE deleted_at IS NOT NULL");
        echo "OK\n";
    }

    // =============================================
    // Step 2: Add 'deleting' to crawls status CHECK
    // =============================================
    echo "   → Updating crawls status constraint... ";

    // Check if 'deleting' is already in the constraint
    $stmt = $pdo->query("
        SELECT pg_get_constraintdef(oid) AS def
        FROM pg_constraint
        WHERE conname = 'crawls_status_check'
    ");
    $constraint = $stmt->fetch(PDO::FETCH_OBJ);

    if ($constraint && strpos($constraint->def, 'deleting') !== false) {
        echo "already includes 'deleting'\n";
    } else {
        $pdo->exec("ALTER TABLE crawls DROP CONSTRAINT IF EXISTS crawls_status_check");
        $pdo->exec("ALTER TABLE crawls ADD CONSTRAINT crawls_status_check
            CHECK (status IN ('pending', 'queued', 'running', 'stopping', 'stopped', 'finished', 'error', 'failed', 'deleting'))");
        echo "OK\n";
    }

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
