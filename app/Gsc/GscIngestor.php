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
 *   - gsc_site_daily        dims [country,device]        → true daily total per geo/device
 *   - gsc_page_daily        dims [page,country,device]   → true per-URL total per geo/device
 *   - gsc_query_daily       dims [query,country,device]  + one (anonyme) row per geo/device
 *   - gsc_page_query_daily  dims [query,page]            + one (anonyme) row/page
 *
 * Two design points added in this revision:
 *   1. country + device are requested and stored on the site / page / query
 *      marginals. The anonymized bucket is therefore reconciled PER (country,
 *      device) for queries — GSC drops low-volume rows within each slice. The
 *      joint page×query table stays geo/device-agnostic to cap its size.
 *   2. URL fragments are stripped at ingestion: `url` and `url#section` collapse
 *      into `url`, their metrics summed and position impression-weighted. This
 *      matches the crawl (which never stores fragment URLs) and kills the
 *      "#anchor" orphan noise.
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
        // Marginals now carry country + device.
        $siteRes = SearchConsoleClient::queryAll($this->accessToken, $this->site, $date, ['country', 'device'], $this->searchType, $dataState);
        $this->assertOk($siteRes, $date, 'site');
        $siteRows = $siteRes['rows'];

        if (empty($siteRows)) {
            return ['hadData' => false, 'site' => 0, 'page' => 0, 'query' => 0, 'pageQuery' => 0];
        }

        $pageRes = SearchConsoleClient::queryAll($this->accessToken, $this->site, $date, ['page', 'country', 'device'], $this->searchType, $dataState);
        $this->assertOk($pageRes, $date, 'page');
        $queryRes = SearchConsoleClient::queryAll($this->accessToken, $this->site, $date, ['query', 'country', 'device'], $this->searchType, $dataState);
        $this->assertOk($queryRes, $date, 'query');
        $pqRes = SearchConsoleClient::queryAll($this->accessToken, $this->site, $date, ['query', 'page'], $this->searchType, $dataState);
        $this->assertOk($pqRes, $date, 'query+page');

        $version = time();

        // Fragment-strip + merge the URL-bearing datasets (page key at index 0 / 1).
        $pageRows = self::mergeFragments($pageRes['rows'], 0);   // keys=[page,country,device]
        $pqRows   = self::mergeFragments($pqRes['rows'], 1);     // keys=[query,page]

        // (1) site per (country, device)
        $siteInsert = [];
        foreach ($siteRows as $r) {
            $siteInsert[] = $this->baseRow($date, $version) + [
                'country'     => (string) ($r['keys'][0] ?? ''),
                'device'      => (string) ($r['keys'][1] ?? ''),
                'clicks'      => self::int($r['clicks'] ?? 0),
                'impressions' => self::int($r['impressions'] ?? 0),
                'position'    => self::pos($r['position'] ?? 0),
            ];
        }
        $this->insert('gsc_site_daily', $siteInsert);

        // (2) page per (page, country, device) — fragment-merged
        $pageInsert = [];
        foreach ($pageRows as $r) {
            $pageInsert[] = $this->baseRow($date, $version) + [
                'page'        => (string) ($r['keys'][0] ?? ''),
                'country'     => (string) ($r['keys'][1] ?? ''),
                'device'      => (string) ($r['keys'][2] ?? ''),
                'clicks'      => $r['clicks'],
                'impressions' => $r['impressions'],
                'position'    => self::pos($r['position']),
            ];
        }
        $this->insert('gsc_page_daily', $pageInsert);

        // (3) query per (query, country, device) + one (anonyme) row PER (country, device)
        $queryInsert = [];
        foreach ($queryRes['rows'] as $r) {
            $queryInsert[] = $this->baseRow($date, $version) + [
                'query'       => (string) ($r['keys'][0] ?? ''),
                'country'     => (string) ($r['keys'][1] ?? ''),
                'device'      => (string) ($r['keys'][2] ?? ''),
                'clicks'      => self::int($r['clicks'] ?? 0),
                'impressions' => self::int($r['impressions'] ?? 0),
                'position'    => self::pos($r['position'] ?? 0),
                'is_anon'     => 0,
            ];
        }
        foreach (self::computeQueryAnonByCd($siteRows, $queryRes['rows']) as $a) {
            $queryInsert[] = $this->baseRow($date, $version) + [
                'query'       => self::ANON_LABEL,
                'country'     => $a['country'],
                'device'      => $a['device'],
                'clicks'      => $a['clicks'],
                'impressions' => $a['impressions'],
                'position'    => 0,
                'is_anon'     => 1,
            ];
        }
        $this->insert('gsc_query_daily', $queryInsert);

        // (4) query×page joint (no country/device) + one (anonyme) row per URL
        $pqInsert = [];
        foreach ($pqRows as $r) {
            $pqInsert[] = $this->baseRow($date, $version) + [
                'query'       => (string) ($r['keys'][0] ?? ''),
                'page'        => (string) ($r['keys'][1] ?? ''),
                'clicks'      => $r['clicks'],
                'impressions' => $r['impressions'],
                'position'    => self::pos($r['position']),
                'is_anon'     => 0,
            ];
        }
        foreach (self::computePageAnon($pageRows, $pqRows) as $page => $delta) {
            $pqInsert[] = $this->baseRow($date, $version) + [
                'query'       => self::ANON_LABEL,
                'page'        => (string) $page,
                'clicks'      => $delta['clicks'],
                'impressions' => $delta['impressions'],
                'position'    => 0,
                'is_anon'     => 1,
            ];
        }
        $this->insert('gsc_page_query_daily', $pqInsert);

        return [
            'hadData'   => true,
            'site'      => count($siteInsert),
            'page'      => count($pageInsert),
            'query'     => count($queryInsert),
            'pageQuery' => count($pqInsert),
        ];
    }

    // -------------------------------------------------------------------------
    // Pure computation (unit-tested)
    // -------------------------------------------------------------------------

    /** Strip a URL fragment (everything from the first '#'). */
    public static function stripFragment(string $url): string
    {
        $h = strpos($url, '#');
        return $h === false ? $url : substr($url, 0, $h);
    }

    /**
     * Collapse rows that become identical once the URL fragment is stripped:
     * clicks/impressions summed, position impression-weighted. `$pageKeyIndex` is
     * the position of the URL within each row's `keys`.
     *
     * @param array<int,array<string,mixed>> $rows  raw GSC rows (['keys'=>[...], clicks, impressions, position])
     * @return array<int,array{keys:array<int,string>,clicks:int,impressions:int,position:float}>
     */
    public static function mergeFragments(array $rows, int $pageKeyIndex): array
    {
        $acc = [];
        foreach ($rows as $r) {
            $keys = array_values($r['keys'] ?? []);
            if (isset($keys[$pageKeyIndex])) {
                $keys[$pageKeyIndex] = self::stripFragment((string) $keys[$pageKeyIndex]);
            }
            $keys = array_map('strval', $keys);
            $sig = implode("\x1f", $keys);
            if (!isset($acc[$sig])) {
                $acc[$sig] = ['keys' => $keys, 'clicks' => 0, 'impressions' => 0, 'posw' => 0.0, 'imprw' => 0];
            }
            $clicks = self::int($r['clicks'] ?? 0);
            $impr   = self::int($r['impressions'] ?? 0);
            $pos    = (float) ($r['position'] ?? 0);
            $acc[$sig]['clicks']      += $clicks;
            $acc[$sig]['impressions'] += $impr;
            if ($impr > 0 && $pos > 0) {
                $acc[$sig]['posw']  += $pos * $impr;
                $acc[$sig]['imprw'] += $impr;
            }
        }

        $out = [];
        foreach ($acc as $a) {
            $out[] = [
                'keys'        => $a['keys'],
                'clicks'      => $a['clicks'],
                'impressions' => $a['impressions'],
                'position'    => $a['imprw'] > 0 ? round($a['posw'] / $a['imprw'], 4) : 0.0,
            ];
        }
        return $out;
    }

    /**
     * Anonymized query bucket PER (country, device): site total − Σ(named query
     * rows) within that slice. GSC drops low-volume query rows independently in
     * each geo/device slice, so the gap must be reconciled per slice.
     *
     * @param array<int,array<string,mixed>> $siteRows   dims [country,device]
     * @param array<int,array<string,mixed>> $queryRows  dims [query,country,device]
     * @return array<int,array{country:string,device:string,clicks:int,impressions:int}>
     */
    public static function computeQueryAnonByCd(array $siteRows, array $queryRows): array
    {
        $qByCd = [];
        foreach ($queryRows as $r) {
            $cd = ((string) ($r['keys'][1] ?? '')) . "\x1f" . ((string) ($r['keys'][2] ?? ''));
            if (!isset($qByCd[$cd])) {
                $qByCd[$cd] = ['clicks' => 0, 'impressions' => 0];
            }
            $qByCd[$cd]['clicks']      += self::int($r['clicks'] ?? 0);
            $qByCd[$cd]['impressions'] += self::int($r['impressions'] ?? 0);
        }

        $out = [];
        foreach ($siteRows as $r) {
            $country = (string) ($r['keys'][0] ?? '');
            $device  = (string) ($r['keys'][1] ?? '');
            $cd      = $country . "\x1f" . $device;
            $sum     = $qByCd[$cd] ?? ['clicks' => 0, 'impressions' => 0];
            $clicks  = max(0, self::int($r['clicks'] ?? 0) - $sum['clicks']);
            $impr    = max(0, self::int($r['impressions'] ?? 0) - $sum['impressions']);
            if ($clicks > 0 || $impr > 0) {
                $out[] = ['country' => $country, 'device' => $device, 'clicks' => $clicks, 'impressions' => $impr];
            }
        }
        return $out;
    }

    /**
     * Per-URL anonymized bucket = page total (summed over country/device) −
     * Σ(named query rows for that page). Only pages with a positive remainder
     * are returned. Both inputs are already fragment-merged.
     *
     * @param array<int,array{keys:array<int,string>,clicks:int,impressions:int,position:float}> $pageRows       keys=[page,country,device]
     * @param array<int,array{keys:array<int,string>,clicks:int,impressions:int,position:float}> $pageQueryRows  keys=[query,page]
     * @return array<string,array{clicks:int,impressions:int}>
     */
    public static function computePageAnon(array $pageRows, array $pageQueryRows): array
    {
        $pageTotal = [];
        foreach ($pageRows as $r) {
            $page = (string) ($r['keys'][0] ?? '');
            if (!isset($pageTotal[$page])) {
                $pageTotal[$page] = ['clicks' => 0, 'impressions' => 0];
            }
            $pageTotal[$page]['clicks']      += self::int($r['clicks'] ?? 0);
            $pageTotal[$page]['impressions'] += self::int($r['impressions'] ?? 0);
        }

        $pqSum = [];
        foreach ($pageQueryRows as $r) {
            $page = (string) ($r['keys'][1] ?? '');
            if (!isset($pqSum[$page])) {
                $pqSum[$page] = ['clicks' => 0, 'impressions' => 0];
            }
            $pqSum[$page]['clicks']      += self::int($r['clicks'] ?? 0);
            $pqSum[$page]['impressions'] += self::int($r['impressions'] ?? 0);
        }

        $out = [];
        foreach ($pageTotal as $page => $t) {
            $sum    = $pqSum[$page] ?? ['clicks' => 0, 'impressions' => 0];
            $clicks = max(0, $t['clicks'] - $sum['clicks']);
            $impr   = max(0, $t['impressions'] - $sum['impressions']);
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
