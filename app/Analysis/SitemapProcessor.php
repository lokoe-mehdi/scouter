<?php

namespace App\Analysis;

use App\Database\PostgresDatabase;
use PDO;

/**
 * Sitemap Analysis Processor
 *
 * Processes sitemap XML files after a crawl completes:
 * - Parses sitemap index and regular sitemaps recursively
 * - Extracts hreflang, lastmod, changefreq, priority
 * - Cross-references sitemap URLs with crawled pages
 * - Identifies orphan URLs (in sitemap but not crawled, and vice-versa)
 * - Flags non-indexable URLs present in sitemaps
 *
 * @package    Scouter
 * @subpackage Analysis
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class SitemapProcessor
{
    private PDO $pdo;
    private int $crawlId;
    private string $userAgent;

    /** @var array Namespace prefixes for hreflang parsing */
    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';

    public function __construct(int $crawlId, string $userAgent = 'Scouter/2.0 (Sitemap Processor)')
    {
        $this->pdo = PostgresDatabase::getInstance()->getConnection();
        $this->crawlId = $crawlId;
        $this->userAgent = $userAgent;
    }

    /**
     * Main entry point — processes all sitemaps configured for this crawl.
     */
    public function process(): bool
    {
        $sitemapUrls = $this->getSitemapUrlsFromConfig();
        if (empty($sitemapUrls)) {
            echo "  No sitemap URLs configured for crawl #{$this->crawlId}. Skipping.\n";
            return true;
        }

        echo "\r \033[32m Sitemap analysis \033[0m : \033[36mfetching sitemaps...\033[0m                    ";
        flush();

        // Step 1: Parse all sitemaps and store URLs
        $totalUrls = 0;
        foreach ($sitemapUrls as $sitemapUrl) {
            $totalUrls += $this->fetchAndParseSitemap(trim($sitemapUrl));
        }

        echo "\r \033[32m Sitemap analysis \033[0m : \033[36m{$totalUrls} URLs parsed, cross-referencing...\033[0m        ";
        flush();

        // Step 2: Cross-reference sitemap URLs with crawled pages
        $this->crossReference();

        // Step 3: Update stats
        $this->updateSitemapStats();

        echo "\r \033[32m Sitemap analysis \033[0m : \033[36mdone ({$totalUrls} sitemap URLs)\033[0m                    \n";
        flush();

        return true;
    }

    /**
     * Fetches and parses a single sitemap (or sitemap index) recursively.
     *
     * @return int Number of URLs parsed
     */
    private function fetchAndParseSitemap(string $sitemapUrl): int
    {
        $xmlContent = $this->fetchUrlContent($sitemapUrl);
        if (!$xmlContent) {
            echo "\n  \033[33m! Failed to fetch: $sitemapUrl\033[0m\n";
            return 0;
        }

        // Suppress libxml errors for malformed sitemaps
        $previousUseErrors = libxml_use_internal_errors(true);
        $totalUrls = 0;

        try {
            $xml = new \SimpleXMLElement($xmlContent);

            // Register namespaces for hreflang parsing
            $namespaces = $xml->getNamespaces(true);

            // Detect type: sitemap index vs URL set
            if (isset($xml->sitemap)) {
                // Sitemap index — recurse into each child sitemap
                foreach ($xml->sitemap as $sitemap) {
                    $loc = trim((string)$sitemap->loc);
                    if (!empty($loc)) {
                        $totalUrls += $this->fetchAndParseSitemap($loc);
                    }
                }
            } elseif (isset($xml->url)) {
                // Regular sitemap — extract URL data
                $batch = [];
                foreach ($xml->url as $urlEntry) {
                    $url = trim((string)$urlEntry->loc);
                    if (empty($url)) continue;

                    $lastmod = !empty((string)$urlEntry->lastmod)
                        ? date('Y-m-d H:i:s', strtotime((string)$urlEntry->lastmod))
                        : null;
                    $changefreq = !empty((string)$urlEntry->changefreq)
                        ? (string)$urlEntry->changefreq
                        : null;
                    $priority = !empty((string)$urlEntry->priority)
                        ? (float)(string)$urlEntry->priority
                        : null;

                    // Parse hreflang alternate links
                    $hreflang = $this->parseHreflang($urlEntry, $namespaces);

                    $batch[] = [
                        'url' => $url,
                        'source_sitemap' => $sitemapUrl,
                        'lastmod' => $lastmod,
                        'changefreq' => $changefreq,
                        'priority' => $priority,
                        'hreflang' => !empty($hreflang) ? json_encode($hreflang) : null,
                    ];

                    // Batch insert every 500 URLs
                    if (count($batch) >= 500) {
                        $this->insertSitemapUrls($batch);
                        $totalUrls += count($batch);
                        $batch = [];
                    }
                }

                // Insert remaining
                if (!empty($batch)) {
                    $this->insertSitemapUrls($batch);
                    $totalUrls += count($batch);
                }
            }
        } catch (\Exception $e) {
            echo "\n  \033[33m! XML parse error for $sitemapUrl: {$e->getMessage()}\033[0m\n";
        }

        libxml_use_internal_errors($previousUseErrors);
        return $totalUrls;
    }

    /**
     * Parses xhtml:link alternate (hreflang) from a <url> entry.
     *
     * @return array<string, string> Map of lang => href
     */
    private function parseHreflang(\SimpleXMLElement $urlEntry, array $namespaces): array
    {
        $hreflang = [];

        // Try xhtml namespace
        $xhtmlPrefix = array_search(self::XHTML_NS, $namespaces);
        if ($xhtmlPrefix !== false) {
            $links = $urlEntry->children(self::XHTML_NS);
            foreach ($links as $link) {
                $attrs = $link->attributes();
                if ((string)($attrs['rel'] ?? '') === 'alternate' && !empty((string)($attrs['hreflang'] ?? ''))) {
                    $lang = (string)$attrs['hreflang'];
                    $href = (string)$attrs['href'];
                    if (!empty($href)) {
                        $hreflang[$lang] = $href;
                    }
                }
            }
        }

        return $hreflang;
    }

    /**
     * Batch-inserts sitemap URLs into the database.
     */
    private function insertSitemapUrls(array $batch): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO sitemap_urls (crawl_id, url, source_sitemap, lastmod, changefreq, priority, hreflang)
            VALUES (:crawl_id, :url, :source_sitemap, :lastmod, :changefreq, :priority, :hreflang)
        ");

        $this->pdo->beginTransaction();
        try {
            foreach ($batch as $row) {
                $stmt->execute([
                    ':crawl_id' => $this->crawlId,
                    ':url' => $row['url'],
                    ':source_sitemap' => $row['source_sitemap'],
                    ':lastmod' => $row['lastmod'],
                    ':changefreq' => $row['changefreq'],
                    ':priority' => $row['priority'],
                    ':hreflang' => $row['hreflang'],
                ]);
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Cross-references sitemap URLs with crawled pages.
     * Sets is_in_crawl on sitemap_urls and is_in_sitemap on pages.
     */
    private function crossReference(): void
    {
        // Mark sitemap URLs that exist in crawled pages
        $this->pdo->prepare("
            UPDATE sitemap_urls su
            SET is_in_crawl = TRUE
            FROM pages p
            WHERE su.crawl_id = :cid1
              AND p.crawl_id = :cid2
              AND su.url = p.url
        ")->execute([':cid1' => $this->crawlId, ':cid2' => $this->crawlId]);

        // Mark pages that exist in sitemap
        $this->pdo->prepare("
            UPDATE pages p
            SET is_in_sitemap = TRUE
            FROM sitemap_urls su
            WHERE p.crawl_id = :cid1
              AND su.crawl_id = :cid2
              AND p.url = su.url
        ")->execute([':cid1' => $this->crawlId, ':cid2' => $this->crawlId]);

        // Determine indexability for sitemap URLs using crawled page data
        // A URL is indexable if: code=200, not noindex, canonical points to self, not blocked
        $this->pdo->prepare("
            UPDATE sitemap_urls su
            SET is_indexable = (
                p.code = 200
                AND p.noindex = FALSE
                AND p.blocked = FALSE
                AND (p.canonical = TRUE OR p.canonical IS NULL)
            ),
            http_status = p.code
            FROM pages p
            WHERE su.crawl_id = :cid1
              AND p.crawl_id = :cid2
              AND su.url = p.url
              AND p.crawled = TRUE
        ")->execute([':cid1' => $this->crawlId, ':cid2' => $this->crawlId]);

        // For sitemap URLs NOT found in crawl, mark is_indexable as NULL (unknown)
        $this->pdo->prepare("
            UPDATE sitemap_urls
            SET is_indexable = NULL
            WHERE crawl_id = :cid AND is_in_crawl = FALSE
        ")->execute([':cid' => $this->crawlId]);
    }

    /**
     * Updates sitemap analysis statistics in the crawls table.
     */
    private function updateSitemapStats(): void
    {
        $cid = $this->crawlId;

        // Total sitemap URLs
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sitemap_urls WHERE crawl_id = :cid");
        $stmt->execute([':cid' => $cid]);
        $sitemapTotal = (int)$stmt->fetchColumn();

        // In sitemap but NOT in crawl (sitemap-only orphans)
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sitemap_urls WHERE crawl_id = :cid AND is_in_crawl = FALSE");
        $stmt->execute([':cid' => $cid]);
        $sitemapOnly = (int)$stmt->fetchColumn();

        // In crawl (indexable) but NOT in sitemap
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM pages
            WHERE crawl_id = :cid AND compliant = TRUE AND is_in_sitemap = FALSE AND external = FALSE
        ");
        $stmt->execute([':cid' => $cid]);
        $crawlOnlyIndexable = (int)$stmt->fetchColumn();

        // In sitemap but NOT indexable
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM sitemap_urls
            WHERE crawl_id = :cid AND is_in_crawl = TRUE AND is_indexable = FALSE
        ");
        $stmt->execute([':cid' => $cid]);
        $sitemapNotIndexable = (int)$stmt->fetchColumn();

        $this->pdo->prepare("
            UPDATE crawls SET
                sitemap_total = :total,
                sitemap_only = :sitemap_only,
                crawl_only_indexable = :crawl_only,
                sitemap_not_indexable = :not_indexable
            WHERE id = :cid
        ")->execute([
            ':total' => $sitemapTotal,
            ':sitemap_only' => $sitemapOnly,
            ':crawl_only' => $crawlOnlyIndexable,
            ':not_indexable' => $sitemapNotIndexable,
            ':cid' => $cid,
        ]);
    }

    /**
     * Retrieves sitemap URLs from the crawl's JSONB config.
     */
    private function getSitemapUrlsFromConfig(): array
    {
        $stmt = $this->pdo->prepare('SELECT config FROM crawls WHERE id = :crawl_id');
        $stmt->execute(['crawl_id' => $this->crawlId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && !empty($result['config'])) {
            $config = json_decode($result['config'], true);
            $urls = $config['general']['sitemap_urls'] ?? [];
            if (is_string($urls)) {
                // Support comma-separated string for backward compat
                return array_filter(array_map('trim', explode(',', $urls)));
            }
            return array_filter($urls);
        }

        return [];
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
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // Accept gzip
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode == 200) ? $content : null;
    }
}
