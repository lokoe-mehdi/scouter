<?php
/**
 * Migration: Search Analytics custom events (timeline annotations).
 *
 * Project-scoped markers (a date + title + optional description) rendered as
 * vertical lines on the Search Analytics chart — e.g. "site redesign",
 * "migration", "Google core update". Independent of the GSC connector lifecycle
 * (they survive a disconnect/reconnect), tied to the project.
 *
 * Idempotent.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    echo "   → Creating gsc_events... ";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gsc_events (
            id          SERIAL PRIMARY KEY,
            project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            event_date  DATE NOT NULL,
            title       TEXT NOT NULL,
            description TEXT,
            created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "OK\n";

    echo "   → Creating index on (project_id, event_date)... ";
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gsc_events_project_date ON gsc_events(project_id, event_date)");
    echo "OK\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
