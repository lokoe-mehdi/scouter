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
     * Vérifie le robots.txt et normalise l'URL avant insertion.
     * 
     * @return void
     */
    private function insertStart()
    {
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
        
        try {
            for ($i = 0; $i <= $this->depthMax; $i++) {
                if ($this->newCrawl == false) {
                    $depthReal = $this->crawlDb->getCurrentDepth();
                    if ($depthReal > 0) {
                        $i = $depthReal;
                    }
                }

                echo "\r\n";
                
                // Récupérer les URLs AVANT de vérifier si la liste est vide
                $crawl = new DepthCrawler($this->crawlDb, $this->pattern, $this->config);
                $urls = $crawl->getNextUrls();
                
                if (count($urls) === 0) {
                    break;
                }
                
                $crawl->run([
                    "depth" => $i,
                    "urls" => $urls
                ]);
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