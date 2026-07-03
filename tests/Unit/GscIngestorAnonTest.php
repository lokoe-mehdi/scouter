<?php

use App\Gsc\GscIngestor;

/**
 * The anonymized "(anonyme)" bucket is the crux of the GSC connector: Google
 * drops low-volume query rows, so Σ(query rows) < the marginal total. We
 * materialise the gap so daily totals reconcile. Pure arithmetic — tested here.
 */

it('computes the site-level anonymized remainder', function () {
    $site = ['clicks' => 100, 'impressions' => 1000];
    $queryRows = [
        ['clicks' => 40, 'impressions' => 400],
        ['clicks' => 25, 'impressions' => 300],
    ];
    $anon = GscIngestor::computeQueryAnon($site, $queryRows);
    expect($anon)->toBe(['clicks' => 35, 'impressions' => 300]);
});

it('returns null when there is nothing to attribute (named rows cover the total)', function () {
    $site = ['clicks' => 50, 'impressions' => 500];
    $queryRows = [['clicks' => 50, 'impressions' => 500]];
    expect(GscIngestor::computeQueryAnon($site, $queryRows))->toBeNull();
});

it('clamps negative remainders to zero (rounding artifacts)', function () {
    $site = ['clicks' => 10, 'impressions' => 100];
    $queryRows = [['clicks' => 12, 'impressions' => 130]]; // over-counts slightly
    expect(GscIngestor::computeQueryAnon($site, $queryRows))->toBeNull();
});

it('computes per-URL anonymized remainders keyed by page', function () {
    // dimensions [page]
    $pageRows = [
        ['keys' => ['https://a/'], 'clicks' => 100, 'impressions' => 1000],
        ['keys' => ['https://b/'], 'clicks' => 50,  'impressions' => 500],
    ];
    // dimensions [query, page]  (keys[1] = page)
    $pageQueryRows = [
        ['keys' => ['kw1', 'https://a/'], 'clicks' => 60, 'impressions' => 600],
        ['keys' => ['kw2', 'https://a/'], 'clicks' => 30, 'impressions' => 300],
        ['keys' => ['kw3', 'https://b/'], 'clicks' => 50, 'impressions' => 500],
    ];
    $anon = GscIngestor::computePageAnon($pageRows, $pageQueryRows);

    // page a: 100-90 = 10 clicks, 1000-900 = 100 impressions
    expect($anon['https://a/'])->toBe(['clicks' => 10, 'impressions' => 100]);
    // page b: fully attributed → no anon row
    expect($anon)->not->toHaveKey('https://b/');
});

it('emits an anon row when a page has no named query rows at all', function () {
    $pageRows = [['keys' => ['https://x/'], 'clicks' => 7, 'impressions' => 70]];
    $anon = GscIngestor::computePageAnon($pageRows, []);
    expect($anon['https://x/'])->toBe(['clicks' => 7, 'impressions' => 70]);
});
