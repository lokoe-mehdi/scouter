<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Database\PostgresDatabase;
use PDO;

/**
 * Controller pour les saved queries personnalisées de l'utilisateur.
 *
 * Les snippets SQL prédéfinis vivent en dur dans sql-explorer.php (i18n).
 * Cette API gère uniquement les snippets que l'utilisateur sauvegarde lui-même
 * depuis l'éditeur. Toutes les opérations sont scopées au user connecté
 * (ownership check sur user_id).
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class SavedQueryController extends Controller
{
    private PDO $db;

    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Liste les requêtes sauvegardées par l'utilisateur courant.
     */
    public function index(Request $request): void
    {
        $stmt = $this->db->prepare("
            SELECT id, name, description, category, query, created_at, updated_at
            FROM user_saved_queries
            WHERE user_id = :uid
            ORDER BY COALESCE(category, ''), name
        ");
        $stmt->execute([':uid' => $this->userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->success(['queries' => $rows]);
    }

    /**
     * Crée une requête sauvegardée pour l'utilisateur courant.
     */
    public function create(Request $request): void
    {
        $name        = trim((string)$request->get('name', ''));
        $description = trim((string)$request->get('description', ''));
        $category    = trim((string)$request->get('category', ''));
        $query       = (string)$request->get('query', '');

        if ($name === '' || trim($query) === '') {
            $this->error('Name and query are required', 400);
            return;
        }
        if (mb_strlen($name) > 255) {
            $this->error('Name too long (max 255 chars)', 400);
            return;
        }
        if (mb_strlen($category) > 100) {
            $this->error('Category too long (max 100 chars)', 400);
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO user_saved_queries (user_id, name, description, category, query)
            VALUES (:uid, :name, :desc, :cat, :query)
            RETURNING id, name, description, category, query, created_at, updated_at
        ");
        $stmt->execute([
            ':uid'   => $this->userId,
            ':name'  => $name,
            ':desc'  => $description !== '' ? $description : null,
            ':cat'   => $category !== '' ? $category : null,
            ':query' => $query,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->success(['query' => $row]);
    }

    /**
     * Met à jour une requête sauvegardée. Ownership check obligatoire.
     */
    public function update(Request $request): void
    {
        $id = (int)$request->get('id');
        if ($id <= 0) {
            $this->error('Invalid id', 400);
            return;
        }
        // Ownership check
        $owner = $this->db->prepare("SELECT user_id FROM user_saved_queries WHERE id = :id");
        $owner->execute([':id' => $id]);
        $ownerId = $owner->fetchColumn();
        if ($ownerId === false) {
            $this->error('Not found', 404);
            return;
        }
        if ((int)$ownerId !== (int)$this->userId) {
            $this->error('Forbidden', 403);
            return;
        }

        $name        = trim((string)$request->get('name', ''));
        $description = trim((string)$request->get('description', ''));
        $category    = trim((string)$request->get('category', ''));
        $query       = (string)$request->get('query', '');

        if ($name === '' || trim($query) === '') {
            $this->error('Name and query are required', 400);
            return;
        }
        if (mb_strlen($name) > 255 || mb_strlen($category) > 100) {
            $this->error('Field too long', 400);
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE user_saved_queries
            SET name = :name, description = :desc, category = :cat,
                query = :query, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            RETURNING id, name, description, category, query, created_at, updated_at
        ");
        $stmt->execute([
            ':id'    => $id,
            ':name'  => $name,
            ':desc'  => $description !== '' ? $description : null,
            ':cat'   => $category !== '' ? $category : null,
            ':query' => $query,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->success(['query' => $row]);
    }

    /**
     * Renomme une catégorie : met à jour toutes les requêtes du user qui
     * portent l'ancien nom. Scopé au user_id : aucune fuite cross-user possible.
     */
    public function renameCategory(Request $request): void
    {
        $old = trim((string)$request->get('old_name', ''));
        $new = trim((string)$request->get('new_name', ''));
        if ($old === '' || $new === '') {
            $this->error('Old and new names are required', 400);
            return;
        }
        if (mb_strlen($new) > 100) {
            $this->error('Category too long (max 100 chars)', 400);
            return;
        }
        $stmt = $this->db->prepare("
            UPDATE user_saved_queries
            SET category = :new, updated_at = CURRENT_TIMESTAMP
            WHERE user_id = :uid AND category = :old
        ");
        $stmt->execute([':uid' => $this->userId, ':old' => $old, ':new' => $new]);
        $this->success(['affected' => $stmt->rowCount()]);
    }

    /**
     * Supprime une catégorie ET toutes les requêtes du user qu'elle contient.
     * Action destructrice — l'UI doit demander confirmation avant d'appeler.
     */
    public function deleteCategory(Request $request): void
    {
        $cat = trim((string)$request->get('name', ''));
        if ($cat === '') {
            $this->error('Category name required', 400);
            return;
        }
        $stmt = $this->db->prepare("
            DELETE FROM user_saved_queries
            WHERE user_id = :uid AND category = :cat
        ");
        $stmt->execute([':uid' => $this->userId, ':cat' => $cat]);
        $this->success(['affected' => $stmt->rowCount()]);
    }

    /**
     * Supprime une requête sauvegardée. Ownership check obligatoire.
     */
    public function delete(Request $request): void
    {
        $id = (int)$request->get('id');
        if ($id <= 0) {
            $this->error('Invalid id', 400);
            return;
        }
        $stmt = $this->db->prepare("
            DELETE FROM user_saved_queries
            WHERE id = :id AND user_id = :uid
        ");
        $stmt->execute([':id' => $id, ':uid' => $this->userId]);
        if ($stmt->rowCount() === 0) {
            $this->error('Not found or forbidden', 404);
            return;
        }
        $this->success([]);
    }
}
