<?php

namespace App\Core;

use RollingCurl\RollingCurl;
use RollingCurl\Request;
use App\Util\HtmlParser;
use App\Util\JsRenderer;
use App\Database\CrawlDatabase;

/**
 * Crawler d'une profondeur spécifique avec requêtes parallèles
 * 
 * Cette classe gère le crawl de toutes les URLs d'une profondeur donnée :
 * - Requêtes HTTP parallèles via RollingCurl
 * - Support du rendu JavaScript via Puppeteer
 * - Gestion de la vitesse de crawl (rate limiting)
 * - Vérification des signaux d'arrêt
 * 
 * Modes de crawl supportés :
 * - `classic` : Requêtes HTTP directes (rapide)
 * - `javascript` : Rendu via navigateur headless (lent mais complet)
 * 
 * @package    Scouter
 * @subpackage Crawler
 * @author     Mehdi Colin
 * @version    2.0.0
 * @since      1.0.0
 * 
 * @see Crawler Pour l'orchestration globale
 * @see JsRenderer Pour le rendu JavaScript
 */
class DepthCrawler
{
    private CrawlDatabase $crawlDb;
    private $depth;
    private $urls;
    private $crawler;
    private $update = 0;
    private $domains;
    private $config;
    private $crawlSpeed;
    private $crawlMode;
    private $simultaneousLimit;
    private $targetUrlsPerSecond;
    private $curlOptions;
    private $jsRenderer;

    static $timestamp = 0;
    static $vitesse = 0;
    static $iterations = 0;
    static $lastRequestTime = 0;
    static $lastStatsUpdate = 0;


    public function __construct(CrawlDatabase $crawlDb, $domains, $config)
    {
        $this->crawlDb = $crawlDb;
        $this->domains = $domains;
        $this->config = $config;
        
        // Déterminer la vitesse de crawl depuis la config
        $this->crawlSpeed = isset($config['crawl_speed']) ? $config['crawl_speed'] : 'fast';
        $this->crawlMode = isset($config['crawl_mode']) ? $config['crawl_mode'] : 'classic';
        $this->configureCrawlSpeed();
        
        // Initialiser le renderer JS si mode javascript
        if ($this->crawlMode === 'javascript') {
            $this->jsRenderer = new JsRenderer();
        }
        
        //dump($this->config['xPathExtractors']);
        $this->crawler = new RollingCurl();
        
        // Préparer les headers HTTP
        $httpHeaders = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ];
        
        // Ajouter les headers personnalisés depuis la config
        if (isset($this->config['customHeaders']) && is_array($this->config['customHeaders'])) {
            foreach ($this->config['customHeaders'] as $name => $value) {
                $httpHeaders[] = "$name: $value";
            }
        }
        
        // Définir le User-Agent depuis la config
        $userAgent = isset($this->config['user-agent']) ? $this->config['user-agent'] : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36';
        
        $curlOptions = [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '', // Laisse cURL gérer automatiquement gzip/deflate/br
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT  => 5,
            CURLOPT_HTTPHEADER => $httpHeaders,
            // === OPTIMISATIONS PERFORMANCE ===
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0, // HTTP/2 multiplexage
            CURLOPT_TCP_KEEPALIVE => 1,       // Maintient les connexions TCP ouvertes
            CURLOPT_TCP_KEEPIDLE => 60,       // Délai avant premier keepalive
            CURLOPT_TCP_KEEPINTVL => 30,      // Intervalle entre keepalives
            CURLOPT_FORBID_REUSE => false,    // Réutilise les connexions
            CURLOPT_FRESH_CONNECT => false,   // Ne force pas nouvelle connexion
            CURLOPT_DNS_CACHE_TIMEOUT => 300, // Cache DNS 5 minutes
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // Force IPv4 (plus rapide)
            CURLOPT_TCP_NODELAY => true,      // Désactive Nagle (envoie immédiat)
        ];
        
        // Ajouter l'authentification HTTP Basic si configurée
        if (isset($this->config['httpAuth']['enabled']) && $this->config['httpAuth']['enabled'] === true) {
            if (!empty($this->config['httpAuth']['username']) && !empty($this->config['httpAuth']['password'])) {
                $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
                $curlOptions[CURLOPT_USERPWD] = $this->config['httpAuth']['username'] . ':' . $this->config['httpAuth']['password'];
            }
        }
        
        $this->curlOptions = $curlOptions;
        $this->crawler->setOptions($curlOptions);

        self::$timestamp = microtime(true);
        self::$lastRequestTime = microtime(true);
    }

    /**
     * Configure la vitesse de crawl en définissant le nombre de threads et les délais
     */
    private function configureCrawlSpeed()
    {
        switch ($this->crawlSpeed) {
            case 'very_slow':
                // ~1 URL/seconde
                $this->simultaneousLimit = 2;
                $this->targetUrlsPerSecond = 1;
                break;
            
            case 'slow':
                // ~5 URLs/seconde
                $this->simultaneousLimit = 3;
                $this->targetUrlsPerSecond = 5;
                break;
            
            case 'fast':
                // ~10-15 URLs/seconde max
                $this->simultaneousLimit = 8;
                $this->targetUrlsPerSecond = 15; // throttling à 15 URLs/sec
                break;
            
            case 'unlimited':
            default:
                // Sans limite - MAXIMUM PERFORMANCE
                $this->simultaneousLimit = 10;
                $this->targetUrlsPerSecond = 0; // pas de limite
                break;
        }

        // OVERRIDE 1: Si une variable d'environnement est définie (Priorité Docker)
        $envMaxCurl = getenv('MAX_CONCURRENT_CURL');
        if ($envMaxCurl !== false && (int)$envMaxCurl > 0) {
            $this->simultaneousLimit = (int)$envMaxCurl;
        }

        // OVERRIDE 2: Si une limite spécifique est définie dans la config du projet (Priorité Projet)
        if (isset($this->config['max_concurrent_curl']) && $this->config['max_concurrent_curl'] > 0) {
            $this->simultaneousLimit = (int)$this->config['max_concurrent_curl'];
        }
    }

    public function getNextUrls()
    {
        $respectRobots = $this->config['respect']['robots'] ?? true;
        return $this->crawlDb->getUrlsToCrawl($respectRobots);
    }

    public function run(array $options)
    {
        $this->depth = $options['depth'];
        $this->urls = $options['urls'];

        // Mode JavaScript : crawl séquentiel avec Puppeteer
        if ($this->crawlMode === 'javascript') {
            $this->runJavascript();
        }
        // Si throttling activé, on crawle par batches avec délai ENTRE les batches
        elseif ($this->targetUrlsPerSecond > 0) {
            $this->runWithThrottling();
        } else {
            // Mode normal sans limitation
            $this->runNormal();
        }
    }
    
    private $lastStopCheck = 0;
    
    private function checkStopSignal()
    {
        // Vérifier toutes les 3 mises à jour OU toutes les 5 secondes
        $now = time();
        $shouldCheck = ($this->update % 3 === 0) || ($now - $this->lastStopCheck >= 5);
        
        if ($shouldCheck) {
            $this->lastStopCheck = $now;
            $status = $this->crawlDb->getCrawlStatus();
            if ($status === 'stopping' || $status === 'stopped') {
                throw new \Exception("Crawl stop signal received");
            }
        }
    }

    /**
     * Crawl normal sans limitation de vitesse
     */
    private function runNormal()
    {
        $this->prepare_crawl();
        
        $this->crawler->setCallback(function(Request $request,RollingCurl $rollingCurl) {
            $this->checkStopSignal();

            $PageCrawler = new PageCrawler($this->crawlDb, $this->depth, $this->domains, $this->config);
            $PageCrawler->run($request);

            self::$iterations++;
            if(self::$iterations == 5)
            {
                $timestamp = microtime(true);
                $duree = $timestamp - self::$timestamp;
                // Protéger contre les durées négatives ou nulles (horloge système ajustée)
                if($duree > 0) { 
                    self::$vitesse = round((5/$duree),2);
                }
                self::$timestamp = $timestamp;
                self::$iterations = 0;
            }
            $this->update++;
            if ($this->update % 10 == 0 || $this->update == count($this->urls)) {
                echo "\r \033[32m Depth ".$this->depth." : \033[0m".self::$vitesse." URLs/sec \033[36m(".$this->update."/".count($this->urls).")\033[0m                             ";
                flush();
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                // Mettre à jour les stats en temps réel (toutes les 10 secondes max)
                if (time() - self::$lastStatsUpdate >= 10) {
                    self::$lastStatsUpdate = time();
                    $this->crawlDb->updateCrawlStats();
                }
            }
        })
        ->setSimultaneousLimit($this->simultaneousLimit)
        ->execute();
        
        echo "\n";
        flush();
        if (ob_get_level() > 0) {
            ob_flush();
        }
    }
    
    /**
     * Crawl avec throttling - on crawle par batches avec délai ENTRE les batches
     * Le sleep est HORS du callback, donc n'impacte pas les temps de réponse
     */
    private function runWithThrottling()
    {
        $batchSize = $this->targetUrlsPerSecond; // 1 batch = 1 seconde de travail
        $batches = array_chunk($this->urls, max(1, $batchSize));
        
        foreach ($batches as $batchIndex => $batchUrls) {
            $this->checkStopSignal();
            
            $batchStartTime = microtime(true);
            
            // Créer un nouveau RollingCurl pour ce batch
            $batchCrawler = new RollingCurl();
            $batchCrawler->setOptions($this->curlOptions);
            
            // Ajouter les URLs du batch
            foreach ($batchUrls as $url) {
                $url = trim($url);
                if (!empty($url)) {
                    if (HtmlParser::regexMatch("#^https?://[^/]+$#", $url) == true) {
                        $url = $url . "/";
                    }
                    $batchCrawler->get($url);
                }
            }
            
            // Callback propre sans sleep
            $batchCrawler->setCallback(function(Request $request, RollingCurl $rollingCurl) {
                $PageCrawler = new PageCrawler($this->crawlDb, $this->depth, $this->domains, $this->config);
                $PageCrawler->run($request);

                self::$iterations++;
                if (self::$iterations == 5) {
                    $timestamp = microtime(true);
                    $duree = $timestamp - self::$timestamp;
                    // Protéger contre les durées négatives ou nulles
                    if ($duree > 0) {
                        self::$vitesse = round((5 / $duree), 2);
                    }
                    self::$timestamp = $timestamp;
                    self::$iterations = 0;
                }
                $this->update++;
                if ($this->update % 10 == 0 || $this->update == count($this->urls)) {
                    echo "\r \033[32m Depth " . $this->depth . " : \033[0m" . self::$vitesse . " URLs/sec \033[36m(" . $this->update . "/" . count($this->urls) . ")\033[0m                             ";
                    flush();
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    // Mettre à jour les stats en temps réel (toutes les 10 secondes max)
                    if (time() - self::$lastStatsUpdate >= 10) {
                        self::$lastStatsUpdate = time();
                        $this->crawlDb->updateCrawlStats();
                    }
                }
            })
            ->setSimultaneousLimit($this->simultaneousLimit)
            ->execute();
            
            // THROTTLING : délai ENTRE les batches (pas dans le callback !)
            $batchDuration = microtime(true) - $batchStartTime;
            $targetDuration = 1.0; // 1 seconde par batch
            
            if ($batchDuration < $targetDuration) {
                $sleepTime = ($targetDuration - $batchDuration) * 1000000;
                usleep((int)$sleepTime);
            }
        }
        
        echo "\n";
        flush();
        if (ob_get_level() > 0) {
            ob_flush();
        }
    }

    private function prepare_crawl(){
        foreach($this->urls as $url)
        {
            $url = trim($url);
            
            
            if(!empty($url)) {
                // FIX SLASH
                if(HtmlParser::regexMatch("#^https?://[^/]+$#", $url) == true)
                {
                    $url = $url."/";
                }
                $this->crawler->get($url);
            }
        }
    }

    /**
     * Crawl avec exécution JavaScript via Puppeteer
     * Utilise /render-batch pour envoyer plusieurs URLs en une seule requête (ULTRA RAPIDE)
     */
    private function runJavascript()
    {
        $totalUrls = count($this->urls);
        
        // Préparer les headers pour le renderer
        $headers = [
            'User-Agent' => isset($this->config['user-agent']) ? $this->config['user-agent'] : 'Scouter/0.3'
        ];
        
        if (isset($this->config['customHeaders']) && is_array($this->config['customHeaders'])) {
            foreach ($this->config['customHeaders'] as $name => $value) {
                $headers[$name] = $value;
            }
        }
        
        if (isset($this->config['httpAuth']['enabled']) && $this->config['httpAuth']['enabled'] === true) {
            if (!empty($this->config['httpAuth']['username']) && !empty($this->config['httpAuth']['password'])) {
                $auth = base64_encode($this->config['httpAuth']['username'] . ':' . $this->config['httpAuth']['password']);
                $headers['Authorization'] = 'Basic ' . $auth;
            }
        }

        // Support multi-renderers pour parallélisme
        $rendererUrlsEnv = getenv('RENDERER_URLS') ?: getenv('RENDERER_URL') ?: 'http://renderer:3000';
        $rendererUrls = array_map('trim', explode(',', $rendererUrlsEnv));
        $rendererCount = count($rendererUrls);
        
        // Préparer les URLs
        $urlsToProcess = [];
        foreach ($this->urls as $url) {
            $url = trim($url);
            if (empty($url)) continue;
            if (HtmlParser::regexMatch("#^https?://[^/]+$#", $url) == true) {
                $url = $url . "/";
            }
            $urlsToProcess[] = $url;
        }
        
        // BATCH MODE: 20 URLs par renderer, 3 renderers = 60 URLs en parallèle
        $batchPerRenderer = 20;
        $totalBatchSize = $batchPerRenderer * $rendererCount; // 30 URLs par mega-batch
        $megaBatches = array_chunk($urlsToProcess, $totalBatchSize);
        
        $totalProcessed = 0;
        $crawlStartTime = microtime(true);
        
        foreach ($megaBatches as $megaBatch) {
            $this->checkStopSignal();
            
            // Diviser le mega-batch entre les renderers
            $rendererBatches = array_chunk($megaBatch, $batchPerRenderer);
            
            // Envoyer en parallèle à chaque renderer
            $multiHandle = curl_multi_init();
            $curlHandles = [];
            
            foreach ($rendererBatches as $index => $batchUrls) {
                $rendererUrl = $rendererUrls[$index % $rendererCount];
                
                $payload = json_encode([
                    'urls' => $batchUrls,
                    'headers' => $headers
                ]);
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $rendererUrl . '/render-batch',
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 60  // Plus long car batch
                ]);
                
                curl_multi_add_handle($multiHandle, $ch);
                $curlHandles[] = $ch;
            }
            
            // Exécuter tous les batches en parallèle
            $running = null;
            do {
                curl_multi_exec($multiHandle, $running);
                curl_multi_select($multiHandle);
            } while ($running > 0);
            
            // Traiter les résultats de tous les renderers
            foreach ($curlHandles as $ch) {
                $response = curl_multi_getcontent($ch);
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);
                
                $data = json_decode($response, true);
                
                if (!isset($data['results']) || !is_array($data['results'])) {
                    continue;
                }
                
                // Traiter chaque résultat du batch
                foreach ($data['results'] as $result) {
                    $url = $result['url'] ?? '';
                    if (empty($url)) continue;
                    
                    if (isset($result['success']) && $result['success']) {
                        $realHttpCode = (int)($result['httpCode'] ?? 200);
                        $realResponseTime = (float)($result['responseTime'] ?? 0);
                        $finalUrl = $result['finalUrl'] ?? '';
                        $httpRedirectCodes = [301, 302, 303, 307, 308];
                        
                        if (in_array($realHttpCode, $httpRedirectCodes) && !empty($finalUrl)) {
                            $request = new JsRequest($url, $result['html'] ?? '', $realResponseTime, $realHttpCode, '', $finalUrl);
                        } elseif (in_array($realHttpCode, [200, 304]) && !empty($result['jsRedirect']) && !empty($finalUrl)) {
                            $request = new JsRequest($url, $result['html'] ?? '', $realResponseTime, 311, '', $finalUrl);
                        } else {
                            $request = new JsRequest($url, $result['html'] ?? '', $realResponseTime, $realHttpCode);
                        }
                    } else {
                        $error = $result['error'] ?? 'Erreur renderer';
                        $request = new JsRequest($url, '', 0, 500, $error);
                    }
                    
                    $PageCrawler = new PageCrawler($this->crawlDb, $this->depth, $this->domains, $this->config);
                    $PageCrawler->run($request);
                    
                    $this->update++;
                    $totalProcessed++;
                }
                
                // Mise à jour affichage après chaque batch renderer
                $elapsedTime = microtime(true) - $crawlStartTime;
                if ($elapsedTime > 0.5) {
                    self::$vitesse = round($totalProcessed / $elapsedTime, 2);
                }
                
                echo "\r \033[32m Depth ".$this->depth." : \033[0m".self::$vitesse." URLs/sec \033[36m(".$this->update."/".$totalUrls.")\033[0m                             ";
                flush();
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                // Mettre à jour les stats en temps réel (toutes les 10 secondes max)
                if (time() - self::$lastStatsUpdate >= 10) {
                    self::$lastStatsUpdate = time();
                    $this->crawlDb->updateCrawlStats();
                }
            }
            
            curl_multi_close($multiHandle);
            $this->checkStopSignal();
        }
        
        echo "\n";
        flush();
        if (ob_get_level() > 0) {
            ob_flush();
        }
    }
}

/**
 * Classe simulant un objet Request de RollingCurl pour le mode JS
 */
class JsRequest
{
    private string $url;
    private string $responseText;
    private float $responseTime;
    private int $httpCode;
    private string $error;
    private string $redirectUrl;
    
    public function __construct(string $url, string $responseText, float $responseTime, int $httpCode = 200, string $error = '', string $redirectUrl = '')
    {
        $this->url = $url;
        $this->responseText = $responseText;
        $this->responseTime = $responseTime;
        $this->httpCode = $httpCode;
        $this->error = $error;
        $this->redirectUrl = $redirectUrl;
    }
    
    public function getUrl(): string
    {
        return $this->url;
    }
    
    public function getResponseText(): string
    {
        return $this->responseText;
    }
    
    public function getResponseInfo(): array
    {
        return [
            'http_code' => $this->httpCode,
            'total_time' => $this->responseTime,
            'content_type' => 'text/html',
            'redirect_url' => $this->redirectUrl,
            'url' => $this->url,
            'error' => $this->error,
            'size_download' => strlen($this->responseText)
        ];
    }
}
