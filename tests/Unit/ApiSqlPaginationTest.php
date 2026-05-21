<?php

use App\AI\SqlExecutor;
use App\Database\PostgresDatabase;

/**
 * Guards the paginated read-only mode used by the public API
 * (SqlExecutor::executePaginated). The security rejections are validated before
 * any DB access (deterministic, no crawl needed); the pagination math is checked
 * against a real crawl when one with data exists.
 */

it('rejects writes/forbidden SQL in the paginated path (inherits SQL Explorer rules)', function () {
    $x = new SqlExecutor();
    // These fail at validation BEFORE any crawl/DB lookup → crawl id is irrelevant.
    expect($x->executePaginated('DELETE FROM pages', 0, 10, 0)['ok'])->toBeFalse();
    expect($x->executePaginated('UPDATE pages SET code=200', 0, 10, 0)['ok'])->toBeFalse();
    expect($x->executePaginated('SELECT pg_sleep(5)', 0, 10, 0)['ok'])->toBeFalse();
    expect($x->executePaginated('SELECT * FROM users', 0, 10, 0)['ok'])->toBeFalse();
    // A bare non-SELECT.
    $r = $x->executePaginated('DROP TABLE pages', 0, 10, 0);
    expect($r['ok'])->toBeFalse();
    expect($r['error'])->toBeString();
});

it('paginates a real crawl: total + page size + count toggle', function () {
    $db = PostgresDatabase::getInstance()->getConnection();
    $cid = (int)($db->query("SELECT crawl_id FROM pages GROUP BY crawl_id ORDER BY crawl_id DESC LIMIT 1")->fetchColumn() ?: 0);
    if ($cid <= 0) {
        test()->markTestSkipped('No crawl with pages available in this environment.');
        return;
    }
    $x = new SqlExecutor();

    $p1 = $x->executePaginated('SELECT url FROM pages ORDER BY url', $cid, 2, 0, true);
    expect($p1['ok'])->toBeTrue();
    expect(count($p1['rows']))->toBeLessThanOrEqual(2);
    expect($p1['total'])->toBeInt();
    expect($p1['page_size'])->toBe(2);

    // count=false → no total computed.
    $p2 = $x->executePaginated('SELECT url FROM pages ORDER BY url', $cid, 2, 2, false);
    expect($p2['ok'])->toBeTrue();
    expect($p2['total'])->toBeNull();
    expect($p2['offset'])->toBe(2);
});

it('caps page_size at the hard limit', function () {
    $db = PostgresDatabase::getInstance()->getConnection();
    $cid = (int)($db->query("SELECT crawl_id FROM pages GROUP BY crawl_id ORDER BY crawl_id DESC LIMIT 1")->fetchColumn() ?: 0);
    if ($cid <= 0) {
        test()->markTestSkipped('No crawl with pages available.');
        return;
    }
    $x = new SqlExecutor();
    // Asking for a million page_size must be clamped (no error, just capped).
    $r = $x->executePaginated('SELECT url FROM pages', $cid, 1000000, 0, false);
    expect($r['ok'])->toBeTrue();
    expect($r['page_size'])->toBeLessThanOrEqual(10000);
});
