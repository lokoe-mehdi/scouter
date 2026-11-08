<?php

use Tests\Support\GscFixtureIngestor;

it('retains page traffic and anonymized remainders when Google returns no segmented detail', function () {
    $ingestor = new GscFixtureIngestor([
        'country,device' => [['keys' => ['fra', 'MOBILE'], 'clicks' => 4, 'impressions' => 7, 'position' => 3]],
        'page' => [['keys' => ['https://example.test/'], 'clicks' => 4, 'impressions' => 7, 'position' => 3]],
        // Adding either country or device can make this entire day disappear.
        'page,country,device' => [], 'query,country,device' => [], 'query,page' => [],
    ]);
    $ingestor->ingestDay('2026-06-05');

    expect($ingestor->requests)->toContain('page', 'page,country,device')
        ->and($ingestor->writes['gsc_page_totals_daily'][0])->toMatchArray([
            'page' => 'https://example.test/', 'clicks' => 4, 'impressions' => 7, 'position' => 3.0,
        ])
        ->and($ingestor->writes['gsc_page_query_daily'][0])->toMatchArray([
            'page' => 'https://example.test/', 'query' => '(anonyme)', 'is_anon' => 1,
            'clicks' => 4, 'impressions' => 7,
        ])
        ->and($ingestor->writes)->not->toHaveKey('gsc_page_daily')
        ->and(array_key_last($ingestor->writes))->toBe('gsc_ingestion_days');
});

it('keeps URL totals separate from partial country and device rows', function () {
    $ingestor = new GscFixtureIngestor([
        'country,device' => [['keys' => ['fra', 'MOBILE'], 'clicks' => 10, 'impressions' => 100, 'position' => 4]],
        'page' => [
            ['keys' => ['https://example.test/'], 'clicks' => 8, 'impressions' => 80, 'position' => 4],
            ['keys' => ['https://example.test/#section'], 'clicks' => 2, 'impressions' => 20, 'position' => 9],
        ],
        'page,country,device' => [['keys' => ['https://example.test/', 'fra', 'MOBILE'], 'clicks' => 3, 'impressions' => 25, 'position' => 2]],
        'query,page' => [['keys' => ['keyword', 'https://example.test/'], 'clicks' => 3, 'impressions' => 25, 'position' => 2]],
    ]);
    $ingestor->ingestDay('2026-06-05');

    expect($ingestor->writes['gsc_page_totals_daily'])->toHaveCount(1)
        ->and($ingestor->writes['gsc_page_totals_daily'][0])->toMatchArray([
            'clicks' => 10, 'impressions' => 100, 'position' => 5.0,
        ])
        ->and($ingestor->writes['gsc_page_daily'][0])->toMatchArray([
            'country' => 'fra', 'device' => 'MOBILE', 'clicks' => 3, 'impressions' => 25,
        ])
        ->and($ingestor->writes['gsc_page_query_daily'][1])->toMatchArray([
            'is_anon' => 1, 'clicks' => 7, 'impressions' => 75,
        ]);
});

it('marks genuinely empty days complete without creating a fake URL', function () {
    $ingestor = new GscFixtureIngestor([]);
    $ingestor->ingestDay('2026-06-05');
    expect($ingestor->requests)->toBe(['country,device'])
        ->and(array_keys($ingestor->writes))->toBe(['gsc_site_daily', 'gsc_ingestion_days'])
        ->and($ingestor->writes['gsc_ingestion_days'][0])->toMatchArray([
            'project_id' => 1, 'search_type' => 'web', 'date' => '2026-06-05',
        ]);
});

it('leaves failed days eligible for repair instead of publishing incomplete totals', function () {
    $ingestor = new GscFixtureIngestor([
        'country,device' => [['keys' => ['fra', 'MOBILE'], 'clicks' => 4, 'impressions' => 7, 'position' => 3]],
        'page' => [['keys' => ['https://example.test/'], 'clicks' => 4, 'impressions' => 7, 'position' => 3]],
        'query,page' => new RuntimeException('Google request failed'),
    ]);
    expect(fn() => $ingestor->ingestDay('2026-06-05'))->toThrow(RuntimeException::class)
        ->and($ingestor->writes)->not->toHaveKey('gsc_ingestion_days')
        ->and($ingestor->writes)->not->toHaveKey('gsc_page_totals_daily');
});
