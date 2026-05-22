<?php

use App\Http\Controllers\ApiV1Controller;
use App\Auth\Auth;

/**
 * Guards the config/schedule helpers added to the public API v1 controller:
 *  - buildCrawlConfig  (POST /crawls): merges caller input over the default
 *    template, enforces the validated fields, and toggles url_list per mode.
 *  - parsePgDays / schedulePayload (GET /projects/{id}/schedule): decode the
 *    PostgreSQL text array and project a row into the stable public shape.
 *  - computeNextRun (PUT schedule): next-run timestamp from the structured
 *    frequency — mirror of the worker scheduler.
 * All are private and exercised via reflection on a DB-less controller.
 */

function makeScheduleController(): ApiV1Controller
{
    return new ApiV1Controller(new Auth(null, null, null, skipDb: true));
}

function callPrivate(ApiV1Controller $c, string $name, array $args)
{
    $m = new ReflectionMethod($c, $name);
    $m->setAccessible(true);
    return $m->invoke($c, ...$args);
}

/* ---------- buildCrawlConfig ---------- */

it('fills the full default template from a minimal spider request', function () {
    $c = makeScheduleController();
    $cfg = callPrivate($c, 'buildCrawlConfig', [
        [], [], 'https://example.com', 'spider', ['example.com'], 3, [],
    ]);

    expect($cfg)->toHaveKeys(['general', 'advanced']);
    expect($cfg['general']['start'])->toBe('https://example.com');
    expect($cfg['general']['domains'])->toBe(['example.com']);
    expect($cfg['general']['crawl_type'])->toBe('spider');
    expect($cfg['general']['depthMax'])->toBe(3);
    expect($cfg['general']['crawl_speed'])->toBe('fast');
    expect($cfg['general'])->not->toHaveKey('url_list');

    // Advanced safe defaults the crawler relies on.
    expect($cfg['advanced']['respect_robots'])->toBeTrue();
    expect($cfg['advanced']['respect_nofollow'])->toBeTrue();
    expect($cfg['advanced']['store_html'])->toBeTrue();
    expect($cfg['advanced']['sitemap_urls'])->toBe([]);
});

it('includes url_list in list mode and drops it otherwise', function () {
    $c = makeScheduleController();
    $urls = ['https://example.com/a', 'https://example.com/b'];

    $list = callPrivate($c, 'buildCrawlConfig', [
        [], [], 'https://example.com', 'list', ['example.com'], 0, $urls,
    ]);
    expect($list['general']['url_list'])->toBe($urls);
    expect($list['general']['crawl_type'])->toBe('list');

    // A url_list passed in general for a spider crawl must be stripped.
    $spider = callPrivate($c, 'buildCrawlConfig', [
        ['url_list' => $urls], [], 'https://example.com', 'spider', ['example.com'], 2, [],
    ]);
    expect($spider['general'])->not->toHaveKey('url_list');
});

it('lets callers override soft fields but not the validated ones', function () {
    $c = makeScheduleController();
    $cfg = callPrivate($c, 'buildCrawlConfig', [
        // caller tries to override everything
        ['crawl_speed' => 'slow', 'start' => 'https://evil.test', 'domains' => ['evil.test'], 'crawl_type' => 'list', 'depthMax' => 99],
        ['store_html' => false, 'respect_nofollow' => false],
        'https://example.com', 'spider', ['example.com'], 4, [],
    ]);

    // Soft field honored.
    expect($cfg['general']['crawl_speed'])->toBe('slow');
    expect($cfg['advanced']['store_html'])->toBeFalse();
    expect($cfg['advanced']['respect_nofollow'])->toBeFalse();
    // Validated fields enforced regardless of caller input.
    expect($cfg['general']['start'])->toBe('https://example.com');
    expect($cfg['general']['domains'])->toBe(['example.com']);
    expect($cfg['general']['crawl_type'])->toBe('spider');
    expect($cfg['general']['depthMax'])->toBe(4);
});

/* ---------- parsePgDays ---------- */

it('decodes a PostgreSQL text array of weekdays', function () {
    $c = makeScheduleController();
    expect(callPrivate($c, 'parsePgDays', ['{mon,wed,fri}']))->toBe(['mon', 'wed', 'fri']);
    expect(callPrivate($c, 'parsePgDays', ['{ mon , wed }']))->toBe(['mon', 'wed']);
    expect(callPrivate($c, 'parsePgDays', ['{}']))->toBe([]);
    expect(callPrivate($c, 'parsePgDays', [null]))->toBe([]);
    expect(callPrivate($c, 'parsePgDays', ['']))->toBe([]);
});

/* ---------- schedulePayload ---------- */

it('projects a schedule row into the stable public shape', function () {
    $c = makeScheduleController();
    $out = callPrivate($c, 'schedulePayload', [[
        'project_id' => '32', 'domain' => 'example.com', 'enabled' => 't',
        'frequency' => 'weekly', 'hour' => '9', 'minute' => '30',
        'days_of_week' => '{mon,thu}', 'day_of_month' => '15',
        'crawl_type' => 'spider', 'depth_max' => '5',
        'next_run_at' => '2026-05-25 09:30:00', 'last_triggered_at' => null,
        'updated_at' => '2026-05-21 12:00:00',
    ]]);

    expect($out['project_id'])->toBe(32);
    expect($out['enabled'])->toBeTrue();
    expect($out['hour'])->toBe(9);
    expect($out['minute'])->toBe(30);
    expect($out['days_of_week'])->toBe(['mon', 'thu']);
    expect($out['day_of_month'])->toBe(15);
    expect($out['depth_max'])->toBe(5);
    expect($out['last_triggered_at'])->toBeNull();
});

it('coerces every truthy/falsy spelling of enabled', function () {
    $c = makeScheduleController();
    $row = ['project_id' => 1];
    foreach (['t', 'true', true, 1, '1'] as $v) {
        expect(callPrivate($c, 'schedulePayload', [$row + ['enabled' => $v]])['enabled'])->toBeTrue();
    }
    foreach (['f', 'false', false, 0, '0', null] as $v) {
        expect(callPrivate($c, 'schedulePayload', [$row + ['enabled' => $v]])['enabled'])->toBeFalse();
    }
});

/* ---------- computeNextRun ---------- */

it('computes the next daily run in the future at the chosen time', function () {
    $c = makeScheduleController();
    $next = callPrivate($c, 'computeNextRun', ['daily', [], 1, 9, 30]);
    expect($next)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/');
    $dt = new DateTime($next);
    expect($dt > new DateTime('now'))->toBeTrue();
    expect($dt->format('H:i'))->toBe('09:30');
});

it('clamps the monthly day-of-month into the 1..28 safe range', function () {
    $c = makeScheduleController();
    $high = new DateTime(callPrivate($c, 'computeNextRun', ['monthly', [], 31, 8, 0]));
    $low  = new DateTime(callPrivate($c, 'computeNextRun', ['monthly', [], 0, 8, 0]));
    expect((int)$high->format('d'))->toBe(28);
    expect((int)$low->format('d'))->toBe(1);
});

it('picks a requested weekday for a weekly schedule, always in the future', function () {
    $c = makeScheduleController();
    $next = new DateTime(callPrivate($c, 'computeNextRun', ['weekly', ['mon', 'thu'], 1, 7, 0]));
    expect($next > new DateTime('now'))->toBeTrue();
    expect(in_array($next->format('D'), ['Mon', 'Thu'], true))->toBeTrue();
    expect($next->format('H:i'))->toBe('07:00');
});

it('falls back to +1 day for an unknown frequency', function () {
    $c = makeScheduleController();
    $next = new DateTime(callPrivate($c, 'computeNextRun', ['hourly', [], 1, 0, 0]));
    expect($next > new DateTime('now'))->toBeTrue();
});

/* ---------- crawlPayload: scheduled flag ---------- */

it('coerces the scheduled flag (bool, null when absent)', function () {
    $c = makeScheduleController();
    $m = new ReflectionMethod($c, 'crawlPayload');
    $m->setAccessible(true);

    expect($m->invoke($c, (object)['id' => 1, 'scheduled' => 't'])['scheduled'])->toBeTrue();
    expect($m->invoke($c, (object)['id' => 1, 'scheduled' => false])['scheduled'])->toBeFalse();
    expect($m->invoke($c, (object)['id' => 1])['scheduled'])->toBeNull();
});
