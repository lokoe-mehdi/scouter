<?php

namespace App\Storage;

interface StorageInterface
{
    public function kind(): string;
    public function get(string $key): ?string;
    public function put(string $key, string $data): bool;
    public function putFile(string $key, string $localPath, string $contentType = 'application/octet-stream'): bool;
    public function delete(string $key): bool;
    public function deletePrefix(string $prefix): int;
    public function presignedGetUrl(string $key, int $expireSeconds, array $queryParams = []): ?string;
}
