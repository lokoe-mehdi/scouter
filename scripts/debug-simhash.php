<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\PostgresDatabase;
use App\Analysis\Simhash;

$db = PostgresDatabase::getInstance()->getConnection();

$urls = [
    'https://www.abondance.com/20231010-229492-google-extended-sge-peut-toujours-utiliser-contenus.html',
    'https://www.abondance.com/20231220-322297-google-durcit-sa-politique-contre-le-retrait-payant-dimages-explicites.html'
];

$texts = [];

foreach ($urls as $url) {
    $stmt = $db->prepare('SELECT p.id, p.simhash, h.html FROM pages p JOIN html h ON h.crawl_id = p.crawl_id AND h.id = p.id WHERE p.crawl_id = 424 AND p.url = :url');
    $stmt->execute([':url' => $url]);
    $row = $stmt->fetch(PDO::FETCH_OBJ);

    if (!$row) { echo "NOT FOUND: $url\n\n"; continue; }

    $dom = @gzinflate(base64_decode($row->html));

    // Reproduire computeSimhash
    $content = null;
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $dom, $m)) {
        $content = $m[1];
    } else {
        $content = $dom;
    }

    // Supprimer nav, header, footer, aside
    $cleaned = preg_replace('/<(nav|header|footer|aside)[^>]*>.*?<\/\1>/is', '', $content);
    if ($cleaned !== null) $content = $cleaned;

    // extractVisibleText
    $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
    $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
    $content = preg_replace('/<noscript[^>]*>.*?<\/noscript>/is', '', $content);
    $content = preg_replace('/<!--.*?-->/s', '', $content);
    $content = strip_tags($content);
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Normaliser (comme Simhash::normalize)
    $text = mb_strtolower($content, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
    $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
    while (strpos($text, '  ') !== false) $text = str_replace('  ', ' ', $text);
    $text = trim($text);

    // Stats
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $words3 = array_values(array_filter($words, fn($w) => mb_strlen($w) > 2));

    $simhashRecalc = Simhash::compute($content);

    echo "=== " . basename($url) . " ===\n";
    echo "ID: $row->id | Simhash DB: $row->simhash | Simhash recalc: $simhashRecalc\n";
    echo "Total words: " . count($words) . " | Words >2 chars (shingles input): " . count($words3) . "\n";
    echo "DOM size: " . strlen($dom) . " | Text size: " . strlen($text) . "\n";
    echo "\n--- FIRST 500 chars of normalized text ---\n";
    echo substr($text, 0, 500) . "\n";
    echo "\n--- LAST 500 chars of normalized text ---\n";
    echo substr($text, -500) . "\n";
    echo "\n--- First 20 shingles (3-word) ---\n";
    for ($i = 0; $i < min(20, count($words3) - 2); $i++) {
        echo "  " . implode(' ', array_slice($words3, $i, 3)) . "\n";
    }
    echo "\n\n";

    $texts[] = $text;
}

// Comparer
if (count($texts) === 2) {
    $w1 = array_values(array_filter(preg_split('/\s+/', $texts[0], -1, PREG_SPLIT_NO_EMPTY), fn($w) => mb_strlen($w) > 2));
    $w2 = array_values(array_filter(preg_split('/\s+/', $texts[1], -1, PREG_SPLIT_NO_EMPTY), fn($w) => mb_strlen($w) > 2));

    // Shingles
    $s1 = [];
    for ($i = 0; $i <= count($w1) - 3; $i++) $s1[] = implode(' ', array_slice($w1, $i, 3));
    $s2 = [];
    for ($i = 0; $i <= count($w2) - 3; $i++) $s2[] = implode(' ', array_slice($w2, $i, 3));

    $set1 = array_unique($s1);
    $set2 = array_unique($s2);
    $common = array_intersect($set1, $set2);
    $union = array_unique(array_merge($set1, $set2));

    $jaccard = count($common) / count($union) * 100;

    echo "=== COMPARISON ===\n";
    echo "Shingles URL1: " . count($set1) . " unique\n";
    echo "Shingles URL2: " . count($set2) . " unique\n";
    echo "Common shingles: " . count($common) . "\n";
    echo "Jaccard similarity: " . round($jaccard, 1) . "%\n";
    echo "\n--- Common shingles (first 30) ---\n";
    $i = 0;
    foreach ($common as $s) {
        echo "  $s\n";
        if (++$i >= 30) break;
    }

    echo "\n--- Unique to URL1 (first 20) ---\n";
    $diff1 = array_diff($set1, $set2);
    $i = 0;
    foreach ($diff1 as $s) {
        echo "  $s\n";
        if (++$i >= 20) break;
    }

    echo "\n--- Unique to URL2 (first 20) ---\n";
    $diff2 = array_diff($set2, $set1);
    $i = 0;
    foreach ($diff2 as $s) {
        echo "  $s\n";
        if (++$i >= 20) break;
    }
}
