<?php

use App\Gsc\GscIngestor;

/**
 * The anonymized "(anonyme)" bucket is the crux of the GSC connector: Google
 * drops low-volume query rows, so Σ(query rows) < the marginal total. We
 * materialise the gap so daily totals reconcile — now reconciled PER (country,
 * device) since GSC drops rows independently in each slice. Plus the URL
 * fragment normalisation (url and url#x collapse). All pure arithmetic.
 */

it('computes the query anonymized remainder for a single country/device slice', function () {
    $site = [['keys' => ['fra', 'MOBILE'], 'clicks' => 100, 'impressions' => 1000]];
    $queryRows = [
        ['keys' => ['kw1', 'fra', 'MOBILE'], 'clicks' => 40, 'impressions' => 400],
        ['keys' => ['kw2', 'fra', 'MOBILE'], 'clicks' => 25, 'impressions' => 300],
    ];
    $anon = GscIngestor::computeQueryAnonByCd($site, $queryRows);
    expect($anon)->toBe([['country' => 'fra', 'device' => 'MOBILE', 'clicks' => 35, 'impressions' => 300]]);
});

it('reconciles each country/device slice independently', function () {
    $site = [
        ['keys' => ['fra', 'MOBILE'],  'clicks' => 100, 'impressions' => 1000],
        ['keys' => ['usa', 'DESKTOP'], 'clicks' => 50,  'impressions' => 500],
    ];
    $queryRows = [
        ['keys' => ['kw1', 'fra', 'MOBILE'],  'clicks' => 60, 'impressions' => 600],
        ['keys' => ['kw1', 'usa', 'DESKTOP'], 'clicks' => 50, 'impressions' => 500], // usa fully attributed
    ];
    $anon = GscIngestor::computeQueryAnonByCd($site, $queryRows);
    // fra: 100-60=40 / 1000-600=400 ; usa: nothing left
    expect($anon)->toBe([['country' => 'fra', 'device' => 'MOBILE', 'clicks' => 40, 'impressions' => 400]]);
});

it('emits no query anon row when named rows cover the slice total', function () {
    $site = [['keys' => ['fra', 'DESKTOP'], 'clicks' => 50, 'impressions' => 500]];
    $queryRows = [['keys' => ['kw', 'fra', 'DESKTOP'], 'clicks' => 50, 'impressions' => 500]];
    expect(GscIngestor::computeQueryAnonByCd($site, $queryRows))->toBe([]);
});

it('clamps negative query remainders to zero (rounding artifacts)', function () {
    $site = [['keys' => ['fra', 'MOBILE'], 'clicks' => 10, 'impressions' => 100]];
    $queryRows = [['keys' => ['kw', 'fra', 'MOBILE'], 'clicks' => 12, 'impressions' => 130]];
    expect(GscIngestor::computeQueryAnonByCd($site, $queryRows))->toBe([]);
});

it('computes per-URL anonymized remainders, summing the page total over country/device', function () {
    // page dimensions [page, country, device] — same URL across two slices → total 100/1000
    $pageRows = [
        ['keys' => ['https://a/', 'fra', 'MOBILE'],  'clicks' => 60, 'impressions' => 600],
        ['keys' => ['https://a/', 'usa', 'DESKTOP'], 'clicks' => 40, 'impressions' => 400],
        ['keys' => ['https://b/', 'fra', 'MOBILE'],  'clicks' => 50, 'impressions' => 500],
    ];
    // page×query dimensions [query, page]  (keys[1] = page)
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

it('emits a page anon row when a page has no named query rows at all', function () {
    $pageRows = [['keys' => ['https://x/', 'fra', 'MOBILE'], 'clicks' => 7, 'impressions' => 70]];
    $anon = GscIngestor::computePageAnon($pageRows, []);
    expect($anon['https://x/'])->toBe(['clicks' => 7, 'impressions' => 70]);
});

// --- URL fragment normalisation ---------------------------------------------

it('strips the fragment from a URL', function () {
    expect(GscIngestor::stripFragment('https://a/p#avis-utilisateurs'))->toBe('https://a/p');
    expect(GscIngestor::stripFragment('https://a/p'))->toBe('https://a/p');
    expect(GscIngestor::stripFragment('https://a/p#'))->toBe('https://a/p');
});

it('merges fragment variants of a URL, summing metrics and weighting position by impressions', function () {
    $rows = [
        ['keys' => ['https://a/p'],       'clicks' => 10, 'impressions' => 100, 'position' => 5.0],
        ['keys' => ['https://a/p#avis'],  'clicks' => 5,  'impressions' => 50,  'position' => 11.0],
        ['keys' => ['https://a/q'],       'clicks' => 1,  'impressions' => 10,  'position' => 3.0],
    ];
    $merged = GscIngestor::mergeFragments($rows, 0);

    expect($merged)->toHaveCount(2);
    // https://a/p: 15 clicks, 150 impr, position = (5*100 + 11*50)/150 = 7.0
    expect($merged[0]['keys'])->toBe(['https://a/p']);
    expect($merged[0]['clicks'])->toBe(15);
    expect($merged[0]['impressions'])->toBe(150);
    expect($merged[0]['position'])->toBe(7.0);
    // https://a/q untouched
    expect($merged[1]['keys'])->toBe(['https://a/q']);
    expect($merged[1]['clicks'])->toBe(1);
});

it('keeps distinct country/device slices separate when merging fragments', function () {
    $rows = [
        ['keys' => ['https://a/p#x', 'fra', 'MOBILE'],  'clicks' => 3, 'impressions' => 30, 'position' => 4.0],
        ['keys' => ['https://a/p',   'fra', 'MOBILE'],  'clicks' => 2, 'impressions' => 20, 'position' => 4.0],
        ['keys' => ['https://a/p',   'usa', 'DESKTOP'], 'clicks' => 1, 'impressions' => 10, 'position' => 9.0],
    ];
    $merged = GscIngestor::mergeFragments($rows, 0);
    // fra/MOBILE collapses the two fragment rows; usa/DESKTOP stays separate
    expect($merged)->toHaveCount(2);
    expect($merged[0])->toMatchArray(['keys' => ['https://a/p', 'fra', 'MOBILE'], 'clicks' => 5, 'impressions' => 50]);
    expect($merged[1])->toMatchArray(['keys' => ['https://a/p', 'usa', 'DESKTOP'], 'clicks' => 1, 'impressions' => 10]);
});
