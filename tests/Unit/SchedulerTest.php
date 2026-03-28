<?php

/**
 * Tests for the crawl scheduler — computeNextRun logic
 *
 * We test the pure scheduling logic without DB by re-implementing
 * the computeNextRun function here (same logic as app/bin/scheduler.php).
 */

if (!function_exists('computeNextRun')) {
    function computeNextRun(object $schedule): string
    {
        $now = new DateTime('now');
        $freq = $schedule->frequency;

        if ($freq === 'minute') {
            $next = clone $now;
            $next->modify('+1 minute');
            return $next->format('Y-m-d H:i:00');
        }

        $hour = (int)$schedule->hour;
        $minute = (int)$schedule->minute;

        if ($freq === 'daily') {
            $next = clone $now;
            $next->setTime($hour, $minute, 0);
            if ($next <= $now) $next->modify('+1 day');
            return $next->format('Y-m-d H:i:00');
        }

        if ($freq === 'weekly') {
            $dayMap = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
                       'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
            $daysRaw = trim($schedule->days_of_week ?? '{mon}', '{}');
            $days = array_map('trim', explode(',', $daysRaw));
            $candidates = [];
            foreach ($days as $day) {
                $dayName = $dayMap[$day] ?? null;
                if (!$dayName) continue;
                $candidate = new DateTime("this week {$dayName}");
                $candidate->setTime($hour, $minute, 0);
                if ($candidate <= $now) {
                    $candidate = new DateTime("next {$dayName}");
                    $candidate->setTime($hour, $minute, 0);
                }
                $candidates[] = $candidate;
            }
            if (empty($candidates)) {
                $next = new DateTime('next Monday');
                $next->setTime($hour, $minute, 0);
                return $next->format('Y-m-d H:i:00');
            }
            usort($candidates, fn($a, $b) => $a <=> $b);
            return $candidates[0]->format('Y-m-d H:i:00');
        }

        if ($freq === 'monthly') {
            $dayOfMonth = max(1, min(28, (int)$schedule->day_of_month));
            $next = clone $now;
            $next->setDate((int)$next->format('Y'), (int)$next->format('m'), $dayOfMonth);
            $next->setTime($hour, $minute, 0);
            if ($next <= $now) {
                $next->modify('+1 month');
                $next->setDate((int)$next->format('Y'), (int)$next->format('m'), $dayOfMonth);
            }
            return $next->format('Y-m-d H:i:00');
        }

        $next = clone $now;
        $next->modify('+1 day');
        return $next->format('Y-m-d H:i:00');
    }
}

describe('computeNextRun — Minute frequency', function () {

    it('returns a time in the future', function () {
        $schedule = (object)[
            'frequency' => 'minute',
            'hour' => 0, 'minute' => 0,
            'days_of_week' => '{mon}', 'day_of_month' => 1,
        ];

        $result = computeNextRun($schedule);
        $next = new DateTime($result);
        $now = new DateTime('now');

        expect($next > $now)->toBeTrue();
        // Should be within 2 minutes
        $diff = $next->getTimestamp() - $now->getTimestamp();
        expect($diff)->toBeLessThanOrEqual(120);
    });
});

describe('computeNextRun — Daily frequency', function () {

    it('returns today if time has not passed', function () {
        $schedule = (object)[
            'frequency' => 'daily',
            'hour' => 23, 'minute' => 59,
            'days_of_week' => '{mon}', 'day_of_month' => 1,
        ];

        $result = computeNextRun($schedule);
        $next = new DateTime($result);
        $today = new DateTime('today');

        expect($next->format('Y-m-d'))->toBe($today->format('Y-m-d'));
        expect($next->format('H:i'))->toBe('23:59');
    });

    it('returns tomorrow if time already passed', function () {
        $schedule = (object)[
            'frequency' => 'daily',
            'hour' => 0, 'minute' => 0,
            'days_of_week' => '{mon}', 'day_of_month' => 1,
        ];

        $result = computeNextRun($schedule);
        $next = new DateTime($result);
        $tomorrow = new DateTime('tomorrow');

        expect($next->format('Y-m-d'))->toBe($tomorrow->format('Y-m-d'));
        expect($next->format('H:i'))->toBe('00:00');
    });
});

describe('computeNextRun — Weekly frequency', function () {

    it('returns a date matching one of the specified days', function () {
        $schedule = (object)[
            'frequency' => 'weekly',
            'hour' => 8, 'minute' => 30,
            'days_of_week' => '{mon,wed,fri}', 'day_of_month' => 1,
        ];

        $result = computeNextRun($schedule);
        $next = new DateTime($result);
        $dayOfWeek = (int)$next->format('N'); // 1=Mon, 3=Wed, 5=Fri

        expect([1, 3, 5])->toContain($dayOfWeek);
        expect($next->format('H:i'))->toBe('08:30');
    });

    it('returns a future date', function () {
        $schedule = (object)[
            'frequency' => 'weekly',
            'hour' => 10, 'minute' => 0,
            'days_of_week' => '{tue,thu}', 'day_of_month' => 1,
        ];

        $result = computeNextRun($schedule);
        $next = new DateTime($result);
        $now = new DateTime('now');

        expect($next > $now)->toBeTrue();
    });

    it('handles single day', function () {
        $schedule = (object)[
            'frequency' => 'weekly',
            'hour' => 14, 'minute' => 0,
            'days_of_week' => '{sun}', 'day_of_month' => 1,
        ];

        $result = computeNextRun($schedule);
        $next = new DateTime($result);

        expect((int)$next->format('N'))->toBe(7); // Sunday
        expect($next->format('H:i'))->toBe('14:00');
    });
});

describe('computeNextRun — Monthly frequency', function () {

    it('sets the correct day of month', function () {
        $schedule = (object)[
            'frequency' => 'monthly',
            'hour' => 9, 'minute' => 15,
            'days_of_week' => '{mon}', 'day_of_month' => 15,
        ];

        $result = computeNextRun($schedule);
        $next = new DateTime($result);

        expect((int)$next->format('j'))->toBe(15);
        expect($next->format('H:i'))->toBe('09:15');
    });

    it('returns a future date', function () {
        $schedule = (object)[
            'frequency' => 'monthly',
            'hour' => 6, 'minute' => 0,
            'days_of_week' => '{mon}', 'day_of_month' => 1,
        ];

        $result = computeNextRun($schedule);
        $next = new DateTime($result);
        $now = new DateTime('now');

        expect($next > $now)->toBeTrue();
    });

    it('caps day at 28 to avoid month overflow', function () {
        $schedule = (object)[
            'frequency' => 'monthly',
            'hour' => 12, 'minute' => 0,
            'days_of_week' => '{mon}', 'day_of_month' => 28,
        ];

        $result = computeNextRun($schedule);
        $next = new DateTime($result);

        expect((int)$next->format('j'))->toBeLessThanOrEqual(28);
    });
});

describe('computeNextRun — Output format', function () {

    it('always returns seconds as 00', function () {
        $frequencies = ['minute', 'daily', 'weekly', 'monthly'];

        foreach ($frequencies as $freq) {
            $schedule = (object)[
                'frequency' => $freq,
                'hour' => 8, 'minute' => 0,
                'days_of_week' => '{mon}', 'day_of_month' => 1,
            ];

            $result = computeNextRun($schedule);
            expect($result)->toEndWith(':00');
        }
    });

    it('returns a valid datetime string', function () {
        $schedule = (object)[
            'frequency' => 'daily',
            'hour' => 14, 'minute' => 30,
            'days_of_week' => '{mon}', 'day_of_month' => 1,
        ];

        $result = computeNextRun($schedule);
        $parsed = DateTime::createFromFormat('Y-m-d H:i:s', $result);

        expect($parsed)->not->toBeFalse();
    });
});
