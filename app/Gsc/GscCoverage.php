<?php

namespace App\Gsc;

use App\Database\ClickHouseDatabase;

/**
 * What we ACTUALLY have in ClickHouse for a project, day by day.
 *
 * The connector used to trust a single Postgres watermark (last_synced_date) and
 * re-fetch a fixed 7-day sliding window behind it. Two consequences, both hit in
 * production: (1) a run that failed on ANY day left the watermark frozen, so the
 * window kept sliding away from reality and the hole was never filled; (2) the
 * UI, anchored on that watermark, hid days that were physically present.
 *
 * So coverage is now derived from the data itself:
 *   - head gap    : every day after the newest day we hold, up to the freshness
 *                   horizon — this is the "frozen for 8 days" case,
 *   - interior gap: days missing BETWEEN the oldest and the newest day we hold
 *                   (a run that died mid-window),
 *   - never       : days older than the oldest day we hold — that's the initial
 *                   backfill's job, not the sync's, and we must not fight it.
 *
 * Days for which Google genuinely has nothing are materialised as a zero row by
 * GscIngestor (see markEmptyDay), so they count as covered and don't get
 * re-fetched forever.
 *
 * @package    Scouter
 * @subpackage Gsc
 */
class GscCoverage
{
    private int $projectId;

    public function __construct(int $projectId)
    {
        $this->projectId = $projectId;
    }

    /** Newest day present for this project, or null when there is no data at all. */
    public function maxDate(): ?string
    {
        return $this->edge('max');
    }

    /** Oldest day present for this project, or null when there is no data at all. */
    public function minDate(): ?string
    {
        return $this->edge('min');
    }

    /**
     * Days we hold, as a set, within [$from, $to].
     *
     * @return array<string,true> 'Y-m-d' => true
     */
    public function daysPresent(string $from, string $to): array
    {
        if (!ClickHouseDatabase::enabled()) {
            return [];
        }
        $rows = ClickHouseDatabase::getInstance()->select(
            "SELECT DISTINCT toString(date) AS d FROM scouter.gsc_site_daily
             WHERE project_id = {pid:Int32} AND date >= {from:Date} AND date <= {to:Date}",
            ['pid' => [$this->projectId, 'Int32'], 'from' => $from, 'to' => $to]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['d']] = true;
        }
        return $out;
    }

    /**
     * The days a sync should (re)fetch, newest first:
     *   1. the freshness window — always re-pulled so GSC's late finalisation
     *      lands, even when those days are already present,
     *   2. the head gap — everything between the newest day we hold and $end,
     *   3. the interior holes — missing days inside the range we already cover.
     *
     * Capped at $maxDays so a connector that has been frozen for weeks doesn't
     * produce one gigantic job; the reconciler re-enqueues until the gap list is
     * empty, so the catch-up happens over successive runs instead of never.
     *
     * @return array{days:string[],remaining:int} `remaining` = how many were cut
     *         off by the cap (0 when the list is complete).
     */
    public function daysToSync(string $end, int $refreshWindow, int $maxDays): array
    {
        $min = $this->minDate();
        $present = $min !== null ? $this->daysPresent($min, $end) : [];

        return self::planDays($min, $end, $present, $refreshWindow, $maxDays);
    }

    /**
     * Pure planner behind daysToSync() — the part worth unit-testing.
     *
     * @param string|null           $min     oldest day held, null when we hold nothing
     * @param array<string,true>    $present days held within [$min, $end]
     * @return array{days:string[],remaining:int}
     */
    public static function planDays(?string $min, string $end, array $present, int $refreshWindow, int $maxDays): array
    {
        $wanted = [];   // de-duplicated set of days to (re)fetch

        // (1) freshness window — unconditional re-pull, present or not.
        $day = $end;
        for ($i = 0; $i < max(1, $refreshWindow); $i++) {
            $wanted[$day] = true;
            $day = date('Y-m-d', strtotime($day . ' -1 day'));
        }

        // (2) + (3) everything missing between the oldest day we hold and $end.
        // Days OLDER than $min are deliberately ignored: that history belongs to
        // the backfill, and claiming it here would make the two jobs fight.
        if ($min !== null) {
            $day = $end;
            while ($day >= $min) {
                if (!isset($present[$day])) {
                    $wanted[$day] = true;
                }
                $day = date('Y-m-d', strtotime($day . ' -1 day'));
            }
        }

        $days = array_keys($wanted);
        rsort($days); // newest first: the days users actually look at come back first

        $total = count($days);
        if ($maxDays > 0 && $total > $maxDays) {
            return ['days' => array_slice($days, 0, $maxDays), 'remaining' => $total - $maxDays];
        }
        return ['days' => $days, 'remaining' => 0];
    }

    /**
     * Days between the newest day we hold and $end — i.e. the fresh data a long
     * running backfill would otherwise not pick up until it finishes walking 16
     * months backwards. Newest first, capped.
     *
     * @return string[]
     */
    public function headGapDays(string $end, int $cap): array
    {
        $max = $this->maxDate();
        if ($max === null || $max >= $end) {
            return [];
        }
        $days = [];
        $day = $end;
        while ($day > $max && count($days) < $cap) {
            $days[] = $day;
            $day = date('Y-m-d', strtotime($day . ' -1 day'));
        }
        return $days;
    }

    /** True when the project is missing at least one day it should hold. */
    public function hasGap(string $end, int $refreshWindow): bool
    {
        $min = $this->minDate();
        if ($min === null) {
            return true;
        }
        $max = $this->maxDate();
        if ($max === null || $max < $end) {
            return true; // head gap — the "frozen since the 18th" case
        }
        $present = $this->daysPresent($min, $end);
        $expected = (int) ((strtotime($end) - strtotime($min)) / 86400) + 1;
        return count($present) < $expected;
    }

    private function edge(string $fn): ?string
    {
        if (!ClickHouseDatabase::enabled()) {
            return null;
        }
        $v = ClickHouseDatabase::getInstance()->selectValue(
            "SELECT toString({$fn}(date)) FROM scouter.gsc_site_daily WHERE project_id = {pid:Int32}",
            ['pid' => [$this->projectId, 'Int32']]
        );
        // ClickHouse returns the Date epoch ('1970-01-01') on an empty set.
        if (!is_string($v) || $v === '' || $v === '1970-01-01') {
            return null;
        }
        return $v;
    }
}
