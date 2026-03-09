<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\PostgresDatabase;
use App\Analysis\Simhash;

$db = PostgresDatabase::getInstance()->getConnection();

$urls = [
    'https://www.abondance.com/netlinking',
    'https://www.abondance.com/debutants'
];

foreach ($urls as $url) {
    $stmt = $db->prepare('SELECT p.id, p.simhash, h.html FROM pages p JOIN html h ON h.crawl_id = p.crawl_id AND h.id = p.id WHERE p.crawl_id = 424 AND p.url = :url');
    $stmt->execute([':url' => $url]);
    $row = $stmt->fetch(PDO::FETCH_OBJ);

    if (!$row) { echo "NOT FOUND: $url\n\n"; continue; }

    $dom = @gzinflate(base64_decode($row->html));

    echo "=== $url ===\n";
    echo "ID: $row->id | Simhash in DB: $row->simhash\n";
    echo "DOM size: " . strlen($dom) . " bytes\n";

    // Check if <main> exists
    $hasMain = preg_match('/<main[^>]*>(.*?)<\/main>/is', $dom, $m);
    echo "Has <main>: " . ($hasMain ? "YES (" . strlen($m[1]) . " bytes)" : "NO") . "\n";

    // Reproduire le calcul : main > body > dom
    $content = null;
    if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $dom, $m)) {
        $content = $m[1];
        echo "Using: <main>\n";
    } elseif (preg_match('/<body[^>]*>(.*?)<\/body>/is', $dom, $m)) {
        $content = $m[1];
        echo "Using: <body> (" . strlen($m[1]) . " bytes)\n";
    } else {
        $content = $dom;
        echo "Using: full DOM\n";
    }

    // Supprimer nav, header, footer, aside
    $before = strlen($content);
    $cleaned = preg_replace('/<(nav|header|footer|aside)[^>]*>.*?<\/\1>/is', '', $content);
    if ($cleaned !== null) {
        $content = $cleaned;
    }
    echo "After removing nav/header/footer/aside: " . strlen($content) . " bytes (removed " . ($before - strlen($content)) . ")\n";

    // extractVisibleText
    $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
    $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
    $content = preg_replace('/<noscript[^>]*>.*?<\/noscript>/is', '', $content);
    $content = preg_replace('/<!--.*?-->/s', '', $content);
    $text = strip_tags($content);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Normalize like Simhash::normalize
    $normalized = mb_strtolower($text, 'UTF-8');
    $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
    $normalized = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $normalized);
    while (strpos($normalized, '  ') !== false) $normalized = str_replace('  ', ' ', $normalized);
    $normalized = trim($normalized);

    $words = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    $words3 = array_values(array_filter($words, fn($w) => mb_strlen($w) > 2));

    echo "Text length: " . strlen($normalized) . " chars, " . count($words) . " words, " . count($words3) . " words>2chars\n";

    // Recalc simhash
    $recalc = Simhash::compute($text);
    echo "Simhash recalc: $recalc\n";

    echo "\n--- FULL NORMALIZED TEXT (first 1000 chars) ---\n";
    echo substr($normalized, 0, 1000) . "\n";
    echo "\n--- LAST 500 chars ---\n";
    echo substr($normalized, -500) . "\n";

    // Show headings in the content
    preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $dom, $headings);
    echo "\n--- HEADINGS found in DOM ---\n";
    foreach ($headings[0] as $i => $h) {
        $clean = strip_tags($h);
        echo "  " . substr($h, 0, 5) . " " . trim($clean) . "\n";
        if ($i > 15) { echo "  ...\n"; break; }
    }

    echo "\n\n";
}
