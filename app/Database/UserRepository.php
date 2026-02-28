<?php

namespace App\Database;

use PDO;

/**
 * Repository pour les opérations CRUD sur les utilisateurs
 * 
 * Gère la création, authentification et gestion des utilisateurs.
 * 
 * @package    Scouter
 * @subpackage Database
 * @author     Mehdi Colin
 * @version    2.0.0
 */
class UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Crée un nouvel utilisateur
     */
    public function create(string $email, string $password, string $role = 'user'): int
    {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $this->db->prepare("
            INSERT INTO users (email, password_hash, role) 
            VALUES (:email, :password_hash, :role)
            RETURNING id
        ");
        $stmt->execute([
            ':email' => $email,
            ':password_hash' => $hashedPassword,
            ':role' => $role
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        return $result->id;
    }

    /**
     * Récupère un utilisateur par son email
     */
    public function getByEmail(string $email): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /**
     * Récupère un utilisateur par son ID
     */
    public function getById(int $id): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /**
     * Récupère tous les utilisateurs
     */
    public function getAll(): array
    {
        $stmt = $this->db->query("SELECT id, email, role, created_at FROM users ORDER BY email ASC");
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Met à jour un utilisateur
     */
    public function update(int $userId, array $data): void
    {
        $updates = [];
        $params = [':id' => $userId];

        if (isset($data['email'])) {
            $updates[] = "email = :email";
            $params[':email'] = $data['email'];
        }

        if (isset($data['role'])) {
            $updates[] = "role = :role";
            $params[':role'] = $data['role'];
        }

        if (isset($data['password'])) {
            $updates[] = "password_hash = :password_hash";
            $params[':password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if (empty($updates)) return;

        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Supprime un utilisateur
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Compte le nombre d'utilisateurs
     */
    public function count(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM users");
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        return (int) $result->count;
    }

    /**
     * Vérifie si un email existe
     */
    public function emailExists(string $email): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        return $result->count > 0;
    }

    /**
     * Change le mot de passe
     */
    public function changePassword(int $userId, string $newPassword): void
    {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :id");
        $stmt->execute([
            ':password_hash' => $hashedPassword,
            ':id' => $userId
        ]);
    }
}
