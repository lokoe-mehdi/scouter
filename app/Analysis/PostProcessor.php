<?php

namespace App\Analysis;

use PDO;
use App\Database\PostgresDatabase;

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
        
        $this->calculateInlinks();
        $this->calculatePagerank();
        $this->semanticAnalysis();
        $this->categorize();
        $this->duplicateAnalysis();
        
        echo "\n\033[32m✓ Post-traitement terminé\033[0m\n\n";
        flush();
    }

    /**
     * Calcul des inlinks pour chaque page
     */
    public function calculateInlinks(): void
    {
        echo "\r \033[32m Inlinks calcul \033[0m : \033[36mprocessing...\033[0m                    ";
        flush();
        
        $stmt = $this->db->prepare("
            UPDATE pages p SET inlinks = (
                SELECT COUNT(*) FROM links l 
                WHERE l.crawl_id = :crawl_id AND l.target = p.id
            )
            WHERE p.crawl_id = :crawl_id2
        ");
        $stmt->execute([':crawl_id' => $this->crawlId, ':crawl_id2' => $this->crawlId]);
        
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
        
        // Récupérer tous les liens
        $stmt = $this->db->prepare("SELECT src, target, nofollow FROM links WHERE crawl_id = :crawl_id");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $links = $stmt->fetchAll(PDO::FETCH_OBJ);
        
        if (empty($links)) {
            echo "\r \033[32m Pagerank calcul \033[0m : \033[33mno links\033[0m                             \n";
            return;
        }
        
        // Construire les structures de données
        $pages = [];
        $backlinks = [];
        
        foreach ($links as $link) {
            if (!isset($pages[$link->src])) {
                $pages[$link->src] = ['links' => 0, 'PR' => 0];
            }
            if (!isset($pages[$link->target])) {
                $pages[$link->target] = ['links' => 0, 'PR' => 0];
            }
            if (!isset($backlinks[$link->target])) {
                $backlinks[$link->target] = [];
            }
            
            $pages[$link->src]['links']++;
            if (!$link->nofollow) {
                $backlinks[$link->target][] = $link->src;
            }
        }
        
        $pagesCount = count($pages);
        if ($pagesCount === 0) {
            echo "\r \033[32m Pagerank calcul \033[0m : \033[33mno pages\033[0m                             \n";
            return;
        }
        
        $bonus = (1 - $damping) / $pagesCount;
        
        // Initialiser le PageRank
        foreach ($pages as $id => &$page) {
            $page['PR'] = 1 / $pagesCount;
        }
        unset($page);
        
        // Itérations
        for ($i = 0; $i < $iterations; $i++) {
            echo "\r \033[32m Pagerank calcul \033[0m : \033[36mIteration " . ($i + 1) . "/$iterations\033[0m                    ";
            flush();
            
            // Calculer le bonus des pages sans liens sortants
            $deadEndBonus = 0;
            foreach ($pages as $page) {
                if ($page['links'] == 0) {
                    $deadEndBonus += $page['PR'];
                }
            }
            $deadEndBonus = $damping * ($deadEndBonus / $pagesCount);
            
            // Calculer le nouveau PR
            $newPR = [];
            foreach ($pages as $id => $page) {
                $pr = 0;
                if (isset($backlinks[$id])) {
                    foreach ($backlinks[$id] as $bl) {
                        if ($pages[$bl]['links'] > 0) {
                            $pr += $pages[$bl]['PR'] / $pages[$bl]['links'];
                        }
                    }
                }
                $newPR[$id] = $pr * $damping + $bonus + $deadEndBonus;
            }
            
            // Mettre à jour
            foreach ($newPR as $id => $pr) {
                $pages[$id]['PR'] = $pr;
            }
        }
        
        // Stocker les résultats par batch pour éviter "out of shared memory"
        $stmt = $this->db->prepare("UPDATE pages SET pri = :pr WHERE crawl_id = :crawl_id AND id = :id");
        $batchSize = 100;
        $count = 0;
        $total = count($pages);
        
        $this->db->beginTransaction();
        try {
            foreach ($pages as $id => $page) {
                $stmt->execute([
                    ':pr' => round($page['PR'], 8),
                    ':crawl_id' => $this->crawlId,
                    ':id' => $id
                ]);
                $count++;
                
                // Commit par batch pour libérer les verrous
                if ($count % $batchSize === 0) {
                    $this->db->commit();
                    $this->db->beginTransaction();
                    echo "\r \033[32m Pagerank calcul \033[0m : \033[36msaving $count/$total\033[0m                    ";
                    flush();
                }
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        
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
        
        // Récupérer uniquement les pages compliant
        $stmt = $this->db->prepare("
            SELECT id, title, h1, metadesc 
            FROM pages 
            WHERE crawl_id = :crawl_id AND compliant = 'true'
        ");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $pages = $stmt->fetchAll(PDO::FETCH_OBJ);
        
        // Créer des maps pour détecter les doublons
        $titles = [];
        $h1s = [];
        $metadescs = [];
        
        foreach ($pages as $page) {
            if (!empty($page->title)) {
                $titles[$page->title][] = $page->id;
            }
            if (!empty($page->h1)) {
                $h1s[$page->h1][] = $page->id;
            }
            if (!empty($page->metadesc)) {
                $metadescs[$page->metadesc][] = $page->id;
            }
        }
        
        $updateStmt = $this->db->prepare("
            UPDATE pages SET 
                title_status = :title_status,
                h1_status = :h1_status,
                metadesc_status = :metadesc_status
            WHERE crawl_id = :crawl_id AND id = :id
        ");
        
        $count = 0;
        $total = count($pages);
        $batchSize = 100;
        
        $this->db->beginTransaction();
        try {
            foreach ($pages as $page) {
                $count++;
                
                // Title status
                $titleStatus = null;
                if (empty($page->title)) {
                    $titleStatus = 'empty';
                } elseif (isset($titles[$page->title]) && count($titles[$page->title]) > 1) {
                    $titleStatus = 'duplicate';
                } else {
                    $titleStatus = 'unique';
                }
                
                // H1 status
                $h1Status = null;
                if (empty($page->h1)) {
                    $h1Status = 'empty';
                } elseif (isset($h1s[$page->h1]) && count($h1s[$page->h1]) > 1) {
                    $h1Status = 'duplicate';
                } else {
                    $h1Status = 'unique';
                }
                
                // Metadesc status
                $metadescStatus = null;
                if (empty($page->metadesc)) {
                    $metadescStatus = 'empty';
                } elseif (isset($metadescs[$page->metadesc]) && count($metadescs[$page->metadesc]) > 1) {
                    $metadescStatus = 'duplicate';
                } else {
                    $metadescStatus = 'unique';
                }
                
                $updateStmt->execute([
                    ':title_status' => $titleStatus,
                    ':h1_status' => $h1Status,
                    ':metadesc_status' => $metadescStatus,
                    ':crawl_id' => $this->crawlId,
                    ':id' => $page->id
                ]);
                
                // Commit par batch pour libérer les verrous
                if ($count % $batchSize === 0) {
                    $this->db->commit();
                    $this->db->beginTransaction();
                    echo "\r \033[32m Semantic analysis \033[0m : \033[36m$count/$total\033[0m                    ";
                    flush();
                }
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        
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
        $domain = $crawl ? $crawl->domain : '';

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

        // Parser le YAML avec Spyc
        $catConfig = \Spyc::YAMLLoadString($yamlConfig);
        if (empty($catConfig)) {
            echo "\r \033[32m Categorisation \033[0m : \033[33mempty config\033[0m                             \n";
            return;
        }
        
        // Supprimer les catégories existantes pour ce crawl
        $this->db->prepare("DELETE FROM categories WHERE crawl_id = :crawl_id")
                 ->execute([':crawl_id' => $this->crawlId]);
        
        // Créer les catégories
        $categories = [];
        $catOrder = [];
        $insertCat = $this->db->prepare("INSERT INTO categories (crawl_id, cat, color) VALUES (:crawl_id, :cat, :color) RETURNING id");
        
        foreach ($catConfig as $catName => $rules) {
            $color = isset($rules['color']) ? $rules['color'] : '#aaaaaa';
            $color = trim($color, '"\'');
            
            $insertCat->execute([':crawl_id' => $this->crawlId, ':cat' => $catName, ':color' => $color]);
            $catId = $insertCat->fetch(PDO::FETCH_OBJ)->id;
            $categories[$catName] = [
                'id' => $catId,
                'rules' => $rules
            ];
            $catOrder[] = $catName;
        }
        
        // Récupérer toutes les pages
        $stmt = $this->db->prepare("SELECT id, url FROM pages WHERE crawl_id = :crawl_id");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $pages = $stmt->fetchAll(PDO::FETCH_OBJ);
        
        $updateStmt = $this->db->prepare("UPDATE pages SET cat_id = :cat_id WHERE crawl_id = :crawl_id AND id = :id");
        
        $count = 0;
        $total = count($pages);
        $batchSize = 100;
        
        $this->db->beginTransaction();
        try {
            foreach ($pages as $page) {
                $count++;
                
                $url = $page->url;
                $catId = null;
                
                // Normaliser l'URL
                $urlPath = preg_replace('#^https?://#i', '', $url);
                $urlPath = preg_replace('#^' . preg_quote($domain, '#') . '#i', '', $urlPath);
                
                // Parcourir les catégories dans l'ordre
                foreach ($catOrder as $catName) {
                    $cat = $categories[$catName];
                    $rules = $cat['rules'];
                    
                    // Vérifier le domaine
                    $domRule = $rules['dom'] ?? '.*';
                    if (is_array($domRule)) {
                        $domRule = $domain;
                    }
                    if (!preg_match('#' . preg_quote($domRule, '#') . '#i', $url)) {
                        continue;
                    }
                    
                    // Vérifier les patterns include
                    $included = false;
                    $includes = $rules['include'] ?? [];
                    foreach ($includes as $pattern) {
                        if (is_array($pattern)) continue;
                        if (preg_match("#$pattern#i", $urlPath)) {
                            $included = true;
                            break;
                        }
                    }
                    
                    if (!$included) {
                        continue;
                    }
                    
                    // Vérifier les patterns exclude
                    $excluded = false;
                    $excludes = $rules['exclude'] ?? [];
                    foreach ($excludes as $pattern) {
                        if (is_array($pattern)) continue;
                        if (preg_match("#$pattern#i", $urlPath)) {
                            $excluded = true;
                            break;
                        }
                    }
                    
                    if ($excluded) {
                        continue;
                    }
                    
                    $catId = $cat['id'];
                    break;
                }
                
                if ($catId !== null) {
                    $updateStmt->execute([
                        ':cat_id' => $catId,
                        ':crawl_id' => $this->crawlId,
                        ':id' => $page->id
                    ]);
                }
                
                // Commit par batch pour libérer les verrous
                if ($count % $batchSize === 0) {
                    $this->db->commit();
                    $this->db->beginTransaction();
                    echo "\r \033[32m Categorisation \033[0m : \033[36m$count/$total\033[0m                    ";
                    flush();
                }
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        
        echo "\r \033[32m Categorisation \033[0m : \033[36mdone\033[0m                             \n";
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
}
