<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\PostgresDatabase;
use App\Analysis\Simhash;
use fivefilters\Readability\Readability;
use fivefilters\Readability\Configuration;

$db = PostgresDatabase::getInstance()->getConnection();

$urls = [
    'https://www.abondance.com/20190226-39077-ux-et-seo-1ere-partie-definitions-video-seo-numero-115.html',
    'https://www.abondance.com/20160614-16723-seo-international-balise-hreflang-video-seo.html'
];

$allTexts = [];

foreach ($urls as $url) {
    $stmt = $db->prepare('SELECT p.id, p.simhash, h.html FROM pages p JOIN html h ON h.crawl_id = p.crawl_id AND h.id = p.id WHERE p.crawl_id = 424 AND p.url = :url');
    $stmt->execute([':url' => $url]);
    $row = $stmt->fetch(PDO::FETCH_OBJ);

    if (!$row) { echo "NOT FOUND: $url\n\n"; continue; }

    $dom = @gzinflate(base64_decode($row->html));

    echo "=== " . basename($url) . " ===\n";
    echo "Simhash in DB: $row->simhash\n";

    // Readability extraction
    $content = null;
    $method = 'fallback';
    try {
        $configuration = new Configuration();
        $configuration->setFixRelativeURLs(false);
        $configuration->setSubstituteEntities(false);

        $readability = new Readability($configuration);
        $readability->parse($dom);
        $content = $readability->getContent();
        if (!empty($content)) {
            $method = 'readability';
        }
    } catch (\Exception $e) {
        echo "Readability error: " . $e->getMessage() . "\n";
        $content = null;
    }

    // Fallback
    if (empty($content)) {
        if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $dom, $m)) {
            $content = $m[1];
            $method = 'fallback (<main>)';
        } elseif (preg_match('/<body[^>]*>(.*?)<\/body>/is', $dom, $m)) {
            $content = $m[1];
            $method = 'fallback (<body>)';
        } else {
            $content = $dom;
            $method = 'fallback (full DOM)';
        }
        $cleaned = preg_replace('/<(nav|header|footer|aside|a|form)[^>]*>.*?<\/\1>/is', '', $content);
        if ($cleaned !== null) $content = $cleaned;
    }

    echo "Source: $method\n";

    // extractVisibleText
    $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
    $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
    $content = preg_replace('/<noscript[^>]*>.*?<\/noscript>/is', '', $content);
    $content = preg_replace('/<!--.*?-->/s', '', $content);
    $text = strip_tags($content);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Normalize
    $normalized = mb_strtolower($text, 'UTF-8');
    $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
    $normalized = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $normalized);
    while (strpos($normalized, '  ') !== false) $normalized = str_replace('  ', ' ', $normalized);
    $normalized = trim($normalized);

    $words = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    $words3 = array_values(array_filter($words, fn($w) => mb_strlen($w) > 2));

    echo "Final text: " . strlen($normalized) . " chars, " . count($words) . " words, " . count($words3) . " words>2\n";

    $simhash = Simhash::compute($text);
    echo "Simhash recalc: $simhash\n";

    // Hamming distance
    if (!empty($allTexts)) {
        $prevHash = $allTexts[0]['simhash'];
        $dist = Simhash::hammingDistance($prevHash, $simhash);
        $similarity = round((64 - $dist) / 64 * 100, 1);
        echo "Hamming distance with prev: $dist bits => $similarity% similarity\n";
    }

    echo "\n--- FULL TEXT ---\n";
    echo $normalized . "\n";
    echo "\n=== END ===\n\n";

    $allTexts[] = ['text' => $normalized, 'words3' => $words3, 'simhash' => $simhash];
}

// Jaccard comparison
if (count($allTexts) === 2) {
    $s1 = []; $s2 = [];
    $w1 = $allTexts[0]['words3'];
    $w2 = $allTexts[1]['words3'];
    for ($i = 0; $i <= count($w1) - 3; $i++) $s1[] = implode(' ', array_slice($w1, $i, 3));
    for ($i = 0; $i <= count($w2) - 3; $i++) $s2[] = implode(' ', array_slice($w2, $i, 3));

    $set1 = array_unique($s1);
    $set2 = array_unique($s2);
    $common = array_intersect($set1, $set2);
    $union = array_unique(array_merge($set1, $set2));

    echo "=== JACCARD ===\n";
    echo "Shingles URL1: " . count($set1) . " | URL2: " . count($set2) . " | Common: " . count($common) . "\n";
    echo "Jaccard: " . round(count($common) / count($union) * 100, 1) . "%\n";
}
