<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use App\Analysis\CategorizationService;
use PDO;

/**
 * Controller pour la catégorisation des pages
 * 
 * Gère la configuration YAML, les tests et les statistiques de catégorisation.
 * 
 * @package    Scouter
 * @subpackage Http\Controllers
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class CategorizationController extends Controller
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
     * Sauvegarde et applique la configuration de catégorisation YAML
     *
     * Nouvelle version : sauvegarde au niveau projet, applique au crawl actif,
     * et crée un job asynchrone pour les autres crawls.
     *
     * @param Request $request Requête HTTP (project, yaml)
     *
     * @return void
     */
    public function save(Request $request): void
    {
        $projectDir = $request->get('project');
        $yamlContent = $request->get('yaml');

        $this->auth->requireCrawlManagement($projectDir, true);

        if (empty($projectDir) || empty($yamlContent)) {
            $this->error('Paramètres manquants');
        }

        // Validate YAML
        $categories = \Spyc::YAMLLoadString($yamlContent);
        if (!is_array($categories)) {
            $this->error('Format YAML invalide');
        }

        // Get crawl + project
        $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        if (!$crawlRecord) {
            $this->error('Crawl non trouvé');
        }

        $crawlId = $crawlRecord->id;
        $projectId = $crawlRecord->project_id;

        if (!$projectId) {
            $this->error('Crawl non associé à un projet');
        }

        // PHASE 1: Save to project level
        $projectRepo = new \App\Database\ProjectRepository();
        $projectRepo->setCategorizationConfig($projectId, $yamlContent);

        // PHASE 2: Save to crawl-level config (fast, no heavy processing)
        $stmt = $this->db->prepare("
            INSERT INTO categorization_config (crawl_id, config)
            VALUES (:crawl_id, :config)
            ON CONFLICT (crawl_id) DO UPDATE SET config = :config2
        ");
        $stmt->execute([
            ':crawl_id' => $crawlId,
            ':config' => $yamlContent,
            ':config2' => $yamlContent
        ]);

        // PHASE 3: Create async job for ALL crawls (including current)
        // Never run heavy categorization in the HTTP request
        $crawlRepo = new \App\Database\CrawlRepository();
        $allCrawls = $crawlRepo->getByProjectId($projectId);

        $jobManager = new \App\Job\JobManager();
        $jobId = $jobManager->createJob(
            $projectDir,
            "Batch Categorization",
            "batch-categorize-project:{$projectId}"
        );
        $jobManager->updateJobStatus($jobId, 'queued');
        $jobManager->addLog($jobId,
            "Queued categorization for " . count($allCrawls) . " crawl(s)",
            'info'
        );

        $this->success([
            'categorized_count' => 0,
            'batch_job_created' => true,
            'job_id' => $jobId,
            'total_crawls' => count($allCrawls),
            'async' => true
        ], 'Configuration sauvegardée, catégorisation en cours...');
    }

    /**
     * Teste une configuration YAML sans l'appliquer
     * 
     * Retourne un échantillon d'URLs pour chaque catégorie.
     * 
     * @param Request $request Requête HTTP (project, yaml)
     * 
     * @return void
     */
    public function test(Request $request): void
    {
        $projectDir = $request->get('project');
        $yamlContent = $request->get('yaml');

        $this->auth->requireCrawlManagement($projectDir, true);

        if (empty($projectDir) || empty($yamlContent)) {
            $this->error('Paramètres manquants');
        }

        $categories = \Spyc::YAMLLoadString($yamlContent);

        if (!is_array($categories)) {
            $this->error('Format YAML invalide');
        }

        $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        if (!$crawlRecord) {
            $this->error('Crawl non trouvé');
        }

        $crawlId = $crawlRecord->id;

        $service = new CategorizationService($this->db);

        try {
            $rules = $service->parseRules($categories);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        }

        $result = $service->testCategorization($crawlId, $rules);

        // Remplacer NULL par le label "Non catégorisé" dans les URLs
        $uncategorizedLabel = 'Non catégorisé';
        foreach ($result['urls'] as &$url) {
            if ($url['category'] === null) {
                $url['category'] = $uncategorizedLabel;
            }
        }
        unset($url);

        // Remplacer NULL par le label dans les stats
        foreach ($result['stats'] as &$stat) {
            if ($stat['category'] === null) {
                $stat['category'] = $uncategorizedLabel;
            }
        }
        unset($stat);

        // Préparer la liste des catégories (sans "Non catégorisé")
        $categoryList = [];
        foreach ($result['stats'] as $stat) {
            if ($stat['category'] !== $uncategorizedLabel) {
                $categoryList[] = [
                    'name' => $stat['category'],
                    'count' => (int) $stat['count']
                ];
            }
        }

        $this->success([
            'categories_count' => count($categories),
            'urls' => $result['urls'],
            'stats' => $result['stats'],
            'category_list' => $categoryList
        ]);
    }

    /**
     * Retourne les statistiques de catégorisation
     * 
     * Nombre d'URLs par catégorie avec couleurs.
     * 
     * @param Request $request Requête HTTP (project)
     * 
     * @return void
     */
    public function stats(Request $request): void
    {
        $projectDir = $request->get('project');
        
        $this->auth->requireCrawlAccess($projectDir, true);
        
        $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        if (!$crawlRecord) {
            $this->error('Crawl non trouvé');
        }
        
        $crawlId = $crawlRecord->id;
        
        $stmt = $this->db->prepare("
            SELECT
                c.cat as category,
                COALESCE(c.color, '#95a5a6') as color,
                COUNT(p.id) as count
            FROM pages p
            LEFT JOIN crawl_categories c ON p.cat_id = c.id
            WHERE p.crawl_id = :crawl_id2 AND p.crawled = true
            GROUP BY c.cat, c.color
            ORDER BY count DESC
        ");
        $stmt->execute([':crawl_id2' => $crawlId]);
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Replace NULL category with translated label (instead of hardcoded French)
        foreach ($stats as &$s) {
            if ($s['category'] === null) {
                $s['category'] = __('categorize.uncategorized');
            }
        }

        $this->success(['stats' => $stats]);
    }

    /**
     * Retourne les URLs d'une catégorie avec pagination
     * 
     * @param Request $request Requête HTTP (project, category, limit, offset)
     * 
     * @return void
     */
    public function table(Request $request): void
    {
        $projectDir = $request->get('project');
        $crawlId = $request->get('crawl_id');
        $filterCat = $request->get('filter_cat', '');
        
        if (empty($projectDir) || empty($crawlId)) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<div class="status-message status-error">Paramètres manquants</div>';
            exit;
        }
        
        $this->auth->requireCrawlAccess($projectDir, false);

        // Récupérer le project_id depuis le crawl
        $crawlRecord = CrawlDatabase::getCrawlById((int)$crawlId);

        // Récupérer le mapping des catégories (project-level)
        $categoriesMap = [];
        $stmt = $this->db->prepare("SELECT id, cat, color FROM crawl_categories WHERE project_id = :project_id");
        $stmt->execute([':project_id' => $crawlRecord->project_id]);
        $cats = $stmt->fetchAll(PDO::FETCH_OBJ);
        foreach ($cats as $cat) {
            $categoriesMap[$cat->id] = ['cat' => $cat->cat, 'color' => $cat->color];
        }
        $GLOBALS['categoriesMap'] = $categoriesMap;
        
        // Construire le mapping des couleurs
        $categoryColors = [];
        foreach ($categoriesMap as $id => $info) {
            $categoryColors[$info['cat']] = $info['color'] ?? '#aaaaaa';
        }
        $GLOBALS['categoryColors'] = $categoryColors;
        
        // Construire le WHERE avec le filtre de catégorie
        $catWhereConditions = ["c.crawled = true"];
        $catParams = [];
        
        if (!empty($filterCat)) {
            if ($filterCat === 'none') {
                $catWhereConditions[] = "c.cat_id IS NULL";
            } else {
                $filterCatId = null;
                foreach ($categoriesMap as $id => $info) {
                    if ($info['cat'] === $filterCat) {
                        $filterCatId = $id;
                        break;
                    }
                }
                if ($filterCatId !== null) {
                    $catWhereConditions[] = "c.cat_id = :filter_cat_id";
                    $catParams[':filter_cat_id'] = $filterCatId;
                }
            }
        }
        
        $catWhereClause = implode(' AND ', $catWhereConditions);
        
        // Renvoyer du HTML comme l'ancien API
        header('Content-Type: text/html; charset=utf-8');
        
        $pdo = $this->db;
        $urlTableConfig = [
            'title' => '',
            'id' => 'categorize_table',
            'whereClause' => 'WHERE ' . $catWhereClause,
            'orderBy' => 'ORDER BY c.url',
            'sqlParams' => $catParams,
            'defaultColumns' => ['url', 'code', 'category'],
            'perPage' => 50,
            'pdo' => $pdo,
            'crawlId' => $crawlId,
            'projectDir' => $projectDir,
            'light' => true,
            'copyUrl' => true,
            'hideTitle' => true,
            'embedMode' => true,
            'skipExtractDiscovery' => true
        ];
        
        include __DIR__ . '/../../../web/components/url-table.php';
        exit;
    }
}
