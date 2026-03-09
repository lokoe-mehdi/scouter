<?php
/**
 * Recalcule les simhash pour un crawl donné à partir du HTML stocké en base.
 * Utilise Readability pour extraire le contenu principal (sans boilerplate).
 * Usage: php scripts/recalc-simhash.php <crawl_id>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\PostgresDatabase;
use App\Analysis\Simhash;
use fivefilters\Readability\Readability;
use fivefilters\Readability\Configuration;

$crawlId = (int)($argv[1] ?? 0);
if ($crawlId <= 0) {
    echo "Usage: php scripts/recalc-simhash.php <crawl_id>\n";
    exit(1);
}

echo "=== Recalcul des simhash pour le crawl #$crawlId ===\n";

$db = PostgresDatabase::getInstance()->getConnection();

// Compter les pages à traiter
$stmt = $db->prepare("
    SELECT COUNT(*) FROM pages p
    JOIN html h ON h.crawl_id = p.crawl_id AND h.id = p.id
    WHERE p.crawl_id = :cid AND p.crawled = true AND p.code = 200
");
$stmt->execute([':cid' => $crawlId]);
$total = (int)$stmt->fetchColumn();

echo "Pages à traiter : $total\n";

// Charger par batch
$batchSize = 500;
$updated = 0;
$nulled = 0;
$readabilityUsed = 0;
$fallbackUsed = 0;

$updateStmt = $db->prepare("UPDATE pages SET simhash = :simhash WHERE crawl_id = :cid AND id = :id");

for ($offset = 0; $offset < $total; $offset += $batchSize) {
    $stmt = $db->prepare("
        SELECT p.id, h.html
        FROM pages p
        JOIN html h ON h.crawl_id = p.crawl_id AND h.id = p.id
        WHERE p.crawl_id = :cid AND p.crawled = true AND p.code = 200
        ORDER BY p.id
        LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':cid', $crawlId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $batchSize, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
        $compressed = $row->html;
        if (empty($compressed)) {
            $nulled++;
            continue;
        }

        // Le HTML est stocké en base64(gzdeflate(dom))
        $dom = @gzinflate(base64_decode($compressed));
        if (empty($dom)) {
            $nulled++;
            continue;
        }

        // Extraire le H1
        $h1Text = '';
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $dom, $h1m)) {
            $h1Text = trim(strip_tags($h1m[1]));
        }

        // Readability pour extraire le contenu principal (sans boilerplate)
        $content = null;
        $useReadability = false;
        try {
            $configuration = new Configuration();
            $configuration->setFixRelativeURLs(false);
            $configuration->setSubstituteEntities(false);

            $readability = new Readability($configuration);
            $readability->parse($dom);
            $readabilityContent = $readability->getContent();

            if (!empty($readabilityContent)) {
                // Vérifier >= 200 mots sinon fallback
                $testText = strip_tags($readabilityContent);
                $wordCount = str_word_count($testText);
                if ($wordCount >= 200) {
                    $content = $readabilityContent;
                    $useReadability = true;
                    $readabilityUsed++;
                }
            }
        } catch (\Exception $e) {
            $content = null;
        }

        // Fallback si Readability échoue ou < 200 mots
        if (!$useReadability) {
            $fallbackUsed++;
            if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $dom, $m)) {
                $content = $m[1];
            } elseif (preg_match('/<body[^>]*>(.*?)<\/body>/is', $dom, $m)) {
                $content = $m[1];
            } else {
                $content = $dom;
            }

            $cleaned = preg_replace('/<(nav|header|footer|aside|form)[^>]*>.*?<\/\1>/is', '', $content);
            if ($cleaned !== null) {
                $content = $cleaned;
            }
        }

        // extractVisibleText
        $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
        $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
        $content = preg_replace('/<noscript[^>]*>.*?<\/noscript>/is', '', $content);
        $content = preg_replace('/<!--.*?-->/s', '', $content);
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Forcer le H1 en tête du contenu
        if (!empty($h1Text)) {
            $content = $h1Text . ' ' . $content;
        }

        if (empty(trim($content))) {
            $updateStmt->execute([':simhash' => null, ':cid' => $crawlId, ':id' => $row->id]);
            $nulled++;
            continue;
        }

        $simhash = Simhash::compute($content);
        $updateStmt->execute([':simhash' => $simhash, ':cid' => $crawlId, ':id' => $row->id]);
        $updated++;
    }

    $done = min($offset + $batchSize, $total);
    echo "\r  $done / $total pages traitées ($updated updated, $nulled null, readability: $readabilityUsed, fallback: $fallbackUsed)";
    flush();
}

echo "\n✅ Terminé : $updated simhash recalculés, $nulled null.\n";
echo "Readability: $readabilityUsed pages | Fallback: $fallbackUsed pages\n";
echo "Relancez le post-processing pour recalculer les clusters de duplication.\n";
