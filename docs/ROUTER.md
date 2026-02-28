# Routeur API Scouter

## Architecture

Le routeur API est un système léger sans dépendance externe qui gère toutes les requêtes REST.

```
web/api/
  index.php      # Point d'entrée + définition des routes
  .htaccess      # Rewrite rules Apache

app/Http/
  Router.php     # Classe de routing
  Request.php    # Wrapper requête HTTP
  Response.php   # Helpers réponse (JSON, CSV, HTML)
  Controller.php # Classe de base abstraite
  Controllers/   # Controllers par domaine
```

## Fonctionnement

1. Toutes les requêtes vers `/api/*` sont redirigées vers `web/api/index.php` via `.htaccess`
2. Le `Router` compare l'URI avec les routes enregistrées
3. Si match, il instancie le Controller et appelle la méthode
4. Le Controller utilise `Response` pour envoyer la réponse

## Définir une route

Les routes sont définies dans `web/api/index.php` :

```php
use App\Http\Router;
use App\Http\Request;
use App\Http\Controllers\MonController;

$router = new Router();
$request = new Request();

// GET /api/items
$router->get('/items', [MonController::class, 'index'], ['auth' => true]);

// POST /api/items
$router->post('/items', [MonController::class, 'create'], ['auth' => true]);

// GET /api/items/123
$router->get('/items/{id}', [MonController::class, 'show'], ['auth' => true]);

// PUT /api/items/123
$router->put('/items/{id}', [MonController::class, 'update'], ['auth' => true]);

// DELETE /api/items/123
$router->delete('/items/{id}', [MonController::class, 'delete'], ['auth' => true]);

// Dispatch (à la fin)
$router->dispatch($request);
```

## Méthodes HTTP disponibles

| Méthode | Usage |
|---------|-------|
| `$router->get()` | Lecture de données |
| `$router->post()` | Création de ressource |
| `$router->put()` | Mise à jour complète |
| `$router->delete()` | Suppression |
| `$router->any()` | Toutes les méthodes |

## Paramètres d'URL

Les paramètres dynamiques utilisent la syntaxe `{param}` :

```php
// Route: /projects/{id}/shares
$router->get('/projects/{id}/shares', [ProjectController::class, 'shares']);

// Dans le controller:
public function shares(Request $request): void
{
    $projectId = $request->param('id');  // Récupère le paramètre de l'URL
}
```

## Options d'authentification

Le 3ème paramètre définit les règles d'accès :

```php
// Authentification requise
['auth' => true]

// Admin uniquement
['auth' => true, 'admin' => true]

// Droits de création requis (rôle user ou admin)
['auth' => true, 'canCreate' => true]
```

## Créer un Controller

1. Créer le fichier dans `app/Http/Controllers/` :

```php
<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Controller pour gérer les items
 * 
 * @package    Scouter
 * @subpackage Http\Controllers
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class ItemController extends Controller
{
    /**
     * Liste tous les items
     * 
     * @param Request $request Requête HTTP
     * 
     * @return void
     */
    public function index(Request $request): void
    {
        $items = ['item1', 'item2'];
        $this->success(['items' => $items]);
    }

    /**
     * Crée un item
     * 
     * @param Request $request Requête HTTP (name, value)
     * 
     * @return void
     */
    public function create(Request $request): void
    {
        $name = $request->get('name');
        
        if (empty($name)) {
            $this->error('Le nom est requis');
        }
        
        // Création...
        
        $this->success(['id' => 123], 'Item créé');
    }
}
```

2. Enregistrer les routes dans `web/api/index.php` :

```php
use App\Http\Controllers\ItemController;

$router->get('/items', [ItemController::class, 'index'], ['auth' => true]);
$router->post('/items', [ItemController::class, 'create'], ['auth' => true]);
```

## Classe Request

Accéder aux données de la requête :

```php
// Paramètre de route (/items/{id})
$request->param('id');

// Paramètre GET ou POST ou JSON
$request->get('name');
$request->get('limit', 10);  // Avec valeur par défaut

// Uniquement GET
$request->query('page');

// Uniquement POST
$request->post('email');

// Uniquement JSON body
$request->json('data');

// Tous les paramètres
$request->all();

// Vérifier si existe
$request->has('filter');

// Méthode HTTP
$request->method();      // 'GET', 'POST', etc.
$request->isMethod('POST');
```

## Classe Response

Envoyer des réponses :

```php
// Succès JSON
Response::success(['data' => $result]);
Response::success(['data' => $result], 'Message de succès');

// Erreur JSON
Response::error('Message d\'erreur');
Response::error('Message', 400);  // Avec code HTTP

// Erreurs prédéfinies
Response::notFound('Ressource non trouvée');
Response::unauthorized('Non authentifié');
Response::forbidden('Accès interdit');
Response::methodNotAllowed();
Response::serverError('Erreur serveur');

// JSON brut
Response::json(['custom' => 'data'], 200);

// Export CSV
Response::csv('export.csv', function($output) {
    fputcsv($output, ['col1', 'col2']);
    fputcsv($output, ['val1', 'val2']);
});

// HTML
Response::html('<h1>Preview</h1>');
```

## Validation

Le Controller de base fournit une méthode `validate()` :

```php
public function create(Request $request): void
{
    $data = $this->validate($request, [
        'email' => 'required|email',
        'password' => 'required|min:6',
        'age' => 'numeric'
    ]);
    
    // Si validation échoue, Response::error() est appelé automatiquement
    // Sinon $data contient les valeurs validées
}
```

Règles disponibles :
- `required` : champ obligatoire
- `email` : format email valide
- `numeric` : valeur numérique
- `min:N` : longueur minimale de N caractères

## Routes existantes

### Categories
- `GET /api/categories` - Liste
- `POST /api/categories` - Créer
- `PUT /api/categories/{id}` - Modifier
- `DELETE /api/categories/{id}` - Supprimer
- `POST /api/categories/assign` - Assigner à un projet

### Users (admin)
- `GET /api/users` - Liste
- `POST /api/users` - Créer
- `PUT /api/users/{id}` - Modifier
- `DELETE /api/users/{id}` - Supprimer
- `POST /api/logout` - Déconnexion

### Projects
- `GET /api/projects` - Liste
- `GET /api/projects/{id}` - Détails
- `POST /api/projects` - Créer
- `PUT /api/projects/{id}` - Modifier
- `DELETE /api/projects/{id}` - Supprimer
- `GET /api/projects/{id}/shares` - Liste partages
- `POST /api/projects/{id}/share` - Partager
- `POST /api/projects/{id}/unshare` - Retirer partage
- `GET /api/projects/{id}/stats` - Statistiques

### Crawls
- `GET /api/crawls/info` - Infos crawl
- `POST /api/crawls/start` - Démarrer
- `POST /api/crawls/stop` - Arrêter
- `POST /api/crawls/resume` - Reprendre
- `POST /api/crawls/delete` - Supprimer
- `GET /api/crawls/running` - Crawls en cours

### Jobs
- `GET /api/jobs/status` - Statut job
- `GET /api/jobs/logs` - Logs job

### Query
- `POST /api/query/execute` - Exécuter SQL
- `GET /api/query/url-details` - Détails URL
- `GET /api/query/quick-search` - Recherche rapide
- `GET /api/query/html-source` - Source HTML

### Export
- `GET /api/export/csv` - Export pages CSV
- `GET /api/export/links-csv` - Export liens CSV

### Monitor
- `GET /api/monitor/preview` - Preview HTML
- `GET /api/monitor/system` - Stats système

### Categorization
- `POST /api/categorization/save` - Sauvegarder config
- `POST /api/categorization/test` - Tester config
- `GET /api/categorization/stats` - Stats catégories
- `GET /api/categorization/table` - URLs par catégorie
