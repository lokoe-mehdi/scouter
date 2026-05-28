<?php

namespace App\Storage;

use PDO;

class HtmlStore
{
    public static function key(int $crawlId, string $pageId): string
    {
        return 'html/' . $crawlId . '/' . $pageId;
    }

    public static function fetch(int $crawlId, string $pageId, bool $useCh, $db): ?string
    {
        $key = self::key($crawlId, $pageId);
        $blob = Storage::instance()->get($key);

        if ($blob !== null) {
            $decompressed = @gzdecode($blob);
            return $decompressed !== false ? $decompressed : $blob;
        }

        if ($db === null) {
            return null;
        }

        $stmt = $db->prepare('SELECT html FROM html WHERE crawl_id = :cid AND page_id = :pid LIMIT 1');
        $stmt->execute([':cid' => $crawlId, ':pid' => $pageId]);
        $stored = $stmt->fetchColumn();

        if ($stored === false || $stored === null) {
            return null;
        }

        return self::decodeStoredHtml($stored);
    }

    public static function fetchMany(int $crawlId, array $pageIds, bool $useCh, $db): array
    {
        if (empty($pageIds)) {
            return [];
        }

        $result = [];
        $missingIds = [];
        $store = Storage::instance();

        foreach ($pageIds as $pid) {
            $key = self::key($crawlId, $pid);
            $blob = $store->get($key);
            if ($blob !== null) {
                $decompressed = @gzdecode($blob);
                $result[$pid] = $decompressed !== false ? $decompressed : $blob;
            } else {
                $missingIds[] = $pid;
            }
        }

        if (!empty($missingIds) && $db !== null) {
            $placeholders = [];
            $params = [':cid' => $crawlId];
            foreach ($missingIds as $i => $pid) {
                $key = ':p' . $i;
                $placeholders[] = $key;
                $params[$key] = $pid;
            }
            $sql = 'SELECT page_id, html FROM html WHERE crawl_id = :cid AND page_id IN (' . implode(',', $placeholders) . ')';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result[$row['page_id']] = self::decodeStoredHtml($row['html']);
            }
        }

        return $result;
    }

    private static function decodeStoredHtml(?string $stored): ?string
    {
        if (!$stored) {
            return null;
        }
        $decoded = base64_decode($stored, true);
        if ($decoded === false) {
            return $stored;
        }
        $decompressed = @gzinflate($decoded);
        return $decompressed !== false ? $decompressed : $decoded;
    }
}
