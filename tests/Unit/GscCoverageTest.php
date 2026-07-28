<?php

use App\Gsc\GscCoverage;

/**
 * The sync used to re-fetch a fixed window anchored on a Postgres watermark that
 * only moved when a whole run succeeded. One failed day froze the watermark, the
 * window drifted away from the hole, and the missing days were never fetched
 * again — three properties silently stopped updating for 8 days that way.
 *
 * The planner below is the replacement: it derives the work from the days we
 * actually hold, so "frozen for N days" and "a day died mid-run" are ordinary
 * inputs, not special cases. Pure arithmetic over dates → unit-testable.
 */

/** @param string[] $days */
function gscHeld(array $days): array
{
    return array_fill_keys($days, true);
}

it('always re-pulls the freshness window, even when those days are already held', function () {
    $held = ['2026-07-24', '2026-07-25', '2026-07-26'];
    $plan = GscCoverage::planDays('2026-07-24', '2026-07-26', gscHeld($held), 3, 40);

    expect($plan['days'])->toBe(['2026-07-26', '2026-07-25', '2026-07-24'])
        ->and($plan['remaining'])->toBe(0);
});

it('fills the head gap left by a connector frozen for days', function () {
    // Data stops on the 18th, today-2 is the 26th → the 8 missing days must come
    // back, not just the last few (the exact production symptom).
    $held = [];
    for ($d = new DateTime('2026-07-10'); $d <= new DateTime('2026-07-18'); $d->modify('+1 day')) {
        $held[] = $d->format('Y-m-d');
    }
    $plan = GscCoverage::planDays('2026-07-10', '2026-07-26', gscHeld($held), 7, 40);

    expect($plan['days'])->toContain('2026-07-19')
        ->and($plan['days'])->toContain('2026-07-26')
        ->and($plan['remaining'])->toBe(0);
    // Every day from the 19th to the 26th is planned.
    foreach (['19', '20', '21', '22', '23', '24', '25', '26'] as $d) {
        expect($plan['days'])->toContain("2026-07-{$d}");
    }
});

it('fills an interior hole left by a run that died mid-window', function () {
    $held = ['2026-07-20', '2026-07-21', /* 22 missing */ '2026-07-23', '2026-07-24'];
    $plan = GscCoverage::planDays('2026-07-20', '2026-07-24', gscHeld($held), 2, 40);

    expect($plan['days'])->toContain('2026-07-22');
});

it('never reaches below the oldest day held — that history belongs to the backfill', function () {
    $plan = GscCoverage::planDays('2026-07-20', '2026-07-22', gscHeld(['2026-07-20', '2026-07-21', '2026-07-22']), 2, 40);

    expect(min($plan['days']))->toBe('2026-07-21'); // freshness window only
});

it('plans only the freshness window when nothing is held yet', function () {
    $plan = GscCoverage::planDays(null, '2026-07-26', [], 3, 40);

    expect($plan['days'])->toBe(['2026-07-26', '2026-07-25', '2026-07-24']);
});

it('caps a huge catch-up and reports what is left for the next run', function () {
    // Frozen since March: the gap is ~100 days, far beyond one job's budget.
    $plan = GscCoverage::planDays('2026-03-01', '2026-07-26', gscHeld(['2026-03-01']), 7, 40);

    expect(count($plan['days']))->toBe(40)
        ->and($plan['remaining'])->toBeGreaterThan(0)
        // Newest first: the days users are looking at are restored first.
        ->and($plan['days'][0])->toBe('2026-07-26');
});

it('returns days newest-first without duplicates when the window overlaps a gap', function () {
    $plan = GscCoverage::planDays('2026-07-20', '2026-07-26', gscHeld(['2026-07-20']), 7, 40);

    expect($plan['days'])->toBe(array_values(array_unique($plan['days'])))
        ->and($plan['days'])->toBe(['2026-07-26', '2026-07-25', '2026-07-24', '2026-07-23', '2026-07-22', '2026-07-21', '2026-07-20']);
});
