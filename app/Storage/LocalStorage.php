<?php

namespace App\Storage;

class LocalStorage implements StorageInterface
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function kind(): string
    {
        return 'local';
    }

    public function get(string $key): ?string
    {
        $path = $this->root . '/' . ltrim($key, '/');
        if (!file_exists($path)) {
            return null;
        }
        $content = file_get_contents($path);
        return $content === false ? null : $content;
    }

    public function put(string $key, string $data): bool
    {
        $path = $this->root . '/' . ltrim($key, '/');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($path, $data) !== false;
    }

    public function putFile(string $key, string $localPath, string $contentType = 'application/octet-stream'): bool
    {
        $destPath = $this->root . '/' . ltrim($key, '/');
        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return rename($localPath, $destPath);
    }

    public function delete(string $key): bool
    {
        $path = $this->root . '/' . ltrim($key, '/');
        if (file_exists($path)) {
            return unlink($path);
        }
        return true;
    }

    public function deletePrefix(string $prefix): int
    {
        $dir = $this->root . '/' . rtrim($prefix, '/');
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $files = glob($dir . '/*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file) && unlink($file)) {
                    $count++;
                }
            }
        }
        @rmdir($dir);
        return $count;
    }

    public function presignedGetUrl(string $key, int $expireSeconds, array $queryParams = []): ?string
    {
        return null;
    }
}
