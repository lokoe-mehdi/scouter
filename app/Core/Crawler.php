<?php

namespace App\Core;

use App\Database\CrawlDatabase;
use App\Util\HtmlParser;
use App\Analysis\RobotsTxt;

/**
 * Orchestrateur principal du crawl
 * 
 * Cette classe gère le cycle de vie complet d'un crawl :
 * - Initialisation de la base de données (partitions PostgreSQL)
 * - Insertion de l'URL de départ
 * - Gestion des profondeurs de crawl (depth 0 à N)
 * - Exécution du post-traitement (inlinks, pagerank, semantic)
 * - Gestion des signaux d'arrêt
 * 
 * @package    Scouter
 * @subpackage Crawler
 * @author     Mehdi Colin
 * @version    2.0.0
 * @since      1.0.0
 * 
 * @see DepthCrawler Pour le crawl d'une profondeur spécifique
 * @see CrawlDatabase Pour les opérations base de données
 */
class Crawler
{
    private CrawlDatabase $crawlDb;
    private int $crawlId;
    private $start;
    private $pattern;
    private $depthMax;
    private $config;
    private $newCrawl = false;
    private string $crawlType;
    private array $urlList;

    /**
     * Constructeur du crawler
     * 
     * @param array $options Options de configuration :
     *        - crawl_id (int) : ID du crawl dans la BDD
     *        - start (string) : URL de départ
     *        - depthMax (int) : Profondeur maximale
     *        - pattern (array) : Domaines autorisés
     *        - config (array) : Configuration avancée
     */
    public function __construct($options)
    {
        $this->crawlId = $options['crawl_id'];
        $this->start = $options['start'];
        $this->depthMax = $options['depthMax'];
        $this->pattern = $options['pattern'];
        $this->config = $options['config'];
        $this->crawlType = $options['crawl_type'] ?? 'spider';
        $this->urlList = $options['url_list'] ?? [];

        // Injecter crawl_type dans config pour propagation vers PageCrawler
        $this->config['crawl_type'] = $this->crawlType;
        
        // Utiliser PostgreSQL via CrawlDatabase
        $this->crawlDb = new CrawlDatabase($this->crawlId, $this->config);
        
        // Vérifier si c'est un nouveau crawl
        $this->newCrawl = $this->crawlDb->isNewCrawl();
        
        // Créer les partitions SEULEMENT si le worker ne les a pas déjà créées
        // Le worker passe PARTITIONS_CREATED=1 pour éviter les deadlocks
        if ($this->newCrawl && !getenv('PARTITIONS_CREATED')) {
            $this->crawlDb->createPartitions();
        }
    }

    /**
     * Insère l'URL de départ dans la base de données
     *
     * En mode spider, insère l'URL de départ unique.
     * En mode liste, insère toutes les URLs de la liste.
     *
     * @return void
     */
    private function insertStart()
    {
        if ($this->crawlType === 'list') {
            $this->insertUrlList();
            return;
        }

        // Fix canonical SLASH
        if (!empty($this->start)) {
            if (HtmlParser::regexMatch("#^https?://[^/]+$#", $this->start) == true) {
                $this->start = $this->start . "/";
            }
        }

        // ROBOTS.TXT VERIF
        $blocked = false;
        $allowed = RobotsTxt::robots_allowed($this->start);
        if ($allowed == false) {
            $blocked = true;
        }

        $id = hash('crc32', $this->start, FALSE);
        preg_match("#https?:\/\/([^/]+)#i", $this->start, $dom);
        $domain = $dom[1] ?? '';

        $this->crawlDb->insertPage([
            'id' => $id,
            'domain' => $domain,
            'url' => $this->start,
            'depth' => 0,
            'code' => 0,
            'crawled' => false,
            'external' => false,
            'blocked' => $blocked
        ]);
    }

    /**
     * Insère toutes les URLs de la liste en mode "liste"
     *
     * Chaque URL est normalisée, vérifiée contre robots.txt,
     * et insérée avec depth=0.
     *
     * @return void
     */
    private function insertUrlList()
    {
        $pages = [];
        $date = date("Y-m-d H:i:s");

        foreach ($this->urlList as $url) {
            // Normaliser le slash final pour les domaines nus
            if (HtmlParser::regexMatch("#^https?://[^/]+$#", $url) == true) {
                $url = $url . "/";
            }

            $id = hash('crc32', $url, FALSE);
            preg_match("#https?:\/\/([^/]+)#i", $url, $dom);
            $domain = $dom[1] ?? '';
            $blocked = !RobotsTxt::robots_allowed($url);

            $pages[] = [
                'id' => $id,
                'domain' => $domain,
                'url' => $url,
                'depth' => 0,
                'code' => 0,
                'crawled' => false,
                'external' => false,
                'blocked' => $blocked,
                'date' => $date
            ];
        }

        if (!empty($pages)) {
            $this->crawlDb->insertPages($pages);
        }
    }

    /**
     * Démarre le crawl itératif par profondeur
     * 
     * Boucle sur les profondeurs de 0 à depthMax, récupère les URLs
     * à crawler à chaque niveau et lance DepthCrawler.
     * Gère les signaux d'arrêt et le post-traitement.
     * 
     * @return void
     * @throws \Exception Si le crawl échoue
     */
    private function depthStarter()
    {
        $urls = [$this->start];
        $respectRobots = $this->config['respect']['robots'] ?? true;
        $batchSize = 5000; // Process URLs in batches to avoid OOM on large crawls

        try {
            for ($i = 0; $i <= $this->depthMax; $i++) {
                if ($this->newCrawl == false) {
                    $depthReal = $this->crawlDb->getCurrentDepth();
                    if ($depthReal > 0) {
                        $i = $depthReal;
                    }
                }

                echo "\r\n";

                // Process URLs in batches to keep memory bounded
                // Filter by depth <= $i to avoid pulling in URLs discovered at deeper depths
                $batchNum = 0;
                while (true) {
                    $crawl = new DepthCrawler($this->crawlDb, $this->pattern, $this->config);
                    $urls = $crawl->getNextUrls($batchSize, $i);

                    if (count($urls) === 0) {
                        break;
                    }

                    $batchNum++;
                    $remaining = $crawl->countRemainingUrls($i);
                    if ($remaining > $batchSize) {
                        echo "  \033[90m[batch $batchNum — " . count($urls) . " URLs, $remaining remaining]\033[0m\n";
                    }

                    $crawl->run([
                        "depth" => $i,
                        "urls" => $urls
                    ]);

                    // Free memory between batches
                    unset($crawl, $urls);
                }

                if ($batchNum === 0) {
                    break; // No URLs found at this depth
                }
            }
        } catch (\Exception $e) {
            if ($e->getMessage() === "Crawl stop signal received") {
                echo "\n\n\033[33m! Crawl stopped by user\033[0m\n";
            } else {
                throw $e; // Re-throw other errors
            }
        }
        
        // Marquer le crawl comme terminé (ou arrêté)
        echo "\n\n\033[32m✓ Crawl finish (or stopped)\033[0m\n";
        flush();
        if (ob_get_level() > 0) {
            ob_flush();
        }
        
        // Exécuter le post-traitement
        $this->crawlDb->runPostProcessing();
        
        // Mettre à jour les stats finales
        // Si le status est 'stopping', on le passe à 'stopped'
        // Sinon on le passe à 'finished'
        $currentStatus = $this->crawlDb->getCrawlStatus();
        if ($currentStatus === 'stopping' || $currentStatus === 'stopped') {
             // Just update stats but keep/set status to stopped
             $this->crawlDb->updateCrawlStats();
             // Manually set to stopped just in case
             $crawlRepo = new \App\Database\CrawlRepository();
             $crawlRepo->update($this->crawlId, ['status' => 'stopped', 'in_progress' => 0, 'finished_at' => date('Y-m-d H:i:s')]);
        } else {
            $this->crawlDb->finishCrawl();
        }
    }

    /**
     * Exécute le crawl complet
     * 
     * Point d'entrée principal : insère l'URL de départ si nouveau crawl,
     * puis lance le crawl par profondeur.
     * 
     * @return void
     */
    public function run()
    {
        if ($this->newCrawl == true) {
            $this->insertStart();
        }
        $this->depthStarter();
    }
}