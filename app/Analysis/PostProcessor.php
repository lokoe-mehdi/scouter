<?php

namespace App\Analysis;

use PDO;
use App\Database\PostgresDatabase;
use App\Analysis\CategorizationService;

/**
 * Post-traitement des données de crawl
 * 
 * Cette classe gère tous les calculs et analyses effectués après le crawl :
 * - Calcul des liens entrants (inlinks)
 * - Calcul du PageRank interne
 * - Analyse sémantique (title, h1, metadesc)
 * - Catégorisation des URLs
 * - Détection des duplicates (Simhash)
 * 
 * @package    Scouter
 * @subpackage Analysis
 * @author     Mehdi Colin
 * @version    2.0.0
 */
class PostProcessor
{
    private PDO $db;
    private int $crawlId;

    public function __construct(int $crawlId)
    {
        $this->db = PostgresDatabase::getInstance()->getConnection();
        $this->crawlId = $crawlId;
    }

    /**
     * Exécute tous les post-traitements
     */
    public function run(): void
    {
        echo "\n";
        flush();

        // Advisory lock : empêcher deux process de faire le post-traitement
        // sur le même crawl_id en même temps (évite les deadlocks)
        $lockId = $this->crawlId + 200000; // Offset pour éviter collision avec d'autres locks
        $stmt = $this->db->prepare("SELECT pg_try_advisory_lock(:lock_id)");
        $stmt->execute([':lock_id' => $lockId]);
        $acquired = (bool)$stmt->fetchColumn();

        if (!$acquired) {
            echo "\033[33m! Post-processing skipped (another process is already running it)\033[0m\n";
            flush();
            return;
        }

        // Désactiver le statement_timeout pour les opérations lourdes de post-processing
        // (le timeout de 120s par défaut est insuffisant pour les crawls de 1M+ pages)
        $this->db->exec("SET statement_timeout = '0'");

        $steps = [
            'calculateInlinks',
            'calculatePagerank',
            'semanticAnalysis',
            'categorize',
            'duplicateAnalysis',
            'redirectChainAnalysis',
        ];

        try {
            foreach ($steps as $step) {
                // Vérifier si le crawl a été interrompu entre les étapes
                if ($this->isCrawlInterrupted()) {
                    echo "\n\033[33m! Post-processing interrupted (crawl stopped or failed)\033[0m\n";
                    flush();
                    break;
                }

                try {
                    $this->$step();
                } catch (\Throwable $e) {
                    echo "\n\033[31m✗ Post-processing error in $step: " . $e->getMessage() . "\033[0m\n";
                    flush();
                    // Log to stderr so worker captures it in log file
                    fwrite(STDERR, "ERROR in PostProcessor::$step(): " . $e->getMessage()
                        . " in " . $e->getFile() . ":" . $e->getLine() . "\n");
                    throw $e;
                }
            }
        } finally {
            // Réactiver le timeout normal
            try {
                $this->db->exec("SET statement_timeout = '120s'");
            } catch (\Throwable $ignored) {}
            // Toujours libérer le lock
            try {
                $this->db->exec("SELECT pg_advisory_unlock($lockId)");
            } catch (\Throwable $ignored) {}
        }

        echo "\n\033[32m✓ Post-traitement terminé\033[0m\n\n";
        flush();
    }

    /**
     * Vérifie si le crawl a été tué par le watchdog
     * On ne bloque PAS sur 'stopping'/'stopped' (arrêt utilisateur) :
     * l'utilisateur veut que le post-traitement se fasse même après un stop
     */
    private function isCrawlInterrupted(): bool
    {
        $stmt = $this->db->prepare("SELECT status FROM crawls WHERE id = :id");
        $stmt->execute([':id' => $this->crawlId]);
        $status = (string)$stmt->fetchColumn();
        return $status === 'failed';
    }

    /**
     * Calcul des inlinks pour chaque page
     */
    public function calculateInlinks(): void
    {
        echo "\r \033[32m Inlinks calcul \033[0m : \033[36mprocessing...\033[0m                    ";
        flush();

        // Une seule requête atomique : LEFT JOIN pour avoir 0 quand pas de liens
        $stmt = $this->db->prepare("
            UPDATE pages p SET inlinks = COALESCE(sub.cnt, 0)
            FROM (
                SELECT p2.id, lc.cnt
                FROM pages p2
                LEFT JOIN (
                    SELECT target, COUNT(*) AS cnt
                    FROM links WHERE crawl_id = :crawl_id
                    GROUP BY target
                ) lc ON p2.id = lc.target
                WHERE p2.crawl_id = :crawl_id2
            ) sub
            WHERE p.crawl_id = :crawl_id3 AND p.id = sub.id
        ");
        $stmt->execute([
            ':crawl_id' => $this->crawlId,
            ':crawl_id2' => $this->crawlId,
            ':crawl_id3' => $this->crawlId
        ]);

        echo "\r \033[32m Inlinks calcul \033[0m : \033[36mdone\033[0m                             \n";
        flush();
    }

    /**
     * Calcul du PageRank interne
     * 
     * Algorithme itératif avec damping factor de 0.85.
     * Gère les pages sans liens sortants (dead ends).
     */
    public function calculatePagerank(): void
    {
        echo "\r \033[32m Pagerank calcul \033[0m : \033[36mprocessing...\033[0m                    ";
        flush();

        $iterations = 30;
        $damping = 0.85;

        // Count pages
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :cid");
        $stmt->execute([':cid' => $this->crawlId]);
        $pagesCount = (int)$stmt->fetchColumn();

        if ($pagesCount === 0) {
            echo "\r \033[32m Pagerank calcul \033[0m : \033[33mno pages\033[0m                             \n";
            return;
        }

        // Check if there are links
        $stmt = $this->db->prepare("SELECT 1 FROM links WHERE crawl_id = :cid LIMIT 1");
        $stmt->execute([':cid' => $this->crawlId]);
        if (!$stmt->fetchColumn()) {
            echo "\r \033[32m Pagerank calcul \033[0m : \033[33mno links\033[0m                             \n";
            return;
        }

        $initPR = 1.0 / $pagesCount;
        $bonus = (1 - $damping) / $pagesCount;

        // Increase work_mem for the heavy JOIN operations
        $this->db->exec("SET LOCAL work_mem = '128MB'");

        // Create temp table for PR computation (zero PHP RAM)
        $this->db->exec("DROP TABLE IF EXISTS tmp_pr");
        $this->db->exec("CREATE TEMP TABLE tmp_pr (
            id char(8) PRIMARY KEY,
            pr float8 NOT NULL,
            outlinks int NOT NULL DEFAULT 0
        )");

        // Initialize: all pages with their outlink counts
        echo "\r \033[32m Pagerank calcul \033[0m : \033[36minitializing...\033[0m                    ";
        flush();

        $stmt = $this->db->prepare("
            INSERT INTO tmp_pr (id, pr, outlinks)
            SELECT p.id, :init_pr, COALESCE(ol.cnt, 0)
            FROM pages p
            LEFT JOIN (
                SELECT src, COUNT(*) as cnt
                FROM links WHERE crawl_id = :cid
                GROUP BY src
            ) ol ON p.id = ol.src
            WHERE p.crawl_id = :cid2
        ");
        $stmt->execute([':init_pr' => $initPR, ':cid' => $this->crawlId, ':cid2' => $this->crawlId]);

        // Iterations — entirely in PostgreSQL, zero PHP RAM
        for ($i = 0; $i < $iterations; $i++) {
            echo "\r \033[32m Pagerank calcul \033[0m : \033[36mIteration " . ($i + 1) . "/$iterations\033[0m                    ";
            flush();

            // Dead-end bonus: sum PR of pages with no outgoing links
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(pr), 0) FROM tmp_pr WHERE outlinks = 0");
            $stmt->execute();
            $deadEndPR = (float)$stmt->fetchColumn();
            $deadEndBonus = $damping * $deadEndPR / $pagesCount;
            $iterBonus = $bonus + $deadEndBonus;

            // Single UPDATE: new_pr = bonus + damping * sum(backlink_pr / backlink_outlinks)
            // PostgreSQL evaluates the FROM clause with OLD pr values before writing new ones
            $stmt = $this->db->prepare("
                UPDATE tmp_pr t
                SET pr = :bonus + :damping * COALESCE(inc.incoming_pr, 0)
                FROM (
                    SELECT t2.id, i.incoming_pr
                    FROM tmp_pr t2
                    LEFT JOIN (
                        SELECT l.target, SUM(tp.pr / tp.outlinks) as incoming_pr
                        FROM links l
                        JOIN tmp_pr tp ON tp.id = l.src AND tp.outlinks > 0
                        WHERE l.crawl_id = :cid AND l.nofollow = false
                        GROUP BY l.target
                    ) i ON t2.id = i.target
                ) inc
                WHERE t.id = inc.id
            ");
            $stmt->execute([
                ':bonus' => $iterBonus,
                ':damping' => $damping,
                ':cid' => $this->crawlId
            ]);
        }

        // Save results back to pages table (single UPDATE)
        echo "\r \033[32m Pagerank calcul \033[0m : \033[36msaving...\033[0m                    ";
        flush();

        $stmt = $this->db->prepare("
            UPDATE pages p SET pri = t.pr
            FROM tmp_pr t
            WHERE p.crawl_id = :cid AND p.id = t.id
        ");
        $stmt->execute([':cid' => $this->crawlId]);

        // Cleanup
        $this->db->exec("DROP TABLE IF EXISTS tmp_pr");

        echo "\r \033[32m Pagerank calcul \033[0m : \033[36mdone\033[0m                             \n";
        flush();
    }

    /**
     * Analyse sémantique (title, h1, metadesc status)
     * 
     * Détermine le statut de chaque balise : empty, duplicate, unique
     * Uniquement sur les pages compliant.
     */
    public function semanticAnalysis(): void
    {
        echo "\r \033[32m Semantic analysis \033[0m : \033[36mprocessing...\033[0m                    ";
        flush();

        // Une seule requête SQL avec window functions (zéro RAM PHP)
        $stmt = $this->db->prepare("
            UPDATE pages p SET
                title_status = s.title_st,
                h1_status = s.h1_st,
                metadesc_status = s.metadesc_st
            FROM (
                SELECT id,
                    CASE
                        WHEN title IS NULL OR title = '' THEN 'empty'
                        WHEN COUNT(*) OVER (PARTITION BY title) > 1 THEN 'duplicate'
                        ELSE 'unique'
                    END AS title_st,
                    CASE
                        WHEN h1 IS NULL OR h1 = '' THEN 'empty'
                        WHEN COUNT(*) OVER (PARTITION BY h1) > 1 THEN 'duplicate'
                        ELSE 'unique'
                    END AS h1_st,
                    CASE
                        WHEN metadesc IS NULL OR metadesc = '' THEN 'empty'
                        WHEN COUNT(*) OVER (PARTITION BY metadesc) > 1 THEN 'duplicate'
                        ELSE 'unique'
                    END AS metadesc_st
                FROM pages
                WHERE crawl_id = :crawl_id AND compliant = true
            ) s
            WHERE p.crawl_id = :crawl_id2 AND p.id = s.id
        ");
        $stmt->execute([':crawl_id' => $this->crawlId, ':crawl_id2' => $this->crawlId]);

        echo "\r \033[32m Semantic analysis \033[0m : \033[36mdone\033[0m                             \n";
        flush();
    }

    /**
     * Catégorisation des pages selon les règles YAML
     */
    public function categorize(): void
    {
        echo "\r \033[32m Categorisation \033[0m : \033[36mprocessing...\033[0m                    ";
        flush();

        $yamlConfig = null;

        // Get crawl's project_id
        $stmt = $this->db->prepare("SELECT project_id, domain FROM crawls WHERE id = :crawl_id");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $crawl = $stmt->fetch(PDO::FETCH_OBJ);
        $projectId = $crawl ? $crawl->project_id : null;

        // Try project-level config FIRST
        if ($projectId) {
            $stmt = $this->db->prepare("SELECT categorization_config FROM projects WHERE id = :project_id");
            $stmt->execute([':project_id' => $projectId]);
            $projectRecord = $stmt->fetch(PDO::FETCH_OBJ);

            if ($projectRecord && !empty($projectRecord->categorization_config)) {
                $yamlConfig = $projectRecord->categorization_config;
            }
        }

        // Fallback to crawl-level config (backward compatibility)
        if (!$yamlConfig) {
            $stmt = $this->db->prepare("SELECT config FROM categorization_config WHERE crawl_id = :crawl_id");
            $stmt->execute([':crawl_id' => $this->crawlId]);
            $crawlConfigRecord = $stmt->fetch(PDO::FETCH_OBJ);

            if ($crawlConfigRecord && !empty($crawlConfigRecord->config)) {
                $yamlConfig = $crawlConfigRecord->config;
            }
        }

        if (!$yamlConfig) {
            echo "\r \033[32m Categorisation \033[0m : \033[33mno config\033[0m                             \n";
            return;
        }

        $service = new CategorizationService($this->db);
        $count = $service->applyCategorization($this->crawlId, $yamlConfig);

        echo "\r \033[32m Categorisation \033[0m : \033[36mdone ($count pages)\033[0m                             \n";
        flush();
    }

    /**
     * Analyse de la duplication de contenu
     * 
     * Détecte les duplicates exacts (même simhash) et 
     * near-duplicates (distance Hamming <= 9, soit 85% similarité).
     */
    public function duplicateAnalysis(): void
    {
        echo "\r \033[32m Duplicate analysis \033[0m : \033[36mprocessing...\033[0m                    ";
        flush();
        
        // Créer la partition si elle n'existe pas
        $this->db->exec("SELECT create_crawl_partitions({$this->crawlId})");
        
        // Supprimer les anciens clusters
        $stmt = $this->db->prepare("DELETE FROM duplicate_clusters WHERE crawl_id = :crawl_id");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        
        $totalDuplicatedPages = 0;
        $totalClusters = 0;
        
        // 1. Clusters de duplication exacte (même simhash)
        echo "\r \033[32m Duplicate analysis \033[0m : \033[36mexact duplicates...\033[0m                    ";
        flush();
        
        $stmt = $this->db->prepare("
            SELECT simhash, array_agg(id) as page_ids, COUNT(*) as page_count
            FROM pages 
            WHERE crawl_id = :crawl_id 
              AND crawled = true 
              AND code = 200 
              AND compliant = true 
              AND simhash IS NOT NULL
            GROUP BY simhash
            HAVING COUNT(*) > 1
            ORDER BY COUNT(*) DESC
        ");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $exactClusters = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $insertStmt = $this->db->prepare("
            INSERT INTO duplicate_clusters (crawl_id, similarity, page_count, page_ids)
            VALUES (:crawl_id, :similarity, :page_count, :page_ids)
        ");
        
        foreach ($exactClusters as $cluster) {
            $pageIds = $cluster['page_ids'];
            $pageIdsArray = explode(',', trim($pageIds, '{}'));
            $pageIdsFormatted = '{' . implode(',', array_map(function($id) {
                return '"' . trim($id) . '"';
            }, $pageIdsArray)) . '}';
            
            $insertStmt->execute([
                ':crawl_id' => $this->crawlId,
                ':similarity' => 100,
                ':page_count' => $cluster['page_count'],
                ':page_ids' => $pageIdsFormatted
            ]);
            
            $totalDuplicatedPages += (int)$cluster['page_count'];
            $totalClusters++;
        }
        
        // 2. Near-duplicates (distance de Hamming <= 9, similarité >= 85%)
        $maxHammingDistance = 9;
        
        echo "\r \033[32m Duplicate analysis \033[0m : \033[36mnear-duplicates...\033[0m                    ";
        flush();
        
        try {
            $stmt = $this->db->prepare("
                WITH pages_sample AS (
                    SELECT id, simhash
                    FROM pages
                    WHERE crawl_id = :crawl_id 
                      AND crawled = true 
                      AND code = 200 
                      AND compliant = true 
                      AND simhash IS NOT NULL
                    ORDER BY inlinks DESC
                    LIMIT 2000
                ),
                near_pairs AS (
                    SELECT 
                        p1.id as id1, 
                        p2.id as id2,
                        100 - (bit_count((p1.simhash # p2.simhash)::bit(64)) * 100 / 64) as similarity
                    FROM pages_sample p1
                    JOIN pages_sample p2 ON p1.id < p2.id
                    WHERE bit_count((p1.simhash # p2.simhash)::bit(64)) BETWEEN 1 AND $maxHammingDistance
                      AND p1.simhash != p2.simhash
                )
                SELECT id1, id2, similarity FROM near_pairs
            ");
            $stmt->execute([':crawl_id' => $this->crawlId]);
            $nearPairs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Regrouper les paires en clusters via Union-Find
            $parent = [];
            
            foreach ($nearPairs as $pair) {
                $id1 = $pair['id1'];
                $id2 = $pair['id2'];
                
                if (!isset($parent[$id1])) $parent[$id1] = $id1;
                if (!isset($parent[$id2])) $parent[$id2] = $id2;
                
                // Find roots
                $root1 = $id1;
                while ($parent[$root1] !== $root1) $root1 = $parent[$root1];
                $root2 = $id2;
                while ($parent[$root2] !== $root2) $root2 = $parent[$root2];
                
                // Union
                if ($root1 !== $root2) {
                    $parent[$root2] = $root1;
                }
            }
            
            // Regrouper par cluster
            $nearClusters = [];
            foreach (array_keys($parent) as $id) {
                $root = $id;
                while ($parent[$root] !== $root) $root = $parent[$root];
                
                if (!isset($nearClusters[$root])) {
                    $nearClusters[$root] = [];
                }
                $nearClusters[$root][] = $id;
            }
            
            foreach ($nearClusters as $root => $pageIds) {
                if (count($pageIds) >= 2) {
                    $pageIdsFormatted = '{' . implode(',', array_map(function($id) {
                        return '"' . trim($id) . '"';
                    }, $pageIds)) . '}';
                    
                    $avgStmt = $this->db->prepare("
                        SELECT AVG(bit_count((p1.simhash # p2.simhash)::bit(64)))::int as avg_distance
                        FROM pages p1, pages p2
                        WHERE p1.crawl_id = :crawl_id AND p2.crawl_id = :crawl_id
                          AND p1.id = ANY(:page_ids) AND p2.id = ANY(:page_ids)
                          AND p1.id < p2.id
                          AND p1.compliant = true AND p2.compliant = true
                    ");
                    $avgStmt->execute([
                        ':crawl_id' => $this->crawlId,
                        ':page_ids' => $pageIdsFormatted
                    ]);
                    $avgResult = $avgStmt->fetch(\PDO::FETCH_ASSOC);
                    $avgDistance = (int)($avgResult['avg_distance'] ?? 0);
                    
                    $similarity = round((64 - $avgDistance) / 64 * 100, 1);
                    
                    $insertStmt->execute([
                        ':crawl_id' => $this->crawlId,
                        ':similarity' => (int)$similarity,
                        ':page_count' => count($pageIds),
                        ':page_ids' => $pageIdsFormatted
                    ]);
                    
                    $totalDuplicatedPages += count($pageIds);
                    $totalClusters++;
                }
            }
        } catch (\Exception $e) {
            error_log("Near-duplicate analysis failed: " . $e->getMessage());
        }
        
        // 3. Mettre à jour les stats du crawl
        $stmt = $this->db->prepare("
            UPDATE crawls 
            SET compliant_duplicate = :dup, clusters_duplicate = :clusters
            WHERE id = :id
        ");
        $stmt->execute([
            ':dup' => $totalDuplicatedPages,
            ':clusters' => $totalClusters,
            ':id' => $this->crawlId
        ]);
        
        echo "\r \033[32m Duplicate analysis \033[0m : \033[36m$totalClusters clusters, $totalDuplicatedPages pages\033[0m                    \n";
        flush();
    }

    /**
     * Analyse des chaînes de redirection
     *
     * Construit les chaînes de redirection à partir des liens de type 'redirect',
     * détecte les boucles et stocke le résultat dans redirect_chains.
     */
    public function redirectChainAnalysis(): void
    {
        echo "\r \033[32m Redirect chains \033[0m : \033[36mprocessing...\033[0m                    ";
        flush();

        // Créer la partition si elle n'existe pas
        $this->db->exec("SELECT create_crawl_partitions({$this->crawlId})");

        // Supprimer les anciennes chaînes
        $stmt = $this->db->prepare("DELETE FROM redirect_chains WHERE crawl_id = :crawl_id");
        $stmt->execute([':crawl_id' => $this->crawlId]);

        // 1. Charger tous les liens redirect du crawl → map src → target
        $stmt = $this->db->prepare("
            SELECT src, target FROM links
            WHERE crawl_id = :crawl_id AND type = 'redirect'
        ");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $redirectLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($redirectLinks)) {
            $this->updateRedirectStats(0, 0, 0);
            echo "\r \033[32m Redirect chains \033[0m : \033[33mno redirects\033[0m                             \n";
            flush();
            return;
        }

        $redirectMap = []; // src → target
        $isTarget = [];    // set of IDs that are targets

        foreach ($redirectLinks as $link) {
            $src = trim($link['src']);
            $target = trim($link['target']);
            $redirectMap[$src] = $target;
            $isTarget[$target] = true;
        }

        // 2. Chain starters = IDs that appear as src but NOT in isTarget
        $chainStarters = [];
        foreach ($redirectMap as $src => $target) {
            if (!isset($isTarget[$src])) {
                $chainStarters[] = $src;
            }
        }

        // 2b. Detect closed loops (all nodes are both src and target, so no chain starter exists)
        // Find nodes that are src but not yet covered by any chain starter's traversal
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

        // Any src node not covered is part of a closed loop - pick one per loop as starter
        $loopVisited = [];
        foreach ($redirectMap as $src => $target) {
            if (!isset($coveredByStarters[$src]) && !isset($loopVisited[$src])) {
                $chainStarters[] = $src;
                // Mark all nodes in this loop as visited to avoid duplicates
                $current = $src;
                while (true) {
                    if (isset($loopVisited[$current])) break;
                    $loopVisited[$current] = true;
                    if (!isset($redirectMap[$current])) break;
                    $current = $redirectMap[$current];
                }
            }
        }

        // 3. Collect all involved page IDs for batch loading
        $allIds = array_unique(array_merge(array_keys($redirectMap), array_values($redirectMap)));

        // 4. Batch load page info (url, code, compliant)
        $pagesMap = [];
        if (!empty($allIds)) {
            $placeholders = implode(',', array_map(function($id) {
                return $this->db->quote($id);
            }, $allIds));

            $stmt = $this->db->query("
                SELECT id, url, code, compliant
                FROM pages
                WHERE crawl_id = {$this->crawlId} AND id IN ($placeholders)
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $pagesMap[trim($row['id'])] = $row;
            }
        }

        // 5. Build chains
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
                    // End of chain - current is the final page
                    break;
                }
                $current = $redirectMap[$current];
            }

            // If it's not a loop, add the final page to chain if it's not already there
            if (!$isLoop && !empty($chainIds)) {
                $lastInChain = end($chainIds);
                if (isset($redirectMap[$lastInChain])) {
                    // Should not happen since we break when no redirect exists
                    $chainIds[] = $redirectMap[$lastInChain];
                }
            }

            $sourceId = $chainIds[0];
            $sourcePage = $pagesMap[$sourceId] ?? null;
            $sourceUrl = $sourcePage['url'] ?? null;

            if ($isLoop) {
                $finalId = null;
                $finalUrl = null;
                $finalCode = null;
                $finalCompliant = false;
                $hops = count($chainIds); // loop: all are hops
            } else {
                $finalId = end($chainIds);
                $finalPage = $pagesMap[$finalId] ?? null;
                $finalUrl = $finalPage['url'] ?? null;
                $finalCode = $finalPage ? (int)$finalPage['code'] : null;
                $finalCompliant = $finalPage ? (bool)$finalPage['compliant'] : false;
                $hops = count($chainIds) - 1;
            }

            // Only store chains with actual redirects (hops > 0)
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

        // 6. Insert chains into redirect_chains
        $insertStmt = $this->db->prepare("
            INSERT INTO redirect_chains (crawl_id, source_id, source_url, final_id, final_url, final_code, final_compliant, hops, is_loop, chain_ids)
            VALUES (:crawl_id, :source_id, :source_url, :final_id, :final_url, :final_code, :final_compliant, :hops, :is_loop, :chain_ids)
        ");

        $count = 0;
        $total = count($chains);
        $batchSize = 100;

        $this->db->beginTransaction();
        try {
            foreach ($chains as $chain) {
                $chainIdsFormatted = '{' . implode(',', array_map(function($id) {
                    return '"' . trim($id) . '"';
                }, $chain['chain_ids'])) . '}';

                $insertStmt->execute([
                    ':crawl_id' => $this->crawlId,
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

                $count++;
                if ($count % $batchSize === 0) {
                    $this->db->commit();
                    $this->db->beginTransaction();
                    echo "\r \033[32m Redirect chains \033[0m : \033[36m$count/$total\033[0m                             ";
                    flush();
                }
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        // 7. Compute metrics
        // redirect_total = number of pages with 3xx code
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM pages
            WHERE crawl_id = :crawl_id AND code >= 300 AND code < 400
        ");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $redirectTotal = (int)$stmt->fetchColumn();

        $chainsCount = count($chains);
        $chainsErrors = 0;
        foreach ($chains as $chain) {
            if ($chain['is_loop'] || ($chain['final_code'] !== null && $chain['final_code'] !== 200)) {
                $chainsErrors++;
            }
        }

        // 8. Update crawl stats
        $this->updateRedirectStats($redirectTotal, $chainsCount, $chainsErrors);

        echo "\r \033[32m Redirect chains \033[0m : \033[36m$chainsCount chains, $chainsErrors errors\033[0m                    \n";
        flush();
    }

    /**
     * Met à jour les stats de redirection dans la table crawls
     */
    private function updateRedirectStats(int $total, int $chains, int $errors): void
    {
        $stmt = $this->db->prepare("
            UPDATE crawls
            SET redirect_total = :total, redirect_chains_count = :chains, redirect_chains_errors = :errors
            WHERE id = :id
        ");
        $stmt->execute([
            ':total' => $total,
            ':chains' => $chains,
            ':errors' => $errors,
            ':id' => $this->crawlId
        ]);
    }
}
