<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use App\Database\CrawlStore;
use App\AI\ClickHouseSqlExecutor;
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

        // ClickHouse-backed crawl → delegate to the CH executor (crawl_id-forced
        // subqueries, live `category`, CH dialect). PG path below otherwise.
        if (CrawlStore::usesClickHouse((int)$crawlId)) {
            // rowLimit = 0 → open bar (pas de plafond de lignes) : la colonne lourde
            // `html` est bloquée dans l'explorer, donc export CSV complet possible.
            $res = (new ClickHouseSqlExecutor())->execute($query, (int)$crawlId, 0);
            if (!$res['ok']) {
                Response::forbidden($res['error'] ?? 'Query failed');
                return;
            }
            $rows = array_map(function ($row) {
                unset($row['crawl_id']);
                return $row;
            }, $res['rows']);
            $columns = array_values(array_filter($res['columns'] ?? [], fn($c) => $c !== 'crawl_id'));
            $this->json([
                'type'    => 'select',
                'columns' => $columns,
                'rows'    => $rows,
                'count'   => count($rows),
            ]);
            return;
        }

        // SÉCURITÉ : Nettoyage et validation stricte
        $queryClean = preg_replace('/\/\*.*?\*\//s', ' ', $query); // strip block comments
        $queryClean = preg_replace('/--.*$/m', ' ', $queryClean);  // strip line comments
        $queryUpper = strtoupper(trim($queryClean));

        // Accept SELECT or WITH (CTE) at the start. WITH RECURSIVE stays
        // blocked further down. Any write op inside a CTE (e.g. `WITH x AS
        // (INSERT ...) ...`) is caught by the FORBIDDEN_KEYWORDS scan + the
        // READ ONLY transaction.
        if (strpos($queryUpper, 'SELECT') !== 0 && strpos($queryUpper, 'WITH') !== 0) {
            Response::forbidden('Seules les requêtes SELECT ou WITH … SELECT sont autorisées.');
        }

        // Block multi-statement attacks
        if (preg_match('/;\s*(INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|GRANT|REVOKE|COPY|SET|DO|CALL)/i', $queryClean)) {
            Response::forbidden('Requête multi-statement interdite.');
        }

        $forbiddenKeywords = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
            'TRUNCATE', 'REPLACE', 'RENAME', 'ATTACH', 'DETACH',
            'VACUUM', 'REINDEX', 'GRANT', 'REVOKE', 'COPY'
        ];
        foreach ($forbiddenKeywords as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $queryClean)) {
                Response::forbidden('Requête interdite : opération de modification détectée (' . $keyword . ').');
            }
        }

        // Block dangerous PostgreSQL functions
        $forbiddenFunctions = [
            'pg_sleep', 'pg_read_file', 'pg_read_binary_file', 'pg_ls_dir',
            'pg_stat_file', 'lo_import', 'lo_export', 'dblink',
            'pg_advisory_lock', 'pg_terminate_backend', 'pg_cancel_backend',
            'set_config', 'current_setting'
        ];
        foreach ($forbiddenFunctions as $fn) {
            if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $queryClean)) {
                Response::forbidden('Fonction interdite : ' . $fn);
            }
        }

        // Block PREPARE/EXECUTE, WITH RECURSIVE, EXPLAIN, COPY TO PROGRAM
        if (preg_match('/\b(PREPARE|EXECUTE|EXPLAIN|WITH\s+RECURSIVE)\b/i', $queryClean)) {
            Response::forbidden('Instruction interdite.');
        }
        if (preg_match('/COPY\s+.*\s+TO\s+PROGRAM/i', $queryClean)) {
            Response::forbidden('COPY TO PROGRAM interdit.');
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
            $queryClean
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
        
        // === SÉCURITÉ : whitelist stricte des tables accessibles ===
        //
        // On n'autorise QUE les tables de données crawlées et leurs partitions.
        // Toutes les autres tables (users, crawls, jobs, projects, project_shares,
        // crawl_schedules, user_saved_queries, schémas pg_*/information_schema...)
        // sont inaccessibles — elles contiennent soit des secrets (HTTP Basic Auth
        // dans crawls.config, hash de mot de passe dans users), soit des données
        // cross-tenant qui ne doivent pas fuiter via le SQL Explorer.
        //
        // Le check se fait APRÈS les transformations (pages → pages_<id>, etc.),
        // donc à ce stade on voit les vrais noms physiques qui partiront vers PG.
        $allowedTables = [
            'crawl_categories',
            'pages', 'links',
            'duplicate_clusters', 'page_schemas', 'redirect_chains',
        ];
        // Extract CTE names so the whitelist doesn't flag them. A query like
        // `WITH ranked AS (SELECT ...) SELECT * FROM ranked` would otherwise
        // fail on `ranked` because it appears after FROM. We collect all CTE
        // identifiers and treat them as valid local table names for this
        // query only. The `AS\s*\(` (parenthesis required) avoids matching
        // column aliases like `AS rk`.
        $cteNames = [];
        if (preg_match_all(
            '/(?:\bWITH\s+|,\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s+AS\s*\(/i',
            $transformedQuery,
            $cteMatches
        )) {
            foreach ($cteMatches[1] as $name) {
                $cteNames[] = strtolower($name);
            }
        }

        // Extrait tous les noms de tables après FROM / JOIN, en gérant "schema.table",
        // les quoted identifiers, et en isolant le dernier segment (= nom de table).
        preg_match_all(
            '/\b(?:FROM|JOIN)\s+(?:"?[a-zA-Z_][a-zA-Z0-9_]*"?\s*\.\s*)?"?([a-zA-Z_][a-zA-Z0-9_]*)"?/i',
            $transformedQuery,
            $tableMatches
        );
        foreach (array_unique($tableMatches[1] ?? []) as $tableName) {
            $tableLower = strtolower($tableName);
            $isAllowed = in_array($tableLower, $allowedTables, true)
                || in_array($tableLower, $cteNames, true)
                || preg_match('/^(pages|links|duplicate_clusters|page_schemas|redirect_chains)_\d+$/i', $tableLower);
            if (!$isAllowed) {
                Response::forbidden(
                    "Table « {$tableName} » non autorisée depuis le SQL Explorer. " .
                    "Tables accessibles : " . implode(', ', $allowedTables) . '.'
                );
            }
        }

        // === SÉCURITÉ : project-scope sur crawl_categories ===
        //
        // crawl_categories est une table partagée entre tous les projets (pas
        // de partitionnement par crawl_id, contrairement à pages/links/...).
        // Sans filtre supplémentaire, un `SELECT * FROM crawl_categories`
        // dans le SQL Explorer renvoie les catégories de TOUS les projets de
        // l'instance, ce qui est une fuite cross-tenant.
        //
        // Solution : on préfixe la requête avec une CTE du même nom qui
        // pré-filtre sur le project_id courant. PostgreSQL résout alors
        // toute référence à `crawl_categories` (FROM, JOIN, subquery) vers
        // la CTE — l'utilisateur ne peut techniquement plus voir les rows
        // des autres projets, peu importe la forme de sa requête.
        //
        // Coût : nul en pratique (PG inline la CTE en query plan ; si la
        // table n'est pas référencée, c'est juste une CTE inutilisée).
        // Garde : la requête ne doit pas définir sa propre CTE nommée
        // `crawl_categories`. On en injecte une avec ce nom exact ci-dessous
        // pour scoper la table partagée par projet ; une CTE utilisateur du
        // même nom (a) entre en collision → erreur "WITH query name specified
        // more than once", et (b) contournerait le filtre projet. On refuse
        // avec un message clair.
        if (in_array('crawl_categories', $cteNames, true)) {
            Response::forbidden(
                '« crawl_categories » est réservé et déjà filtré par projet — ' .
                'référencez-la directement (ex. JOIN crawl_categories) sans ' .
                'définir de CTE portant ce nom.'
            );
            return;
        }

        $pid = (int)$crawlRecord->project_id;
        if ($pid > 0) {
            $catScope = "crawl_categories AS (SELECT * FROM crawl_categories WHERE project_id = {$pid})";
            if (preg_match('/^\s*WITH\s+/i', $transformedQuery)) {
                // Fusion avec la CTE existante de l'utilisateur.
                $transformedQuery = preg_replace(
                    '/^\s*WITH\s+/i', "WITH {$catScope}, ", $transformedQuery, 1
                );
            } else {
                $transformedQuery = "WITH {$catScope} " . ltrim($transformedQuery);
            }
        }

        // Force a LIMIT if none present (max 10000 rows)
        if (!preg_match('/\bLIMIT\s+\d/i', $transformedQuery)) {
            $transformedQuery .= ' LIMIT 10000';
        }

        // Security: read-only transaction + timeout
        $this->db->exec("SET statement_timeout = '10s'");
        $this->db->exec("SET TRANSACTION READ ONLY");
        $stmt = $this->db->query($transformedQuery);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->db->exec("SET statement_timeout = '0'");
        
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

        // On ClickHouse crawls the PG partitions are purged → read through the same
        // shim the reports use (ChPdo), which exposes the LIVE `category` (name) and
        // a synthetic cat_id. The legacy PG path (raw pages with stored cat_id) is
        // kept for crawls not yet migrated. Category colours are project metadata
        // (crawl_categories), always in PG, keyed by category NAME for both.
        $useCh = CrawlStore::usesClickHouse((int)$crawlId);
        $dataDb = $useCh ? new \App\Database\ChPdo((int)$crawlId) : $this->db;

        $categoriesMap = [];   // PG cat_id → name (legacy path only)
        $categoryColors = [];  // name → color (both paths)
        $stmt = $this->db->prepare("SELECT id, cat, color FROM crawl_categories WHERE project_id = :project_id");
        $stmt->execute([':project_id' => $crawlRecord->project_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categoriesMap[$row['id']] = $row['cat'];
            $categoryColors[$row['cat']] = $row['color'];
        }

        // `category` (live name) on CH ; `cat_id` (stored) on legacy PG.
        $catCol = $useCh ? 'category' : 'cat_id';
        $stmt = $dataDb->prepare("
            SELECT id, url, domain, depth, code, crawled, content_type, inlinks, outlinks, date,
                   nofollow, compliant, noindex, canonical, canonical_value, redirect_to,
                   response_time, blocked, external, title, h1, metadesc, extracts, {$catCol},
                   h1_multiple, headings_missing, schemas, word_count
            FROM pages WHERE crawl_id = :crawl_id AND url = :url
        ");
        $stmt->execute([':crawl_id' => $crawlId, ':url' => $url]);
        $urlData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$urlData) {
            Response::notFound('URL not found');
        }

        $catName = $useCh
            ? (($urlData['category'] ?? '') !== '' ? $urlData['category'] : 'Non catégorisé')
            : ($categoriesMap[$urlData['cat_id']] ?? 'Non catégorisé');
        $urlData['category'] = $catName;
        $urlData['category_color'] = $categoryColors[$catName] ?? '#95a5a6';

        $extracts = [
            'title' => $urlData['title'] ?? '',
            'h1' => $urlData['h1'] ?? '',
            'metadesc' => $urlData['metadesc'] ?? ''
        ];

        // extracts: CH (Map) is already decoded to an array by the shim; legacy PG
        // returns a JSONB string.
        $rawExtracts = $urlData['extracts'] ?? null;
        $customExtracts = is_array($rawExtracts) ? $rawExtracts : ($rawExtracts ? json_decode($rawExtracts, true) : []);

        $urlData['response_time'] = (int)($urlData['response_time'] ?? 0);
        $urlData['depth'] = (int)($urlData['depth'] ?? 0);
        $urlData['code'] = (int)($urlData['code'] ?? 0);

        // schemas: both paths render the PG array literal '{A,B}' (the shim mirrors it).
        $schemas = $urlData['schemas'] ?? '{}';
        if (is_array($schemas)) {
            $urlData['schemas'] = $schemas;
        } elseif ($schemas && $schemas !== '{}') {
            $schemas = trim($schemas, '{}');
            $urlData['schemas'] = !empty($schemas)
                ? array_map(fn($s) => trim($s, '"'), explode(',', $schemas))
                : [];
        } else {
            $urlData['schemas'] = [];
        }

        // Vrai count depuis la table links (pages.outlinks peut être faux pour les non-canoniques)
        $stmtOut = $dataDb->prepare("SELECT COUNT(*) FROM links WHERE crawl_id = :crawl_id AND src = :id");
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
     * The PDO-compatible handle for a crawl's DATA (pages/links/html): the ChPdo
     * shim for migrated crawls (PG purged → read ClickHouse, live `category`), or
     * the raw PG connection for crawls not yet migrated. Metadata (crawl_categories
     * colours, etc.) always stays on the raw PG `$this->db`.
     */
    private function reportDb(int $crawlId)
    {
        return CrawlStore::usesClickHouse($crawlId)
            ? new \App\Database\ChPdo($crawlId)
            : $this->db;
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
        $useCh = CrawlStore::usesClickHouse((int)$crawlId);
        $db = $useCh ? new \App\Database\ChPdo((int)$crawlId) : $this->db;

        if ($pageId) {
            $stmt = $db->prepare("SELECT id FROM pages WHERE crawl_id = :crawl_id AND id = :id");
            $stmt->execute([':crawl_id' => $crawlId, ':id' => $pageId]);
        } else {
            $stmt = $db->prepare("SELECT id FROM pages WHERE crawl_id = :crawl_id AND url = :url");
            $stmt->execute([':crawl_id' => $crawlId, ':url' => $url]);
        }
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$page) {
            Response::notFound('Page not found');
        }

        return ['crawlId' => $crawlId, 'pageId' => $page['id'], 'projectId' => $crawlRecord->project_id, 'useCh' => $useCh];
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

        $catSel = $ctx['useCh'] ? 'c.category' : 'c.cat_id';
        $stmt = $this->reportDb($ctx['crawlId'])->prepare("
            SELECT c.id, c.url, l.anchor, l.type, l.nofollow, c.pri, {$catSel}
            FROM links l
            JOIN pages c ON l.src = c.id AND c.crawl_id = :crawl_id AND c.in_crawl = TRUE
            WHERE l.crawl_id = :crawl_id2 AND l.target = :id
            ORDER BY c.pri DESC LIMIT 100
        ");
        $stmt->execute([':crawl_id' => $ctx['crawlId'], ':crawl_id2' => $ctx['crawlId'], ':id' => $ctx['pageId']]);
        $inlinks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($inlinks as &$link) {
            $catName = $ctx['useCh']
                ? (($link['category'] ?? '') !== '' ? $link['category'] : 'Non catégorisé')
                : ($categoriesMap[$link['cat_id']] ?? 'Non catégorisé');
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

        $catSel = $ctx['useCh'] ? 'c.category' : 'c.cat_id';
        $stmt = $this->reportDb($ctx['crawlId'])->prepare("
            SELECT c.id, c.url, l.anchor, l.type, l.nofollow, c.external AS external, {$catSel}
            FROM links l
            JOIN pages c ON l.target = c.id AND c.crawl_id = :crawl_id AND c.in_crawl = TRUE
            WHERE l.crawl_id = :crawl_id2 AND l.src = :id
        ");
        $stmt->execute([':crawl_id' => $ctx['crawlId'], ':crawl_id2' => $ctx['crawlId'], ':id' => $ctx['pageId']]);
        $outlinks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($outlinks as &$link) {
            $catName = $ctx['useCh']
                ? (($link['category'] ?? '') !== '' ? $link['category'] : 'Non catégorisé')
                : ($categoriesMap[$link['cat_id']] ?? 'Non catégorisé');
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

        $stmt = $this->reportDb((int)$crawlId)->prepare("
            SELECT url, title, code FROM pages
            WHERE crawl_id = :crawl_id AND url LIKE :search AND in_crawl = TRUE
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

        // Get HTML. ClickHouse stores RAW html (ZSTD column codec, decoded by the
        // shim); legacy PG stores it base64 + gzdeflate and needs decoding.
        $stmt = $this->reportDb($ctx['crawlId'])->prepare("SELECT html FROM html WHERE crawl_id = :crawl_id AND id = :id");
        $stmt->execute([':crawl_id' => $ctx['crawlId'], ':id' => $ctx['pageId']]);
        $htmlRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $htmlContent = null;
        $headings = [];
        if ($htmlRow && $htmlRow['html']) {
            if (!empty($ctx['useCh'])) {
                $htmlContent = $htmlRow['html']; // already raw HTML
            } else {
                $decoded = base64_decode($htmlRow['html']);
                if ($decoded !== false) {
                    $decompressed = @gzinflate($decoded);
                    $htmlContent = $decompressed !== false ? $decompressed : $decoded;
                }
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
