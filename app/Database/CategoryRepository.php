<?php

namespace App\Database;

use PDO;

/**
 * Repository pour les opérations CRUD sur les catégories de projets
 * 
 * Gère la création et l'assignation des catégories aux projets.
 * 
 * @package    Scouter
 * @subpackage Database
 * @author     Mehdi Colin
 * @version    2.0.0
 */
class CategoryRepository
{
    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? PostgresDatabase::getInstance()->getConnection();
    }

    public function getConnection(): ?PDO
    {
        return $this->db;
    }

    /**
     * Crée une catégorie de projet
     */
    public function create(int $userId, string $name, string $color = '#4ECDC4'): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO project_categories (user_id, name, color) 
            VALUES (:user_id, :name, :color)
            RETURNING id
        ");
        $stmt->execute([':user_id' => $userId, ':name' => $name, ':color' => $color]);
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        return $result->id;
    }

    /**
     * Met à jour une catégorie de projet
     */
    public function update(int $id, int $userId, ?string $name, ?string $color): void
    {
        $updates = [];
        $params = [':id' => $id, ':user_id' => $userId];
        
        if (!empty($name)) {
            $updates[] = "name = :name";
            $params[':name'] = $name;
        }
        if (!empty($color)) {
            $updates[] = "color = :color";
            $params[':color'] = $color;
        }
        
        if (empty($updates)) return;
        
        $sql = "UPDATE project_categories SET " . implode(', ', $updates) . " WHERE id = :id AND user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Supprime une catégorie de projet
     */
    public function delete(int $id, int $userId): void
    {
        $stmt = $this->db->prepare("DELETE FROM project_categories WHERE id = :id AND user_id = :user_id");
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
    }

    /**
     * Récupère toutes les catégories d'un utilisateur avec compteur de projets
     */
    public function getForUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                pc.*,
                COUNT(DISTINCT pcl.project_id) as project_count
            FROM project_categories pc
            LEFT JOIN project_category_links pcl ON pcl.category_id = pc.id
            WHERE pc.user_id = :user_id
            GROUP BY pc.id
            ORDER BY pc.name ASC
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Récupère une catégorie par son ID
     */
    public function getById(int $id, ?int $userId = null): ?object
    {
        if ($userId) {
            $stmt = $this->db->prepare("SELECT * FROM project_categories WHERE id = :id AND user_id = :user_id");
            $stmt->execute([':id' => $id, ':user_id' => $userId]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM project_categories WHERE id = :id");
            $stmt->execute([':id' => $id]);
        }
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /**
     * Assigne un projet à une catégorie
     */
    public function assignProject(int $projectId, int $categoryId, int $userId): void
    {
        $cat = $this->getById($categoryId, $userId);
        if (!$cat) {
            throw new \Exception("Catégorie non trouvée ou non autorisée");
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO project_category_links (project_id, category_id) 
            VALUES (:project_id, :category_id)
            ON CONFLICT DO NOTHING
        ");
        $stmt->execute([':project_id' => $projectId, ':category_id' => $categoryId]);
    }

    /**
     * Retire un projet d'une catégorie
     */
    public function removeProject(int $projectId, int $categoryId, int $userId): void
    {
        $cat = $this->getById($categoryId, $userId);
        if (!$cat) {
            throw new \Exception("Catégorie non trouvée ou non autorisée");
        }
        
        $stmt = $this->db->prepare("
            DELETE FROM project_category_links 
            WHERE project_id = :project_id AND category_id = :category_id
        ");
        $stmt->execute([':project_id' => $projectId, ':category_id' => $categoryId]);
    }

    /**
     * Récupère les catégories d'un projet pour un utilisateur donné
     */
    public function getForProject(int $projectId, int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT pc.*
            FROM project_categories pc
            JOIN project_category_links pcl ON pcl.category_id = pc.id
            WHERE pcl.project_id = :project_id AND pc.user_id = :user_id
            ORDER BY pc.name ASC
        ");
        $stmt->execute([':project_id' => $projectId, ':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Définit les catégories d'un projet
     */
    public function setForProject(int $projectId, array $categoryIds, int $userId): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM project_category_links 
            WHERE project_id = :project_id 
            AND category_id IN (SELECT id FROM project_categories WHERE user_id = :user_id)
        ");
        $stmt->execute([':project_id' => $projectId, ':user_id' => $userId]);
        
        foreach ($categoryIds as $categoryId) {
            $this->assignProject($projectId, $categoryId, $userId);
        }
    }

    /**
     * Récupère toutes les catégories (pour admin)
     */
    public function getAll(): array
    {
        $stmt = $this->db->query("
            SELECT pc.*, COUNT(DISTINCT pcl.project_id) as project_count
            FROM project_categories pc
            LEFT JOIN project_category_links pcl ON pcl.category_id = pc.id
            GROUP BY pc.id
            ORDER BY pc.name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
}
