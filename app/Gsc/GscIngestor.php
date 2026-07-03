<?php

namespace App\Gsc;

use App\Database\ClickHouseDatabase;
use App\Google\SearchConsoleClient;

/**
 * Fetches one day of Search Console performance data (4 dimension sets) and
 * writes it to ClickHouse, materialising the anonymized "(anonyme)" buckets.
 *
 * Why 4 datasets? GSC drops low-volume rows when the `query` dimension is
 * requested, so Σ(query rows) < the true total. We fetch the marginals
 * (site total, per-URL total) that DON'T carry that loss, then materialise the
 * gap as explicit `is_anon=1` rows so every level reconciles:
 *   - gsc_site_daily        dimensions []            → true daily total
 *   - gsc_page_daily        dimensions [page]        → true per-URL total
 *   - gsc_query_daily       dimensions [query]       + one (anonyme) row/day
 *   - gsc_page_query_daily  dimensions [query,page]  + one (anonyme) row/page/day
 *
 * clicks/impressions are additive (so the subtraction is valid); position is
 * NOT — the (anonyme) rows carry position 0.
 *
 * @package    Scouter
 * @subpackage Gsc
 */
class GscIngestor
{
    public const ANON_LABEL = '(anonyme)';

    private const INSERT_CHUNK = 20000;

    private int $projectId;
    private string $site;
    private string $accessToken;
    private string $searchType;

    public function __construct(int $projectId, string $site, string $accessToken, string $searchType = 'web')
    {
        $this->projectId   = $projectId;
        $this->site        = $site;
        $this->accessToken = $accessToken;
        $this->searchType  = $searchType;
    }

    /**
     * Ingest a single day. Returns a small summary; `hadData` is false when GSC
     * returned nothing for that day (common for the last 2-3 days / lag).
     *
     * @return array{hadData:bool,site:int,page:int,query:int,pageQuery:int}
     */
    public function ingestDay(string $date, string $dataState = 'final'): array
    {
        $siteRes = SearchConsoleClient::queryAll($this->accessToken, $this->site, $date, [], $this->searchType, $dataState);
        $this->assertOk($siteRes, $date, 'site');
        $siteRows = $siteRes['rows'];

        if (empty($siteRows)) {
            return ['hadData' => false, 'site' => 0, 'page' => 0, 'query' => 0, 'pageQuery' => 0];
        }

        $pageRes = SearchConsoleClient::queryAll($this->accessToken, $this->site, $date, ['page'], $this->searchType, $dataState);
        $this->assertOk($pageRes, $date, 'page');
        $queryRes = SearchConsoleClient::queryAll($this->accessToken, $this->site, $date, ['query'], $this->searchType, $dataState);
        $this->assertOk($queryRes, $date, 'query');
        $pqRes = SearchConsoleClient::queryAll($this->accessToken, $this->site, $date, ['query', 'page'], $this->searchType, $dataState);
        $this->assertOk($pqRes, $date, 'query+page');

        $version = time();

        // (1) site
        $siteRow = $siteRows[0];
        $this->insert('gsc_site_daily', [$this->baseRow($date, $version) + [
            'clicks'      => self::int($siteRow['clicks'] ?? 0),
            'impressions' => self::int($siteRow['impressions'] ?? 0),
            'position'    => self::pos($siteRow['position'] ?? 0),
        ]]);

        // (2) page
        $pageRows = [];
        foreach ($pageRes['rows'] as $r) {
            $pageRows[] = $this->baseRow($date, $version) + [
                'page'        => (string) ($r['keys'][0] ?? ''),
                'clicks'      => self::int($r['clicks'] ?? 0),
                'impressions' => self::int($r['impressions'] ?? 0),
                'position'    => self::pos($r['position'] ?? 0),
            ];
        }
        $this->insert('gsc_page_daily', $pageRows);

        // (3) query + one (anonyme) row for the whole day
        $queryRows = [];
        foreach ($queryRes['rows'] as $r) {
            $queryRows[] = $this->baseRow($date, $version) + [
                'query'       => (string) ($r['keys'][0] ?? ''),
                'clicks'      => self::int($r['clicks'] ?? 0),
                'impressions' => self::int($r['impressions'] ?? 0),
                'position'    => self::pos($r['position'] ?? 0),
                'is_anon'     => 0,
            ];
        }
        $qAnon = self::computeQueryAnon($siteRow, $queryRes['rows']);
        if ($qAnon !== null) {
            $queryRows[] = $this->baseRow($date, $version) + [
                'query'       => self::ANON_LABEL,
                'clicks'      => $qAnon['clicks'],
                'impressions' => $qAnon['impressions'],
                'position'    => 0,
                'is_anon'     => 1,
            ];
        }
        $this->insert('gsc_query_daily', $queryRows);

        // (4) query×page joint + one (anonyme) row per URL
        $pqRows = [];
        foreach ($pqRes['rows'] as $r) {
            $pqRows[] = $this->baseRow($date, $version) + [
                'query'       => (string) ($r['keys'][0] ?? ''),
                'page'        => (string) ($r['keys'][1] ?? ''),
                'clicks'      => self::int($r['clicks'] ?? 0),
                'impressions' => self::int($r['impressions'] ?? 0),
                'position'    => self::pos($r['position'] ?? 0),
                'is_anon'     => 0,
            ];
        }
        foreach (self::computePageAnon($pageRes['rows'], $pqRes['rows']) as $page => $delta) {
            $pqRows[] = $this->baseRow($date, $version) + [
                'query'       => self::ANON_LABEL,
                'page'        => (string) $page,
                'clicks'      => $delta['clicks'],
                'impressions' => $delta['impressions'],
                'position'    => 0,
                'is_anon'     => 1,
            ];
        }
        $this->insert('gsc_page_query_daily', $pqRows);

        return [
            'hadData'   => true,
            'site'      => 1,
            'page'      => count($pageRows),
            'query'     => count($queryRows),
            'pageQuery' => count($pqRows),
        ];
    }

    // -------------------------------------------------------------------------
    // Pure computation (unit-tested)
    // -------------------------------------------------------------------------

    /**
     * Anonymized bucket for the whole day = site total − Σ(named query rows).
     * Returns null when there's nothing to attribute. Clamped at 0.
     *
     * @param array<string,mixed> $siteRow
     * @param array<int,array<string,mixed>> $queryRows
     * @return array{clicks:int,impressions:int}|null
     */
    public static function computeQueryAnon(array $siteRow, array $queryRows): ?array
    {
        $sumClicks = 0;
        $sumImpr   = 0;
        foreach ($queryRows as $r) {
            $sumClicks += self::int($r['clicks'] ?? 0);
            $sumImpr   += self::int($r['impressions'] ?? 0);
        }
        $clicks = max(0, self::int($siteRow['clicks'] ?? 0) - $sumClicks);
        $impr   = max(0, self::int($siteRow['impressions'] ?? 0) - $sumImpr);
        if ($clicks === 0 && $impr === 0) {
            return null;
        }
        return ['clicks' => $clicks, 'impressions' => $impr];
    }

    /**
     * Per-URL anonymized bucket = page total − Σ(named query rows for that page).
     * Only pages with a positive remainder are returned.
     *
     * @param array<int,array<string,mixed>> $pageRows       dimensions [page]
     * @param array<int,array<string,mixed>> $pageQueryRows  dimensions [query,page]
     * @return array<string,array{clicks:int,impressions:int}>
     */
    public static function computePageAnon(array $pageRows, array $pageQueryRows): array
    {
        $sumByPage = [];
        foreach ($pageQueryRows as $r) {
            $page = (string) ($r['keys'][1] ?? '');
            if (!isset($sumByPage[$page])) {
                $sumByPage[$page] = ['clicks' => 0, 'impressions' => 0];
            }
            $sumByPage[$page]['clicks']      += self::int($r['clicks'] ?? 0);
            $sumByPage[$page]['impressions'] += self::int($r['impressions'] ?? 0);
        }

        $out = [];
        foreach ($pageRows as $r) {
            $page = (string) ($r['keys'][0] ?? '');
            $sum  = $sumByPage[$page] ?? ['clicks' => 0, 'impressions' => 0];
            $clicks = max(0, self::int($r['clicks'] ?? 0) - $sum['clicks']);
            $impr   = max(0, self::int($r['impressions'] ?? 0) - $sum['impressions']);
            if ($clicks > 0 || $impr > 0) {
                $out[$page] = ['clicks' => $clicks, 'impressions' => $impr];
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> the columns common to every row */
    private function baseRow(string $date, int $version): array
    {
        return [
            'project_id'  => $this->projectId,
            'site'        => $this->site,
            'search_type' => $this->searchType,
            'date'        => $date,
            'version'     => $version,
        ];
    }

    /**
     * Insert associative rows via ClickHouse FORMAT JSONEachRow (json-escaped,
     * injection-safe). Missing columns fall back to their DEFAULT.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function insert(string $table, array $rows): void
    {
        if (empty($rows)) {
            return;
        }
        $ch = ClickHouseDatabase::getInstance();
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $lines = [];
            foreach ($chunk as $row) {
                $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $sql = "INSERT INTO scouter.{$table} FORMAT JSONEachRow\n" . implode("\n", $lines);
            $ch->exec($sql);
        }
    }

    /**
     * @param array{ok:bool,error?:string} $res
     */
    private function assertOk(array $res, string $date, string $which): void
    {
        if (!($res['ok'] ?? false)) {
            throw new \RuntimeException("GSC {$which} query failed for {$date}: " . ($res['error'] ?? 'unknown error'));
        }
    }

    private static function int($v): int
    {
        return (int) round((float) $v);
    }

    private static function pos($v): float
    {
        return round((float) $v, 4);
    }
}
