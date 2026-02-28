<?php

namespace App\Database;

use PDO;

/**
 * Repository pour les opérations CRUD sur les projets
 * 
 * Gère la création, partage et gestion des projets.
 * 
 * @package    Scouter
 * @subpackage Database
 * @author     Mehdi Colin
 * @version    2.0.0
 */
class ProjectRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Crée un nouveau projet
     */
    public function create(int $userId, string $name): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO projects (user_id, name) 
            VALUES (:user_id, :name)
            RETURNING id
        ");
        $stmt->execute([':user_id' => $userId, ':name' => $name]);
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        return $result->id;
    }

    /**
     * Récupère un projet par son ID
     */
    public function getById(int $projectId): ?object
    {
        $stmt = $this->db->prepare("
            SELECT p.*, u.email as owner_email
            FROM projects p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = :id
        ");
        $stmt->execute([':id' => $projectId]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /**
     * Récupère un projet par couple (user_id, name/domain)
     */
    public function getByUserAndName(int $userId, string $name): ?object
    {
        $stmt = $this->db->prepare("
            SELECT p.*, u.email as owner_email
            FROM projects p
            JOIN users u ON p.user_id = u.id
            WHERE p.user_id = :user_id AND p.name = :name
        ");
        $stmt->execute([':user_id' => $userId, ':name' => $name]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /**
     * Récupère ou crée un projet pour un utilisateur et un domaine
     */
    public function getOrCreate(int $userId, string $domain): int
    {
        $project = $this->getByUserAndName($userId, $domain);
        if ($project) {
            return $project->id;
        }
        return $this->create($userId, $domain);
    }

    /**
     * Met à jour un projet
     */
    public function update(int $projectId, string $name): void
    {
        $stmt = $this->db->prepare("UPDATE projects SET name = :name WHERE id = :id");
        $stmt->execute([':id' => $projectId, ':name' => $name]);
    }

    /**
     * Supprime un projet (cascade sur crawls)
     */
    public function delete(int $projectId): void
    {
        $stmt = $this->db->prepare("DELETE FROM projects WHERE id = :id");
        $stmt->execute([':id' => $projectId]);
    }

    /**
     * Récupère les projets dont l'utilisateur est propriétaire
     */
    public function getForUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.*, 
                   u.email as owner_email,
                   COUNT(DISTINCT c.id) as crawl_count,
                   MAX(c.started_at) as last_crawl_at
            FROM projects p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN crawls c ON c.project_id = p.id
            WHERE p.user_id = :user_id
            GROUP BY p.id, u.email
            ORDER BY p.name ASC
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Récupère les projets partagés avec l'utilisateur
     */
    public function getSharedForUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT ON (p.id)
                   p.id,
                   p.user_id,
                   p.name,
                   p.created_at,
                   u.email as owner_email,
                   (SELECT COUNT(*) FROM crawls WHERE project_id = p.id) as crawl_count,
                   (SELECT MAX(started_at) FROM crawls WHERE project_id = p.id) as last_crawl_at
            FROM project_shares ps
            JOIN projects p ON ps.project_id = p.id
            JOIN users u ON p.user_id = u.id
            WHERE ps.user_id = :shared_with_user_id
            AND p.user_id != :exclude_owner_id
            ORDER BY p.id, p.name ASC
        ");
        $stmt->execute([
            ':shared_with_user_id' => $userId,
            ':exclude_owner_id' => $userId
        ]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Récupère tous les projets avec leur propriétaire (pour les Admins)
     */
    public function getAllWithOwner(): array
    {
        $stmt = $this->db->query("
            SELECT p.*, 
                   u.email as owner_email,
                   COUNT(DISTINCT c.id) as crawl_count,
                   MAX(c.started_at) as last_crawl_at
            FROM projects p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN crawls c ON c.project_id = p.id
            GROUP BY p.id, u.email
            ORDER BY u.email ASC, p.name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Vérifie si un utilisateur est propriétaire d'un projet
     */
    public function isOwner(int $userId, int $projectId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM projects WHERE id = :project_id AND user_id = :user_id
        ");
        $stmt->execute([':project_id' => $projectId, ':user_id' => $userId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Vérifie si un utilisateur peut accéder à un projet
     */
    public function userCanAccess(int $userId, int $projectId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM projects WHERE id = :project_id AND user_id = :user_id
            UNION
            SELECT 1 FROM project_shares WHERE project_id = :project_id2 AND user_id = :user_id2
        ");
        $stmt->execute([
            ':project_id' => $projectId, 
            ':user_id' => $userId,
            ':project_id2' => $projectId, 
            ':user_id2' => $userId
        ]);
        return $stmt->fetch() !== false;
    }

    /**
     * Partage un projet avec un utilisateur
     */
    public function share(int $projectId, int $targetUserId): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO project_shares (project_id, user_id) 
                VALUES (:project_id, :user_id)
            ");
            $stmt->execute([':project_id' => $projectId, ':user_id' => $targetUserId]);
            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() == '23505') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Retire le partage d'un projet avec un utilisateur
     */
    public function unshare(int $projectId, int $targetUserId): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM project_shares 
            WHERE project_id = :project_id AND user_id = :user_id
        ");
        $stmt->execute([':project_id' => $projectId, ':user_id' => $targetUserId]);
    }

    /**
     * Récupère la liste des utilisateurs ayant accès à un projet
     */
    public function getShares(int $projectId): array
    {
        $stmt = $this->db->prepare("
            SELECT u.id, u.email, u.role, ps.created_at as shared_at
            FROM project_shares ps
            JOIN users u ON ps.user_id = u.id
            WHERE ps.project_id = :project_id
            ORDER BY u.email ASC
        ");
        $stmt->execute([':project_id' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Récupère les utilisateurs disponibles pour le partage
     */
    public function getAvailableUsersForSharing(int $projectId): array
    {
        $stmt = $this->db->prepare("
            SELECT u.id, u.email, u.role
            FROM users u
            WHERE u.id NOT IN (
                SELECT user_id FROM projects WHERE id = :project_id
                UNION
                SELECT user_id FROM project_shares WHERE project_id = :project_id2
            )
            AND u.role != 'admin'
            ORDER BY u.email ASC
        ");
        $stmt->execute([':project_id' => $projectId, ':project_id2' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Get categorization config for a project
     *
     * @param int $projectId Project ID
     * @return string|null YAML config or null
     */
    public function getCategorizationConfig(int $projectId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT categorization_config
            FROM projects
            WHERE id = :id
        ");
        $stmt->execute([':id' => $projectId]);
        $result = $stmt->fetch(PDO::FETCH_OBJ);

        return $result ? $result->categorization_config : null;
    }

    /**
     * Set categorization config for a project
     *
     * @param int $projectId Project ID
     * @param string $yamlConfig YAML configuration
     * @return void
     */
    public function setCategorizationConfig(int $projectId, string $yamlConfig): void
    {
        $stmt = $this->db->prepare("
            UPDATE projects
            SET categorization_config = :config
            WHERE id = :id
        ");
        $stmt->execute([':id' => $projectId, ':config' => $yamlConfig]);
    }
}
