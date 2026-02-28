<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\UserRepository;

/**
 * Controller pour la gestion des utilisateurs
 * 
 * Gère les opérations CRUD sur les utilisateurs (admin uniquement).
 * 
 * @package    Scouter
 * @subpackage Http\Controllers
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class UserController extends Controller
{
    /**
     * Repository des utilisateurs
     * 
     * @var UserRepository
     */
    private UserRepository $users;

    /**
     * Constructeur
     * 
     * @param \App\Auth\Auth $auth Instance d'authentification
     */
    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->users = new UserRepository();
    }

    /**
     * Liste tous les utilisateurs
     * 
     * Retourne la liste complète des utilisateurs (admin uniquement).
     * 
     * @param Request $request Requête HTTP
     * 
     * @return void
     */
    public function index(Request $request): void
    {
        $allUsers = $this->users->getAll();
        $this->success(['users' => $allUsers]);
    }

    /**
     * Crée un nouvel utilisateur
     * 
     * Valide l'email, le mot de passe et le rôle avant création.
     * 
     * @param Request $request Requête HTTP (email, password, role)
     * 
     * @return void
     */
    public function create(Request $request): void
    {
        $email = trim($request->get('email', ''));
        $password = $request->get('password', '');
        $role = $request->get('role', 'user');
        
        $validRoles = ['admin', 'user', 'viewer'];
        if (!in_array($role, $validRoles)) {
            $role = 'user';
        }
        
        if (empty($email) || empty($password)) {
            $this->error('L\'email et le mot de passe sont obligatoires');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email invalide');
        }
        
        if (strlen($password) < 6) {
            $this->error('Le mot de passe doit contenir au moins 6 caractères');
        }
        
        if ($this->users->emailExists($email)) {
            $this->error('Cet email existe déjà');
        }
        
        $userId = $this->users->create($email, $password, $role);
        $this->success([
            'user_id' => $userId
        ], 'Utilisateur créé avec succès');
    }

    /**
     * Met à jour un utilisateur existant
     * 
     * Permet de modifier l'email, le rôle et/ou le mot de passe.
     * Empêche un admin de retirer ses propres droits.
     * 
     * @param Request $request Requête HTTP (id en route, email, role, password)
     * 
     * @return void
     */
    public function update(Request $request): void
    {
        $userId = (int)$request->param('id');
        
        if ($userId === 0) {
            $this->error('ID utilisateur invalide');
        }
        
        $user = $this->users->getById($userId);
        if (!$user) {
            $this->error('Utilisateur non trouvé');
        }
        
        $updateData = [];
        
        $email = trim($request->get('email', ''));
        if (!empty($email)) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('Email invalide');
            }
            if ($email !== $user->email && $this->users->emailExists($email)) {
                $this->error('Cet email est déjà utilisé');
            }
            $updateData['email'] = $email;
        }
        
        $role = $request->get('role', '');
        if (!empty($role)) {
            $validRoles = ['admin', 'user', 'viewer'];
            if (!in_array($role, $validRoles)) {
                $this->error('Rôle invalide');
            }
            if ($userId === $this->userId && $role !== 'admin' && $user->role === 'admin') {
                $this->error('Vous ne pouvez pas retirer vos propres droits administrateur');
            }
            $updateData['role'] = $role;
        }
        
        $password = $request->get('password', '');
        if (!empty($password)) {
            if (strlen($password) < 6) {
                $this->error('Le mot de passe doit contenir au moins 6 caractères');
            }
            $updateData['password'] = $password;
        }
        
        if (empty($updateData)) {
            $this->error('Aucune donnée à mettre à jour');
        }
        
        $this->users->update($userId, $updateData);
        $this->success([], 'Utilisateur mis à jour avec succès');
    }

    /**
     * Supprime un utilisateur
     * 
     * Empêche un utilisateur de supprimer son propre compte.
     * 
     * @param Request $request Requête HTTP (id en route)
     * 
     * @return void
     */
    public function delete(Request $request): void
    {
        $userId = (int)$request->param('id');
        
        if ($userId === 0) {
            $this->error('ID utilisateur invalide');
        }
        
        if ($userId === $this->userId) {
            $this->error('Vous ne pouvez pas supprimer votre propre compte');
        }
        
        $this->users->delete($userId);
        $this->success([], 'Utilisateur supprimé avec succès');
    }

    /**
     * Déconnecte l'utilisateur courant
     * 
     * Détruit la session et retourne un message de succès.
     * 
     * @param Request $request Requête HTTP
     * 
     * @return void
     */
    public function logout(Request $request): void
    {
        $this->auth->logout();
        // Rediriger vers la page de login comme l'ancien comportement
        header('Location: ../login.php');
        exit;
    }

    /**
     * Met à jour un utilisateur (ID dans le body pour compatibilité frontend)
     * 
     * @param Request $request Requête HTTP (id, email, role, password dans body)
     * 
     * @return void
     */
    public function updateFromBody(Request $request): void
    {
        $userId = (int)$request->get('id');
        
        if ($userId === 0) {
            $this->error('ID utilisateur invalide');
        }
        
        $user = $this->users->getById($userId);
        if (!$user) {
            $this->error('Utilisateur non trouvé');
        }
        
        $updateData = [];
        
        $email = trim($request->get('email', ''));
        if (!empty($email)) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('Email invalide');
            }
            if ($email !== $user->email && $this->users->emailExists($email)) {
                $this->error('Cet email est déjà utilisé');
            }
            $updateData['email'] = $email;
        }
        
        $role = $request->get('role', '');
        if (!empty($role)) {
            $validRoles = ['admin', 'user', 'viewer'];
            if (!in_array($role, $validRoles)) {
                $this->error('Rôle invalide');
            }
            if ($userId === $this->userId && $role !== 'admin' && $user->role === 'admin') {
                $this->error('Vous ne pouvez pas retirer vos propres droits administrateur');
            }
            $updateData['role'] = $role;
        }
        
        $password = $request->get('password', '');
        if (!empty($password)) {
            if (strlen($password) < 6) {
                $this->error('Le mot de passe doit contenir au moins 6 caractères');
            }
            $updateData['password'] = $password;
        }
        
        if (empty($updateData)) {
            $this->error('Aucune donnée à mettre à jour');
        }
        
        $this->users->update($userId, $updateData);
        $this->success([], 'Utilisateur mis à jour avec succès');
    }
}
