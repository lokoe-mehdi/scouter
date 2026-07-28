<?php
/**
 * Migration: make the GSC connector self-healing.
 *
 * Adds the bookkeeping the reconciler (app/bin/gsc-reconciler.php) needs to
 * decide, on its own, whether a connector is progressing, dead, or waiting on a
 * backoff — without depending on the `jobs` table telling the truth:
 *
 *   - heartbeat_at          : bumped by the running job after every ingested day.
 *                             A 'running' job whose connector heartbeat is stale
 *                             is a dead process → reaped and re-enqueued.
 *   - last_attempt_at       : set at every attempt (success OR failure), unlike
 *                             last_sync_at which only moved on success — so a
 *                             silently failing connector is now visible.
 *   - consecutive_failures  : drives the exponential backoff.
 *   - next_retry_at         : "don't touch this connector before …".
 *   - backfill_days_done /
 *     backfill_days_total   : progress of the initial backfill, for the UI
 *                             (before this, "BACKFILLING" gave no way to tell a
 *                             live job from a dead one).
 *
 * Idempotent.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    $columns = [
        'heartbeat_at'        => 'TIMESTAMP',
        'last_attempt_at'     => 'TIMESTAMP',
        'consecutive_failures' => 'INTEGER NOT NULL DEFAULT 0',
        'next_retry_at'       => 'TIMESTAMP',
        'backfill_days_done'  => 'INTEGER NOT NULL DEFAULT 0',
        'backfill_days_total' => 'INTEGER NOT NULL DEFAULT 0',
    ];

    foreach ($columns as $name => $type) {
        echo "   → gsc_connectors.{$name}... ";
        $pdo->exec("ALTER TABLE gsc_connectors ADD COLUMN IF NOT EXISTS {$name} {$type}");
        echo "OK\n";
    }

    // The reconciler scans every connector on each tick; keep it index-backed.
    echo "   → Index on next_retry_at... ";
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gsc_connectors_next_retry ON gsc_connectors(next_retry_at)");
    echo "OK\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
