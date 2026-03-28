<?php
/**
 * Migration: Crawl scheduling system
 *
 * Creates crawl_schedules table for recurring crawl automation.
 * Adds scheduled boolean to crawls table to distinguish scheduled from manual crawls.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    // =============================================
    // Step 1: Create crawl_schedules table
    // =============================================
    $stmt = $pdo->query("
        SELECT table_name FROM information_schema.tables
        WHERE table_name = 'crawl_schedules'
    ");

    if ($stmt->fetch()) {
        echo "   → Table crawl_schedules already exists, skipping creation\n";
    } else {
        echo "   → Creating crawl_schedules table... ";
        $pdo->exec("
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
            )
        ");
        $pdo->exec("CREATE INDEX idx_crawl_schedules_due ON crawl_schedules(enabled, next_run_at)");
        $pdo->exec("CREATE INDEX idx_crawl_schedules_project ON crawl_schedules(project_id)");
        echo "OK\n";
    }

    // =============================================
    // Step 2: Add scheduled column to crawls
    // =============================================
    $stmt = $pdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_name = 'crawls' AND column_name = 'scheduled'
    ");

    if ($stmt->fetch()) {
        echo "   → Column crawls.scheduled already exists\n";
    } else {
        echo "   → Adding scheduled column to crawls... ";
        $pdo->exec("ALTER TABLE crawls ADD COLUMN scheduled BOOLEAN DEFAULT FALSE");
        echo "OK\n";
    }

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
