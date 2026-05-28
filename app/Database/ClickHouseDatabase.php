<?php

namespace App\Database;

/**
 * Connexion ClickHouse singleton (interface HTTP).
 *
 * ClickHouse stocke les données de crawl (pages/links/html/page_schemas +
 * tables dérivées du post-processing). PostgreSQL garde les métadonnées et la
 * frontier. On parle à ClickHouse via son interface HTTP (port 8123) plutôt
 * qu'un driver lourd : les rapports font des SELECT analytiques, pas du
 * transactionnel.
 *
 * Activée uniquement si `CLICKHOUSE_URL` est défini. Configuration :
 * ```
 * CLICKHOUSE_URL=http://clickhouse:8123
 * CLICKHOUSE_DB=scouter
 * CLICKHOUSE_USER=scouter
 * CLICKHOUSE_PASSWORD=...
 * ```
 *
 * @package    Scouter
 * @subpackage Database
 */
class ClickHouseDatabase
{
    private static ?ClickHouseDatabase $instance = null;

    private string $base;
    private string $db;
    private string $user;
    private string $pass;

    private function __construct()
    {
        $url = getenv('CLICKHOUSE_URL');
        if (!$url) {
            throw new \RuntimeException('CLICKHOUSE_URL environment variable is required for ClickHouseDatabase.');
        }
        $this->base = rtrim($url, '/');
        $this->db   = getenv('CLICKHOUSE_DB') ?: 'scouter';
        $this->user = getenv('CLICKHOUSE_USER') ?: 'scouter';
        $this->pass = getenv('CLICKHOUSE_PASSWORD') ?: '';
    }

    /** Whether ClickHouse is configured (used to route reads PG vs CH). */
    public static function enabled(): bool
    {
        return (bool) getenv('CLICKHOUSE_URL');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Run a SELECT and return rows as associative arrays.
     *
     * Bound parameters use ClickHouse server-side placeholders `{name:Type}`
     * (injection-safe). Example:
     *   select("SELECT count() FROM pages WHERE crawl_id = {cid:Int32}", ['cid' => 12])
     *
     * @param array<string,array{0:mixed,1:string}|mixed> $params name => value
     *        (type defaults to String) or name => [value, 'Int32'].
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $params = [], int $cacheTtl = 0): array
    {
        $resp = $this->httpQuery($sql . "\nFORMAT JSON", $params, self::cacheSettings($cacheTtl));
        $decoded = json_decode($resp, true);
        if (!is_array($decoded) || !isset($decoded['data'])) {
            return [];
        }
        return $decoded['data'];
    }

    /**
     * ClickHouse query-cache settings for a read, or [] to disable.
     *
     * The native query cache keys on the exact query text (+ params + user + db),
     * so it auto-invalidates when the SQL changes — which it does whenever the
     * project's category regex is edited (the regex is inlined into the report
     * SQL). For finished crawls the data is immutable, so a long TTL is safe; the
     * remaining edge (a crawl re-processed under the SAME id via resume/backfill)
     * is covered by the TTL backstop + an explicit `SYSTEM DROP QUERY CACHE` the
     * Go backfill issues after re-processing.
     *
     * nondeterministic_function_handling='ignore' guarantees enabling the cache
     * never errors on a report that happens to call now()/today() — such queries
     * are simply not cached instead of throwing.
     *
     * @return array<string,string>
     */
    private static function cacheSettings(int $ttl): array
    {
        if ($ttl <= 0 || getenv('CLICKHOUSE_QUERY_CACHE') === '0') {
            return [];
        }
        return [
            'use_query_cache'                                => '1',
            'query_cache_ttl'                                => (string) $ttl,
            'query_cache_nondeterministic_function_handling' => 'ignore',
            // Only cache queries that took long enough to be worth it (ms); the
            // slow report aggregations qualify, trivial lookups don't pollute it.
            'query_cache_min_query_duration'                 => '150',
        ];
    }

    /** Run a SELECT returning a single scalar (first row, first column), or null. */
    public function selectValue(string $sql, array $params = [])
    {
        $rows = $this->select($sql, $params);
        if (empty($rows)) {
            return null;
        }
        $first = reset($rows);
        return is_array($first) ? reset($first) : null;
    }

    /** Run a statement with no result set (DDL, ALTER, INSERT). */
    public function exec(string $sql, array $params = []): void
    {
        $this->httpQuery($sql, $params);
    }

    /**
     * Low-level HTTP call. Parameters are passed as `param_<name>` form fields
     * so ClickHouse binds them server-side ({name:Type} in the SQL).
     *
     * @param array<string,string> $settings extra ClickHouse settings appended to
     *        the request query string (e.g. query-cache flags). Reserved names
     *        (database, param_*, the JSON formatting flags) are not overridable.
     */
    private function httpQuery(string $sql, array $params = [], array $settings = []): string
    {
        // prefer_column_name_to_alias=1 makes CH resolve an identifier to a column
        // before a same-named SELECT alias — PostgreSQL's behaviour — which the
        // reports rely on (e.g. `SUM(...) AS blocked` alongside `… blocked …`).
        //
        // output_format_json_quote_64bit_integers=0 emits UInt64/Int64 (what
        // count()/sum() return) as JSON *numbers* instead of quoted strings, so
        // json_decode yields PHP ints/floats. Otherwise aggregates come back as
        // strings ("95") and break consumers expecting numbers — e.g. Highcharts
        // pie/stacked charts string-concatenate the totals and render blank slices
        // (crawl counts stay well within PHP's 64-bit int, so no precision loss).
        $query = [
            'database'                              => $this->db,
            'prefer_column_name_to_alias'           => '1',
            'output_format_json_quote_64bit_integers' => '0',
        ];
        // Extra settings (e.g. query-cache) — never let them clobber the reserved
        // routing/formatting keys or the bound param_* fields below.
        foreach ($settings as $k => $v) {
            if ($k === 'database' || strncmp($k, 'param_', 6) === 0
                || $k === 'prefer_column_name_to_alias'
                || $k === 'output_format_json_quote_64bit_integers') {
                continue;
            }
            $query[$k] = (string) $v;
        }
        foreach ($params as $name => $val) {
            $value = is_array($val) ? ($val[0] ?? '') : $val;
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $query['param_' . $name] = (string) $value;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->base . '/?' . http_build_query($query),
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $sql,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_filter([
                'X-ClickHouse-User: ' . $this->user,
                $this->pass !== '' ? 'X-ClickHouse-Key: ' . $this->pass : null,
            ]),
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('ClickHouse HTTP error: ' . $err);
        }
        if ($code !== 200) {
            throw new \RuntimeException('ClickHouse query failed (' . $code . '): ' . trim((string) $body));
        }
        return (string) $body;
    }

    /**
     * Stream a query's response straight into $fh (an open, READ+WRITE file
     * handle, e.g. fopen(..,'w+b')), without buffering it in PHP memory — for
     * huge CSV exports (millions of rows). $sql must include the FORMAT clause
     * (e.g. "… FORMAT CSVWithNames"). On a non-200 response the (small) error
     * body that got written is read back, truncated away, and thrown.
     *
     * The output is appended at the handle's current position, so a caller can
     * write a BOM first and have the CSV follow it.
     *
     * @param array<string,string> $settings extra query-string settings
     */
    public function streamSelectToFile(string $sql, $fh, array $settings = []): void
    {
        $query = [
            'database'                    => $this->db,
            'prefer_column_name_to_alias' => '1',
        ];
        foreach ($settings as $k => $v) {
            if ($k === 'database' || $k === 'prefer_column_name_to_alias') {
                continue;
            }
            $query[$k] = (string) $v;
        }

        $startPos = ftell($fh);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->base . '/?' . http_build_query($query),
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $sql,
            CURLOPT_FILE           => $fh, // stream body to disk, no PHP buffer
            CURLOPT_HTTPHEADER     => array_filter([
                'X-ClickHouse-User: ' . $this->user,
                $this->pass !== '' ? 'X-ClickHouse-Key: ' . $this->pass : null,
            ]),
            CURLOPT_TIMEOUT        => 1800, // multi-GB exports
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($ok === false) {
            throw new \RuntimeException('ClickHouse HTTP error: ' . $err);
        }
        if ($code !== 200) {
            // The error text was streamed to the file; read it back, then undo it.
            fflush($fh);
            $len = max(0, (int) ftell($fh) - (int) $startPos);
            fseek($fh, (int) $startPos);
            $body = $len > 0 ? (string) fread($fh, min($len, 8192)) : '';
            fseek($fh, (int) $startPos);
            ftruncate($fh, (int) $startPos);
            throw new \RuntimeException('ClickHouse query failed (' . $code . '): ' . trim($body));
        }
    }

    public function getDatabase(): string
    {
        return $this->db;
    }
}
