<?php

namespace App\Http;

/**
 * Helpers statiques pour les réponses HTTP
 * 
 * Fournit des méthodes pour envoyer des réponses JSON, CSV ou HTML
 * avec les codes de statut appropriés.
 * 
 * @package    Scouter
 * @subpackage Http
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class Response
{
    /**
     * Envoie une réponse JSON
     * 
     * @param array<string, mixed> $data   Données à encoder
     * @param int                  $status Code de statut HTTP
     * 
     * @return void
     */
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Envoie une réponse de succès
     * 
     * @param array<string, mixed> $data    Données additionnelles
     * @param string|null          $message Message de succès
     * 
     * @return void
     */
    public static function success(array $data = [], string $message = null): void
    {
        $response = ['success' => true];
        
        if ($message) {
            $response['message'] = $message;
        }
        
        self::json(array_merge($response, $data));
    }

    /**
     * Envoie une réponse d'erreur
     * 
     * @param string               $message Message d'erreur
     * @param int                  $status  Code de statut HTTP
     * @param array<string, mixed> $extra   Données additionnelles
     * 
     * @return void
     */
    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(array_merge([
            'success' => false,
            'error' => $message
        ], $extra), $status);
    }

    /**
     * Envoie une erreur 404 Not Found
     * 
     * @param string $message Message d'erreur
     * 
     * @return void
     */
    public static function notFound(string $message = 'Ressource non trouvée'): void
    {
        self::error($message, 404);
    }

    /**
     * Envoie une erreur 401 Unauthorized
     * 
     * @param string $message Message d'erreur
     * 
     * @return void
     */
    public static function unauthorized(string $message = 'Non autorisé'): void
    {
        self::error($message, 401);
    }

    /**
     * Envoie une erreur 403 Forbidden
     * 
     * @param string $message Message d'erreur
     * 
     * @return void
     */
    public static function forbidden(string $message = 'Accès interdit'): void
    {
        self::error($message, 403);
    }

    /**
     * Envoie une erreur 405 Method Not Allowed
     * 
     * @return void
     */
    public static function methodNotAllowed(): void
    {
        self::error('Méthode non autorisée', 405);
    }

    /**
     * Envoie une erreur 500 Internal Server Error
     * 
     * @param string $message Message d'erreur
     * 
     * @return void
     */
    public static function serverError(string $message = 'Erreur serveur'): void
    {
        self::error($message, 500);
    }

    /**
     * Envoie un fichier CSV en téléchargement
     * 
     * @param string   $filename  Nom du fichier
     * @param callable $generator Fonction qui écrit les données CSV
     * 
     * @return void
     */
    public static function csv(string $filename, callable $generator): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        $generator($output);
        
        fclose($output);
        exit;
    }

    /**
     * Envoie une réponse HTML
     * 
     * @param string $content Contenu HTML
     * @param int    $status  Code de statut HTTP
     * 
     * @return void
     */
    public static function html(string $content, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $content;
        exit;
    }
}
