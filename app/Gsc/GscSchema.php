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
 * @package    Scouter
 * @subpackage Gsc
 */
class GscSchema
{
    /** @var array<string,string> table name => CREATE statement */
    private const TABLES = [
        'gsc_site_daily' => "CREATE TABLE IF NOT EXISTS scouter.gsc_site_daily (
            project_id Int32, site String, search_type LowCardinality(String) DEFAULT 'web',
            date Date, clicks Int64 DEFAULT 0, impressions Int64 DEFAULT 0, position Float32 DEFAULT 0,
            version UInt64 DEFAULT toUnixTimestamp(now())
        ) ENGINE = ReplacingMergeTree(version) PARTITION BY project_id
          ORDER BY (project_id, search_type, date)",

        'gsc_page_daily' => "CREATE TABLE IF NOT EXISTS scouter.gsc_page_daily (
            project_id Int32, site String, search_type LowCardinality(String) DEFAULT 'web',
            date Date, page String, clicks Int64 DEFAULT 0, impressions Int64 DEFAULT 0, position Float32 DEFAULT 0,
            version UInt64 DEFAULT toUnixTimestamp(now())
        ) ENGINE = ReplacingMergeTree(version) PARTITION BY project_id
          ORDER BY (project_id, search_type, date, page)",

        'gsc_query_daily' => "CREATE TABLE IF NOT EXISTS scouter.gsc_query_daily (
            project_id Int32, site String, search_type LowCardinality(String) DEFAULT 'web',
            date Date, query String, clicks Int64 DEFAULT 0, impressions Int64 DEFAULT 0, position Float32 DEFAULT 0,
            is_anon UInt8 DEFAULT 0, version UInt64 DEFAULT toUnixTimestamp(now())
        ) ENGINE = ReplacingMergeTree(version) PARTITION BY project_id
          ORDER BY (project_id, search_type, date, query)",

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
}
