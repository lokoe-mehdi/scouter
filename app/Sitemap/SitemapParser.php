<?php

namespace App\Sitemap;

/**
 * Fetches and parses XML/TXT sitemaps (including gzipped and sitemap-index files).
 *
 * Safety limits prevent a malicious or pathological sitemap from exploding the crawl:
 *  - MAX_INDEX_DEPTH : how deeply we recurse into sitemap-index files
 *  - MAX_CHILD_SITEMAPS : total number of sitemap files we will fetch
 *  - MAX_URLS : total number of URLs we will return
 */
class SitemapParser
{
    const MAX_INDEX_DEPTH = 2;
    const MAX_CHILD_SITEMAPS = 50;
    const MAX_URLS = 50000;
    const FETCH_TIMEOUT = 30;
    const MAX_BODY_BYTES = 50 * 1024 * 1024; // 50 MB

    private SitemapResult $result;
    private int $sitemapsFetched = 0;

    public function parse(array $sitemapUrls): SitemapResult
    {
        $this->result = new SitemapResult();
        $this->sitemapsFetched = 0;

        $seen = [];
        $urlSet = [];

        foreach ($sitemapUrls as $url) {
            $url = trim($url);
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $this->visit($url, 0, $urlSet);
        }

        $this->result->urls = array_keys($urlSet);
        return $this->result;
    }

    private function visit(string $url, int $depth, array &$urlSet): void
    {
        if ($this->sitemapsFetched >= self::MAX_CHILD_SITEMAPS) {
            $this->result->truncated = true;
            return;
        }
        if (count($urlSet) >= self::MAX_URLS) {
            $this->result->truncated = true;
            return;
        }

        $this->sitemapsFetched++;

        $body = $this->fetch($url);
        if ($body === null) {
            return;
        }

        // Transparent gzip decoding (some hosts serve .xml.gz as octet-stream without
        // Content-Encoding, so cURL's automatic decompression doesn't kick in).
        if (substr($body, 0, 2) === "\x1f\x8b") {
            $decoded = @gzdecode($body);
            if ($decoded === false) {
                $this->result->errors[] = "$url (gzip decode failed)";
                return;
            }
            $body = $decoded;
        }

        $this->result->sitemapsVisited[] = $url;

        // Plain-text sitemap: one URL per line
        if ($this->looksLikeText($url, $body)) {
            foreach (preg_split('/\r?\n/', $body) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (!preg_match('#^https?://#i', $line)) continue;
                $urlSet[$line] = true;
                if (count($urlSet) >= self::MAX_URLS) {
                    $this->result->truncated = true;
                    return;
                }
            }
            return;
        }

        // XML sitemap or sitemap-index. libxml_use_internal_errors prevents
        // libxml warnings from leaking to stderr when the body is malformed.
        $prevInternalErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prevInternalErrors);
        if ($xml === false) {
            $this->result->errors[] = "$url (malformed XML)";
            return;
        }

        $rootName = strtolower($xml->getName());

        if ($rootName === 'sitemapindex') {
            if ($depth >= self::MAX_INDEX_DEPTH) {
                $this->result->errors[] = "$url (max index depth reached)";
                return;
            }
            foreach ($xml->sitemap as $entry) {
                $childUrl = trim((string)$entry->loc);
                if ($childUrl === '') continue;
                $this->visit($childUrl, $depth + 1, $urlSet);
                if (count($urlSet) >= self::MAX_URLS || $this->sitemapsFetched >= self::MAX_CHILD_SITEMAPS) {
                    return;
                }
            }
            return;
        }

        if ($rootName === 'urlset') {
            foreach ($xml->url as $entry) {
                $loc = trim((string)$entry->loc);
                if ($loc === '') continue;
                $urlSet[$loc] = true;
                if (count($urlSet) >= self::MAX_URLS) {
                    $this->result->truncated = true;
                    return;
                }
            }
            return;
        }

        $this->result->errors[] = "$url (unknown root element <$rootName>)";
    }

    /**
     * Fetch a URL and return its (decompressed) body, or null on failure.
     * Protected so tests can subclass and stub out the network call.
     */
    protected function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '', // let cURL handle gzip/deflate via Content-Encoding
            CURLOPT_USERAGENT => 'Scouter/SitemapFetcher (+https://lokoe.fr/scouter)',
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            $this->result->errors[] = "$url ($error)";
            return null;
        }
        $httpCode = (int)($info['http_code'] ?? 0);
        if ($httpCode < 200 || $httpCode >= 400) {
            $this->result->errors[] = "$url (HTTP $httpCode)";
            return null;
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            $this->result->errors[] = "$url (body exceeds " . self::MAX_BODY_BYTES . " bytes)";
            return null;
        }

        return $body;
    }

    private function looksLikeText(string $url, string $body): bool
    {
        // .txt extension or no XML prolog and first non-whitespace char isn't '<'
        if (preg_match('/\.txt(\?|$)/i', $url)) {
            return true;
        }
        $trimmed = ltrim($body);
        if ($trimmed === '') return false;
        return $trimmed[0] !== '<';
    }
}
