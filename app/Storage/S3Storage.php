<?php

namespace App\Storage;

class S3Storage implements StorageInterface
{
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private string $endpoint;
    private string $region;
    private bool $pathStyle;
    private string $prefix;

    public function __construct(
        string $bucket,
        string $accessKey,
        string $secretKey,
        string $endpoint = '',
        string $region = 'us-east-1',
        bool $pathStyle = false,
        string $prefix = ''
    ) {
        $this->bucket = $bucket;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->endpoint = rtrim($endpoint, '/');
        $this->region = $region;
        $this->pathStyle = $pathStyle;
        $this->prefix = $prefix !== '' ? rtrim($prefix, '/') . '/' : '';
    }

    public function kind(): string
    {
        return 's3';
    }

    private function fullKey(string $key): string
    {
        return $this->prefix . ltrim($key, '/');
    }

    private function baseUrl(): string
    {
        if ($this->endpoint !== '') {
            if ($this->pathStyle) {
                return $this->endpoint . '/' . $this->bucket;
            }
            $parsed = parse_url($this->endpoint);
            return ($parsed['scheme'] ?? 'https') . '://' . $this->bucket . '.' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        }
        return 'https://' . $this->bucket . '.s3.' . $this->region . '.amazonaws.com';
    }

    public function get(string $key): ?string
    {
        $url = $this->baseUrl() . '/' . $this->fullKey($key);
        $headers = $this->signRequest('GET', $this->fullKey($key));
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => $this->buildHeaderString($headers),
            'ignore_errors' => true,
        ]]);
        $content = @file_get_contents($url, false, $ctx);
        if ($content === false) {
            return null;
        }
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $h, $m) && (int)$m[1] >= 400) {
                return null;
            }
        }
        return $content;
    }

    public function put(string $key, string $data): bool
    {
        $url = $this->baseUrl() . '/' . $this->fullKey($key);
        $headers = $this->signRequest('PUT', $this->fullKey($key), $data);
        $ctx = stream_context_create(['http' => [
            'method' => 'PUT',
            'header' => $this->buildHeaderString($headers),
            'content' => $data,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($url, false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $h, $m)) {
                return (int)$m[1] < 400;
            }
        }
        return false;
    }

    public function putFile(string $key, string $localPath, string $contentType = 'application/octet-stream'): bool
    {
        $data = file_get_contents($localPath);
        if ($data === false) {
            return false;
        }
        $result = $this->put($key, $data);
        if ($result) {
            @unlink($localPath);
        }
        return $result;
    }

    public function delete(string $key): bool
    {
        $url = $this->baseUrl() . '/' . $this->fullKey($key);
        $headers = $this->signRequest('DELETE', $this->fullKey($key));
        $ctx = stream_context_create(['http' => [
            'method' => 'DELETE',
            'header' => $this->buildHeaderString($headers),
            'ignore_errors' => true,
        ]]);
        @file_get_contents($url, false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $h, $m)) {
                return (int)$m[1] < 400 || (int)$m[1] === 404;
            }
        }
        return false;
    }

    public function deletePrefix(string $prefix): int
    {
        return 0;
    }

    public function presignedGetUrl(string $key, int $expireSeconds, array $queryParams = []): ?string
    {
        $fullKey = $this->fullKey($key);
        $now = time();
        $date = gmdate('Ymd', $now);
        $timestamp = gmdate('Ymd\THis\Z', $now);
        $credential = $this->accessKey . '/' . $date . '/' . $this->region . '/s3/aws4_request';

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $timestamp,
            'X-Amz-Expires' => (string)$expireSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        foreach ($queryParams as $k => $v) {
            $query[$k] = $v;
        }
        ksort($query);

        $host = $this->getHost();
        $canonicalUri = '/' . $fullKey;
        $canonicalQueryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $canonicalHeaders = 'host:' . $host . "\n";
        $signedHeaders = 'host';
        $canonicalRequest = "GET\n{$canonicalUri}\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\nUNSIGNED-PAYLOAD";

        $scope = $date . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $signingKey = $this->getSignatureKey($date);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $query['X-Amz-Signature'] = $signature;
        return $this->baseUrl() . '/' . $fullKey . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function getHost(): string
    {
        if ($this->endpoint !== '') {
            $parsed = parse_url($this->endpoint);
            $host = $parsed['host'] ?? '';
            if (isset($parsed['port'])) {
                $host .= ':' . $parsed['port'];
            }
            if ($this->pathStyle) {
                return $host;
            }
            return $this->bucket . '.' . $host;
        }
        return $this->bucket . '.s3.' . $this->region . '.amazonaws.com';
    }

    private function signRequest(string $method, string $key, string $payload = ''): array
    {
        $now = time();
        $date = gmdate('Ymd', $now);
        $timestamp = gmdate('Ymd\THis\Z', $now);
        $host = $this->getHost();
        $payloadHash = hash('sha256', $payload);

        $headers = [
            'Host' => $host,
            'x-amz-date' => $timestamp,
            'x-amz-content-sha256' => $payloadHash,
        ];

        $canonicalUri = '/' . $key;
        $canonicalHeaders = '';
        $signedHeadersList = [];
        ksort($headers);
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeadersList[] = strtolower($k);
        }
        $signedHeaders = implode(';', $signedHeadersList);

        $canonicalRequest = "{$method}\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $scope = $date . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $signingKey = $this->getSignatureKey($date);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        return $headers;
    }

    private function getSignatureKey(string $date): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function buildHeaderString(array $headers): string
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        return implode("\r\n", $lines);
    }
}
