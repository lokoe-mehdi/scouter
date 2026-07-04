<?php

namespace App\Gsc;

use App\Database\ClickHouseDatabase;
use App\Database\PostgresDatabase;

/**
 * Shared bootstrap for the dashboard "Performance" sub-report — the family of
 * views that cross the CRAWL data (crawl-scoped `pages`) with GOOGLE SEARCH
 * CONSOLE data (project-scoped `gsc_*`) over a user-chosen date range.
 *
 * The crux: crawl reports are crawl-scoped and read through {@see \App\Database\ChPdo},
 * which ALSO exposes the project's `gsc_*` tables as project-scoped virtual
 * sources (see ChPdo::rewriteTables). So a Performance report can simply
 * `JOIN gsc_page_daily g ON g.page = p.url` inside a normal `$pdo` query — the
 * per-URL GSC totals live in `gsc_page_daily` (the cross-analysis rollup the GSC
 * plan reserved for exactly this, §11).
 *
 * Availability requires (1) a ClickHouse-backed crawl (GSC data lives only in
 * CH), and (2) a connected GSC property with at least one day of data. This
 * class centralises that guard + resolves the shared date range + renders the
 * shared date-range control, so every Performance page is a thin, consistent
 * shell around its own SQL.
 *
 * @package    Scouter
 * @subpackage Gsc
 */
class PerformanceReport
{
    /** Presets offered by the date-range control (in days ending on the last
     *  day that actually has data — like GSC anchors on last-synced, not today). */
    public const PRESETS = [7, 28, 90, 180, 365];

    public const DEFAULT_PRESET = 28;

    /**
     * Resolve everything a Performance page needs: availability, the connector,
     * the effective [from, to] range and a human label.
     *
     * @return array{
     *   available:bool, reason:string, projectId:int, connector:?object,
     *   minDate:?string, maxDate:?string, from:string, to:string,
     *   preset:string, label:string
     * }
     */
    public static function context(int $crawlId, bool $useCh): array
    {
        $projectId = self::projectIdOf($crawlId);
        $ctx = [
            'available' => false, 'reason' => '', 'projectId' => $projectId,
            'connector' => null, 'minDate' => null, 'maxDate' => null,
            'from' => '', 'to' => '', 'preset' => (string) self::DEFAULT_PRESET, 'label' => '',
        ];

        if (!$useCh) {
            $ctx['reason'] = 'clickhouse_required';
            return $ctx;
        }
        if ($projectId <= 0) {
            $ctx['reason'] = 'not_connected';
            return $ctx;
        }

        $connector = (new ConnectorRepository())->getByProject($projectId);
        $ctx['connector'] = $connector;
        if (!$connector) {
            $ctx['reason'] = 'not_connected';
            return $ctx;
        }

        [$minDate, $maxDate] = self::dataBounds($projectId);
        // The connector may report a last-synced day even before we query bounds;
        // prefer the real data bounds, fall back to the connector's cursor.
        $maxDate = $maxDate ?: ($connector->last_synced_date ?? null);
        $ctx['minDate'] = $minDate;
        $ctx['maxDate'] = $maxDate;

        if (!$maxDate) {
            // Connected but no data yet (backfill still running / just linked).
            $ctx['reason'] = in_array($connector->status ?? '', ['connecting', 'backfilling'], true)
                ? 'backfill_running' : 'no_data';
            return $ctx;
        }

        [$from, $to, $preset, $label] = self::resolveRange($minDate ?: $maxDate, $maxDate);
        $ctx['available'] = true;
        $ctx['from'] = $from;
        $ctx['to'] = $to;
        $ctx['preset'] = $preset;
        $ctx['label'] = $label;
        return $ctx;
    }

    /** project_id for a crawl (light PG lookup — mirrors ChPdo). */
    private static function projectIdOf(int $crawlId): int
    {
        try {
            $pg = PostgresDatabase::getInstance()->getConnection();
            $stmt = $pg->prepare("SELECT project_id FROM crawls WHERE id = :id");
            $stmt->execute([':id' => $crawlId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** min/max GSC date for the project (from the site marginal table). */
    private static function dataBounds(int $projectId): array
    {
        try {
            $ch = ClickHouseDatabase::getInstance();
            $row = $ch->select(
                "SELECT toString(min(date)) AS mn, toString(max(date)) AS mx "
                . "FROM scouter.gsc_site_daily WHERE project_id = {pid:Int32}",
                ['pid' => $projectId]
            );
            $mn = $row[0]['mn'] ?? '';
            $mx = $row[0]['mx'] ?? '';
            // CH returns the zero date for an empty table.
            $mn = ($mn && $mn !== '1970-01-01') ? $mn : null;
            $mx = ($mx && $mx !== '1970-01-01') ? $mx : null;
            return [$mn, $mx];
        } catch (\Throwable $e) {
            return [null, null];
        }
    }

    /**
     * Turn the request ($_GET pf/pfrom/pto) into a concrete range, clamped to
     * the available data window.
     *
     * @return array{0:string,1:string,2:string,3:string} [from, to, preset, label]
     */
    private static function resolveRange(string $minDate, string $maxDate): array
    {
        $pf   = isset($_GET['pf']) ? (string) $_GET['pf'] : (string) self::DEFAULT_PRESET;
        $to   = $maxDate;
        $from = null;

        if ($pf === 'custom') {
            $cf = self::safeDate($_GET['pfrom'] ?? '');
            $ct = self::safeDate($_GET['pto'] ?? '');
            if ($cf && $ct) {
                $from = $cf;
                $to   = $ct;
            } else {
                $pf = (string) self::DEFAULT_PRESET; // fall back if malformed
            }
        }

        if ($from === null) {
            $days = in_array((int) $pf, self::PRESETS, true) ? (int) $pf : self::DEFAULT_PRESET;
            $pf   = (string) $days;
            $from = date('Y-m-d', strtotime($maxDate . " -" . ($days - 1) . " days"));
        }

        // Clamp to the data window.
        if ($from < $minDate) { $from = $minDate; }
        if ($to > $maxDate)   { $to = $maxDate; }
        if ($from > $to)      { $from = $to; }

        $label = ($pf === 'custom' || !in_array((int) $pf, self::PRESETS, true))
            ? ($from . ' → ' . $to)
            : \__('performance.range_' . $pf);

        return [$from, $to, $pf, $label];
    }

    /** A same-page URL with the date-range params swapped (preserves crawl/page). */
    public static function url(int $crawlId, string $page, string $pf, ?string $from = null, ?string $to = null): string
    {
        $q = ['crawl' => $crawlId, 'page' => $page, 'pf' => $pf];
        if ($pf === 'custom') {
            $q['pfrom'] = $from ?? '';
            $q['pto']   = $to ?? '';
        }
        if (!empty($_GET['project'])) {
            $q['project'] = (string) $_GET['project'];
        }
        return '?' . http_build_query($q);
    }

    /**
     * The per-URL Search Console aggregate over the selected range, as an inline
     * SELECT to be LEFT JOIN-ed to `pages` on `page = url`. Pre-aggregating per
     * page (1 row/URL) keeps the outer join from fanning out, so downstream
     * `count()` counts URLs and `sum(g.clicks)` stays exact. `pos_num` carries the
     * impression-weighted position numerator (position isn't additive).
     *
     * Uses the `:from`/`:to` bound params — every Performance query passes them.
     * `gsc_page_daily` is rewritten by ChPdo into the project-scoped source.
     */
    public static function gscPageAgg(): string
    {
        return "SELECT page, "
             . "sum(clicks) AS clicks, sum(impressions) AS impressions, "
             . "sum(position * impressions) AS pos_num "
             . "FROM gsc_page_daily "
             . "WHERE date >= toDate(:from) AND date <= toDate(:to) "
             . "GROUP BY page";
    }

    /**
     * Impression-weighted average position over the joined `gscPageAgg` alias
     * (clicks / impressions are additive; position is not → weight by impressions).
     */
    public static function weightedPos(string $alias = 'g'): string
    {
        return "if(sum({$alias}.impressions) = 0, 0, sum({$alias}.pos_num) / sum({$alias}.impressions))";
    }

    private static function safeDate($d): ?string
    {
        $d = is_string($d) ? $d : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    /** Full-width notice shown in place of a report when GSC data isn't available. */
    public static function renderUnavailable(array $ctx): void
    {
        $reason = $ctx['reason'] ?: 'no_data';
        $projectId = $ctx['projectId'];
        $canConnect = ($reason === 'not_connected');
        $icon = $reason === 'backfill_running' ? 'hourglass_top' : 'search_insights';
        ?>
        <h1 class="page-title"><?= \__('performance.page_title') ?></h1>
        <div class="perf-unavailable">
            <div class="perf-unavailable-icon"><span class="material-symbols-outlined"><?= $icon ?></span></div>
            <h2><?= \__('performance.unavailable_' . $reason . '_title') ?></h2>
            <p><?= \__('performance.unavailable_' . $reason . '_desc') ?></p>
            <?php if ($canConnect && $projectId > 0): ?>
                <a class="btn btn-primary perf-unavailable-btn" href="search-analytics.php?project=<?= (int) $projectId ?>">
                    <span class="material-symbols-outlined">link</span> <?= \__('performance.go_connect') ?>
                </a>
            <?php elseif ($reason === 'no_data' && $projectId > 0): ?>
                <a class="btn btn-primary perf-unavailable-btn" href="search-analytics.php?project=<?= (int) $projectId ?>">
                    <span class="material-symbols-outlined">open_in_new</span> <?= \__('performance.open_search_analytics') ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }
}
