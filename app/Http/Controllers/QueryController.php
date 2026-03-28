<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use PDO;

/**
 * Controller pour les requêtes SQL et les détails d'URL
 * 
 * Permet d'exécuter des requêtes SELECT et de récupérer les détails des pages.
 * 
 * @package    Scouter
 * @subpackage Http\Controllers
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class QueryController extends Controller
{
    /**
     * Connexion PDO à la base de données
     * 
     * @var PDO
     */
    private PDO $db;

    /**
     * Constructeur
     * 
     * @param \App\Auth\Auth $auth Instance d'authentification
     */
    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Exécute une requête SQL SELECT sur les tables du crawl
     * 
     * Transforme les noms de tables virtuels (pages, links, categories)
     * en tables partitionnées réelles. Interdit les requêtes de modification.
     * 
     * @param Request $request Requête HTTP (query, project)
     * 
     * @return void
     */
    public function execute(Request $request): void
    {
        $query = trim($request->get('query', ''));
        $projectDir = $request->get('project');
        
        if (empty($query) || empty($projectDir)) {
            $this->error('Requête ou projet manquant');
        }
        
        if (is_numeric($projectDir)) {
            $this->auth->requireCrawlAccessById((int)$projectDir, true);
            $crawlRecord = CrawlDatabase::getCrawlById((int)$projectDir);
        } else {
            $this->auth->requireCrawlAccess($projectDir, true);
            $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        }

        if (!$crawlRecord) {
            Response::notFound('Projet non trouvé');
        }

        $crawlId = $crawlRecord->id;

        // SÉCURITÉ : Vérifier que SEULES les requêtes SELECT sont autorisées
        $queryUpper = strtoupper(trim($query));
        
        $forbiddenKeywords = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 
            'TRUNCATE', 'REPLACE', 'RENAME', 'ATTACH', 'DETACH',
            'VACUUM', 'REINDEX', 'GRANT', 'REVOKE'
        ];
        
        if (strpos($queryUpper, 'SELECT') !== 0) {
            Response::forbidden('Seules les requêtes SELECT sont autorisées.');
        }
        
        foreach ($forbiddenKeywords as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $query)) {
                Response::forbidden('Requête interdite : opération de modification détectée (' . $keyword . ').');
            }
        }
        
        // Transformer les références multi-crawl (syntaxe table@ID)
        // categories@ID is a no-op since categories are now project-level
        $referencedCrawlIds = [];
        $transformedQuery = preg_replace_callback(
            '/\b(pages|links|duplicate_clusters|page_schemas|redirect_chains)@(\d+)\b/i',
            function($matches) use (&$referencedCrawlIds) {
                $referencedCrawlIds[] = (int)$matches[2];
                return $matches[1] . '_' . $matches[2];
            },
            $query
        );
        // categories@ID → crawl_categories (project-level, no partition)
        $transformedQuery = preg_replace('/\bcategories@\d+\b/i', 'crawl_categories', $transformedQuery);

        // Valider que les crawl IDs référencés appartiennent au même projet
        if (!empty($referencedCrawlIds)) {
            foreach (array_unique($referencedCrawlIds) as $refId) {
                $refCrawl = CrawlDatabase::getCrawlById($refId);
                if (!$refCrawl || $refCrawl->project_id !== $crawlRecord->project_id) {
                    Response::forbidden("Cannot query crawl {$refId}: not in the same project.");
                }
            }
        }

        // Transformer les tables virtuelles restantes vers le crawl courant
        $transformedQuery = preg_replace('/\bpages\b(?!_\d)/i', "pages_{$crawlId}", $transformedQuery);
        $transformedQuery = preg_replace('/\blinks\b(?!_\d)/i', "links_{$crawlId}", $transformedQuery);
        $transformedQuery = preg_replace('/(?<!crawl_)\bcategories\b(?!_\d)/i', "crawl_categories", $transformedQuery);
        $transformedQuery = preg_replace('/\bduplicate_clusters\b(?!_\d)/i', "duplicate_clusters_{$crawlId}", $transformedQuery);
        $transformedQuery = preg_replace('/\bpage_schemas\b(?!_\d)/i', "page_schemas_{$crawlId}", $transformedQuery);
        
        $stmt = $this->db->query($transformedQuery);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $columns = [];
        if (!empty($rows)) {
            $columns = array_keys($rows[0]);
            $columns = array_filter($columns, fn($col) => $col !== 'crawl_id');
            $columns = array_values($columns);
            
            $rows = array_map(function($row) {
                unset($row['crawl_id']);
                return $row;
            }, $rows);
        }
        
        $this->json([
            'type' => 'select',
            'columns' => $columns,
            'rows' => $rows,
            'count' => count($rows)
        ]);
    }

    /**
     * Retourne les détails complets d'une URL
     * 
     * Inclut les métadonnées, extractions, inlinks, outlinks et headings HTML.
     * 
     * @param Request $request Requête HTTP (project, url)
     * 
     * @return void
     */
    public function urlDetails(Request $request): void
    {
        $projectDir = $request->get('project');
        $url = $request->get('url');
        
        if (!$projectDir || !$url) {
            $this->error('Missing parameters');
        }
        
        if (is_numeric($projectDir)) {
            $this->auth->requireCrawlAccessById((int)$projectDir, true);
            $crawlRecord = CrawlDatabase::getCrawlById((int)$projectDir);
        } else {
            $this->auth->requireCrawlAccess($projectDir, true);
            $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        }
        
        if (!$crawlRecord) {
            Response::notFound('Crawl not found');
        }
        
        $crawlId = $crawlRecord->id;
        
        // Charger les catégories (project-level)
        $categoriesMap = [];
        $categoryColors = [];
        $stmt = $this->db->prepare("SELECT id, cat, color FROM crawl_categories WHERE project_id = :project_id");
        $stmt->execute([':project_id' => $crawlRecord->project_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categoriesMap[$row['id']] = $row['cat'];
            $categoryColors[$row['cat']] = $row['color'];
        }
        
        // Récupérer les détails de l'URL (inclut inlinks/outlinks counts pré-calculés)
        $stmt = $this->db->prepare("
            SELECT id, url, domain, depth, code, crawled, content_type, inlinks, outlinks, date,
                   nofollow, compliant, noindex, canonical, canonical_value, redirect_to,
                   response_time, blocked, external, title, h1, metadesc, extracts, cat_id,
                   h1_multiple, headings_missing, schemas, word_count
            FROM pages WHERE crawl_id = :crawl_id AND url = :url
        ");
        $stmt->execute([':crawl_id' => $crawlId, ':url' => $url]);
        $urlData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$urlData) {
            Response::notFound('URL not found');
        }

        $catName = $categoriesMap[$urlData['cat_id']] ?? 'Non catégorisé';
        $urlData['category'] = $catName;
        $urlData['category_color'] = $categoryColors[$catName] ?? '#95a5a6';

        $extracts = [
            'title' => $urlData['title'] ?? '',
            'h1' => $urlData['h1'] ?? '',
            'metadesc' => $urlData['metadesc'] ?? ''
        ];

        $customExtracts = $urlData['extracts'] ? json_decode($urlData['extracts'], true) : [];

        $urlData['response_time'] = (int)($urlData['response_time'] ?? 0);
        $urlData['depth'] = (int)($urlData['depth'] ?? 0);
        $urlData['code'] = (int)($urlData['code'] ?? 0);

        $schemas = $urlData['schemas'] ?? '{}';
        if ($schemas && $schemas !== '{}') {
            $schemas = trim($schemas, '{}');
            $urlData['schemas'] = !empty($schemas)
                ? array_map(fn($s) => trim($s, '"'), explode(',', $schemas))
                : [];
        } else {
            $urlData['schemas'] = [];
        }

        // Vrai count depuis la table links (pages.outlinks peut être faux pour les non-canoniques)
        $stmtOut = $this->db->prepare("SELECT COUNT(*) FROM links WHERE crawl_id = :crawl_id AND src = :id");
        $stmtOut->execute([':crawl_id' => $crawlId, ':id' => $urlData['id']]);
        $realOutlinks = (int)$stmtOut->fetchColumn();

        $this->json([
            'success' => true,
            'url' => $urlData,
            'category' => $urlData['category'],
            'extracts' => $extracts,
            'extractions' => $customExtracts,
            'inlinks_count' => (int)($urlData['inlinks'] ?? 0),
            'outlinks_count' => $realOutlinks
        ]);
    }

    /**
     * Helper: resolve crawl + page from request params
     */
    private function resolvePageContext(Request $request): array
    {
        $projectDir = $request->get('project');
        $url = $request->get('url');
        $pageId = $request->get('id');

        if (!$projectDir || (!$url && !$pageId)) {
            $this->error('Missing parameters');
        }

        if (is_numeric($projectDir)) {
            $this->auth->requireCrawlAccessById((int)$projectDir, true);
            $crawlRecord = CrawlDatabase::getCrawlById((int)$projectDir);
        } else {
            $this->auth->requireCrawlAccess($projectDir, true);
            $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        }

        if (!$crawlRecord) {
            Response::notFound('Crawl not found');
        }

        $crawlId = $crawlRecord->id;

        if ($pageId) {
            $stmt = $this->db->prepare("SELECT id FROM pages WHERE crawl_id = :crawl_id AND id = :id");
            $stmt->execute([':crawl_id' => $crawlId, ':id' => $pageId]);
        } else {
            $stmt = $this->db->prepare("SELECT id FROM pages WHERE crawl_id = :crawl_id AND url = :url");
            $stmt->execute([':crawl_id' => $crawlId, ':url' => $url]);
        }
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$page) {
            Response::notFound('Page not found');
        }

        return ['crawlId' => $crawlId, 'pageId' => $page['id'], 'projectId' => $crawlRecord->project_id];
    }

    /**
     * Retourne les inlinks d'une URL (lazy-loaded)
     */
    public function urlInlinks(Request $request): void
    {
        $ctx = $this->resolvePageContext($request);

        // Charger les catégories (project-level)
        $categoriesMap = [];
        $categoryColors = [];
        $stmt = $this->db->prepare("SELECT id, cat, color FROM crawl_categories WHERE project_id = :project_id");
        $stmt->execute([':project_id' => $ctx['projectId']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categoriesMap[$row['id']] = $row['cat'];
            $categoryColors[$row['cat']] = $row['color'];
        }

        $stmt = $this->db->prepare("
            SELECT c.id, c.url, l.anchor, l.type, l.nofollow, c.pri, c.cat_id
            FROM links l
            JOIN pages c ON l.src = c.id AND c.crawl_id = :crawl_id
            WHERE l.crawl_id = :crawl_id2 AND l.target = :id
            ORDER BY c.pri DESC LIMIT 100
        ");
        $stmt->execute([':crawl_id' => $ctx['crawlId'], ':crawl_id2' => $ctx['crawlId'], ':id' => $ctx['pageId']]);
        $inlinks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($inlinks as &$link) {
            $catName = $categoriesMap[$link['cat_id']] ?? 'Non catégorisé';
            $link['category'] = $catName;
            $link['category_color'] = $categoryColors[$catName] ?? '#95a5a6';
        }

        $this->success(['inlinks' => $inlinks]);
    }

    /**
     * Retourne les outlinks d'une URL (lazy-loaded)
     */
    public function urlOutlinks(Request $request): void
    {
        $ctx = $this->resolvePageContext($request);

        // Charger les catégories (project-level)
        $categoriesMap = [];
        $categoryColors = [];
        $stmt = $this->db->prepare("SELECT id, cat, color FROM crawl_categories WHERE project_id = :project_id");
        $stmt->execute([':project_id' => $ctx['projectId']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categoriesMap[$row['id']] = $row['cat'];
            $categoryColors[$row['cat']] = $row['color'];
        }

        $stmt = $this->db->prepare("
            SELECT c.id, c.url, l.anchor, l.type, l.nofollow, c.external, c.cat_id
            FROM links l
            JOIN pages c ON l.target = c.id AND c.crawl_id = :crawl_id
            WHERE l.crawl_id = :crawl_id2 AND l.src = :id
        ");
        $stmt->execute([':crawl_id' => $ctx['crawlId'], ':crawl_id2' => $ctx['crawlId'], ':id' => $ctx['pageId']]);
        $outlinks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($outlinks as &$link) {
            $catName = $categoriesMap[$link['cat_id']] ?? 'Non catégorisé';
            $link['category'] = $catName;
            $link['category_color'] = $categoryColors[$catName] ?? '#95a5a6';
        }

        $this->success(['outlinks' => $outlinks]);
    }

    /**
     * Recherche rapide d'URLs par pattern
     * 
     * Recherche dans les URLs avec tri par PageRank décroissant.
     * 
     * @param Request $request Requête HTTP (project, q, limit)
     * 
     * @return void
     */
    public function quickSearch(Request $request): void
    {
        $projectDir = $request->get('project');
        $search = $request->get('q', '');
        $limit = (int)$request->get('limit', 10);
        
        if (empty($projectDir)) {
            $this->error('Projet non spécifié');
        }
        
        $this->auth->requireCrawlAccess($projectDir, true);
        
        $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        if (!$crawlRecord) {
            Response::notFound('Projet non trouvé');
        }
        
        $crawlId = $crawlRecord->id;
        
        $stmt = $this->db->prepare("
            SELECT url, title, code FROM pages 
            WHERE crawl_id = :crawl_id AND url LIKE :search
            ORDER BY pri DESC LIMIT :limit
        ");
        $stmt->execute([
            ':crawl_id' => $crawlId,
            ':search' => '%' . $search . '%',
            ':limit' => $limit
        ]);
        
        $this->success(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * Retourne le code source HTML d'une page
     * 
     * Décode et décompresse le HTML stocké en base.
     * 
     * @param Request $request Requête HTTP (project, url)
     * 
     * @return void
     */
    public function htmlSource(Request $request): void
    {
        $ctx = $this->resolvePageContext($request);

        // Get HTML
        $stmt = $this->db->prepare("SELECT html FROM html WHERE crawl_id = :crawl_id AND id = :id");
        $stmt->execute([':crawl_id' => $ctx['crawlId'], ':id' => $ctx['pageId']]);
        $htmlRow = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $htmlContent = null;
        $headings = [];
        if ($htmlRow && $htmlRow['html']) {
            $decoded = base64_decode($htmlRow['html']);
            if ($decoded !== false) {
                $decompressed = @gzinflate($decoded);
                $htmlContent = $decompressed !== false ? $decompressed : $decoded;
            }

            // Extract headings from HTML
            if (!empty($htmlContent)) {
                $dom = new \DOMDocument();
                @$dom->loadHTML('<?xml encoding="UTF-8">' . $htmlContent);
                $xpath = new \DOMXPath($dom);
                $headingNodes = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
                foreach ($headingNodes as $node) {
                    $headings[] = [
                        'level' => (int)substr($node->nodeName, 1),
                        'text' => trim($node->textContent)
                    ];
                }
            }
        }

        $this->success(['html' => $htmlContent, 'headings' => $headings]);
    }
}
