<?php
/**
 * Migration : Ajout de la table duplicate_clusters et colonnes de stats
 * 
 * Cette migration :
 * 1. Ajoute les colonnes compliant_duplicate et clusters_duplicate à crawls
 * 2. Crée la table duplicate_clusters partitionnée
 * 3. Met à jour les fonctions de partition
 * 4. Recalcule les données pour tous les crawls existants
 */

require_once __DIR__ . '/../app/Database/PostgresDatabase.php';

echo "Migration: duplicate_clusters\n";
echo "==============================\n\n";

try {
    $pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();
    $pdo->beginTransaction();
    
    // 1. Ajouter les colonnes à crawls si elles n'existent pas
    echo "1. Ajout des colonnes à crawls...\n";
    
    $checkColumn = $pdo->query("
        SELECT column_name FROM information_schema.columns 
        WHERE table_name = 'crawls' AND column_name = 'compliant_duplicate'
    ");
    
    if ($checkColumn->rowCount() === 0) {
        $pdo->exec("ALTER TABLE crawls ADD COLUMN compliant_duplicate INTEGER DEFAULT 0");
        $pdo->exec("ALTER TABLE crawls ADD COLUMN clusters_duplicate INTEGER DEFAULT 0");
        echo "   ✓ Colonnes ajoutées\n";
    } else {
        echo "   → Colonnes déjà existantes\n";
    }
    
    // 2. Créer la table duplicate_clusters si elle n'existe pas
    echo "2. Création de la table duplicate_clusters...\n";
    
    $checkTable = $pdo->query("
        SELECT table_name FROM information_schema.tables 
        WHERE table_name = 'duplicate_clusters'
    ");
    
    if ($checkTable->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE duplicate_clusters (
                crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
                id SERIAL,
                similarity INTEGER NOT NULL DEFAULT 100,
                page_count INTEGER NOT NULL DEFAULT 0,
                page_ids TEXT[] NOT NULL DEFAULT '{}',
                PRIMARY KEY (crawl_id, id)
            ) PARTITION BY LIST (crawl_id)
        ");
        echo "   ✓ Table créée\n";
    } else {
        echo "   → Table déjà existante\n";
    }
    
    // 3. Mettre à jour la fonction create_crawl_partitions
    echo "3. Mise à jour de create_crawl_partitions...\n";
    
    $pdo->exec("
        CREATE OR REPLACE FUNCTION create_crawl_partitions(p_crawl_id INTEGER)
        RETURNS VOID AS \$\$
        BEGIN
            -- Partition pour categories
            EXECUTE format('CREATE TABLE IF NOT EXISTS categories_%s PARTITION OF categories FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            
            -- Partition pour pages
            EXECUTE format('CREATE TABLE IF NOT EXISTS pages_%s PARTITION OF pages FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            
            -- Index pages: colonnes de base
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_id ON pages_%s(id)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_url ON pages_%s(url)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_code ON pages_%s(code)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_depth ON pages_%s(depth)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_cat_id ON pages_%s(cat_id)', p_crawl_id, p_crawl_id);
            
            -- Index pages: colonnes de filtrage/tri booléens
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_crawled ON pages_%s(crawled)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_compliant ON pages_%s(compliant)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_noindex ON pages_%s(noindex)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_nofollow ON pages_%s(nofollow)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_external ON pages_%s(external)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_blocked ON pages_%s(blocked)', p_crawl_id, p_crawl_id);
            
            -- Index pages: canonical
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_canonical ON pages_%s(canonical)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_canonical_value ON pages_%s(canonical_value) WHERE canonical_value IS NOT NULL', p_crawl_id, p_crawl_id);
            
            -- Index pages: statuts SEO
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_title_status ON pages_%s(title_status)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_h1_status ON pages_%s(h1_status)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_metadesc_status ON pages_%s(metadesc_status)', p_crawl_id, p_crawl_id);
            
            -- Index pages: métriques
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_inlinks ON pages_%s(inlinks)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_response_time ON pages_%s(response_time)', p_crawl_id, p_crawl_id);
            
            -- Index pages: simhash
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_simhash ON pages_%s(simhash) WHERE simhash IS NOT NULL', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_pages_%s_is_html ON pages_%s(is_html)', p_crawl_id, p_crawl_id);
            
            -- Partition pour links
            EXECUTE format('CREATE TABLE IF NOT EXISTS links_%s PARTITION OF links FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_src ON links_%s(src)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_target ON links_%s(target)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_external ON links_%s(external)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_nofollow ON links_%s(nofollow)', p_crawl_id, p_crawl_id);
            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_links_%s_type ON links_%s(type)', p_crawl_id, p_crawl_id);
            
            -- Partition pour html
            EXECUTE format('CREATE TABLE IF NOT EXISTS html_%s PARTITION OF html FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
            
            -- Partition pour duplicate_clusters
            EXECUTE format('CREATE TABLE IF NOT EXISTS duplicate_clusters_%s PARTITION OF duplicate_clusters FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);
        END;
        \$\$ LANGUAGE plpgsql
    ");
    echo "   ✓ Fonction mise à jour\n";
    
    // 4. Mettre à jour la fonction drop_crawl_partitions
    echo "4. Mise à jour de drop_crawl_partitions...\n";
    
    $pdo->exec("
        CREATE OR REPLACE FUNCTION drop_crawl_partitions(p_crawl_id INTEGER)
        RETURNS VOID AS \$\$
        BEGIN
            EXECUTE format('DROP TABLE IF EXISTS categories_%s', p_crawl_id);
            EXECUTE format('DROP TABLE IF EXISTS pages_%s', p_crawl_id);
            EXECUTE format('DROP TABLE IF EXISTS links_%s', p_crawl_id);
            EXECUTE format('DROP TABLE IF EXISTS html_%s', p_crawl_id);
            EXECUTE format('DROP TABLE IF EXISTS duplicate_clusters_%s', p_crawl_id);
        END;
        \$\$ LANGUAGE plpgsql
    ");
    echo "   ✓ Fonction mise à jour\n";
    
    // 5. Créer les partitions pour les crawls existants et recalculer les données
    echo "5. Création des partitions et recalcul des données...\n";
    
    // Inclure les crawls finished ET stopped (qui ont des données exploitables)
    $crawls = $pdo->query("SELECT id FROM crawls WHERE status IN ('finished', 'stopped', 'pending')")->fetchAll(PDO::FETCH_OBJ);
    $totalCrawls = count($crawls);
    
    if ($totalCrawls === 0) {
        echo "   → Aucun crawl terminé à traiter\n";
    } else {
        echo "   Traitement de $totalCrawls crawls...\n";
        
        foreach ($crawls as $index => $crawl) {
            $crawlId = $crawl->id;
            $num = $index + 1;
            echo "   [$num/$totalCrawls] Crawl #$crawlId : ";
            
            // Créer la partition si elle n'existe pas
            $checkPartition = $pdo->query("
                SELECT table_name FROM information_schema.tables 
                WHERE table_name = 'duplicate_clusters_$crawlId'
            ");
            
            if ($checkPartition->rowCount() === 0) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS duplicate_clusters_$crawlId PARTITION OF duplicate_clusters FOR VALUES IN ($crawlId)");
            }
            
            // Supprimer les anciens clusters
            $pdo->exec("DELETE FROM duplicate_clusters WHERE crawl_id = $crawlId");
            
            // Calculer les clusters de duplication exacte (même simhash)
            $exactClusters = $pdo->query("
                SELECT simhash, array_agg(id) as page_ids, COUNT(*) as page_count
                FROM pages 
                WHERE crawl_id = $crawlId 
                  AND crawled = true 
                  AND code = 200 
                  AND compliant = true 
                  AND simhash IS NOT NULL
                GROUP BY simhash
                HAVING COUNT(*) > 1
                ORDER BY COUNT(*) DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $totalDuplicatedPages = 0;
            $totalClusters = 0;
            
            // Insérer les clusters exacts
            $insertStmt = $pdo->prepare("
                INSERT INTO duplicate_clusters (crawl_id, similarity, page_count, page_ids)
                VALUES (:crawl_id, :similarity, :page_count, :page_ids)
            ");
            
            foreach ($exactClusters as $cluster) {
                // Convertir le format PostgreSQL array en PHP array puis retour
                $pageIds = $cluster['page_ids'];
                // PostgreSQL retourne {id1,id2,id3} - on doit le parser
                $pageIdsArray = explode(',', trim($pageIds, '{}'));
                $pageIdsFormatted = '{' . implode(',', array_map(function($id) {
                    return '"' . trim($id) . '"';
                }, $pageIdsArray)) . '}';
                
                $insertStmt->execute([
                    ':crawl_id' => $crawlId,
                    ':similarity' => 100,
                    ':page_count' => $cluster['page_count'],
                    ':page_ids' => $pageIdsFormatted
                ]);
                
                $totalDuplicatedPages += (int)$cluster['page_count'];
                $totalClusters++;
            }
            
            // Calculer les near-duplicates (distance de Hamming <= 9, similarité >= 85%)
            // Formule: maxHammingDistance = floor(64 * (1 - 0.85)) = 9 bits
            $maxHammingDistance = 9;
            $nearDupQuery = "
                WITH pages_sample AS (
                    SELECT id, simhash
                    FROM pages
                    WHERE crawl_id = $crawlId 
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
            ";
            
            try {
                $nearPairs = $pdo->query($nearDupQuery)->fetchAll(PDO::FETCH_ASSOC);
                
                // Regrouper les paires en clusters via Union-Find
                $parent = [];
                
                foreach ($nearPairs as $pair) {
                    $id1 = $pair['id1'];
                    $id2 = $pair['id2'];
                    
                    if (!isset($parent[$id1])) $parent[$id1] = $id1;
                    if (!isset($parent[$id2])) $parent[$id2] = $id2;
                    
                    // Union
                    $root1 = $id1;
                    while ($parent[$root1] !== $root1) $root1 = $parent[$root1];
                    $root2 = $id2;
                    while ($parent[$root2] !== $root2) $root2 = $parent[$root2];
                    
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
                
                // Pour chaque cluster, calculer la similarité moyenne (comme l'ancien code)
                // en faisant la moyenne des distances de Hamming de TOUTES les paires du cluster
                foreach ($nearClusters as $root => $pageIds) {
                    if (count($pageIds) >= 2) {
                        $pageIdsFormatted = '{' . implode(',', array_map(function($id) {
                            return '"' . trim($id) . '"';
                        }, $pageIds)) . '}';
                        
                        // Calculer la distance moyenne de Hamming pour ce cluster via SQL
                        $avgResult = $pdo->query("
                            SELECT AVG(bit_count((p1.simhash # p2.simhash)::bit(64)))::int as avg_distance
                            FROM pages p1, pages p2
                            WHERE p1.crawl_id = $crawlId AND p2.crawl_id = $crawlId
                              AND p1.id = ANY('$pageIdsFormatted') AND p2.id = ANY('$pageIdsFormatted')
                              AND p1.id < p2.id
                              AND p1.compliant = true AND p2.compliant = true
                        ")->fetch(PDO::FETCH_ASSOC);
                        $avgDistance = (int)($avgResult['avg_distance'] ?? 0);
                        
                        // Convertir en pourcentage de similarité (comme l'ancien code)
                        // similarity = (64 - avgDistance) / 64 * 100
                        $similarity = (int)round((64 - $avgDistance) / 64 * 100, 1);
                        
                        $insertStmt->execute([
                            ':crawl_id' => $crawlId,
                            ':similarity' => $similarity,
                            ':page_count' => count($pageIds),
                            ':page_ids' => $pageIdsFormatted
                        ]);
                        
                        $totalDuplicatedPages += count($pageIds);
                        $totalClusters++;
                    }
                }
            } catch (Exception $e) {
                // Ignorer les erreurs de near-duplicate (peut échouer si pas de simhash)
            }
            
            // Mettre à jour les stats du crawl
            $pdo->prepare("
                UPDATE crawls 
                SET compliant_duplicate = :dup, clusters_duplicate = :clusters
                WHERE id = :id
            ")->execute([
                ':dup' => $totalDuplicatedPages,
                ':clusters' => $totalClusters,
                ':id' => $crawlId
            ]);
            
            echo "$totalClusters clusters, $totalDuplicatedPages pages dupliquées\n";
        }
    }
    
    $pdo->commit();
    
    echo "\n✓ Migration terminée avec succès\n";
    
} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    echo "\n✗ Erreur : " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
