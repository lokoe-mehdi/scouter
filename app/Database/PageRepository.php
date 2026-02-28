<?php

namespace App\Database;

use PDO;

/**
 * Repository pour les opérations CRUD sur les pages
 * 
 * Gère l'insertion, mise à jour et récupération des pages crawlées.
 * 
 * @package    Scouter
 * @subpackage Database
 * @author     Mehdi Colin
 * @version    2.0.0
 */
class PageRepository
{
    private PDO $db;
    private int $crawlId;

    public function __construct(int $crawlId)
    {
        $this->db = PostgresDatabase::getInstance()->getConnection();
        $this->crawlId = $crawlId;
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
     * Convertit un array PHP en syntaxe PostgreSQL TEXT[]
     */
    private function toPostgresArray(array $arr): string
    {
        if (empty($arr)) {
            return '{}';
        }
        
        $escaped = array_map(function($item) {
            $item = str_replace('\\', '\\\\', $item);
            $item = str_replace('"', '\\"', $item);
            return '"' . $item . '"';
        }, $arr);
        
        return '{' . implode(',', $escaped) . '}';
    }

    /**
     * Insère une page (URL découverte)
     */
    public function insert(array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO pages (crawl_id, id, domain, url, depth, code, crawled, external, blocked, date)
            VALUES (:crawl_id, :id, :domain, :url, :depth, :code, :crawled, :external, :blocked, :date)
            ON CONFLICT (crawl_id, id) DO NOTHING
        ");
        
        $stmt->execute([
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
        ]);
    }

    /**
     * Met à jour une page après crawl
     */
    public function update(string $pageId, array $data): void
    {
        $sets = [];
        $params = [':crawl_id' => $this->crawlId, ':id' => $pageId];
        
        $boolFields = ['crawled', 'nofollow', 'compliant', 'noindex', 'external', 'blocked', 'canonical', 'is_html', 'h1_multiple', 'headings_missing'];
        
        $fields = ['code', 'crawled', 'content_type', 'outlinks', 'date', 'nofollow', 
                   'compliant', 'noindex', 'canonical', 'canonical_value', 'redirect_to', 'response_time',
                   'title', 'h1', 'metadesc', 'title_status', 'h1_status', 'metadesc_status',
                   'simhash', 'is_html', 'h1_multiple', 'headings_missing', 'word_count'];
        
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = :$field";
                if (in_array($field, $boolFields)) {
                    $params[":$field"] = $this->toBool($data[$field]);
                } else {
                    $params[":$field"] = $data[$field];
                }
            }
        }
        
        if (isset($data['extracts'])) {
            $sets[] = "extracts = :extracts";
            $params[':extracts'] = json_encode($data['extracts']);
        }
        
        if (isset($data['schemas'])) {
            $sets[] = "schemas = :schemas";
            $params[':schemas'] = $this->toPostgresArray($data['schemas']);
        }
        
        if (empty($sets)) return;
        
        $sql = "UPDATE pages SET " . implode(', ', $sets) . " WHERE crawl_id = :crawl_id AND id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Insère plusieurs pages en batch
     */
    public function insertBatch(array $pages): void
    {
        if (empty($pages)) return;
        
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
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }
    }

    /**
     * Insère les types de schémas pour une page
     */
    public function insertSchemas(string $pageId, array $schemas): void
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
     * Récupère les URLs à crawler
     */
    public function getUrlsToCrawl(bool $respectRobots = true): array
    {
        $blockedCondition = $respectRobots ? "AND blocked = false" : "";
        
        $stmt = $this->db->prepare("
            SELECT id, url, depth FROM pages 
            WHERE crawl_id = :crawl_id 
            AND crawled = false 
            AND external = false 
            $blockedCondition
            ORDER BY depth ASC
        ");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Récupère la profondeur actuelle du crawl
     */
    public function getCurrentDepth(): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(depth), 0) as max_depth 
            FROM pages 
            WHERE crawl_id = :crawl_id AND crawled = true
        ");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Vérifie si c'est un nouveau crawl (aucune page crawlée)
     */
    public function isNewCrawl(): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM pages 
            WHERE crawl_id = :crawl_id AND crawled = true
        ");
        $stmt->execute([':crawl_id' => $this->crawlId]);
        return (int)$stmt->fetchColumn() === 0;
    }
}
