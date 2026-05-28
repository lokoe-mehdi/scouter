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
    private string $cdnUrl;

    public function __construct(
        string $bucket,
        string $accessKey,
        string $secretKey,
        string $endpoint = '',
        string $region = 'us-east-1',
        bool $pathStyle = false,
        string $cdnUrl = ''
    ) {
        $this->bucket = $bucket;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->endpoint = rtrim($endpoint, '/');
        $this->region = $region;
        $this->pathStyle = $pathStyle;
        $this->cdnUrl = rtrim($cdnUrl, '/');
    }

    public function kind(): string
    {
        return 's3';
    }

    private function getHost(): string
    {
        if ($this->endpoint) {
            $parsed = parse_url($this->endpoint);
            return $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        }
        if ($this->pathStyle) {
            return "s3.{$this->region}.amazonaws.com";
        }
        return "{$this->bucket}.s3.{$this->region}.amazonaws.com";
    }

    private function getBaseUrl(): string
    {
        if ($this->endpoint) {
            return $this->pathStyle
                ? "{$this->endpoint}/{$this->bucket}"
                : "{$this->endpoint}";
        }
        return $this->pathStyle
            ? "https://s3.{$this->region}.amazonaws.com/{$this->bucket}"
            : "https://{$this->bucket}.s3.{$this->region}.amazonaws.com";
    }

    public function get(string $key): ?string
    {
        $url = $this->getBaseUrl() . '/' . $key;
        $date = gmdate('Ymd\THis\Z');
        $dateShort = gmdate('Ymd');

        $headers = $this->signRequest('GET', $key, '', $date, $dateShort);
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => $this->headersToString($headers),
            'ignore_errors' => true,
        ]]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            return null;
        }
        return $result;
    }

    public function put(string $key, string $data): bool
    {
        return $this->putWithContent($key, $data, 'application/octet-stream');
    }

    public function putFile(string $key, string $localPath, string $contentType = ''): bool
    {
        $data = file_get_contents($localPath);
        if ($data === false) {
            return false;
        }
        $result = $this->putWithContent($key, $data, $contentType ?: 'application/octet-stream');
        if ($result) {
            @unlink($localPath);
        }
        return $result;
    }

    private function putWithContent(string $key, string $data, string $contentType): bool
    {
        $url = $this->getBaseUrl() . '/' . $key;
        $date = gmdate('Ymd\THis\Z');
        $dateShort = gmdate('Ymd');

        $headers = $this->signRequest('PUT', $key, $data, $date, $dateShort, $contentType);
        $ctx = stream_context_create(['http' => [
            'method' => 'PUT',
            'header' => $this->headersToString($headers),
            'content' => $data,
            'ignore_errors' => true,
        ]]);

        $result = @file_get_contents($url, false, $ctx);
        return $result !== false;
    }

    public function delete(string $key): bool
    {
        $url = $this->getBaseUrl() . '/' . $key;
        $date = gmdate('Ymd\THis\Z');
        $dateShort = gmdate('Ymd');

        $headers = $this->signRequest('DELETE', $key, '', $date, $dateShort);
        $ctx = stream_context_create(['http' => [
            'method' => 'DELETE',
            'header' => $this->headersToString($headers),
            'ignore_errors' => true,
        ]]);

        @file_get_contents($url, false, $ctx);
        return true;
    }

    public function deletePrefix(string $prefix): int
    {
        return 0;
    }

    public function presignedGetUrl(string $key, int $ttl, array $params = []): ?string
    {
        $date = gmdate('Ymd\THis\Z');
        $dateShort = gmdate('Ymd');
        $host = $this->getHost();
        $credential = "{$this->accessKey}/{$dateShort}/{$this->region}/s3/aws4_request";

        $queryParams = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $date,
            'X-Amz-Expires' => (string)$ttl,
            'X-Amz-SignedHeaders' => 'host',
        ];
        foreach ($params as $k => $v) {
            $queryParams[$k] = $v;
        }
        ksort($queryParams);

        $canonicalQueryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        $path = $this->pathStyle ? "/{$this->bucket}/{$key}" : "/{$key}";
        $canonicalRequest = implode("\n", [
            'GET',
            $path,
            $canonicalQueryString,
            "host:{$host}",
            '',
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $date,
            "{$dateShort}/{$this->region}/s3/aws4_request",
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSigningKey($dateShort);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $baseUrl = $this->endpoint
            ? ($this->pathStyle ? "{$this->endpoint}/{$this->bucket}" : $this->endpoint)
            : ($this->pathStyle
                ? "https://s3.{$this->region}.amazonaws.com/{$this->bucket}"
                : "https://{$this->bucket}.s3.{$this->region}.amazonaws.com");

        return "{$baseUrl}/{$key}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }

    private function signRequest(
        string $method,
        string $key,
        string $payload,
        string $date,
        string $dateShort,
        string $contentType = ''
    ): array {
        $host = $this->getHost();
        $payloadHash = hash('sha256', $payload);
        $path = $this->pathStyle ? "/{$this->bucket}/{$key}" : "/{$key}";

        $headers = [
            'Host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $date,
        ];
        if ($contentType) {
            $headers['Content-Type'] = $contentType;
        }

        ksort($headers);
        $signedHeaders = implode(';', array_map('strtolower', array_keys($headers)));
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
        }

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $date,
            "{$dateShort}/{$this->region}/s3/aws4_request",
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSigningKey($dateShort);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $credential = "{$this->accessKey}/{$dateShort}/{$this->region}/s3/aws4_request";
        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$credential}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return $headers;
    }

    private function getSigningKey(string $dateShort): string
    {
        $kDate = hash_hmac('sha256', $dateShort, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function headersToString(array $headers): string
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        return implode("\r\n", $lines);
    }
}
