<?php

namespace App\Core;

use Xparse\ElementFinder\ElementFinder;
use App\Util\HtmlParser;
use App\Analysis\RobotsTxt;
use App\Database\CrawlDatabase;
use App\Database\DeadlockRetry;
use PDOException;

/**
 * Traitement d'une page crawlée et stockage en base
 * 
 * Cette classe fait le lien entre le crawler et la base de données :
 * - Reçoit la réponse HTTP brute
 * - Crée un objet Page pour l'analyse
 * - Stocke les données en base (page, links, HTML)
 * - Gère les transactions pour la performance
 * 
 * @package    Scouter
 * @subpackage Crawler
 * @author     Mehdi Colin
 * @version    2.0.0
 * @since      1.0.0
 * 
 * @see Page Pour l'analyse du contenu
 * @see DepthCrawler Pour l'orchestration des requêtes
 */
class PageCrawler
{
    use DeadlockRetry;
    
    private int $depth;
    private CrawlDatabase $crawlDb;
    private array $pattern;
    private array $config;
    private $page;

    public function __construct(CrawlDatabase $crawlDb, int $depth, array $pattern, array $config)
    {
        $this->crawlDb = $crawlDb;
        $this->depth = $depth;
        $this->pattern = $pattern;
        $this->config = $config;
    }
    
    public function run($request)
    {
        $url = $request->getUrl();
        $headers = (object) $request->getResponseInfo();
        $dom = $request->getResponseText();
        
        // cURL gère automatiquement la décompression gzip via CURLOPT_ENCODING
        
        $p = new Page($url, $headers, $dom, $this->pattern, $this->config);
        $this->page = $p->getPage();

        // Transaction avec retry automatique sur deadlock (40P01)
        $db = $this->crawlDb->getDb();
        
        $this->executeTransactionWithRetry($db, function($pdo) {
            // OPTIMISATION: Un seul updatePage() au lieu de 2
            $this->storePageComplete();
            
            if (!empty(trim($this->page->headers->redirect_to))) {
                $external = $this->isExternal($this->page->headers->redirect_to);
                $this->storeRedirect($this->page->headers->redirect_to, $external);
            } else {
                $this->storeLinks();
                $this->storeRaw();
            }
        }, 3); // 3 tentatives max
    }

    private function isExternal($url){
        $external = 1;
        foreach($this->pattern as $domain){
            $domain = str_replace(".","\.",$domain);
            $domain = str_replace("*","[^\.]*",$domain);
            if(HtmlParser::regexMatch("#^https?://".$domain."#", trim($url))){
                $external=0;
            }
        }
        return $external;
    }


    /**
     * OPTIMISÉ: Stocke la page ET les extraits en UN SEUL appel updatePage()
     * Réduit la latence DB de 50% (2 roundtrips -> 1)
     */
    private function storePageComplete()
    {
        $responseTime = $this->page->headers->response_time * 1000;
        $contentType = $this->page->headers->content_type ?? '';
        $date = date("Y-m-d H:i:s");
        $id = $this->page->id;
        $redirectTo = $this->page->headers->redirect_to ?? '';
        $countLinks = count($this->page->links);

        $nofollow = (bool)$this->page->config['nofollow'];
        $noindex = (bool)$this->page->config['noindex'];
        $isCanonical = (bool)($this->page->config['canonical'] == 1);
        $canonicalValue = $this->page->extracts['canonical'] ?? null;
        $compliant = false;
        $blocked = !RobotsTxt::robots_allowed($this->page->url);

        if (!$blocked && !$noindex && $isCanonical && 
            $this->page->headers->http_code == 200 && !empty($this->page->domHash)) {
            $compliant = true;
        }

        // Préparer les données de base
        $updateData = [
            'code' => $this->page->headers->http_code,
            'crawled' => true,
            'content_type' => $contentType,
            'outlinks' => $countLinks,
            'date' => $date,
            'nofollow' => $nofollow,
            'compliant' => $compliant,
            'noindex' => $noindex,
            'canonical' => $isCanonical,
            'canonical_value' => $canonicalValue,
            'redirect_to' => $redirectTo,
            'response_time' => $responseTime,
            'is_html' => $this->page->is_html ?? false,
            'simhash' => $this->page->simhash ?? null,
            'h1_multiple' => $this->page->h1_multiple ?? false,
            'headings_missing' => $this->page->headings_missing ?? false
        ];
        
        // Ajouter les extraits SEO si la page a du contenu
        if (!empty($this->page->domHash)) {
            $updateData['title'] = $this->page->extracts['title'] ?? null;
            $updateData['h1'] = $this->page->extracts['h1'] ?? null;
            $updateData['metadesc'] = $this->page->extracts['meta_desc'] ?? null;
            $updateData['word_count'] = $this->page->word_count ?? 0;
            
            // Extraits custom (JSONB)
            $extracts = [];
            foreach ($this->page->extracts as $key => $val) {
                if (!in_array($key, ['title', 'h1', 'meta_desc', 'canonical'])) {
                    $extracts[$key] = $val;
                }
            }
            if (!empty($extracts)) {
                $updateData['extracts'] = $extracts;
            }
            
            // Schemas
            $schemas = $this->page->schemas ?? [];
            if (!empty($schemas)) {
                $updateData['schemas'] = $schemas;
                // Insérer aussi dans page_schemas pour les stats
                $this->crawlDb->insertPageSchemas($this->page->id, $schemas);
            }
        }
        
        // UN SEUL appel DB au lieu de 2!
        $this->crawlDb->updatePage($id, $updateData);
    }

    private function storeRedirect($url, $external)
    {
        $date = date("Y-m-d H:i:s");
        $depth = $this->depth + 1;
        $id = hash('crc32', $url, FALSE);
        $blocked = !RobotsTxt::robots_allowed($url);

        // Insérer la page de redirection
        $this->crawlDb->insertPage([
            'id' => $id,
            'domain' => $this->page->domain,
            'url' => $url,
            'depth' => $depth,
            'code' => 0,
            'crawled' => false,
            'external' => (bool)$external,
            'blocked' => $blocked,
            'date' => $date
        ]);

        // Insérer le lien de redirection
        $this->crawlDb->insertLink([
            'src' => $this->page->id,
            'target' => $id,
            'type' => 'redirect',
            'external' => (bool)$external,
            'nofollow' => false
        ]);
    }

    private function storeLinks()
    {
        $src = $this->page->id;
        $depth = $this->depth + 1;
        $date = date("Y-m-d H:i:s");
        
        // Si non-canonique et respect_canonical activé, on suit seulement la canonical
        if ($this->page->config['canonical'] === 0 && ($this->config['respect']['canonical'] ?? true)) {
            $this->page->links = [];
            
            $canonicalUrl = $this->page->extracts['canonical'] ?? '';
            if (!empty($canonicalUrl)) {
                $cible = hash('crc32', trim($canonicalUrl), FALSE);
                $external = $this->isExternal($canonicalUrl);
                $blocked = !RobotsTxt::robots_allowed($canonicalUrl);
                
                preg_match("#https?:\/\/([^/\?]+)#i", $canonicalUrl, $dom);
                $domain = $dom[1] ?? '';
                
                // Insérer le lien canonical
                $this->crawlDb->insertLink([
                    'src' => $src,
                    'target' => $cible,
                    'anchor' => '',
                    'type' => 'canonical',
                    'external' => (bool)$external,
                    'nofollow' => false
                ]);
                
                // Insérer la page canonical
                $this->crawlDb->insertPage([
                    'id' => $cible,
                    'domain' => $domain,
                    'url' => $canonicalUrl,
                    'depth' => $depth,
                    'code' => 0,
                    'crawled' => false,
                    'external' => (bool)$external,
                    'blocked' => $blocked,
                    'date' => $date
                ]);
            }
        }

        // Insérer les liens et pages découvertes
        if (count($this->page->links) > 0) {
            $links = [];
            $pages = [];
            
            foreach ($this->page->links as $link) {
                $links[] = [
                    'src' => $src,
                    'target' => $link->target_id,
                    'anchor' => $link->anchor ?? '',
                    'type' => 'ahref',
                    'external' => (bool)$link->external,
                    'nofollow' => (bool)$link->nofollow
                ];
                
                preg_match("#https?:\/\/([^/\?]+)#i", $link->target, $dom);
                $domain = $dom[1] ?? '';
                
                $pages[] = [
                    'id' => $link->target_id,
                    'domain' => $domain,
                    'url' => $link->target,
                    'depth' => $depth,
                    'code' => 0,
                    'crawled' => false,
                    'external' => (bool)$link->external,
                    'blocked' => (bool)$link->blocked,
                    'date' => $date
                ];
            }
            
            $this->crawlDb->insertLinks($links);
            $this->crawlDb->insertPages($pages);
        }
    }

    private function storeRaw()
    {
        $this->crawlDb->insertHtml($this->page->id, $this->page->domZip);
    }
}