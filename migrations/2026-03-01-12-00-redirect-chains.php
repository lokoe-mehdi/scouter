<?php
/**
 * Migration : Ajout de la table redirect_chains et colonnes de stats
 *
 * Cette migration :
 * 1. Ajoute les colonnes redirect_total, redirect_chains_count, redirect_chains_errors à crawls
 * 2. Crée la table redirect_chains partitionnée
 * 3. Met à jour les fonctions de partition
 * 4. Recalcule les données pour tous les crawls existants
 */

require_once __DIR__ . '/../app/Database/PostgresDatabase.php';

echo "Migration: redirect_chains\n";
echo "==============================\n\n";

try {
    $pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();
    $pdo->beginTransaction();

    // 1. Ajouter les colonnes à crawls si elles n'existent pas
    echo "1. Ajout des colonnes à crawls...\n";

    $checkColumn = $pdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_name = 'crawls' AND column_name = 'redirect_total'
    ");

    if ($checkColumn->rowCount() === 0) {
        $pdo->exec("ALTER TABLE crawls ADD COLUMN redirect_total INTEGER DEFAULT 0");
        $pdo->exec("ALTER TABLE crawls ADD COLUMN redirect_chains_count INTEGER DEFAULT 0");
        $pdo->exec("ALTER TABLE crawls ADD COLUMN redirect_chains_errors INTEGER DEFAULT 0");
        echo "   ✓ Colonnes ajoutées\n";
    } else {
        echo "   → Colonnes déjà existantes\n";
    }

    // 2. Créer la table redirect_chains si elle n'existe pas
    echo "2. Création de la table redirect_chains...\n";

    $checkTable = $pdo->query("
        SELECT table_name FROM information_schema.tables
        WHERE table_name = 'redirect_chains'
    ");

    if ($checkTable->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE redirect_chains (
                crawl_id INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
                id SERIAL,
                source_id CHAR(8) NOT NULL,
                source_url TEXT,
                final_id CHAR(8),
                final_url TEXT,
                final_code INTEGER,
                final_compliant BOOLEAN DEFAULT FALSE,
                hops INTEGER DEFAULT 0,
                is_loop BOOLEAN DEFAULT FALSE,
                chain_ids TEXT[] NOT NULL DEFAULT '{}',
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
            -- Advisory lock pour sérialiser la création des partitions
            PERFORM pg_advisory_lock(12345);

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

                -- Partition pour page_schemas
                EXECUTE format('CREATE TABLE IF NOT EXISTS page_schemas_%s PARTITION OF page_schemas FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);

                -- Index page_schemas
                EXECUTE format('CREATE INDEX IF NOT EXISTS idx_page_schemas_%s_schema_type ON page_schemas_%s(schema_type)', p_crawl_id, p_crawl_id);
                EXECUTE format('CREATE INDEX IF NOT EXISTS idx_page_schemas_%s_page_id ON page_schemas_%s(page_id)', p_crawl_id, p_crawl_id);

                -- Partition pour duplicate_clusters
                EXECUTE format('CREATE TABLE IF NOT EXISTS duplicate_clusters_%s PARTITION OF duplicate_clusters FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);

                -- Partition pour redirect_chains
                EXECUTE format('CREATE TABLE IF NOT EXISTS redirect_chains_%s PARTITION OF redirect_chains FOR VALUES IN (%s)', p_crawl_id, p_crawl_id);

            EXCEPTION WHEN OTHERS THEN
                -- Libérer le lock même en cas d'erreur
                PERFORM pg_advisory_unlock(12345);
                RAISE;
            END;

            -- Libérer le lock
            PERFORM pg_advisory_unlock(12345);
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
            EXECUTE format('DROP TABLE IF EXISTS page_schemas_%s', p_crawl_id);
            EXECUTE format('DROP TABLE IF EXISTS duplicate_clusters_%s', p_crawl_id);
            EXECUTE format('DROP TABLE IF EXISTS redirect_chains_%s', p_crawl_id);
        END;
        \$\$ LANGUAGE plpgsql
    ");
    echo "   ✓ Fonction mise à jour\n";

    // 5. Créer les partitions pour les crawls existants et recalculer les données
    echo "5. Création des partitions et calcul des chaînes de redirection...\n";

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
                WHERE table_name = 'redirect_chains_$crawlId'
            ");

            if ($checkPartition->rowCount() === 0) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS redirect_chains_$crawlId PARTITION OF redirect_chains FOR VALUES IN ($crawlId)");
            }

            // Supprimer les anciennes chaînes
            $pdo->exec("DELETE FROM redirect_chains WHERE crawl_id = $crawlId");

            // Charger les liens redirect
            $redirectLinks = $pdo->query("
                SELECT src, target FROM links
                WHERE crawl_id = $crawlId AND type = 'redirect'
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($redirectLinks)) {
                $pdo->prepare("
                    UPDATE crawls SET redirect_total = 0, redirect_chains_count = 0, redirect_chains_errors = 0
                    WHERE id = :id
                ")->execute([':id' => $crawlId]);
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

            // Chain starters
            $chainStarters = [];
            foreach ($redirectMap as $src => $target) {
                if (!isset($isTarget[$src])) {
                    $chainStarters[] = $src;
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

            // Insert chains
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
