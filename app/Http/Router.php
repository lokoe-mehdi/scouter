<?php

namespace App\Http;

use App\Auth\Auth;

/**
 * Routeur HTTP pour les APIs REST
 * 
 * Gère le routing des requêtes HTTP vers les controllers appropriés.
 * Supporte les paramètres dynamiques dans les URLs (ex: /users/{id}).
 * 
 * @package    Scouter
 * @subpackage Http
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class Router
{
    /**
     * Liste des routes enregistrées
     * 
     * @var array<int, array{method: string, path: string, handler: array|callable, options: array}>
     */
    private array $routes = [];

    /**
     * Instance d'authentification
     * 
     * @var Auth|null
     */
    private ?Auth $auth = null;

    /**
     * Constructeur - Initialise l'authentification
     */
    public function __construct()
    {
        $this->auth = new Auth();
    }

    /**
     * Enregistre une route GET
     * 
     * @param string         $path    Chemin de la route (ex: /users/{id})
     * @param array|callable $handler Handler [Controller::class, 'method'] ou callable
     * @param array          $options Options (auth, admin, canCreate)
     * 
     * @return self
     */
    public function get(string $path, array|callable $handler, array $options = []): self
    {
        return $this->addRoute('GET', $path, $handler, $options);
    }

    /**
     * Enregistre une route POST
     * 
     * @param string         $path    Chemin de la route
     * @param array|callable $handler Handler
     * @param array          $options Options d'authentification
     * 
     * @return self
     */
    public function post(string $path, array|callable $handler, array $options = []): self
    {
        return $this->addRoute('POST', $path, $handler, $options);
    }

    /**
     * Enregistre une route PUT
     * 
     * @param string         $path    Chemin de la route
     * @param array|callable $handler Handler
     * @param array          $options Options d'authentification
     * 
     * @return self
     */
    public function put(string $path, array|callable $handler, array $options = []): self
    {
        return $this->addRoute('PUT', $path, $handler, $options);
    }

    /**
     * Enregistre une route DELETE
     * 
     * @param string         $path    Chemin de la route
     * @param array|callable $handler Handler
     * @param array          $options Options d'authentification
     * 
     * @return self
     */
    public function delete(string $path, array|callable $handler, array $options = []): self
    {
        return $this->addRoute('DELETE', $path, $handler, $options);
    }

    /**
     * Enregistre une route pour toutes les méthodes HTTP
     * 
     * @param string         $path    Chemin de la route
     * @param array|callable $handler Handler
     * @param array          $options Options d'authentification
     * 
     * @return self
     */
    public function any(string $path, array|callable $handler, array $options = []): self
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $this->addRoute($method, $path, $handler, $options);
        }
        return $this;
    }

    /**
     * Ajoute une route à la liste
     * 
     * @param string         $method  Méthode HTTP
     * @param string         $path    Chemin de la route
     * @param array|callable $handler Handler
     * @param array          $options Options
     * 
     * @return self
     */
    private function addRoute(string $method, string $path, array|callable $handler, array $options): self
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'options' => $options
        ];
        return $this;
    }

    /**
     * Dispatche la requête vers le handler approprié
     * 
     * @param Request $request Requête HTTP
     * 
     * @return void
     */
    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $uri = $request->uri();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchRoute($route['path'], $uri);
            
            if ($params !== false) {
                $request->setRouteParams($params);
                $this->applyAuth($route['options']);
                $this->executeHandler($route['handler'], $request);
                return;
            }
        }

        Response::notFound('Route non trouvée: ' . $method . ' ' . $uri);
    }

    /**
     * Vérifie si une route correspond à l'URI
     * 
     * @param string $pattern Pattern de la route (ex: /users/{id})
     * @param string $uri     URI de la requête
     * 
     * @return array|false Paramètres extraits ou false
     */
    private function matchRoute(string $pattern, string $uri): array|false
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $uri, $matches)) {
            return array_filter($matches, fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
        }

        return false;
    }

    /**
     * Applique les règles d'authentification
     * 
     * @param array $options Options (auth, admin, canCreate)
     * 
     * @return void
     */
    private function applyAuth(array $options): void
    {
        if (!empty($options['auth'])) {
            $this->auth->requireLoginApi();
        }

        if (!empty($options['admin'])) {
            $this->auth->requireAdmin(true);
        }

        if (!empty($options['canCreate'])) {
            $this->auth->requireCanCreate(true);
        }
    }

    /**
     * Exécute le handler de la route
     * 
     * @param array|callable $handler Handler à exécuter
     * @param Request        $request Requête HTTP
     * 
     * @return void
     */
    private function executeHandler(array|callable $handler, Request $request): void
    {
        try {
            if (is_callable($handler)) {
                $handler($request, $this->auth);
            } elseif (is_array($handler) && count($handler) === 2) {
                [$class, $method] = $handler;
                $controller = new $class($this->auth);
                $controller->$method($request);
            }
        } catch (\Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    /**
     * Retourne l'instance d'authentification
     * 
     * @return Auth
     */
    public function getAuth(): Auth
    {
        return $this->auth;
    }
}
