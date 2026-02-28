<?php

namespace App\Http;

/**
 * Wrapper pour les données de requête HTTP
 * 
 * Encapsule les données GET, POST, JSON et les paramètres de route.
 * Fournit une interface unifiée pour accéder aux données de la requête.
 * 
 * @package    Scouter
 * @subpackage Http
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class Request
{
    /**
     * Méthode HTTP (GET, POST, PUT, DELETE, etc.)
     * 
     * @var string
     */
    private string $method;

    /**
     * URI de la requête (sans query string)
     * 
     * @var string
     */
    private string $uri;

    /**
     * Tous les paramètres fusionnés (query + post + json + route)
     * 
     * @var array<string, mixed>
     */
    private array $params;

    /**
     * Paramètres GET ($_GET)
     * 
     * @var array<string, mixed>
     */
    private array $query;

    /**
     * Paramètres POST ($_POST)
     * 
     * @var array<string, mixed>
     */
    private array $post;

    /**
     * Corps JSON décodé
     * 
     * @var array<string, mixed>|null
     */
    private ?array $json;

    /**
     * Paramètres extraits de l'URL (ex: {id})
     * 
     * @var array<string, mixed>
     */
    private array $routeParams = [];

    /**
     * Constructeur - Parse la requête HTTP
     */
    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->uri = $this->parseUri();
        $this->query = $_GET;
        $this->post = $_POST;
        $this->json = $this->parseJsonBody();
        $this->params = array_merge($this->query, $this->post, $this->json ?? []);
    }

    /**
     * Parse et nettoie l'URI de la requête
     * 
     * @return string
     */
    private function parseUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Retirer le prefix /api si présent
        $uri = preg_replace('#^/api#', '', $uri);
        
        // Retirer la query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        
        // Nettoyer
        $uri = '/' . trim($uri, '/');
        
        return $uri;
    }

    /**
     * Parse le corps JSON de la requête
     * 
     * @return array<string, mixed>|null
     */
    private function parseJsonBody(): ?array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (stripos($contentType, 'application/json') !== false) {
            $body = file_get_contents('php://input');
            return json_decode($body, true) ?: [];
        }
        
        if (in_array($this->method, ['PUT', 'DELETE', 'PATCH'])) {
            $body = file_get_contents('php://input');
            if ($body) {
                $decoded = json_decode($body, true);
                if ($decoded !== null) {
                    return $decoded;
                }
                parse_str($body, $parsed);
                return $parsed;
            }
        }
        
        return null;
    }

    /**
     * Retourne la méthode HTTP
     * 
     * @return string
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Retourne l'URI de la requête
     * 
     * @return string
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Récupère un paramètre (query, post, json ou route)
     * 
     * @param string $key     Clé du paramètre
     * @param mixed  $default Valeur par défaut
     * 
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Retourne tous les paramètres
     * 
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->params;
    }

    /**
     * Récupère un paramètre GET
     * 
     * @param string $key     Clé du paramètre
     * @param mixed  $default Valeur par défaut
     * 
     * @return mixed
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Récupère un paramètre POST
     * 
     * @param string $key     Clé du paramètre
     * @param mixed  $default Valeur par défaut
     * 
     * @return mixed
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Récupère un paramètre du corps JSON
     * 
     * @param string $key     Clé du paramètre
     * @param mixed  $default Valeur par défaut
     * 
     * @return mixed
     */
    public function json(string $key, mixed $default = null): mixed
    {
        return $this->json[$key] ?? $default;
    }

    /**
     * Vérifie si un paramètre existe
     * 
     * @param string $key Clé du paramètre
     * 
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->params[$key]);
    }

    /**
     * Définit les paramètres de route
     * 
     * @param array<string, mixed> $params Paramètres extraits de l'URL
     * 
     * @return void
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
        $this->params = array_merge($this->params, $params);
    }

    /**
     * Récupère un paramètre de route
     * 
     * @param string $key     Clé du paramètre
     * @param mixed  $default Valeur par défaut
     * 
     * @return mixed
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Vérifie si la requête utilise une méthode HTTP donnée
     * 
     * @param string $method Méthode à vérifier
     * 
     * @return bool
     */
    public function isMethod(string $method): bool
    {
        return strtoupper($this->method) === strtoupper($method);
    }
}
