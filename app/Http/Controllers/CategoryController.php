<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\CategoryRepository;

/**
 * Controller pour la gestion des catégories de projets
 * 
 * Gère les opérations CRUD sur les catégories et leur assignation aux projets.
 * 
 * @package    Scouter
 * @subpackage Http\Controllers
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class CategoryController extends Controller
{
    /**
     * Repository des catégories
     * 
     * @var CategoryRepository
     */
    private CategoryRepository $categories;

    /**
     * Constructeur
     * 
     * @param \App\Auth\Auth $auth Instance d'authentification
     */
    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->categories = new CategoryRepository();
    }

    /**
     * Liste les catégories de l'utilisateur connecté
     * 
     * @param Request $request Requête HTTP
     * 
     * @return void
     */
    public function index(Request $request): void
    {
        $userCategories = $this->categories->getForUser($this->userId);
        $this->success(['categories' => $userCategories]);
    }

    /**
     * Crée une nouvelle catégorie
     * 
     * @param Request $request Requête HTTP (name, color)
     * 
     * @return void
     */
    public function create(Request $request): void
    {
        $name = trim($request->get('name', ''));
        
        if (empty($name)) {
            $this->error('Le nom de la catégorie est requis');
        }
        
        $color = $request->get('color', '#4ECDC4');
        $categoryId = $this->categories->create($this->userId, $name, $color);
        
        $this->success([
            'category_id' => $categoryId
        ], 'Catégorie créée avec succès');
    }

    /**
     * Met à jour une catégorie existante
     * 
     * @param Request $request Requête HTTP (id en route, name, color)
     * 
     * @return void
     */
    public function update(Request $request): void
    {
        $id = (int)$request->param('id');
        
        if (!$id) {
            $this->error('ID requis');
        }
        
        $this->categories->update(
            $id,
            $this->userId,
            $request->get('name', ''),
            $request->get('color', '')
        );
        
        $this->success([], 'Catégorie mise à jour avec succès');
    }

    /**
     * Supprime une catégorie
     * 
     * @param Request $request Requête HTTP (id en route)
     * 
     * @return void
     */
    public function delete(Request $request): void
    {
        $id = (int)$request->param('id');
        
        if (!$id) {
            $this->error('ID requis');
        }
        
        $this->categories->delete($id, $this->userId);
        $this->success([], 'Catégorie supprimée avec succès');
    }

    /**
     * Supprime une catégorie (ID dans le body pour compatibilité frontend)
     * 
     * @param Request $request Requête HTTP (id dans body)
     * 
     * @return void
     */
    public function deleteFromBody(Request $request): void
    {
        $id = (int)$request->get('id');
        
        if (!$id) {
            $this->error('ID requis');
        }
        
        $this->categories->delete($id, $this->userId);
        $this->success([], 'Catégorie supprimée avec succès');
    }

    /**
     * Assigne ou retire une catégorie d'un projet
     * 
     * @param Request $request Requête HTTP (project_id, category_id)
     * 
     * @return void
     */
    public function assign(Request $request): void
    {
        $projectId = (int)$request->get('project_id');
        $categoryId = $request->get('category_id');
        
        if (!$projectId) {
            $this->error('Le project_id est requis');
        }
        
        if ($categoryId !== null && $categoryId !== '') {
            $this->categories->setForProject($projectId, [(int)$categoryId], $this->userId);
            $this->success([], 'Catégorie assignée avec succès');
        } else {
            $this->categories->setForProject($projectId, [], $this->userId);
            $this->success([], 'Catégorie retirée avec succès');
        }
    }
}
