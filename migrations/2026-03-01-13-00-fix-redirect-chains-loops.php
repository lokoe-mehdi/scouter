<?php
/**
 * Migration : Fix redirect chains - détection des boucles fermées
 *
 * Corrige un bug où les boucles fermées (A→B→A ou A→A) n'étaient pas détectées
 * car tous les nœuds étaient à la fois source et target, donc aucun chain starter
 * n'était identifié. Cette migration recalcule les chaînes pour tous les crawls.
 */

require_once __DIR__ . '/../app/Database/PostgresDatabase.php';

echo "Migration: fix-redirect-chains-loops\n";
echo "=====================================\n\n";

try {
    $pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

    $crawls = $pdo->query("SELECT id FROM crawls WHERE status IN ('finished', 'stopped', 'pending')")->fetchAll(PDO::FETCH_OBJ);
    $totalCrawls = count($crawls);

    if ($totalCrawls === 0) {
        echo "   → Aucun crawl à traiter\n";
    } else {
        echo "   Recalcul pour $totalCrawls crawls...\n";

        foreach ($crawls as $index => $crawl) {
            $crawlId = $crawl->id;
            $num = $index + 1;
            echo "   [$num/$totalCrawls] Crawl #$crawlId : ";

            // Charger les liens redirect
            $redirectLinks = $pdo->query("
                SELECT src, target FROM links
                WHERE crawl_id = $crawlId AND type = 'redirect'
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($redirectLinks)) {
                echo "no redirects\n";
                continue;
            }

            // Construire la map src → target et le set isTarget
            $redirectMap = [];
            $isTarget = [];

            foreach ($redirectLinks as $link) {
                $src = trim($link['src']);
                $target = trim($link['target']);
                $redirectMap[$src] = $target;
                $isTarget[$target] = true;
            }

            // Chain starters classiques
            $chainStarters = [];
            foreach ($redirectMap as $src => $target) {
                if (!isset($isTarget[$src])) {
                    $chainStarters[] = $src;
                }
            }

            // FIX: Détecter les boucles fermées (tous les noeuds sont src ET target)
            $coveredByStarters = [];
            foreach ($chainStarters as $startId) {
                $current = $startId;
                $visited = [];
                while (true) {
                    if (isset($visited[$current])) break;
                    $visited[$current] = true;
                    $coveredByStarters[$current] = true;
                    if (!isset($redirectMap[$current])) break;
                    $current = $redirectMap[$current];
                }
            }

            $loopVisited = [];
            foreach ($redirectMap as $src => $target) {
                if (!isset($coveredByStarters[$src]) && !isset($loopVisited[$src])) {
                    $chainStarters[] = $src;
                    $current = $src;
                    while (true) {
                        if (isset($loopVisited[$current])) break;
                        $loopVisited[$current] = true;
                        if (!isset($redirectMap[$current])) break;
                        $current = $redirectMap[$current];
                    }
                }
            }

            // Load page info
            $allIds = array_unique(array_merge(array_keys($redirectMap), array_values($redirectMap)));
            $pagesMap = [];
            if (!empty($allIds)) {
                $placeholders = implode(',', array_map(function($id) use ($pdo) {
                    return $pdo->quote($id);
                }, $allIds));

                $pageRows = $pdo->query("
                    SELECT id, url, code, compliant
                    FROM pages
                    WHERE crawl_id = $crawlId AND id IN ($placeholders)
                ")->fetchAll(PDO::FETCH_ASSOC);

                foreach ($pageRows as $row) {
                    $pagesMap[trim($row['id'])] = $row;
                }
            }

            // Build chains
            $chains = [];
            foreach ($chainStarters as $startId) {
                $visited = [];
                $chainIds = [];
                $current = $startId;
                $isLoop = false;

                while (true) {
                    if (in_array($current, $visited)) {
                        $isLoop = true;
                        break;
                    }
                    $visited[] = $current;
                    $chainIds[] = $current;

                    if (!isset($redirectMap[$current])) {
                        break;
                    }
                    $current = $redirectMap[$current];
                }

                $sourceId = $chainIds[0];
                $sourcePage = $pagesMap[$sourceId] ?? null;
                $sourceUrl = $sourcePage['url'] ?? null;

                if ($isLoop) {
                    $finalId = null;
                    $finalUrl = null;
                    $finalCode = null;
                    $finalCompliant = false;
                    $hops = count($chainIds);
                } else {
                    $finalId = end($chainIds);
                    $finalPage = $pagesMap[$finalId] ?? null;
                    $finalUrl = $finalPage['url'] ?? null;
                    $finalCode = $finalPage ? (int)$finalPage['code'] : null;
                    $finalCompliant = $finalPage ? (bool)$finalPage['compliant'] : false;
                    $hops = count($chainIds) - 1;
                }

                if ($hops > 0 || $isLoop) {
                    $chains[] = [
                        'source_id' => $sourceId,
                        'source_url' => $sourceUrl,
                        'final_id' => $finalId,
                        'final_url' => $finalUrl,
                        'final_code' => $finalCode,
                        'final_compliant' => $finalCompliant,
                        'hops' => $hops,
                        'is_loop' => $isLoop,
                        'chain_ids' => $chainIds
                    ];
                }
            }

            // Supprimer les anciennes chaînes et réinsérer
            $pdo->exec("DELETE FROM redirect_chains WHERE crawl_id = $crawlId");

            $insertStmt = $pdo->prepare("
                INSERT INTO redirect_chains (crawl_id, source_id, source_url, final_id, final_url, final_code, final_compliant, hops, is_loop, chain_ids)
                VALUES (:crawl_id, :source_id, :source_url, :final_id, :final_url, :final_code, :final_compliant, :hops, :is_loop, :chain_ids)
            ");

            foreach ($chains as $chain) {
                $chainIdsFormatted = '{' . implode(',', array_map(function($id) {
                    return '"' . trim($id) . '"';
                }, $chain['chain_ids'])) . '}';

                $insertStmt->execute([
                    ':crawl_id' => $crawlId,
                    ':source_id' => $chain['source_id'],
                    ':source_url' => $chain['source_url'],
                    ':final_id' => $chain['final_id'],
                    ':final_url' => $chain['final_url'],
                    ':final_code' => $chain['final_code'],
                    ':final_compliant' => $chain['final_compliant'] ? 'true' : 'false',
                    ':hops' => $chain['hops'],
                    ':is_loop' => $chain['is_loop'] ? 'true' : 'false',
                    ':chain_ids' => $chainIdsFormatted
                ]);
            }

            // Compute metrics
            $redirectTotal = (int)$pdo->query("
                SELECT COUNT(*) FROM pages
                WHERE crawl_id = $crawlId AND code >= 300 AND code < 400
            ")->fetchColumn();

            $chainsCount = count($chains);
            $chainsErrors = 0;
            foreach ($chains as $chain) {
                if ($chain['is_loop'] || ($chain['final_code'] !== null && $chain['final_code'] !== 200)) {
                    $chainsErrors++;
                }
            }

            $pdo->prepare("
                UPDATE crawls SET redirect_total = :total, redirect_chains_count = :chains, redirect_chains_errors = :errors
                WHERE id = :id
            ")->execute([
                ':total' => $redirectTotal,
                ':chains' => $chainsCount,
                ':errors' => $chainsErrors,
                ':id' => $crawlId
            ]);

            echo "$chainsCount chains, $chainsErrors errors\n";
        }
    }

    echo "\n✓ Migration terminée avec succès\n";

} catch (Exception $e) {
    echo "\n✗ Erreur : " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
