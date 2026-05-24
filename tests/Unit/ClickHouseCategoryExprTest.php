<?php

use App\Analysis\CategoryExpr;

/**
 * Unit tests for the live ClickHouse categorization expression builder.
 *
 * CategoryExpr::build() turns parsed YAML rules into a ClickHouse CASE WHEN
 * (RE2 match(), inlined path replace) — the CH counterpart of the PG
 * CategorizationService::buildCaseWhenSql(). These tests pin the dialect
 * translation and the first-match-wins ordering without touching a database.
 */

function makeCategoryExpr(): CategoryExpr
{
    // The constructor stores the PDO only for forCrawl(); build() never uses it,
    // so a deliberately unusable handle is fine for these pure-logic tests.
    $ref = new ReflectionClass(CategoryExpr::class);
    return $ref->newInstanceWithoutConstructor();
}

it('returns empty-string literal when there are no rules', function () {
    expect(makeCategoryExpr()->build([]))->toBe("''");
});

it('builds a case-insensitive RE2 match on url + path for a simple rule', function () {
    $sql = makeCategoryExpr()->build([
        ['name' => 'product', 'domain' => 'shop.com', 'includes' => ['^/p/'], 'excludes' => []],
    ]);

    expect($sql)->toStartWith('CASE WHEN ');
    expect($sql)->toEndWith(" ELSE '' END");
    // domain match, case-insensitive, dot escaped by preg_quote:
    expect($sql)->toContain("match(url, '(?i)shop\\.com')");
    // include matched against the path (host stripped via replaceRegexpOne):
    expect($sql)->toContain("replaceRegexpOne(url, '^https?://[^/]+', '')");
    expect($sql)->toContain("'(?i)^/p/'");
    expect($sql)->toContain("THEN 'product'");
});

it('adds a negated match for excludes', function () {
    $sql = makeCategoryExpr()->build([
        ['name' => 'blog', 'domain' => 'x.com', 'includes' => ['^/blog'], 'excludes' => ['/tag/', '/author/']],
    ]);

    expect($sql)->toContain("AND NOT match(replaceRegexpOne(url, '^https?://[^/]+', ''), '(?i)/tag/|/author/')");
});

it('preserves rule order (first match wins) and ORs multiple includes', function () {
    $sql = makeCategoryExpr()->build([
        ['name' => 'a', 'domain' => 'x.com', 'includes' => ['^/a', '^/aa'], 'excludes' => []],
        ['name' => 'b', 'domain' => 'x.com', 'includes' => ['^/b'], 'excludes' => []],
    ]);

    expect($sql)->toContain("'(?i)^/a|^/aa'");
    expect(strpos($sql, "THEN 'a'"))->toBeLessThan(strpos($sql, "THEN 'b'"));
});

it('escapes single quotes in category names and patterns', function () {
    $sql = makeCategoryExpr()->build([
        ['name' => "O'Reilly", 'domain' => 'x.com', 'includes' => ["/o'r"], 'excludes' => []],
    ]);

    // doubled quotes inside the SQL string literals
    expect($sql)->toContain("THEN 'O''Reilly'");
    expect($sql)->toContain("/o''r");
});
