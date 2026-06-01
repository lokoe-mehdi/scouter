<?php

namespace App\Storage;

/**
 * S3-compatible blob store (AWS S3, Cloudflare R2, MinIO, Backblaze B2…), with a
 * self-contained AWS Signature V4 signer over cURL — no SDK dependency, since we
 * only need GET / PUT / DELETE / ListObjectsV2.
 *
 * Addressing:
 *  - No endpoint → AWS, virtual-hosted style: {bucket}.s3.{region}.amazonaws.com
 *  - Custom endpoint (R2/MinIO/B2) → set S3_ENDPOINT; flip S3_USE_PATH_STYLE=true
 *    when the provider needs path-style ({host}/{bucket}/{key}), as MinIO does.
 *
 * @package    Scouter
 * @subpackage Storage
 */
class S3Storage implements StorageInterface
{
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private string $region;
    private string $scheme;   // http|https
    private string $host;     // bare host[:port], no scheme
    private bool $pathStyle;
    private string $prefix;   // optional key namespace, no leading/trailing slash

    public function __construct(
        string $bucket,
        string $accessKey,
        string $secretKey,
        string $endpoint = '',
        string $region = '',
        bool $pathStyle = false,
        string $prefix = ''
    ) {
        $this->bucket = $bucket;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->region = $region !== '' ? $region : 'us-east-1';
        $this->pathStyle = $pathStyle;
        $this->prefix = trim($prefix, '/');

        if ($endpoint === '') {
            $this->scheme = 'https';
            $this->host = 's3.' . $this->region . '.amazonaws.com';
        } else {
            if (str_starts_with($endpoint, 'http://')) {
                $this->scheme = 'http';
                $endpoint = substr($endpoint, 7);
            } elseif (str_starts_with($endpoint, 'https://')) {
                $this->scheme = 'https';
                $endpoint = substr($endpoint, 8);
            } else {
                $this->scheme = 'https';
            }
            $this->host = rtrim($endpoint, '/');
        }
    }

    /** Apply the optional prefix to a logical key. */
    private function objectKey(string $key): string
    {
        $key = ltrim($key, '/');
        return $this->prefix === '' ? $key : $this->prefix . '/' . $key;
    }

    public function get(string $key): ?string
    {
        $res = $this->request('GET', $this->objectKey($key));
        if ($res['status'] === 200) {
            return $res['body'];
        }
        // 404 (missing object) is an expected miss, not an error.
        return null;
    }

    public function put(string $key, string $data): bool
    {
        $res = $this->request('PUT', $this->objectKey($key), [], $data, 'application/gzip');
        return $res['status'] >= 200 && $res['status'] < 300;
    }

    public function putFile(string $key, string $path, string $contentType = ''): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $objectKey = $this->objectKey($key);
        $type = $contentType ?: 'application/octet-stream';
        $size = filesize($path);

        // Above S3's 5 GB single-PUT limit (and to stay resilient on multi-GB
        // exports), upload in parts. Below, a single streamed PUT (CURLOPT_INFILE)
        // keeps it out of PHP memory.
        if ($size !== false && $size > self::MULTIPART_THRESHOLD) {
            return $this->multipartUpload($objectKey, $path, $type);
        }
        $res = $this->request('PUT', $objectKey, [], '', $type, $path);
        return $res['status'] >= 200 && $res['status'] < 300;
    }

    public function presignedGetUrl(string $key, int $expirySeconds, array $responseHeaders = []): ?string
    {
        $objectPath = $this->objectKey($key);
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $expirySeconds = max(1, min($expirySeconds, 604800)); // SigV4 caps at 7 days

        if ($this->pathStyle) {
            $hostHeader = $this->host;
            $canonicalPath = '/' . $this->bucket . '/' . $this->encodeKey($objectPath);
        } else {
            $hostHeader = $this->bucket . '.' . $this->host;
            $canonicalPath = '/' . $this->encodeKey($objectPath);
        }

        $scope = $date . '/' . $this->region . '/s3/aws4_request';
        $query = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $this->accessKey . '/' . $scope,
            'X-Amz-Date'          => $now,
            'X-Amz-Expires'       => (string)$expirySeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        // e.g. response-content-disposition=attachment; filename="…" → S3 returns
        // the object as a named download.
        foreach ($responseHeaders as $k => $v) {
            $query[$k] = $v;
        }
        ksort($query);
        $canonicalQueryParts = [];
        foreach ($query as $k => $v) {
            $canonicalQueryParts[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $canonicalQuery = implode('&', $canonicalQueryParts);

        $canonicalRequest = "GET\n"
            . $canonicalPath . "\n"
            . $canonicalQuery . "\n"
            . 'host:' . $hostHeader . "\n\n"
            . "host\n"
            . 'UNSIGNED-PAYLOAD';

        $stringToSign = "AWS4-HMAC-SHA256\n"
            . $now . "\n"
            . $scope . "\n"
            . hash('sha256', $canonicalRequest);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));

        return $this->scheme . '://' . $hostHeader . $canonicalPath . '?'
            . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    public function deletePrefix(string $prefix): int
    {
        $deleted = 0;
        $token = null;
        $full = $this->objectKey($prefix);
        do {
            $query = ['list-type' => '2', 'prefix' => $full];
            if ($token !== null && $token !== '') {
                $query['continuation-token'] = $token;
            }
            // List against the bucket root (empty object path) with query params.
            $res = $this->request('GET', '', $query);
            if ($res['status'] !== 200) {
                break;
            }
            $xml = @simplexml_load_string($res['body']);
            if ($xml === false) {
                break;
            }
            foreach ($xml->Contents as $obj) {
                $objKey = (string)$obj->Key;
                if ($objKey === '') {
                    continue;
                }
                // $objKey already includes the prefix namespace; delete it raw
                // (bypass objectKey() which would double-prefix it).
                $del = $this->request('DELETE', $objKey);
                if ($del['status'] >= 200 && $del['status'] < 300) {
                    $deleted++;
                }
            }
            $token = ((string)$xml->IsTruncated === 'true') ? (string)$xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');

        return $deleted;
    }

    public function kind(): string
    {
        return 's3';
    }

    /**
     * Perform one signed request. $objectPath is the S3 object key WITHOUT the
     * bucket (path-style prepends the bucket); pass '' for bucket-level calls
     * (e.g. ListObjectsV2). Returns ['status'=>int, 'body'=>string].
     *
     * @param array<string,string> $query
     * @return array{status:int, body:string}
     */
    private function request(string $method, string $objectPath, array $query = [], string $body = '', string $contentType = '', ?string $filePath = null, bool $returnHeaders = false): array
    {
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        // hash_file streams the file → constant memory even for huge uploads.
        $payloadHash = $filePath !== null ? hash_file('sha256', $filePath) : hash('sha256', $body);

        // Canonical URI: virtual-hosted puts the bucket in the host; path-style
        // puts it as the first path segment. Each key segment is RFC3986-encoded
        // but '/' separators are preserved.
        if ($this->pathStyle) {
            $hostHeader = $this->host;
            $canonicalPath = '/' . $this->bucket . ($objectPath !== '' ? '/' . $this->encodeKey($objectPath) : '');
        } else {
            $hostHeader = $this->bucket . '.' . $this->host;
            $canonicalPath = '/' . ($objectPath !== '' ? $this->encodeKey($objectPath) : '');
        }

        // Canonical query string: sorted, each part RFC3986-encoded.
        ksort($query);
        $canonicalQueryParts = [];
        foreach ($query as $k => $v) {
            $canonicalQueryParts[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $canonicalQuery = implode('&', $canonicalQueryParts);

        // Signed headers (lowercased, sorted): host, x-amz-content-sha256, x-amz-date.
        $headers = [
            'host' => $hostHeader,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $now,
        ];
        if ($contentType !== '') {
            $headers['content-type'] = $contentType;
        }
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $hk => $hv) {
            $canonicalHeaders .= $hk . ':' . trim($hv) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = $method . "\n"
            . $canonicalPath . "\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $scope = $date . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n"
            . $now . "\n"
            . $scope . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = $this->signingKey($date);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = 'AWS4-HMAC-SHA256 '
            . 'Credential=' . $this->accessKey . '/' . $scope . ', '
            . 'SignedHeaders=' . $signedHeaders . ', '
            . 'Signature=' . $signature;

        // Build the wire request.
        $url = $this->scheme . '://' . $hostHeader . $canonicalPath;
        if ($canonicalQuery !== '') {
            $url .= '?' . $canonicalQuery;
        }
        $curlHeaders = ['Authorization: ' . $authorization];
        foreach ($headers as $hk => $hv) {
            if ($hk === 'host') {
                continue; // cURL sets Host from the URL
            }
            $curlHeaders[] = $this->headerName($hk) . ': ' . $hv;
        }

        // Disable 100-continue (some S3-compatibles reject the expectation).
        $curlHeaders[] = 'Expect:';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 1800, // large multi-part uploads
            CURLOPT_HEADER => $returnHeaders,
        ]);
        $fh = null;
        if ($filePath !== null) {
            // Streamed upload: cURL reads from the file handle, no in-memory copy.
            $fh = fopen($filePath, 'rb');
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $fh);
            curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filePath));
        } elseif ($method === 'PUT' || $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($fh !== null) {
            fclose($fh);
        }

        $rawStr = $raw === false ? '' : (string)$raw;
        $headers = [];
        $respBody = $rawStr;
        if ($returnHeaders) {
            $headBlock = substr($rawStr, 0, $headerSize);
            $respBody = substr($rawStr, $headerSize);
            foreach (explode("\r\n", $headBlock) as $line) {
                if (strpos($line, ':') !== false) {
                    [$hk, $hv] = explode(':', $line, 2);
                    $headers[strtolower(trim($hk))] = trim($hv);
                }
            }
        }

        return [
            'status'  => $status,
            'body'    => $respBody,
            'headers' => $headers,
        ];
    }

    // -------------------------------------------------------------------------
    // Multipart upload (objects above S3's 5 GB single-PUT limit / large CSVs)
    // -------------------------------------------------------------------------

    /** Files larger than this are uploaded via multipart (S3 single-PUT caps at 5 GB). */
    private const MULTIPART_THRESHOLD = 64 * 1024 * 1024;   // 64 MB
    /** Part size: ≥5 MB (S3 minimum), small enough to stay well within worker RAM. */
    private const PART_SIZE = 32 * 1024 * 1024;             // 32 MB

    /**
     * Upload $path under $objectKey (already prefixed) using S3 multipart:
     * initiate → upload parts → complete (abort on any failure). Returns success.
     */
    private function multipartUpload(string $objectKey, string $path, string $contentType): bool
    {
        // 1) Initiate.
        $init = $this->request('POST', $objectKey, ['uploads' => ''], '', $contentType);
        if ($init['status'] < 200 || $init['status'] >= 300) {
            return false;
        }
        if (!preg_match('#<UploadId>(.*?)</UploadId>#s', (string)$init['body'], $m)) {
            return false;
        }
        $uploadId = $m[1];

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }

        $parts = [];
        $partNo = 0;
        try {
            while (!feof($fh)) {
                $chunk = $this->readChunk($fh, self::PART_SIZE);
                if ($chunk === '') {
                    break;
                }
                $partNo++;
                $res = $this->request('PUT', $objectKey, ['partNumber' => $partNo, 'uploadId' => $uploadId], $chunk, '', null, true);
                if ($res['status'] < 200 || $res['status'] >= 300) {
                    throw new \RuntimeException('part ' . $partNo . ' failed (' . $res['status'] . ')');
                }
                $etag = $res['headers']['etag'] ?? '';
                if ($etag === '') {
                    throw new \RuntimeException('part ' . $partNo . ' missing ETag');
                }
                $parts[] = ['n' => $partNo, 'etag' => $etag];
            }
        } catch (\Throwable $e) {
            fclose($fh);
            $this->request('DELETE', $objectKey, ['uploadId' => $uploadId]); // abort
            return false;
        }
        fclose($fh);

        if (empty($parts)) {
            $this->request('DELETE', $objectKey, ['uploadId' => $uploadId]);
            return false;
        }

        // 3) Complete.
        $xml = '<CompleteMultipartUpload>';
        foreach ($parts as $p) {
            $xml .= '<Part><PartNumber>' . $p['n'] . '</PartNumber><ETag>' . $p['etag'] . '</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';

        $done = $this->request('POST', $objectKey, ['uploadId' => $uploadId], $xml, 'application/xml');
        // CompleteMultipartUpload can return HTTP 200 with an <Error> in the body.
        if ($done['status'] < 200 || $done['status'] >= 300 || strpos((string)$done['body'], '<Error>') !== false) {
            $this->request('DELETE', $objectKey, ['uploadId' => $uploadId]);
            return false;
        }
        return true;
    }

    /** Read exactly $size bytes (or until EOF) from $fh, looping over short reads. */
    private function readChunk($fh, int $size): string
    {
        $buf = '';
        while (strlen($buf) < $size && !feof($fh)) {
            $piece = fread($fh, $size - strlen($buf));
            if ($piece === false || $piece === '') {
                break;
            }
            $buf .= $piece;
        }
        return $buf;
    }

    /** RFC3986-encode an object key, preserving '/' separators. */
    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    /** Title-case a canonical header name for the wire (x-amz-date → X-Amz-Date). */
    private function headerName(string $lower): string
    {
        return implode('-', array_map('ucfirst', explode('-', $lower)));
    }

    /** Derive the SigV4 signing key for a given date (yyyymmdd). */
    private function signingKey(string $date): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
