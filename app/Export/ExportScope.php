<?php

namespace App\Export;

use App\Settings\AppSettings;

/**
 * Signature of a table's WHERE clause ("export scope").
 *
 * An async export re-runs, worker-side, the query the user was looking at. The
 * only practical way to guarantee "the CSV matches the screen" is to replay the
 * table's own WHERE clause — but that clause travels through the browser, so the
 * worker cannot trust it: a crafted `report_where` would otherwise let an
 * authenticated user bolt an arbitrary sub-SELECT onto the export query.
 *
 * So the server signs the clause it rendered, and the export endpoint only
 * accepts clauses carrying a valid signature. The client can replay what the
 * server produced and nothing else — which is exactly what "export what I see"
 * needs, and it lets legitimate report scopes keep their sub-SELECTs (lost-urls
 * / new-urls compare against another crawl's partition).
 *
 * Values are NOT part of the signature: they travel as bound parameters, so they
 * can only change what a filter matches, never the shape of the query.
 *
 * @package    Scouter
 * @subpackage Export
 */
class ExportScope
{
    /** Where the per-install signing key lives (generated on first use). */
    private const SETTING_KEY = 'export.scope_signing_key';

    private static ?string $key = null;

    /** HMAC of a WHERE clause, to be posted alongside it. */
    public static function sign(string $where): string
    {
        return hash_hmac('sha256', self::normalize($where), self::key());
    }

    /** Whether $signature was produced by this install for $where. */
    public static function verify(string $where, string $signature): bool
    {
        if ($signature === '' || $where === '') {
            return false;
        }
        return hash_equals(self::sign($where), $signature);
    }

    /**
     * Whitespace-insensitive: the clause round-trips through an HTML attribute
     * and a JSON body, and report pages build it with newlines/indentation.
     */
    private static function normalize(string $where): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $where));
    }

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $key = AppSettings::get(self::SETTING_KEY);
        if ($key === null || $key === '') {
            $key = bin2hex(random_bytes(32));
            AppSettings::set(self::SETTING_KEY, $key);
            // Re-read: a concurrent request may have won the INSERT, and both
            // sides must end up signing with the same key.
            AppSettings::flushCache();
            $key = AppSettings::get(self::SETTING_KEY) ?: $key;
        }
        self::$key = $key;
        return $key;
    }
}
