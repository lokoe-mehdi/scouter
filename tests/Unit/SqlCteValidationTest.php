<?php

/**
 * Guards the CTE handling added to SqlExecutor / QueryController.
 *
 * `crawl_categories` is auto-scoped per project by an INJECTED CTE of that exact
 * name. A query that defines its OWN `crawl_categories` CTE collides ("WITH query
 * name specified more than once") AND bypasses scoping, so it must be detected
 * and rejected. Any OTHER CTE name is fine.
 *
 * This mirrors the CTE-name extraction regex used in the controllers (same
 * DB-free, replicated-logic approach as SqlExplorerSecurityTest).
 */

function extractCteNames(string $query): array
{
    $names = [];
    if (preg_match_all(
        '/(?:\bWITH\s+|,\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s+AS\s*\(/i',
        $query,
        $m
    )) {
        foreach ($m[1] as $n) {
            $names[] = strtolower($n);
        }
    }
    return $names;
}

/** True when the query defines a CTE named crawl_categories → must be rejected. */
function definesReservedCategoriesCte(string $query): bool
{
    return in_array('crawl_categories', extractCteNames($query), true);
}

it('detects a user-defined crawl_categories CTE (rejected)', function () {
    $q = "WITH crawl_categories AS (SELECT * FROM crawl_categories WHERE project_id = 32)\n"
       . "SELECT t.code, COUNT(*) FROM links l JOIN pages t ON l.target = t.id GROUP BY t.code";
    expect(definesReservedCategoriesCte($q))->toBeTrue();
});

it('allows CTEs with any other name', function () {
    $q = "WITH ranked AS (SELECT url FROM pages ORDER BY pri DESC) SELECT * FROM ranked";
    $names = extractCteNames($q);
    expect($names)->toContain('ranked');
    expect(definesReservedCategoriesCte($q))->toBeFalse();
});

it('detects crawl_categories as a SECOND CTE in a multi-CTE WITH', function () {
    $q = "WITH ranked AS (SELECT 1), crawl_categories AS (SELECT 1) SELECT 1";
    expect(definesReservedCategoriesCte($q))->toBeTrue();
});

it('does not flag a plain reference to crawl_categories (no CTE)', function () {
    // Referencing the table directly is fine — it gets auto-scoped, not rejected.
    $q = "SELECT c.cat FROM crawl_categories c JOIN pages p ON p.cat_id = c.id";
    expect(definesReservedCategoriesCte($q))->toBeFalse();
});
