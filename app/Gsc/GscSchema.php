<?php

namespace App\Gsc;

use App\Database\ClickHouseDatabase;

/**
 * Ensures the ClickHouse tables backing the GSC connector exist.
 *
 * The canonical DDL lives in crawler-go/internal/db/schema.sql (applied at
 * crawler boot). But a connector can be activated without a crawler restart, so
 * we also create the tables idempotently from PHP the first time we ingest.
 * The DDL below MUST stay in sync with schema.sql.
 *
 * Dimensions: `country` + `device` are stored on the site / page / query tables
 * (full geo/device segmentation of the marginals). They are NOT on the joint
 * page×query table, which would multiply into billions of rows. `page` URLs are
 * fragment-stripped at ingestion (url and url#x collapse to url), so these keys
 * are the source of truth. An ORDER BY change can't be applied in place — the
 * tables must be DROPped + recreated + re-backfilled (see GscSchema::recreate).
 *
 * @package    Scouter
 * @subpackage Gsc
 */
class GscSchema
{
    /** @var array<string,string> table name => CREATE statement */
    private const TABLES = [
        'gsc_site_daily' => "CREATE TABLE IF NOT EXISTS scouter.gsc_site_daily (
            project_id Int32, site String, search_type LowCardinality(String) DEFAULT 'web',
            date Date, country LowCardinality(String) DEFAULT '', device LowCardinality(String) DEFAULT '',
            clicks Int64 DEFAULT 0, impressions Int64 DEFAULT 0, position Float32 DEFAULT 0,
            version UInt64 DEFAULT toUnixTimestamp(now())
        ) ENGINE = ReplacingMergeTree(version) PARTITION BY project_id
          ORDER BY (project_id, search_type, date, country, device)",

        'gsc_page_daily' => "CREATE TABLE IF NOT EXISTS scouter.gsc_page_daily (
            project_id Int32, site String, search_type LowCardinality(String) DEFAULT 'web',
            date Date, page String, country LowCardinality(String) DEFAULT '', device LowCardinality(String) DEFAULT '',
            clicks Int64 DEFAULT 0, impressions Int64 DEFAULT 0, position Float32 DEFAULT 0,
            version UInt64 DEFAULT toUnixTimestamp(now())
        ) ENGINE = ReplacingMergeTree(version) PARTITION BY project_id
          ORDER BY (project_id, search_type, date, page, country, device)",

        'gsc_query_daily' => "CREATE TABLE IF NOT EXISTS scouter.gsc_query_daily (
            project_id Int32, site String, search_type LowCardinality(String) DEFAULT 'web',
            date Date, query String, country LowCardinality(String) DEFAULT '', device LowCardinality(String) DEFAULT '',
            clicks Int64 DEFAULT 0, impressions Int64 DEFAULT 0, position Float32 DEFAULT 0,
            is_anon UInt8 DEFAULT 0, version UInt64 DEFAULT toUnixTimestamp(now())
        ) ENGINE = ReplacingMergeTree(version) PARTITION BY project_id
          ORDER BY (project_id, search_type, date, query, country, device)",

        // Joint page×query: NO country/device (volume) — but page is fragment-stripped.
        'gsc_page_query_daily' => "CREATE TABLE IF NOT EXISTS scouter.gsc_page_query_daily (
            project_id Int32, site String, search_type LowCardinality(String) DEFAULT 'web',
            date Date, page String, query String, clicks Int64 DEFAULT 0, impressions Int64 DEFAULT 0, position Float32 DEFAULT 0,
            is_anon UInt8 DEFAULT 0, version UInt64 DEFAULT toUnixTimestamp(now())
        ) ENGINE = ReplacingMergeTree(version) PARTITION BY project_id
          ORDER BY (project_id, search_type, date, page, query)",
    ];

    /** The 4 GSC tables (used by the SQL explorer whitelist + delete job). */
    public static function tableNames(): array
    {
        return array_keys(self::TABLES);
    }

    /** Create the 4 tables if missing. Idempotent. */
    public static function ensure(): void
    {
        $ch = ClickHouseDatabase::getInstance();
        foreach (self::TABLES as $ddl) {
            $ch->exec($ddl);
        }
    }

    /**
     * DROP + recreate the 4 tables. DESTRUCTIVE — used when the schema (columns /
     * ORDER BY) changes, e.g. adding country/device. Every connector must then be
     * re-backfilled. Only run this when explicitly requested.
     */
    public static function recreate(): void
    {
        $ch = ClickHouseDatabase::getInstance();
        foreach (array_keys(self::TABLES) as $table) {
            $ch->exec("DROP TABLE IF EXISTS scouter.{$table}");
        }
        self::ensure();
    }
}
