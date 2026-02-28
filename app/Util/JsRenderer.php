<?php

namespace App\Util;

/**
 * Client pour le microservice de rendu JavaScript
 * 
 * Cette classe communique avec un service Puppeteer (Node.js) qui
 * exécute le JavaScript des pages et retourne le HTML rendu.
 * 
 * Utilisé pour crawler les SPA (Single Page Applications) et
 * les sites avec contenu généré dynamiquement côté client.
 * 
 * Architecture :
 * ```
 * Scouter -> JsRenderer -> Puppeteer (Docker) -> Site web
 * ```
 * 
 * @package    Scouter
 * @subpackage Renderer
 * @author     Mehdi Colin
 * @version    2.0.0
 * @since      2.0.0
 * 
 * @see DepthCrawler Pour l'utilisation en mode JavaScript
 */
class JsRenderer
{
    private string $serviceUrl;
    private int $timeout;
    
    /**
     * @param string $serviceUrl URL du service de rendu (défaut: http://renderer:3000)
     * @param int $timeout Timeout en secondes (défaut: 60)
     */
    public function __construct(string $serviceUrl = null, int $timeout = 60)
    {
        $this->serviceUrl = $serviceUrl ?? (getenv('RENDERER_URL') ?: 'http://renderer:3000');
        $this->timeout = $timeout;
    }
    
    /**
     * Récupère le contenu HTML d'une page après exécution du JavaScript
     * 
     * @param string $url L'URL cible à rendre
     * @param array $headers Headers HTTP à envoyer (User-Agent, Cookie, Authorization, etc.)
     * @return string Le HTML rendu
     * @throws \Exception En cas d'erreur
     */
    public function render(string $url, array $headers = []): string
    {
        $endpoint = rtrim($this->serviceUrl, '/') . '/render';
        
        $payload = json_encode([
            'url' => $url,
            'headers' => $headers
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        
        if ($errno) {
            throw new \Exception("Erreur cURL ($errno): $error");
        }
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error'] ?? $response;
            throw new \Exception("Erreur Renderer JS ($httpCode): $errorMessage");
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['success'])) {
            throw new \Exception("Réponse invalide du renderer");
        }
        
        if (!$data['success']) {
            throw new \Exception("Erreur de rendu: " . ($data['error'] ?? 'Erreur inconnue'));
        }
        
        return $data['html'] ?? '';
    }
    
    /**
     * Vérifie si le service de rendu est disponible
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        $endpoint = rtrim($this->serviceUrl, '/') . '/health';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return false;
        }
        
        $data = json_decode($response, true);
        return isset($data['status']) && $data['status'] === 'ok';
    }
    
    /**
     * Définit le timeout
     * 
     * @param int $timeout Timeout en secondes
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }
}
