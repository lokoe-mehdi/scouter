<?php

namespace App\Google;

use App\Util\SafeHttp;

/**
 * Thin client for the Google Search Console API (v3 "webmasters").
 *
 * Two operations matter for the connector:
 *   - listSites()  — GET /sites : the properties the account can read (shown in
 *                    the property picker after OAuth).
 *   - queryAll()   — POST /sites/{siteUrl}/searchAnalytics/query : the
 *                    performance rows, paginated (25 000 rows/page, startRow).
 *
 * The access token is short-lived (1 h); the caller refreshes it from the stored
 * refresh token (GoogleOAuthClient::refreshAccessToken) before calling here.
 *
 * Rate limits: 1 200 queries/min per site. queryAll() backs off on HTTP 429 /
 * 5xx (the docs recommend waiting ~15 min on quota exhaustion, but a bounded
 * exponential backoff is enough in practice for our day-by-day pulls).
 *
 * @package    Scouter
 * @subpackage Google
 */
class SearchConsoleClient
{
    private const BASE = 'https://www.googleapis.com/webmasters/v3';

    /** Max rows per page (API hard cap). */
    public const PAGE_SIZE = 25000;

    /** Backoff attempts on 429/5xx before giving up on a page. */
    private const MAX_RETRIES = 5;

    /**
     * List the properties the token can access.
     *
     * @return array{ok:bool,sites?:array<int,array{siteUrl:string,permissionLevel:string}>,error?:string}
     */
    public static function listSites(string $accessToken): array
    {
        $resp = self::request('GET', self::BASE . '/sites', $accessToken, null);
        if (!$resp['ok']) {
            return $resp;
        }
        $b = json_decode($resp['body'], true);
        $sites = [];
        foreach (($b['siteEntry'] ?? []) as $e) {
            $sites[] = [
                'siteUrl'         => (string) ($e['siteUrl'] ?? ''),
                'permissionLevel' => (string) ($e['permissionLevel'] ?? ''),
            ];
        }
        return ['ok' => true, 'sites' => $sites];
    }

    /**
     * Fetch ALL rows for one (siteUrl, day, dimensions) tuple, paginating over
     * startRow until a short page is returned.
     *
     * @param string[] $dimensions e.g. [], ['page'], ['query'], ['query','page']
     * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
     *   Each row: ['keys'=>[...], 'clicks'=>float, 'impressions'=>float,
     *              'ctr'=>float, 'position'=>float]
     */
    public static function queryAll(
        string $accessToken,
        string $siteUrl,
        string $date,
        array $dimensions,
        string $type = 'web',
        string $dataState = 'final',
        int $sleepMsBetweenPages = 0
    ): array {
        $all = [];
        $startRow = 0;
        while (true) {
            $page = self::queryPage($accessToken, $siteUrl, $date, $dimensions, $type, $dataState, $startRow);
            if (!$page['ok']) {
                return $page;
            }
            $rows = $page['rows'];
            foreach ($rows as $r) {
                $all[] = $r;
            }
            if (count($rows) < self::PAGE_SIZE) {
                break; // last page
            }
            $startRow += self::PAGE_SIZE;
            if ($sleepMsBetweenPages > 0) {
                usleep($sleepMsBetweenPages * 1000);
            }
        }
        return ['ok' => true, 'rows' => $all];
    }

    /**
     * One page of searchAnalytics.query.
     *
     * @param string[] $dimensions
     * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
     */
    public static function queryPage(
        string $accessToken,
        string $siteUrl,
        string $date,
        array $dimensions,
        string $type,
        string $dataState,
        int $startRow
    ): array {
        $url = self::BASE . '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';
        $payload = [
            'startDate'  => $date,
            'endDate'    => $date,
            'dimensions' => array_values($dimensions),
            'type'       => $type,
            'dataState'  => $dataState,
            'rowLimit'   => self::PAGE_SIZE,
            'startRow'   => $startRow,
        ];

        $attempt = 0;
        while (true) {
            $resp = self::request('POST', $url, $accessToken, json_encode($payload));
            if ($resp['ok']) {
                $b = json_decode($resp['body'], true);
                $rows = is_array($b['rows'] ?? null) ? $b['rows'] : [];
                return ['ok' => true, 'rows' => $rows];
            }
            // Retry on throttling / transient server errors.
            $status = $resp['status'] ?? 0;
            if (($status === 429 || $status >= 500) && $attempt < self::MAX_RETRIES) {
                // 2s, 4s, 8s, 16s, 32s — bounded exponential backoff.
                sleep((int) (2 ** ($attempt + 1)));
                $attempt++;
                continue;
            }
            return $resp;
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array{ok:bool,body?:string,error?:string,status?:int}
     */
    private static function request(string $method, string $url, string $accessToken, ?string $body): array
    {
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . $accessToken];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
        ]);
        SafeHttp::applyCurlSecurity($ch);

        $respBody = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errmsg   = $errno ? curl_error($ch) : '';
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        try {
            SafeHttp::validateFinalIp($ch);
        } catch (\Throwable $e) {
            curl_close($ch);
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 0];
        }
        curl_close($ch);

        if ($errno || $respBody === false) {
            return ['ok' => false, 'error' => "Network error: {$errmsg}", 'status' => 0];
        }
        if ($status >= 400) {
            $decoded = json_decode((string) $respBody, true);
            $msg = is_array($decoded)
                ? ($decoded['error']['message'] ?? "HTTP {$status}")
                : "HTTP {$status}";
            return ['ok' => false, 'error' => (string) $msg, 'status' => $status, 'body' => (string) $respBody];
        }
        return ['ok' => true, 'body' => (string) $respBody, 'status' => $status];
    }
}
