<?php

namespace App\Storage;

class LocalStorage implements StorageInterface
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }
    }

    public function kind(): string
    {
        return 'local';
    }

    public function get(string $key): ?string
    {
        $path = $this->root . '/' . $key;
        if (!file_exists($path)) {
            return null;
        }
        return file_get_contents($path);
    }

    public function put(string $key, string $data): bool
    {
        $path = $this->root . '/' . $key;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($path, $data) !== false;
    }

    public function putFile(string $key, string $localPath, string $contentType = ''): bool
    {
        $path = $this->root . '/' . $key;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return rename($localPath, $path);
    }

    public function delete(string $key): bool
    {
        $path = $this->root . '/' . $key;
        if (!file_exists($path)) {
            return true;
        }
        return unlink($path);
    }

    public function deletePrefix(string $prefix): int
    {
        $dir = $this->root . '/' . rtrim($prefix, '/');
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file) && unlink($file)) {
                $count++;
            }
        }
        @rmdir($dir);
        return $count;
    }

    public function presignedGetUrl(string $key, int $ttl, array $params = []): ?string
    {
        return null;
    }
}
