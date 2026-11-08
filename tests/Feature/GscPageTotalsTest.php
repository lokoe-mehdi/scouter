<?php

use App\Database\ClickHouseDatabase;
use App\Gsc\GscCoverage;
use App\Gsc\GscQueryService;
use App\Gsc\GscSchema;
use Tests\Support\GscFixtureIngestor;

beforeEach(function () {
    if (!getenv('GSC_TEST_CLICKHOUSE_URL')) {
        $this->markTestSkipped('Set GSC_TEST_CLICKHOUSE_URL to a disposable ClickHouse instance.');
    }
    putenv('CLICKHOUSE_URL=' . getenv('GSC_TEST_CLICKHOUSE_URL'));
    putenv('CLICKHOUSE_USER=default');
    putenv('CLICKHOUSE_PASSWORD=');
    ClickHouseDatabase::resetInstance();
    GscSchema::ensure();
});

afterEach(function () {
    if (getenv('GSC_TEST_CLICKHOUSE_URL')) {
        foreach (GscSchema::tableNames() as $table) {
            ClickHouseDatabase::getInstance()->exec("ALTER TABLE scouter.{$table} DROP PARTITION -900001");
        }
        ClickHouseDatabase::resetInstance();
    }
});

it('switches repaired days atomically from legacy detail to page-only totals without double counting', function () {
    $ch = ClickHouseDatabase::getInstance();
    $ch->exec("INSERT INTO scouter.gsc_page_daily (project_id,site,date,page,country,device,clicks,impressions,position) VALUES (-900001,'https://example.test/','2026-06-05','https://example.test/','fra','MOBILE',1,2,2)");
    $ch->exec("INSERT INTO scouter.gsc_site_daily (project_id,site,date,country,device,clicks,impressions,position) VALUES (-900001,'https://example.test/','2026-06-05','fra','MOBILE',4,7,3)");
    // A partial/failed repair must not displace the existing report data.
    $ch->exec("INSERT INTO scouter.gsc_page_totals_daily (project_id,site,date,page,clicks,impressions,position,version) VALUES (-900001,'https://example.test/','2026-06-05','https://example.test/',4,7,3,1)");
    $svc = new GscQueryService(-900001);
    $args = ['urls', '2026-06-05', '2026-06-05', []];
    expect($svc->table(...$args)['rows'][0])->toMatchArray(['clicks' => '1', 'impressions' => '2'])
        ->and((new GscCoverage(-900001))->daysPresent('2026-06-05', '2026-06-05'))->toBe([]);

    $ingestor = new GscFixtureIngestor([
        'country,device' => [['keys' => ['fra', 'MOBILE'], 'clicks' => 4, 'impressions' => 7, 'position' => 3]],
        'page' => [['keys' => ['https://example.test/'], 'clicks' => 4, 'impressions' => 7, 'position' => 3]],
    ], true, -900001);
    $ingestor->ingestDay('2026-06-05');

    expect($svc->table(...$args)['rows'][0])->toMatchArray(['clicks' => '4', 'impressions' => '7'])
        ->and($svc->table('both', '2026-06-05', '2026-06-05', [])['rows'][0])->toMatchArray(['clicks' => '4', 'impressions' => '7'])
        ->and($svc->kpis(...$args))->toMatchArray(['clicks' => 4, 'impressions' => 7])
        ->and((new GscCoverage(-900001))->daysPresent('2026-06-05', '2026-06-05'))->toBe(['2026-06-05' => true]);

    // Filtered reports still read the segmented table, never the full totals.
    $filter = [['items' => [['field' => 'country', 'operator' => 'in', 'value' => ['fra']]]]];
    expect($svc->table('urls', '2026-06-05', '2026-06-05', $filter)['rows'][0])->toMatchArray(['clicks' => '1', 'impressions' => '2']);
    $export = $svc->exportSelect(...$args);
    expect($ch->select($export['sql'], $export['params'])[0])->toMatchArray(['clics' => '4', 'impressions' => '7']);
});

it('counts zero-traffic days as repaired and keeps projects isolated', function () {
    (new GscFixtureIngestor([], true, -900001))->ingestDay('2026-06-06');
    expect((new GscCoverage(-900001))->daysPresent('2026-06-06', '2026-06-06'))->toBe(['2026-06-06' => true])
        ->and((new GscQueryService(-900001))->table('urls', '2026-06-06', '2026-06-06', [])['rows'])->toBe([])
        ->and((new GscQueryService(-900002))->table('urls', '2026-06-05', '2026-06-06', [])['rows'])->toBe([]);
});

it('does not retain an obsolete URL when a day is imported again', function () {
    $responses = [
        'country,device' => [['keys' => ['fra', 'MOBILE'], 'clicks' => 4, 'impressions' => 7, 'position' => 3]],
        'page' => [['keys' => ['https://example.test/old'], 'clicks' => 4, 'impressions' => 7, 'position' => 3]],
    ];
    (new GscFixtureIngestor($responses, true, -900001))->ingestDay('2026-06-05');
    $responses['page'][0]['keys'] = ['https://example.test/new'];
    (new GscFixtureIngestor($responses, true, -900001))->ingestDay('2026-06-05');
    $rows = (new GscQueryService(-900001))->table('urls', '2026-06-05', '2026-06-05', [])['rows'];
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['page' => 'https://example.test/new', 'clicks' => '4', 'impressions' => '7']);
    $joint = (new GscQueryService(-900001))->table('both', '2026-06-05', '2026-06-05', [])['rows'];
    expect($joint)->toHaveCount(1)
        ->and($joint[0])->toMatchArray(['page' => 'https://example.test/new', 'clicks' => '4', 'impressions' => '7']);
});

it('preserves archived days outside Google retention instead of scheduling an empty replacement', function () {
    ClickHouseDatabase::getInstance()->exec("INSERT INTO scouter.gsc_site_daily (project_id,site,date,clicks,impressions) VALUES (-900001,'https://example.test/','2020-01-01',4,7)");
    $plan = (new GscCoverage(-900001))->daysToSync('2026-11-06', 7, 1000);
    expect(min($plan['days']))->toBe('2025-07-08')
        ->and($plan['days'])->not->toContain('2020-01-01');
});
