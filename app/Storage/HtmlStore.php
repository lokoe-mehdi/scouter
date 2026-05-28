<?php

namespace App\Storage;

class HtmlStore
{
    public static function key(int $crawlId, string $pageId): string
    {
        return "html/{$crawlId}/{$pageId}.html.gz";
    }

    public static function fetch(int $crawlId, string $pageId, bool $useCh, $db): ?string
    {
        $key = self::key($crawlId, $pageId);
        $blob = Storage::instance()->get($key);

        if ($blob !== null) {
            $decoded = @gzdecode($blob);
            return $decoded !== false ? $decoded : $blob;
        }

        if ($db === null) {
            return null;
        }

        $table = $useCh ? 'pages' : 'pages';
        $column = $useCh ? 'raw_html' : 'raw_html';
        $stmt = $db->prepare("SELECT {$column} FROM {$table} WHERE crawl_id = ? AND id = ?");
        $stmt->execute([$crawlId, $pageId]);
        $raw = $stmt->fetchColumn();

        return $raw ?: null;
    }

    public static function deleteCrawl(int $crawlId): int
    {
        return Storage::instance()->deletePrefix("html/{$crawlId}/");
    }
}
