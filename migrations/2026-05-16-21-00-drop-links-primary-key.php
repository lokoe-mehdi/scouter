<?php
/**
 * Migration: Drop the PRIMARY KEY on the links table to keep ALL links.
 *
 * Previously the PK (crawl_id, src, target) dedupes any same-source/same-target
 * pair, which silently drops the 2nd, 3rd, ... `<a>` of a page when several
 * point to the same URL (with possibly different anchors / positions / xpath).
 * That hurt SEO analysis (lost anchors, lost positions) and biased the
 * PageRank denominator since outlinks counted dedupd targets rather than
 * actual `<a>` tags.
 *
 * Dropping the PK lets every parsed `<a>` be stored. We keep all existing
 * btree indexes (src, target, external, nofollow, type, position) for query
 * performance. The downside is that DELETE/UPDATE targeting a single row by
 * (src, target) is no longer unambiguous — not an issue in this codebase
 * since we never do that.
 *
 * Postgres allows LIST-partitioned tables to have no primary key. Dropping
 * the PK on the parent cascades to all partitions.
 *
 * Idempotent: only acts if the PK is still present.
 */

use App\Database\PostgresDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

try {
    $pkName = $pdo->query("
        SELECT conname FROM pg_constraint
        WHERE conrelid = 'links'::regclass AND contype = 'p'
    ")->fetchColumn();

    if (!$pkName) {
        echo "   → links has no primary key, nothing to drop\n";
        echo "   ✓ Migration completed successfully\n";
        return true;
    }

    // conname is always a safe alphanumeric identifier from Postgres internals;
    // we wrap it in double quotes for proper identifier quoting.
    $pkIdent = '"' . str_replace('"', '""', $pkName) . '"';
    echo "   → Dropping primary key {$pkName} on links (propagates to partitions)... ";
    $pdo->exec("ALTER TABLE links DROP CONSTRAINT {$pkIdent}");
    echo "OK\n";

    echo "   ✓ Migration completed successfully\n";
    return true;

} catch (Exception $e) {
    echo "\n   ✗ Error: " . $e->getMessage() . "\n";
    return false;
}
