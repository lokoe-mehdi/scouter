<?php

use App\Gsc\GscQueryService;

/**
 * GscQueryService::buildFilterSql translates the FilterBar JSON (regex on
 * query/url, combinable) into a ClickHouse WHERE fragment with bound params.
 * Groups are AND'd, chips within a group OR'd; chips whose column isn't on the
 * source table are dropped. Pure — no DB.
 */

it('normalizes modes and falls back to keywords', function () {
    expect(GscQueryService::normalizeMode('urls'))->toBe('urls');
    expect(GscQueryService::normalizeMode('both'))->toBe('both');
    expect(GscQueryService::normalizeMode('nonsense'))->toBe('keywords');
});

it('detects which dimension a filter set references', function () {
    $f = [['logic' => 'OR', 'items' => [['field' => 'query', 'operator' => 'contains', 'value' => 'x']]]];
    expect(GscQueryService::filtersReference($f, 'query'))->toBeTrue();
    expect(GscQueryService::filtersReference($f, 'url'))->toBeFalse();

    $g = [['logic' => 'OR', 'items' => [['field' => 'url', 'operator' => 'regex', 'value' => '/a/']]]];
    expect(GscQueryService::filtersReference($g, 'url'))->toBeTrue();
    expect(GscQueryService::filtersReference($g, 'query'))->toBeFalse();
});

it('builds a contains filter with a bound param', function () {
    $params = [];
    $sql = GscQueryService::buildFilterSql(
        [['type' => 'group', 'logic' => 'AND', 'items' => [['field' => 'query', 'operator' => 'contains', 'value' => 'shoes']]]],
        ['query'],
        $params
    );
    expect($sql)->toContain('positionCaseInsensitive(query, {f0:String}) > 0');
    expect($params)->toBe(['f0' => 'shoes']);
});

it('maps regex operators to match() and url→page column', function () {
    $params = [];
    $sql = GscQueryService::buildFilterSql(
        [['logic' => 'OR', 'items' => [
            ['field' => 'url', 'operator' => 'regex', 'value' => '/blog/'],
            ['field' => 'query', 'operator' => 'not_regex', 'value' => 'free'],
        ]]],
        ['page', 'query'],
        $params
    );
    expect($sql)->toContain('match(page, {f0:String})');
    expect($sql)->toContain('NOT match(query, {f1:String})');
    expect($params)->toBe(['f0' => '/blog/', 'f1' => 'free']);
});

it('maps an exact-match (drill) chip to col = param', function () {
    $params = [];
    $sql = GscQueryService::buildFilterSql(
        [['logic' => 'OR', 'items' => [['field' => 'query', 'operator' => '=', 'value' => 'nike']]]],
        ['page', 'query'],
        $params
    );
    expect($sql)->toContain('query = {f0:String}');
    expect($params)->toBe(['f0' => 'nike']);
});

it('drops a chip whose column is not on the source table', function () {
    $params = [];
    // urls mode without a query filter → only the page column exists.
    $sql = GscQueryService::buildFilterSql(
        [['logic' => 'AND', 'items' => [['field' => 'query', 'operator' => 'contains', 'value' => 'x']]]],
        ['page'],
        $params
    );
    expect($sql)->toBe('');
    expect($params)->toBe([]);
});

it('AND-joins groups and OR-joins chips within a group', function () {
    $params = [];
    $sql = GscQueryService::buildFilterSql(
        [
            ['logic' => 'OR', 'items' => [
                ['field' => 'query', 'operator' => 'contains', 'value' => 'a'],
                ['field' => 'query', 'operator' => 'contains', 'value' => 'b'],
            ]],
            ['logic' => 'AND', 'items' => [['field' => 'url', 'operator' => 'contains', 'value' => 'c']]],
        ],
        ['page', 'query'],
        $params
    );
    expect($sql)->toStartWith(' AND ');
    expect($sql)->toContain(' OR ');
    expect(substr_count($sql, 'AND ('))->toBe(2);
    expect($params)->toHaveCount(3);
});

it('maps a category filter to the project rules conditions on page', function () {
    $params = [];
    // categoryConds: id => pre-built SQL condition (as GscCategories produces).
    $conds = [1 => '(match(page, ...blog))', 2 => '(match(page, ...tool))'];
    $sql = GscQueryService::buildFilterSql(
        [['logic' => 'OR', 'items' => [['field' => 'category', 'operator' => 'in', 'value' => ['1', '2']]]]],
        ['page', 'query'],
        $params,
        $conds
    );
    expect($sql)->toContain('(match(page, ...blog)) OR (match(page, ...tool))');
    expect($params)->toBe([]); // conditions are inlined, no bound params
});

it('negates a not_in category filter and drops it when page column is absent', function () {
    $conds = [1 => '(match(page, ...blog))'];
    $p1 = [];
    $in = GscQueryService::buildFilterSql(
        [['logic' => 'OR', 'items' => [['field' => 'category', 'operator' => 'not_in', 'value' => ['1']]]]],
        ['page'], $p1, $conds
    );
    expect($in)->toContain('NOT ((match(page, ...blog)))');

    $p2 = [];
    $noPage = GscQueryService::buildFilterSql(
        [['logic' => 'OR', 'items' => [['field' => 'category', 'operator' => 'in', 'value' => ['1']]]]],
        ['query'], $p2, $conds  // keywords-only table has no page column
    );
    expect($noPage)->toBe('');
});

it('accepts the country and device modes', function () {
    expect(GscQueryService::normalizeMode('country'))->toBe('country');
    expect(GscQueryService::normalizeMode('device'))->toBe('device');
});

it('builds a country/device filter as an IN of bound params', function () {
    $params = [];
    $sql = GscQueryService::buildFilterSql(
        [['logic' => 'AND', 'items' => [['field' => 'country', 'operator' => 'in', 'value' => ['fra', 'usa']]]]],
        ['country', 'device'],
        $params
    );
    expect($sql)->toContain('country IN ({f0:String}, {f1:String})');
    expect($params)->toBe(['f0' => 'fra', 'f1' => 'usa']);

    $p2 = [];
    $dev = GscQueryService::buildFilterSql(
        [['logic' => 'AND', 'items' => [['field' => 'device', 'operator' => '=', 'value' => 'MOBILE']]]],
        ['page', 'country', 'device'],
        $p2
    );
    expect($dev)->toContain('device IN ({f0:String})');
    expect($p2)->toBe(['f0' => 'MOBILE']);
});

it('negates a not_in country filter and drops it when the column is absent', function () {
    $params = [];
    $sql = GscQueryService::buildFilterSql(
        [['logic' => 'OR', 'items' => [['field' => 'country', 'operator' => 'not_in', 'value' => ['fra']]]]],
        ['country', 'device'],
        $params
    );
    expect($sql)->toContain('NOT (country IN ({f0:String}))');

    // The joint page×query table has no country/device column → dropped.
    $p2 = [];
    $dropped = GscQueryService::buildFilterSql(
        [['logic' => 'OR', 'items' => [['field' => 'device', 'operator' => 'in', 'value' => ['MOBILE']]]]],
        ['page', 'query'],
        $p2
    );
    expect($dropped)->toBe('');
});

it('ignores empty-value chips', function () {
    $params = [];
    $sql = GscQueryService::buildFilterSql(
        [['logic' => 'AND', 'items' => [['field' => 'query', 'operator' => 'contains', 'value' => '']]]],
        ['query'],
        $params
    );
    expect($sql)->toBe('');
});
