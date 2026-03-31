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
    private int $totalForDepth = 0;
    private int $processedOffset = 0;

    private const RETRYABLE_CODES = [429, 500, 502, 503, 504];
    private const MAX_RETRIES = 4;
    private const BASE_DELAY = 2;
    private const JITTER_PERCENT = 20;

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

    public function getNextUrls(int $limit = 0, int $maxDepth = -1)
    {
        $respectRobots = $this->config['respect']['robots'] ?? true;
        return $this->crawlDb->getUrlsToCrawl($respectRobots, $limit, $maxDepth);
    }

    public function countRemainingUrls(int $maxDepth = -1): int
    {
        $respectRobots = $this->config['respect']['robots'] ?? true;
        return $this->crawlDb->countUrlsToCrawl($respectRobots, $maxDepth);
    }

    public function run(array $options)
    {
        $this->depth = $options['depth'];
        $this->urls = $options['urls'];
        $this->totalForDepth = $options['totalForDepth'] ?? count($this->urls);
        $this->processedOffset = $options['processedOffset'] ?? 0;

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
            if ($status === 'stopping' || $status === 'stopped' || $status === 'failed') {
                throw new \Exception("Crawl stop signal received");
            }
        }
    }

    /**
     * Detecte si la reponse HTTP est retryable (429, 5xx, timeout)
     */
    private function isRetryableResponse($request): bool
    {
        $httpCode = 0;
        $isTimeout = false;

        if ($request instanceof Request) {
            $httpCode = (int)($request->getResponseInfo()['http_code'] ?? 0);
            $isTimeout = ($request->getResponseErrno() == CURLE_OPERATION_TIMEDOUT);
        } else {
            $info = $request->getResponseInfo();
            $httpCode = (int)($info['http_code'] ?? 0);
            $isTimeout = ($httpCode === 0 && !empty($info['error']));
        }

        return in_array($httpCode, self::RETRYABLE_CODES) || $isTimeout;
    }

    /**
     * Retry les URLs echouees avec backoff exponentiel via curl_multi
     * Un seul sleep avant chaque batch, puis toutes les URLs en parallele
     * Tentatives: 2s, 4s, 8s, 16s avec +/- 20% de jitter
     */
    private function retryFailedUrls(array $failedUrls): void
    {
        if (empty($failedUrls)) return;

        // Skip retry si désactivé dans la config
        if (!($this->config['retry_failed_urls'] ?? true)) {
            return;
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES && !empty($failedUrls); $attempt++) {
            $this->checkStopSignal();

            $baseDelay = self::BASE_DELAY * pow(2, $attempt - 1); // 2, 4, 8, 16
            // Jitter global : +/- 20%
            $jitter = $baseDelay * (mt_rand(-self::JITTER_PERCENT, self::JITTER_PERCENT) / 100);
            $actualDelay = $baseDelay + $jitter;

            echo "\n \033[33m ↻ Retry attempt $attempt/" . self::MAX_RETRIES
                 . " for " . count($failedUrls) . " URLs (~" . round($actualDelay, 1) . "s pause)\033[0m";
            flush();
            if (ob_get_level() > 0) ob_flush();

            // Un seul sleep avant le batch entier
            usleep((int)($actualDelay * 1000000));

            // curl_multi pour fetcher toutes les URLs en parallele
            $mh = curl_multi_init();
            $handles = [];

            foreach ($failedUrls as $url) {
                $ch = curl_init($url);
                curl_setopt_array($ch, $this->curlOptions);
                curl_multi_add_handle($mh, $ch);
                $handles[(int)$ch] = ['handle' => $ch, 'url' => $url];
            }

            // Executer toutes les requetes en parallele
            $active = null;
            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh, 1);
                }
            } while ($active && $status === CURLM_OK);

            // Traiter les resultats
            $stillFailing = [];
            foreach ($handles as $id => $data) {
                $ch = $data['handle'];
                $url = $data['url'];

                $response = curl_multi_getcontent($ch);
                $info = curl_getinfo($ch);
                $errno = curl_errno($ch);
                $error = curl_error($ch);

                $httpCode = (int)($info['http_code'] ?? 0);
                $isTimeout = ($errno == CURLE_OPERATION_TIMEDOUT);
                $isRetryable = in_array($httpCode, self::RETRYABLE_CODES) || $isTimeout;

                if ($isRetryable && $attempt < self::MAX_RETRIES) {
                    $stillFailing[] = $url;
                } else {
                    // Succes OU derniere tentative : stocker en base
                    $request = new RetryRequest($url, $response ?: '', $info, $errno, $error);
                    try {
                        $pageCrawler = new PageCrawler($this->crawlDb, $this->depth, $this->domains, $this->config);
                        $pageCrawler->run($request);
                    } catch (\Exception $e) {
                        error_log("PageCrawler error on " . $request->getUrl() . ": " . $e->getMessage());
                    }
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);

            // Mettre a jour les stats apres chaque batch de retry
            $this->crawlDb->updateCrawlStats();

            $resolved = count($failedUrls) - count($stillFailing);
            if ($resolved > 0) {
                echo "\n \033[32m ✓ $resolved URLs resolved on attempt $attempt\033[0m";
                flush();
                if (ob_get_level() > 0) ob_flush();
            }

            $failedUrls = $stillFailing;
        }

        echo "\n";
        flush();
        if (ob_get_level() > 0) ob_flush();
    }

    /**
     * Crawl normal sans limitation de vitesse
     */
    private function runNormal()
    {
        $this->prepare_crawl();
        $failedUrls = [];

        $retryEnabled = $this->config['retry_failed_urls'] ?? true;
        $this->crawler->setCallback(function(Request $request,RollingCurl $rollingCurl) use (&$failedUrls, $retryEnabled) {
            $this->checkStopSignal();

            if ($retryEnabled && $this->isRetryableResponse($request)) {
                $failedUrls[] = $request->getUrl();
            } else {
                try {
                    $PageCrawler = new PageCrawler($this->crawlDb, $this->depth, $this->domains, $this->config);
                    $PageCrawler->run($request);
                } catch (\Exception $e) {
                    error_log("PageCrawler error on " . $request->getUrl() . ": " . $e->getMessage());
                }
            }

            self::$iterations++;
            if(self::$iterations == 5)
            {
                $timestamp = microtime(true);
                $duree = $timestamp - self::$timestamp;
                if($duree > 0) {
                    self::$vitesse = round((5/$duree),2);
                }
                self::$timestamp = $timestamp;
                self::$iterations = 0;
            }
            $this->update++;
            $globalDone = min($this->processedOffset + $this->update, $this->totalForDepth);
            if ($this->update % 10 == 0 || $this->update == count($this->urls)) {
                echo "\r \033[32m Depth ".$this->depth." : \033[0m".self::$vitesse." URLs/sec \033[36m(".$globalDone."/".$this->totalForDepth.")\033[0m                             ";
                flush();
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                if (time() - self::$lastStatsUpdate >= 10) {
                    self::$lastStatsUpdate = time();
                    $this->crawlDb->updateCrawlStats();
                }
            }
        })
        ->setSimultaneousLimit($this->simultaneousLimit)
        ->execute();

        // Retry les URLs echouees avec backoff exponentiel
        $this->retryFailedUrls($failedUrls);

        // Forcer un stats update apres les retries
        $this->crawlDb->updateCrawlStats();
        self::$lastStatsUpdate = time();

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
        $failedUrls = [];

        foreach ($batches as $batchIndex => $batchUrls) {
            $this->checkStopSignal();

            $batchStartTime = microtime(true);

            $batchCrawler = new RollingCurl();
            $batchCrawler->setOptions($this->curlOptions);

            foreach ($batchUrls as $url) {
                $url = trim($url);
                if (!empty($url)) {
                    if (HtmlParser::regexMatch("#^https?://[^/]+$#", $url) == true) {
                        $url = $url . "/";
                    }
                    $batchCrawler->get($url);
                }
            }

            $retryEnabled = $this->config['retry_failed_urls'] ?? true;
            $batchCrawler->setCallback(function(Request $request, RollingCurl $rollingCurl) use (&$failedUrls, $retryEnabled) {
                if ($retryEnabled && $this->isRetryableResponse($request)) {
                    $failedUrls[] = $request->getUrl();
                } else {
                    $PageCrawler = new PageCrawler($this->crawlDb, $this->depth, $this->domains, $this->config);
                    $PageCrawler->run($request);
                }

                self::$iterations++;
                if (self::$iterations == 5) {
                    $timestamp = microtime(true);
                    $duree = $timestamp - self::$timestamp;
                    if ($duree > 0) {
                        self::$vitesse = round((5 / $duree), 2);
                    }
                    self::$timestamp = $timestamp;
                    self::$iterations = 0;
                }
                $this->update++;
                $globalDone = min($this->processedOffset + $this->update, $this->totalForDepth);
                if ($this->update % 10 == 0 || $this->update == count($this->urls)) {
                    echo "\r \033[32m Depth " . $this->depth . " : \033[0m" . self::$vitesse . " URLs/sec \033[36m(" . $globalDone . "/" . $this->totalForDepth . ")\033[0m                             ";
                    flush();
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    if (time() - self::$lastStatsUpdate >= 10) {
                        self::$lastStatsUpdate = time();
                        $this->crawlDb->updateCrawlStats();
                    }
                }
            })
            ->setSimultaneousLimit($this->simultaneousLimit)
            ->execute();

            // THROTTLING : délai ENTRE les batches
            $batchDuration = microtime(true) - $batchStartTime;
            $targetDuration = 1.0;

            if ($batchDuration < $targetDuration) {
                $sleepTime = ($targetDuration - $batchDuration) * 1000000;
                usleep((int)$sleepTime);
            }
        }

        // Retry les URLs echouees avec backoff exponentiel
        $this->retryFailedUrls($failedUrls);

        // Forcer un stats update apres les retries
        $this->crawlDb->updateCrawlStats();
        self::$lastStatsUpdate = time();

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
        $failedUrls = [];

        foreach ($megaBatches as $megaBatch) {
            $this->checkStopSignal();

            $rendererBatches = array_chunk($megaBatch, $batchPerRenderer);

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
                    CURLOPT_TIMEOUT => 60
                ]);

                curl_multi_add_handle($multiHandle, $ch);
                $curlHandles[] = $ch;
            }

            $running = null;
            do {
                curl_multi_exec($multiHandle, $running);
                curl_multi_select($multiHandle);
            } while ($running > 0);

            foreach ($curlHandles as $ch) {
                $response = curl_multi_getcontent($ch);
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);

                $data = json_decode($response, true);

                if (!isset($data['results']) || !is_array($data['results'])) {
                    continue;
                }

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

                    // Verifier si retryable (429, 5xx, timeout)
                    $retryEnabled = $this->config['retry_failed_urls'] ?? true;
                    if ($retryEnabled && $this->isRetryableResponse($request)) {
                        $failedUrls[] = $url;
                    } else {
                        $PageCrawler = new PageCrawler($this->crawlDb, $this->depth, $this->domains, $this->config);
                        $PageCrawler->run($request);
                    }

                    $this->update++;
                    $totalProcessed++;
                }

                $elapsedTime = microtime(true) - $crawlStartTime;
                if ($elapsedTime > 0.5) {
                    self::$vitesse = round($totalProcessed / $elapsedTime, 2);
                }

                $globalDone = min($this->processedOffset + $this->update, $this->totalForDepth);
                echo "\r \033[32m Depth ".$this->depth." : \033[0m".self::$vitesse." URLs/sec \033[36m(".$globalDone."/".$this->totalForDepth.")\033[0m                             ";
                flush();
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                if (time() - self::$lastStatsUpdate >= 10) {
                    self::$lastStatsUpdate = time();
                    $this->crawlDb->updateCrawlStats();
                }
            }

            curl_multi_close($multiHandle);
            $this->checkStopSignal();
        }

        // Retry les URLs echouees avec backoff exponentiel (cURL direct, pas renderer)
        $this->retryFailedUrls($failedUrls);

        // Forcer un stats update apres les retries
        $this->crawlDb->updateCrawlStats();
        self::$lastStatsUpdate = time();

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

/**
 * Classe simulant un objet Request pour les retries cURL
 */
class RetryRequest
{
    private string $url;
    private string $responseText;
    private array $info;
    private int $errno;
    private string $error;

    public function __construct(string $url, string $responseText, array $info, int $errno = 0, string $error = '')
    {
        $this->url = $url;
        $this->responseText = $responseText;
        $this->info = $info;
        $this->errno = $errno;
        $this->error = $error;
    }

    public function getUrl(): string { return $this->url; }
    public function getResponseText(): string { return $this->responseText; }
    public function getResponseInfo(): array
    {
        return [
            'http_code' => $this->info['http_code'] ?? 0,
            'total_time' => $this->info['total_time'] ?? 0,
            'content_type' => $this->info['content_type'] ?? '',
            'redirect_url' => $this->info['redirect_url'] ?? '',
            'url' => $this->url,
            'error' => $this->error,
            'size_download' => $this->info['size_download'] ?? strlen($this->responseText),
            'starttransfer_time' => $this->info['starttransfer_time'] ?? 0,
        ];
    }
    public function getResponseErrno(): int { return $this->errno; }
    public function getResponseError(): string { return $this->error; }
}
