<?php

use App\Export\ExportScope;
use App\Export\ExportService;

/**
 * Query building of the async CSV exports.
 *
 * These pin the contract that broke filtered exports in production: the worker
 * re-runs the table's query from the posted params, so the SELECT may only
 * contain columns that exist, and the WHERE clause's placeholders must arrive
 * with their values. A miss on either side is not a wrong CSV — it is a database
 * error, i.e. an export that shows up as "Échec" in the download center.
 *
 * ExportService only needs its PDO for the exports table, never for building a
 * query, so these run without a database (private API reached by reflection).
 */

function exportService(): ExportService
{
    return (new ReflectionClass(ExportService::class))->newInstanceWithoutConstructor();
}

/** @param array<int,mixed> $args */
function exportCall(string $method, array $args)
{
    $m = new ReflectionMethod(ExportService::class, $method);
    $m->setAccessible(true);
    return $m->invokeArgs(exportService(), $args);
}

// ---------------------------------------------------------------- columns ---

it('maps every exportable url column to a real SQL expression', function () {
    $select = exportCall('buildUrlSelectList', [['url', 'out_of_scope', 'extract_price', 'generation_intent']]);

    expect($select)->toContain('c.url AS url');
    // Computed in url-table.php, NOT a column of `pages`.
    expect($select)->toContain('(c.external = false AND c.blocked = false AND c.crawled = false) AS out_of_scope');
    // PG-style JSONB access — ChPdo rewrites `->>` to ClickHouse map access.
    expect($select)->toContain("c.extracts->>'price' AS extract_price");
    expect($select)->toContain("c.generation->>'intent' AS generation_intent");
});

it('drops column keys the export cannot produce instead of emitting c.<key>', function () {
    // gsc_* / cmp_* come from joins the export does not have; emitting them blindly
    // made ClickHouse fail the whole query with "Unknown identifier".
    $select = exportCall('buildUrlSelectList', [['url', 'gsc_clicks', 'cmp_depth', 'nope']]);

    expect($select)->toBe('c.url AS url');
});

it('keeps the CSV header aligned with the SELECT', function () {
    $cols = ['url', 'gsc_clicks', 'depth'];

    expect(exportCall('urlExportColumns', [$cols]))->toBe(['url', 'depth']);
});

it('falls back to url when no requested column is exportable', function () {
    expect(exportCall('urlExportColumns', [['gsc_ctr']]))->toBe(['url']);
});

// ----------------------------------------------------------- report scope ---

it('replays the table WHERE clause with its placeholders bound', function () {
    // What the URL Explorer posts: a clause built with PDO params. Posting the
    // clause alone left `:url_0` unbound in the worker's query → SQL error.
    [$sql, $params] = exportCall('buildUrlsSelect', [42, [
        'columns'       => '["url"]',
        'report_where'  => 'WHERE 1=1 AND ((c.url LIKE :url_0 AND c.depth > :param_1))',
        'report_params' => '{":url_0":"%/blog/%",":param_1":2}',
    ]]);

    expect($sql)->toContain('c.crawl_id = 42');
    expect($sql)->toContain('c.url LIKE :url_0');
    expect($params)->toBe([':url_0' => '%/blog/%', ':param_1' => 2]);
});

it('does not narrow the row set the table showed', function () {
    // The scope IS the table's row set: re-adding crawled/in_crawl on top dropped
    // uncrawled and sitemap-only URLs the user could see on screen.
    [$sql] = exportCall('buildUrlsSelect', [42, [
        'report_where' => 'WHERE 1=1 AND ((c.external = false))',
    ]]);

    expect($sql)->not->toContain('c.crawled = true');
    expect($sql)->toContain('c.external = false');
});

it('keeps the historical default row set when no scope is posted', function () {
    [$sql] = exportCall('buildUrlsSelect', [42, ['columns' => '["url"]']]);

    expect($sql)->toContain('c.crawled = true');
    expect($sql)->toContain('c.in_crawl = TRUE');
});

it('rejects an unsigned scope carrying a sub-SELECT', function () {
    exportCall('buildUrlsSelect', [42, ['report_where' => 'WHERE c.url NOT IN (SELECT url FROM users)']]);
})->throws(RuntimeException::class, 'unsigned');

it('accepts a signed scope carrying a sub-SELECT (lost-urls / new-urls)', function () {
    // Their scope compares against another crawl's partition; the keyword filter
    // used to drop it silently, so those exports returned the whole crawl.
    $where = 'WHERE c.crawled = true AND c.url NOT IN (SELECT url FROM pages_7 WHERE crawled = true)';
    [$sql] = exportCall('buildUrlsSelect', [42, [
        'report_where' => $where,
        'report_sig'   => ExportScope::sign($where),
    ]]);

    expect($sql)->toContain('NOT IN (SELECT url FROM pages_7');
});

it('signs whitespace-insensitively but rejects a tampered clause', function () {
    $where = 'WHERE c.depth > 2';
    $sig = ExportScope::sign($where);

    expect(ExportScope::verify("WHERE   c.depth  >  2", $sig))->toBeTrue();
    expect(ExportScope::verify('WHERE c.depth > 2 OR 1=1', $sig))->toBeFalse();
    expect(ExportScope::verify($where, ''))->toBeFalse();
});

it('drops report params that are not plain placeholder/scalar pairs', function () {
    $decoded = exportCall('decodeReportParams', [[
        'url_0'      => 'ok',       // normalized to :url_0
        ':bad name'  => 'x',
        ':arr'       => ['a'],
    ]]);

    expect($decoded)->toBe([':url_0' => 'ok']);
});

it('only binds the params the query references', function () {
    // PDO rejects an execute() carrying a placeholder absent from the statement.
    $used = exportCall('usedParams', ['SELECT 1 WHERE a = :cat_1', [':cat_1' => 'x', ':cat_10' => 'y']]);

    expect($used)->toBe([':cat_1' => 'x']);
});

// --------------------------------------------------------------- filters ----

it('understands the list-of-groups filter payload the UI produces', function () {
    // filter-bar.js posts [{type:group,logic,items:[…]}]; only a single
    // {items:[…]} object was read, so the filters never reached the CSV.
    $params = [];
    $where = exportCall('buildFilterGroups', [
        '[{"type":"group","logic":"OR","items":[{"field":"external","operator":null,"value":"false"},{"field":"depth","operator":">","value":"3"}]}]',
        &$params,
    ]);

    expect($where)->toContain('c.external = false');
    expect($where)->toContain('c.depth > :p');
    expect(array_values($params))->toBe([3]);
});

it('emits booleans as SQL literals, never as bound strings', function () {
    // `c.external = 'false'` is a type error on ClickHouse (the column is UInt8).
    $params = [];
    $where = exportCall('buildFilterGroups', [[['field' => 'compliant', 'value' => 'true']], &$params]);

    expect($where)->toContain('c.compliant = true');
    expect($params)->toBe([]);
});

it('skips array-valued chips it cannot translate faithfully', function () {
    // An http-code group used to be stringified into `c.code = 'Array'`.
    $params = [];
    $where = exportCall('buildFilterGroups', [[['field' => 'code', 'value' => ['4xx']]], &$params]);

    expect($where)->toBe('');
});

// ----------------------------------------------------------------- links ----

it('builds link columns for both endpoints with a matching header', function () {
    $cols = ['url', 'anchor', 'extract_price'];
    $map = exportCall('linkSelectMap', [$cols]);

    expect(array_keys($map))->toBe(exportCall('linkExportColumns', [$cols]));
    expect($map['source_url'])->toBe('cs.url');
    expect($map['target_url'])->toBe('ct.url');
    expect($map['anchor'])->toBe('l.anchor');
    expect($map['source_extract_price'])->toContain("extracts->>'price'");
});
