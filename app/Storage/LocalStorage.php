<?php

namespace App\Storage;

/**
 * Filesystem-backed blob store: keys map onto paths under a single root
 * directory (html/123/abcd.gz → <root>/html/123/abcd.gz). Used when no S3
 * credentials are configured. The root MUST be a persistent volume shared with
 * the crawler container so writer (Go) and readers (PHP) see the same files.
 *
 * @package    Scouter
 * @subpackage Storage
 */
class LocalStorage implements StorageInterface
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    private function path(string $key): string
    {
        // Keys are trusted internal paths (html/<id>/<id>.gz); still strip any
        // ".." segment defensively so a key can never escape the root.
        $key = str_replace('..', '', ltrim($key, '/'));
        return $this->root . '/' . $key;
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $data = @file_get_contents($path);
        return $data === false ? null : $data;
    }

    public function put(string $key, string $data): bool
    {
        $path = $this->path($key);
        $dir = dirname($path);
        // 0o777 (filtré par umask) pour que le crawler (Go) ET le reader (PHP) — qui
        // tournent souvent avec des uid différents dans des conteneurs distincts —
        // puissent tous deux écrire/lire dans la même racine.
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return false;
        }
        // Write to a temp file then rename so a concurrent reader never sees a
        // half-written blob (rename is atomic on the same filesystem).
        $tmp = $path . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $data) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            return false;
        }
        @chmod($path, 0644);
        return true;
    }

    public function putFile(string $key, string $path, string $contentType = ''): bool
    {
        $dest = $this->path($key);
        $dir = dirname($dest);
        // 0o777 (filtré par umask) pour que le crawler (Go) ET le reader (PHP) — qui
        // tournent souvent avec des uid différents dans des conteneurs distincts —
        // puissent tous deux écrire/lire dans la même racine.
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return false;
        }
        // Same filesystem → atomic rename if we own the temp; otherwise copy.
        if (@rename($path, $dest)) {
            @chmod($dest, 0644);
            return true;
        }
        if (!@copy($path, $dest)) {
            return false;
        }
        @chmod($dest, 0644);
        return true;
    }

    public function presignedGetUrl(string $key, int $expirySeconds, array $responseHeaders = []): ?string
    {
        // Local disk has no signed URLs — the app streams the file instead.
        return null;
    }

    public function deletePrefix(string $prefix): int
    {
        $target = rtrim($this->path($prefix), '/');
        if (!is_dir($target)) {
            // Maybe an exact-file prefix rather than a directory.
            if (is_file($target)) {
                return @unlink($target) ? 1 : 0;
            }
            return 0;
        }
        return $this->rmtree($target);
    }

    private function rmtree(string $dir): int
    {
        $count = 0;
        $items = @scandir($dir);
        if ($items === false) {
            return 0;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $count += $this->rmtree($path);
            } elseif (@unlink($path)) {
                $count++;
            }
        }
        @rmdir($dir);
        return $count;
    }

    public function kind(): string
    {
        return 'local';
    }
}
