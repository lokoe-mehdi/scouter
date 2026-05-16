<?php
/**
 * Migration: Add xpath + position columns to the links table.
 *
 * - xpath    TEXT NULL          → enriched XPath of the <a> tag in the source
 *                                 DOM (tags + class/id of ancestors). NULL when
 *                                 extraction failed (broken HTML, etc.).
 * - position VARCHAR(20) NOT NULL DEFAULT 'Content'
 *                                → semantic location of the link: Navigation,
 *                                  Header, Footer, Aside, Content.
 *
 * The links table is LIST-partitioned by crawl_id. ALTER TABLE on the parent
 * propagates to all existing partitions automatically.
 *
 * Idempotent: each column is added only if missing.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    $columnsToAdd = [
        'xpath'    => "ALTER TABLE links ADD COLUMN xpath TEXT",
        'position' => "ALTER TABLE links ADD COLUMN position VARCHAR(20) NOT NULL DEFAULT 'Content'",
    ];

    foreach ($columnsToAdd as $col => $alter) {
        $exists = (bool)$pdo->query("
            SELECT 1 FROM information_schema.columns
            WHERE table_name = 'links' AND column_name = " . $pdo->quote($col)
        )->fetchColumn();

        if ($exists) {
            echo "   → Column links.{$col} already exists, skipping\n";
            continue;
        }

        echo "   → Adding column links.{$col}... ";
        $pdo->exec($alter);
        echo "OK\n";
    }

    // Index on position for filter/aggregation queries. Created on each existing
    // partition (links_<crawl_id>) — new partitions get the index via the
    // partition-creation function in init.sql (to be updated separately).
    $partitions = $pdo->query("
        SELECT inhrelid::regclass::text AS part
        FROM pg_inherits
        WHERE inhparent = 'links'::regclass
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($partitions as $part) {
        // pg_inherits returns names like "links_42"
        if (!preg_match('/^links_(\d+)$/', $part, $m)) continue;
        $cid = $m[1];
        $idxName = "idx_{$part}_position";
        $exists = (bool)$pdo->query("
            SELECT 1 FROM pg_indexes
            WHERE indexname = " . $pdo->quote($idxName)
        )->fetchColumn();
        if ($exists) continue;
        echo "   → Creating index {$idxName}... ";
        $pdo->exec("CREATE INDEX {$idxName} ON {$part}(position)");
        echo "OK\n";
    }

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
