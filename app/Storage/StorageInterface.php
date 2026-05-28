<?php

namespace App\Storage;

interface StorageInterface
{
    public function kind(): string;
    public function get(string $key): ?string;
    public function put(string $key, string $data): bool;
    public function putFile(string $key, string $localPath, string $contentType = ''): bool;
    public function delete(string $key): bool;
    public function deletePrefix(string $prefix): int;
    public function presignedGetUrl(string $key, int $ttl, array $params = []): ?string;
}
