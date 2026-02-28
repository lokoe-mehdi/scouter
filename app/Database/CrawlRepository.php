<?php

namespace App\Database;

use PDO;

/**
 * Repository pour les opérations CRUD sur les crawls
 * 
 * Gère la création, mise à jour et récupération des crawls.
 * 
 * @package    Scouter
 * @subpackage Database
 * @author     Mehdi Colin
 * @version    2.0.0
 */
class CrawlRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Insère ou met à jour un crawl
     */
    public function insert(array $data): int
    {
        $path = $data['path'] ?? null;
        
        if ($path) {
            $stmt = $this->db->prepare("SELECT id FROM crawls WHERE path = :path");
            $stmt->execute([':path' => $path]);
            $existing = $stmt->fetch(PDO::FETCH_OBJ);
            
            if ($existing) {
                return $this->update($existing->id, $data);
            }
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO crawls (domain, path, status, config, urls, crawled, compliant, duplicates, response_time, depth_max, in_progress, started_at, project_id)
            VALUES (:domain, :path, :status, :config, :urls, :crawled, :compliant, :duplicates, :response_time, :depth_max, :in_progress, :started_at, :project_id)
            RETURNING id
        ");
        
        $stmt->execute([
            ':domain' => $data['domain'],
            ':path' => $path,
            ':status' => $data['status'] ?? 'running',
            ':config' => isset($data['config']) ? json_encode($data['config']) : null,
            ':urls' => $data['urls'] ?? 0,
            ':crawled' => $data['crawled'] ?? 0,
            ':compliant' => $data['compliant'] ?? 0,
            ':duplicates' => $data['duplicates'] ?? 0,
            ':response_time' => $data['response_time'] ?? 0,
            ':depth_max' => $data['depth_max'] ?? 0,
            ':in_progress' => $data['in_progress'] ?? 0,
            ':started_at' => $data['date'] ?? date('Y-m-d H:i:s'),
            ':project_id' => $data['project_id'] ?? null
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        return $result->id;
    }

    /**
     * Met à jour un crawl existant
     */
    public function update(int $crawlId, array $data): int
    {
        $updates = [];
        $params = [':id' => $crawlId];

        $fields = ['status', 'urls', 'crawled', 'compliant', 'duplicates', 'response_time', 'depth_max', 'in_progress'];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (isset($data['config'])) {
            $updates[] = "config = :config";
            $params[':config'] = json_encode($data['config']);
        }

        if (isset($data['finished_at'])) {
            $updates[] = "finished_at = :finished_at";
            $params[':finished_at'] = $data['finished_at'];
        }

        if (empty($updates)) return $crawlId;

        $sql = "UPDATE crawls SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $crawlId;
    }

    /**
     * Récupère un crawl par son ID
     */
    public function getById(int $id): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM crawls WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /**
     * Récupère un crawl par son path
     */
    public function getByPath(string $path): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM crawls WHERE path = :path");
        $stmt->execute([':path' => $path]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /**
     * Récupère tous les crawls
     */
    public function getAll(): array
    {
        $stmt = $this->db->query("
            SELECT c.*
            FROM crawls c
            ORDER BY c.started_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Récupère tous les crawls groupés par domaine
     */
    public function getDomainsGrouped(): array
    {
        $result = [];
        $stmt = $this->db->query("SELECT * FROM crawls ORDER BY domain ASC, started_at DESC");
        $crawls = $stmt->fetchAll(PDO::FETCH_OBJ);
        
        foreach ($crawls as $crawl) {
            $domain = $crawl->domain;
            if (!isset($result[$domain])) {
                $result[$domain] = [];
            }
            $result[$domain][] = $crawl;
        }
        
        return $result;
    }

    /**
     * Supprime un crawl par son ID
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM crawls WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Récupère tous les crawls avec infos compatibles
     */
    public function getAllWithDetails(): array
    {
        $stmt = $this->db->query("
            SELECT 
                c.id as crawl_id,
                c.domain,
                c.path,
                c.started_at as date,
                c.urls,
                c.crawled,
                c.compliant,
                c.duplicates,
                c.response_time,
                c.depth_max,
                c.in_progress,
                c.status,
                c.config
            FROM crawls c
            ORDER BY c.started_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Récupère les crawls d'un projet
     */
    public function getByProjectId(int $projectId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*
            FROM crawls c
            WHERE c.project_id = :project_id
            ORDER BY c.started_at DESC
        ");
        $stmt->execute([':project_id' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Associe un crawl à un projet
     */
    public function setProject(int $crawlId, int $projectId): void
    {
        $stmt = $this->db->prepare("UPDATE crawls SET project_id = :project_id WHERE id = :id");
        $stmt->execute([':id' => $crawlId, ':project_id' => $projectId]);
    }
}
