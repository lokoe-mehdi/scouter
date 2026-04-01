<?php

namespace App\Analysis;

use App\Database\PostgresDatabase;
use PDO;

class SitemapProcessor
{
    private PDO $pdo;
    private int $crawlId;

    public function __construct(int $crawlId)
    {
        $this->pdo = PostgresDatabase::getInstance()->getConnection();
        $this->crawlId = $crawlId;
    }

    /**
     * Main entry point to process all sitemaps for the crawl.
     * It fetches the sitemap URLs from the crawl's configuration.
     */
    public function process(): bool
    {
        $sitemapUrls = $this->getSitemapUrlsFromConfig();
        if (empty($sitemapUrls)) {
            echo "No sitemap URLs configured for crawl ID: {$this->crawlId}. Skipping.\n";
            return true;
        }

        echo "Starting sitemap processing for crawl ID: {$this->crawlId}\n";

        foreach ($sitemapUrls as $sitemapUrl) {
            $this->fetchAndParseSitemap(trim($sitemapUrl));
        }

        echo "Sitemap processing finished.\n";
        return true;
    }

    /**
     * Fetches and parses a single sitemap (or sitemap index).
     */
    private function fetchAndParseSitemap(string $sitemapUrl): void
    {
        echo "Fetching sitemap: $sitemapUrl\n";
        $xmlContent = $this->fetchUrlContent($sitemapUrl);
        if (!$xmlContent) {
            echo "Failed to fetch sitemap content from: $sitemapUrl\n";
            return;
        }

        try {
            $xml = new \SimpleXMLElement($xmlContent);
            $isSitemapIndex = isset($xml->sitemap);
            $isUrlSet = isset($xml->url);

            if ($isSitemapIndex) {
                // It's a sitemap index, parse it recursively
                foreach ($xml->sitemap as $sitemap) {
                    $this->fetchAndParseSitemap((string)$sitemap->loc);
                }
            } elseif ($isUrlSet) {
                // It's a regular sitemap with URLs
                $urlsToCheck = [];
                foreach ($xml->url as $urlEntry) {
                    $urlsToCheck[] = (string)$urlEntry->loc;
                }
                $this->checkAndStoreUrls($urlsToCheck, $sitemapUrl);
            }
        } catch (\Exception $e) {
            echo "Error parsing XML for sitemap: $sitemapUrl. Error: {$e->getMessage()}\n";
        }
    }

    /**
     * Checks a batch of URLs status and stores them in the database.
     */
    private function checkAndStoreUrls(array $urls, string $sourceSitemap): void
    {
        $dataToInsert = [];
        foreach ($urls as $url) {
            $statusInfo = $this->checkUrlStatus($url);
            $dataToInsert[] = [
                'crawl_id' => $this->crawlId,
                'url' => $url,
                'source_sitemap' => $sourceSitemap,
                'http_status' => $statusInfo['http_status'],
                'is_indexable' => $statusInfo['is_indexable']
            ];
        }

        if (empty($dataToInsert)) {
            return;
        }

        // Bulk insert for efficiency
        $stmt = $this->pdo->prepare(
            'INSERT INTO sitemap_urls (crawl_id, url, source_sitemap, http_status, is_indexable) VALUES (:crawl_id, :url, :source_sitemap, :http_status, :is_indexable)'
        );

        $this->pdo->beginTransaction();
        foreach ($dataToInsert as $row) {
            $stmt->execute($row);
        }
        $this->pdo->commit();
        echo "Stored " . count($dataToInsert) . " URLs from sitemap: $sourceSitemap\n";
    }

    /**
     * Fetches content of a given URL using cURL.
     */
    private function fetchUrlContent(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Scouter-Sitemap-Processor/1.0');
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode == 200) ? $content : null;
    }
    
    /**
     * Performs a HEAD request to get URL status and checks for noindex header.
     */
    private function checkUrlStatus(string $url): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
        curl_setopt($ch, CURLOPT_HEADER, true); // Get headers
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $headers = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $isIndexable = true;
        if ($headers && preg_match('/x-robots-tag:.*noindex/i', $headers)) {
            $isIndexable = false;
        }

        return [
            'http_status' => $httpStatus,
            'is_indexable' => $isIndexable,
        ];
    }
    
    /**
     * Retrieves sitemap URLs from the crawl configuration in the database.
     */
    private function getSitemapUrlsFromConfig(): array
    {
        $stmt = $this->pdo->prepare('SELECT sitemap_urls FROM crawls WHERE id = :crawl_id');
        $stmt->execute(['crawl_id' => $this->crawlId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && !empty($result['sitemap_urls'])) {
            // URLs are stored as a comma-separated string
            return explode(',', $result['sitemap_urls']);
        }

        return [];
    }
}
