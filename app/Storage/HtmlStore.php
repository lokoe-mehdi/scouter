<?php

namespace App\Storage;

use App\Api\PageContent;

/**
 * Single source of truth for reading a crawled page's raw HTML.
 *
 * New crawls store gzip-compressed HTML in the blob store (S3/local) under
 * {@see HtmlStore::key()}. Older crawls still have their HTML in the database
 * (ClickHouse stores it raw; legacy PostgreSQL stores it base64 + gzdeflate).
 * Every reader — public API, URL Explorer, preview, MCP and the Dr. Brief
 * chatbot tools — goes through here so the "blob first, DB fallback" logic lives
 * in one place.
 *
 * @package    Scouter
 * @subpackage Storage
 */
class HtmlStore
{
    /** Object key for one page's HTML: html/<crawl_id>/<page_id>.gz. */
    public static function key(int $crawlId, string $pageId): string
    {
        return "html/{$crawlId}/{$pageId}.gz";
    }

    /**
     * Return the raw (decompressed) HTML of one page, or null when none is
     * stored. $dataDb is the crawl's DATA handle (ChPdo for migrated crawls, the
     * PG connection otherwise); $useCh tells how the DB fallback blob is encoded.
     *
     * @param \PDO|\App\Database\ChPdo $dataDb
     */
    public static function fetch(int $crawlId, string $pageId, bool $useCh, $dataDb): ?string
    {
        $blob = Storage::instance()->get(self::key($crawlId, $pageId));
        if ($blob !== null && $blob !== '') {
            $raw = @gzdecode($blob);
            // Tolerate an already-raw blob (e.g. a hand-placed uncompressed file).
            return $raw !== false ? $raw : $blob;
        }

        // Fallback: crawls created before HTML moved to the blob store.
        $stmt = $dataDb->prepare("SELECT html FROM html WHERE crawl_id = :cid AND id = :id LIMIT 1");
        $stmt->execute([':cid' => $crawlId, ':id' => $pageId]);
        $stored = $stmt->fetchColumn();
        if ($stored === false || $stored === null || $stored === '') {
            return null;
        }
        return $useCh ? (string)$stored : PageContent::decode($stored);
    }

    /**
     * Batch read for tools that fetch several pages at once. Returns a map
     * pageId => rawHtml (only entries that have HTML). Blob reads are per-key;
     * any page missing from the store falls back to a single batched DB query.
     *
     * @param string[] $pageIds
     * @param \PDO|\App\Database\ChPdo $dataDb
     * @return array<string,string>
     */
    public static function fetchMany(int $crawlId, array $pageIds, bool $useCh, $dataDb): array
    {
        $out = [];
        $missing = [];
        $store = Storage::instance();
        foreach ($pageIds as $pid) {
            $blob = $store->get(self::key($crawlId, (string)$pid));
            if ($blob !== null && $blob !== '') {
                $raw = @gzdecode($blob);
                $out[$pid] = $raw !== false ? $raw : $blob;
            } else {
                $missing[] = $pid;
            }
        }

        if (!empty($missing)) {
            $placeholders = [];
            $params = [':cid' => $crawlId];
            foreach (array_values($missing) as $i => $pid) {
                $k = ':p' . $i;
                $placeholders[] = $k;
                $params[$k] = $pid;
            }
            $stmt = $dataDb->prepare(
                "SELECT id, html FROM html WHERE crawl_id = :cid AND id IN (" . implode(',', $placeholders) . ")"
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $stored = $row['html'];
                if ($stored === null || $stored === '') {
                    continue;
                }
                $raw = $useCh ? (string)$stored : PageContent::decode($stored);
                if ($raw !== null && $raw !== '') {
                    $out[$row['id']] = $raw;
                }
            }
        }

        return $out;
    }
}
