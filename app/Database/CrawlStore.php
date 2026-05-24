<?php

namespace App\Database;

/**
 * Resolves where a crawl's data lives (PostgreSQL vs ClickHouse), so the read
 * layer can route queries during the PG->CH migration. A crawl uses ClickHouse
 * only when CH is configured AND the crawl was recorded with
 * `crawls.data_store = 'clickhouse'` (set by the Go crawler at crawl start).
 *
 * @package    Scouter
 * @subpackage Database
 */
class CrawlStore
{
    /** @var array<int,bool> per-request memo */
    private static array $cache = [];

    public static function usesClickHouse(int $crawlId): bool
    {
        if (!ClickHouseDatabase::enabled()) {
            return false;
        }
        if (isset(self::$cache[$crawlId])) {
            return self::$cache[$crawlId];
        }
        try {
            $pdo = PostgresDatabase::getInstance()->getConnection();
            $stmt = $pdo->prepare("SELECT data_store FROM crawls WHERE id = :id");
            $stmt->execute([':id' => $crawlId]);
            $store = $stmt->fetchColumn();
            $isCH = ($store === 'clickhouse');
        } catch (\Throwable $e) {
            $isCH = false;
        }
        return self::$cache[$crawlId] = $isCH;
    }
}
