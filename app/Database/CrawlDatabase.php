<?php

namespace App\Database;

use PDO;
use PDOException;
use App\Analysis\PostProcessor;

/**
 * Gestion des données de crawl dans PostgreSQL
 * 
 * Cette classe gère les opérations sur les données de crawl :
 * - Pages : insertion, mise à jour, requêtes
 * - Liens : insertion en batch
 * - HTML : stockage du contenu brut
 * - Statistiques : mise à jour des stats de crawl
 * 
 * Les analyses post-crawl sont déléguées à App\Analysis\PostProcessor.
 * 
 * @package    Scouter
 * @subpackage Database
 * @author     Mehdi Colin
 * @version    2.0.0
 */
class CrawlDatabase
{
    use DeadlockRetry;
    
    private PDO $db;
    private int $crawlId;
    private array $config;

    public function __construct(int $crawlId, array $config = [])
    {
        $this->db = PostgresDatabase::getInstance()->getConnection();
        $this->crawlId = $crawlId;
        $this->config = $config;
    }

    /**
     * Retourne la connexion PDO
     */
    public function getDb(): PDO
    {
        return $this->db;
    }

    /**
     * Retourne le crawl_id
     */
    public function getCrawlId(): int
    {
        return $this->crawlId;
    }

    /**
     * Récupère le statut actuel du crawl
     */
    public function getCrawlStatus(): string
    {
        $stmt = $this->db->prepare("SELECT status FROM crawls WHERE id = :id");
        $stmt->execute([':id' => $this->crawlId]);
        return (string)$stmt->fetchColumn();
    }

    /**
     * Crée les partitions pour ce crawl
     * Utilise un advisory lock EXCLUSIF pour bloquer tous les crawls pendant la création
     */
    public function createPartitions(): void
    {
        $crawlId = $this->crawlId;
        
        // Prendre un lock EXCLUSIF - bloque tous les shared locks des crawls
        // Cela garantit qu'aucun crawl ne fait d'UPDATE pendant la création
        $this->db->exec("SELECT pg_advisory_lock(12345)");
        
        try {
            $stmt = $this->db->prepare("SELECT create_crawl_partitions(:crawl_id)");
            $stmt->execute([':crawl_id' => $crawlId]);
        } finally {
            // Toujours libérer le lock
            $this->db->exec("SELECT pg_advisory_unlock(12345)");
        }
    }
    
    /**
     * Prend un shared lock avant les opérations critiques
     * Permet à plusieurs crawls de travailler en parallèle
     * Mais bloque si une création de partition est en cours
     */
    public function acquireSharedLock(): void
    {
        $this->db->exec("SELECT pg_advisory_lock_shared(12345)");
    }
    
    /**
     * Libère le shared lock
     */
    public function releaseSharedLock(): void
    {
        $this->db->exec("SELECT pg_advisory_unlock_shared(12345)");
    }

    /**
     * Convertit une valeur en string boolean pour PostgreSQL
     */
    private function toBool($value): string
    {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_numeric($value)) return $value ? 'true' : 'false';
        if (is_string($value)) {
            if (in_array(strtolower($value), ['true', '1', 'yes', 't'])) return 'true';
            return 'false';
        }
        return 'false';
    }

    /**
     * Insère une page (URL découverte) avec retry sur deadlock
     */
    public function insertPage(array $data): void
    {
        $params = [
            ':crawl_id' => $this->crawlId,
            ':id' => $data['id'],
            ':domain' => $data['domain'] ?? '',
            ':url' => $data['url'],
            ':depth' => (int)($data['depth'] ?? 0),
            ':code' => (int)($data['code'] ?? 0),
            ':crawled' => $this->toBool($data['crawled'] ?? false),
            ':external' => $this->toBool($data['external'] ?? false),
            ':blocked' => $this->toBool($data['blocked'] ?? false),
            ':date' => $data['date'] ?? date('Y-m-d H:i:s')
        ];
        
        $this->executeWithRetry($this->db, function($pdo) use ($params) {
            $stmt = $pdo->prepare("
                INSERT INTO pages (crawl_id, id, domain, url, depth, code, crawled, external, blocked, date)
                VALUES (:crawl_id, :id, :domain, :url, :depth, :code, :crawled, :external, :blocked, :date)
                ON CONFLICT (crawl_id, id) DO NOTHING
            ");
            $stmt->execute($params);
        });
    }

    /**
     * Met à jour une page après crawl (avec retry sur deadlock)
     */
    public function updatePage(string $pageId, array $data): void
    {
        $sets = [];
        $params = [':crawl_id' => $this->crawlId, ':id' => $pageId];
        
        // Champs booleans qui nécessitent une conversion
        $boolFields = ['crawled', 'nofollow', 'compliant', 'noindex', 'external', 'blocked', 'canonical', 'is_html', 'h1_multiple', 'headings_missing'];
        
        $fields = ['code', 'crawled', 'content_type', 'outlinks', 'date', 'nofollow', 
                   'compliant', 'noindex', 'canonical', 'canonical_value', 'redirect_to', 'response_time',
                   'title', 'h1', 'metadesc', 'title_status', 'h1_status', 'metadesc_status',
                   'simhash', 'is_html', 'h1_multiple', 'headings_missing', 'word_count'];
        
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = :$field";
                // Convertir les booleans
                if (in_array($field, $boolFields)) {
                    $params[":$field"] = $this->toBool($data[$field]);
                } else {
                    $params[":$field"] = $data[$field];
                }
            }
        }
        
        // Gestion spéciale pour extracts (JSONB)
        if (isset($data['extracts'])) {
            $sets[] = "extracts = :extracts";
            $params[':extracts'] = json_encode($data['extracts']);
        }
        
        // Gestion spéciale pour schemas (TEXT[])
        if (isset($data['schemas'])) {
            $sets[] = "schemas = :schemas";
            $params[':schemas'] = $this->toPostgresArray($data['schemas']);
        }
        
        if (empty($sets)) return;
        
        $sql = "UPDATE pages SET " . implode(', ', $sets) . " WHERE crawl_id = :crawl_id AND id = :id";
        
        // Utiliser le retry avec backoff exponentiel pour les deadlocks
        $this->executeWithRetry($this->db, function($pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        });
    }

    /**
     * Convertit un array PHP en syntaxe PostgreSQL TEXT[]
     */
    private function toPostgresArray(array $arr): string
    {
        if (empty($arr)) {
            return '{}';
        }
        
        $escaped = array_map(function($item) {
            // Échapper les guillemets doubles et antislashes
            $item = str_replace('\\', '\\\\', $item);
            $item = str_replace('"', '\\"', $item);
            return '"' . $item . '"';
        }, $arr);
        
        return '{' . implode(',', $escaped) . '}';
    }
    
    /**
     * Insère les types de schémas pour une page dans la table page_schemas (TRUE batch)
     */
    public function insertPageSchemas(string $pageId, array $schemas): void
    {
        if (empty($schemas)) return;
        
        $values = [];
        $params = [];
        $i = 0;
        
        foreach ($schemas as $schemaType) {
            $values[] = "(:crawl_id{$i}, :page_id{$i}, :schema_type{$i})";
            $params[":crawl_id{$i}"] = $this->crawlId;
            $params[":page_id{$i}"] = $pageId;
            $params[":schema_type{$i}"] = $schemaType;
            $i++;
        }
        
        $sql = "INSERT INTO page_schemas (crawl_id, page_id, schema_type) VALUES " 
             . implode(', ', $values) 
             . " ON CONFLICT (crawl_id, page_id, schema_type) DO NOTHING";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Insère un lien (avec retry sur deadlock)
     */
    public function insertLink(array $data): void
    {
        $params = [
            ':crawl_id' => $this->crawlId,
            ':src' => $data['src'],
            ':target' => $data['target'],
            ':anchor' => $data['anchor'] ?? '',
            ':type' => $data['type'] ?? 'ahref',
            ':external' => $this->toBool($data['external'] ?? false),
            ':nofollow' => $this->toBool($data['nofollow'] ?? false)
        ];
        
        $this->executeWithRetry($this->db, function($pdo) use ($params) {
            $stmt = $pdo->prepare("
                INSERT INTO links (crawl_id, src, target, anchor, type, external, nofollow)
                VALUES (:crawl_id, :src, :target, :anchor, :type, :external, :nofollow)
                ON CONFLICT (crawl_id, src, target) DO NOTHING
            ");
            $stmt->execute($params);
        });
    }

    /**
     * Insère plusieurs liens en batch (TRUE batch insert - 1 query pour N liens)
     * Avec retry automatique sur deadlock
     */
    public function insertLinks(array $links): void
    {
        if (empty($links)) return;
        
        // Batch par chunks de 100 pour éviter les requêtes trop longues
        $chunks = array_chunk($links, 100);
        
        foreach ($chunks as $chunk) {
            $values = [];
            $params = [];
            $i = 0;
            
            foreach ($chunk as $link) {
                $values[] = "(:crawl_id{$i}, :src{$i}, :target{$i}, :anchor{$i}, :type{$i}, :external{$i}, :nofollow{$i})";
                $params[":crawl_id{$i}"] = $this->crawlId;
                $params[":src{$i}"] = $link['src'];
                $params[":target{$i}"] = $link['target'];
                $params[":anchor{$i}"] = mb_substr($link['anchor'] ?? '', 0, 500);
                $params[":type{$i}"] = $link['type'] ?? 'ahref';
                $params[":external{$i}"] = $this->toBool($link['external'] ?? false);
                $params[":nofollow{$i}"] = $this->toBool($link['nofollow'] ?? false);
                $i++;
            }
            
            $sql = "INSERT INTO links (crawl_id, src, target, anchor, type, external, nofollow) VALUES " 
                 . implode(', ', $values) 
                 . " ON CONFLICT (crawl_id, src, target) DO NOTHING";
            
            // Retry sur deadlock
            $this->executeWithRetry($this->db, function($pdo) use ($sql, $params) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            });
        }
    }

    /**
     * Insère plusieurs pages en batch (TRUE batch insert - 1 query pour N pages)
     * Avec retry automatique sur deadlock
     */
    public function insertPages(array $pages): void
    {
        if (empty($pages)) return;
        
        // Batch par chunks de 100 pour éviter les requêtes trop longues
        $chunks = array_chunk($pages, 100);
        
        foreach ($chunks as $chunk) {
            $values = [];
            $params = [];
            $i = 0;
            
            foreach ($chunk as $page) {
                $values[] = "(:crawl_id{$i}, :id{$i}, :domain{$i}, :url{$i}, :depth{$i}, :code{$i}, :crawled{$i}, :external{$i}, :blocked{$i}, :date{$i})";
                $params[":crawl_id{$i}"] = $this->crawlId;
                $params[":id{$i}"] = $page['id'];
                $params[":domain{$i}"] = $page['domain'] ?? '';
                $params[":url{$i}"] = $page['url'];
                $params[":depth{$i}"] = (int)($page['depth'] ?? 0);
                $params[":code{$i}"] = (int)($page['code'] ?? 0);
                $params[":crawled{$i}"] = $this->toBool($page['crawled'] ?? false);
                $params[":external{$i}"] = $this->toBool($page['external'] ?? false);
                $params[":blocked{$i}"] = $this->toBool($page['blocked'] ?? false);
                $params[":date{$i}"] = $page['date'] ?? date('Y-m-d H:i:s');
                $i++;
            }
            
            $sql = "INSERT INTO pages (crawl_id, id, domain, url, depth, code, crawled, external, blocked, date) VALUES " 
                 . implode(', ', $values) 
                 . " ON CONFLICT (crawl_id, id) DO NOTHING";
            
            // Retry sur deadlock
            $this->executeWithRetry($this->db, function($pdo) use ($sql, $params) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            });
        }
    }

    /**
     * Insère le HTML d'une page (avec retry sur deadlock)
     * 
     * Limite la taille du HTML à 1MB pour éviter les timeouts sur les pages anormalement grandes.
     * Les pages trop volumineuses sont tronquées avec un marqueur.
     */
    public function insertHtml(string $pageId, string $html): void
    {
        // Limite à 1MB pour éviter les timeouts sur insertion
        $maxSize = 1 * 1024 * 1024; // 1MB
        
        if (strlen($html) > $maxSize) {
            error_log("HTML too large for page {$pageId}: " . strlen($html) . " bytes, truncating to {$maxSize}");
            $html = substr($html, 0, $maxSize) . "\n<!-- TRUNCATED: Original size was " . strlen($html) . " bytes -->";
        }
        
        $this->executeWithRetry($this->db, function($pdo) use ($pageId, $html) {
            $stmt = $pdo->prepare("
                INSERT INTO html (crawl_id, id, html)
                VALUES (:crawl_id, :id, :html)
                ON CONFLICT (crawl_id, id) DO NOTHING
            ");
            
            $stmt->execute([
                ':crawl_id' => $this->crawlId,
                ':id' => $pageId,
                ':html' => $html
            ]);
        });
    }

    /**
     * Récupère les URLs à crawler
     * Note: FOR UPDATE SKIP LOCKED n'est pas utilisé ici car les URLs sont
     * récupérées en batch avant le crawl parallèle (pas de contention)
     */
    public function getUrlsToCrawl(bool $respectRobots = true): array
    {
        $sql = "SELECT url FROM pages WHERE crawl_id = :crawl_id AND crawled = false AND external = false";
        if ($respectRobots) {
            $sql .= " AND blocked = false";
        }
        // Ordre constant par ID pour éviter les deadlocks sur les verrous croisés
        $sql .= " ORDER BY id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':crawl_id' => $this->crawlId]);
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /**
     * Récupère et verrouille un batch d'URLs à crawler (pour workers concurrents)
     * Utilise FOR UPDATE SKIP LOCKED pour éviter les deadlocks
     * 
     * @param int $batchSize Nombre d'URLs à récupérer
     * @param bool $respectRobots Respecter robots.txt
     * @return array Liste des URLs verrouillées
     */
    public function getAndLockUrlsToCrawl(int $batchSize = 10, bool $respectRobots = true): array
    {
        return $this->executeTransactionWithRetry($this->db, function($pdo) use ($batchSize, $respectRobots) {
            $sql = "SELECT id, url FROM pages 
                    WHERE crawl_id = :crawl_id AND crawled = false AND external = false";
            if ($respectRobots) {
                $sql .= " AND blocked = false";
            }
            // Ordre constant + SKIP LOCKED pour éviter les deadlocks
            $sql .= " ORDER BY id LIMIT :limit FOR UPDATE SKIP LOCKED";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':crawl_id', $this->crawlId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            
            $rows = $stmt->fetchAll(PDO::FETCH_OBJ);
            $urls = [];
            $ids = [];
            
            foreach ($rows as $row) {
                $urls[] = $row->url;
                $ids[] = $row->id;
            }
            
            // Marquer immédiatement comme "en cours" pour éviter le double-crawl
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $updateSql = "UPDATE pages SET crawled = true WHERE crawl_id = ? AND id IN ($placeholders)";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute(array_merge([$this->crawlId], $ids));
            }
            
            return $urls;
        });
    }

    /**
     * Récupère la profondeur actuelle (pour reprise de crawl)
     */
    public function getCurrentDepth(): int
    {
        $stmt = $this->db->prepare("
            SELECT depth FROM pages 
            WHERE crawl_id = :crawl_id AND crawled = false AND external = false AND blocked = false 
            LIMIT 1
        ");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        
        return $result ? (int)$result->depth : 0;
    }

    /**
     * Vérifie si c'est un nouveau crawl (aucune page)
     */
    public function isNewCrawl(): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :crawl_id");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        return (int)$stmt->fetchColumn() === 0;
    }

    /**
     * Met à jour les statistiques du crawl dans la table crawls
     */
    public function updateCrawlStats(): void
    {
        // Total URLs
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :crawl_id");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $urls = (int)$stmt->fetchColumn();
        
        // URLs crawlées
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :crawl_id AND crawled = true");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $crawled = (int)$stmt->fetchColumn();
        
        // URLs compliant
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :crawl_id AND compliant = true");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $compliant = (int)$stmt->fetchColumn();
        
        // Duplicates (URLs non canoniques)
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :crawl_id AND canonical = false");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $duplicates = (int)$stmt->fetchColumn();
        
        // Temps de réponse moyen (uniquement sur les URLs code 200)
        $stmt = $this->db->prepare("SELECT AVG(response_time) FROM pages WHERE crawl_id = :crawl_id AND code = 200 AND response_time > 0");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $responseTime = (float)$stmt->fetchColumn() ?: 0;
        
        // Profondeur max (uniquement sur les URLs crawlées)
        $stmt = $this->db->prepare("SELECT MAX(depth) FROM pages WHERE crawl_id = :crawl_id AND crawled = true");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $depthMax = (int)$stmt->fetchColumn();
        
        // URLs en cours
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :crawl_id AND crawled = false AND external = false");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        $inProgress = (int)$stmt->fetchColumn();
        
        // Mise à jour
        $stmt = $this->db->prepare("
            UPDATE crawls SET 
                urls = :urls,
                crawled = :crawled,
                compliant = :compliant,
                duplicates = :duplicates,
                response_time = :response_time,
                depth_max = :depth_max,
                in_progress = :in_progress
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':urls' => $urls,
            ':crawled' => $crawled,
            ':compliant' => $compliant,
            ':duplicates' => $duplicates,
            ':response_time' => round($responseTime, 2),
            ':depth_max' => $depthMax,
            ':in_progress' => $inProgress,
            ':id' => $this->crawlId
        ]);
    }

    /**
     * Marque le crawl comme terminé
     */
    public function finishCrawl(): void
    {
        $this->updateCrawlStats();
        
        $stmt = $this->db->prepare("
            UPDATE crawls SET 
                status = 'finished',
                finished_at = CURRENT_TIMESTAMP,
                in_progress = 0
            WHERE id = :id
        ");
        $stmt->execute([':id' => $this->crawlId]);
    }

    /**
     * Récupère la config du crawl depuis la base
     */
    public static function getConfigFromDb(int $crawlId): ?array
    {
        $db = PostgresDatabase::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT config FROM crawls WHERE id = :id");
        $stmt->execute([':id' => $crawlId]);
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        
        if ($result && $result->config) {
            return json_decode($result->config, true);
        }
        return null;
    }

    /**
     * Récupère un crawl par son path (directory)
     */
    public static function getCrawlByPath(string $path): ?object
    {
        $db = PostgresDatabase::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM crawls WHERE path = :path");
        $stmt->execute([':path' => $path]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /**
     * Récupère un crawl par son ID
     */
    public static function getCrawlById(int $id): ?object
    {
        $db = PostgresDatabase::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM crawls WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /**
     * Exécute tous les post-traitements via PostProcessor
     */
    public function runPostProcessing(): void
    {
        $processor = new PostProcessor($this->crawlId);
        $processor->run();
        
        // Mettre à jour les stats finales
        $this->updateCrawlStats();
    }
}
