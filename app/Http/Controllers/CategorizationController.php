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

        // PHASE 2: Save the per-crawl config snapshot.
        //
        // Each crawl FREEZES the project config into its own categorization_config
        // row at creation (see Cmder/scheduler), and the live `category` reads that
        // per-crawl snapshot first (CategoryExpr::loadYaml). So saving only the
        // current crawl would leave every OTHER crawl on its stale snapshot — the
        // user edits the project's segments but sees no change elsewhere.
        //
        // Categorization is LIVE (no stored cat_id to rebuild), so making it
        // project-wide is just a cheap UPSERT of the new YAML into every crawl's
        // snapshot → instant everywhere, no re-processing. Do this for the whole
        // project here (covers the current crawl too).
        $crawlRepo = new \App\Database\CrawlRepository();
        $allCrawls = $crawlRepo->getByProjectId($projectId);
        $projectCrawlIds = array_map(fn($c) => (int)$c->id, $allCrawls);
        if (!in_array((int)$crawlId, $projectCrawlIds, true)) {
            $projectCrawlIds[] = (int)$crawlId;
        }

        $upsert = $this->db->prepare("
            INSERT INTO categorization_config (crawl_id, config)
            VALUES (:crawl_id, :config)
            ON CONFLICT (crawl_id) DO UPDATE SET config = :config2
        ");
        foreach ($projectCrawlIds as $cid) {
            $upsert->execute([':crawl_id' => $cid, ':config' => $yamlContent, ':config2' => $yamlContent]);
        }

        // PHASE 3: Apply SYNCHRONOUSLY to the current crawl so the user sees
        //          the new categories in the filters / dropdowns immediately
        //          on next page load. Categorization is just a few regex
        //          UPDATE statements + a cleanup pass; takes <5s on crawls
        //          up to ~100k pages. We pay the HTTP latency once on save
        //          rather than leaving a stale UI for an arbitrary worker delay.
        //
        //          The previous fully-async flow led to a confusing UX: the
        //          editor showed the new YAML right away, but the URL/Link
        //          Explorer filters kept the old categories until the worker
        //          picked the job — which could be minutes if the queue was
        //          busy. Worth the trade-off for accurate immediate feedback.
        // On ClickHouse there is NO stored cat_id: `category` is computed live at
        // query time from the YAML we just saved, so persisting the config (phases
        // 1-2) is all that's needed — the new rules show up immediately everywhere.
        // The PG apply (UPDATE pages SET cat_id) and the per-crawl batch job below
        // are skipped entirely (they'd write to purged PG and do nothing).
        $useCh = \App\Database\CrawlStore::usesClickHouse((int)$crawlId);
        $currentCategorized = 0;
        $currentError = null;
        if (!$useCh) {
            try {
                $service = new CategorizationService($this->db);
                $currentCategorized = $service->applyCategorization($crawlId, $yamlContent, $projectId);
            } catch (\Throwable $e) {
                $currentError = $e->getMessage();
                error_log('[Categorization] Sync apply on crawl ' . $crawlId . ' failed: ' . $e->getMessage());
            }
        } else {
            // Live count for the success message (informational only).
            try {
                $rules = (new CategorizationService($this->db))->parseRules($categories);
                $res = (new CategorizationService($this->db))->testCategorizationCH($crawlId, $rules);
                foreach ($res['stats'] as $s) {
                    if (($s['category'] ?? null) !== null) { $currentCategorized += (int)$s['count']; }
                }
            } catch (\Throwable $e) {
                error_log('[Categorization] CH live count on crawl ' . $crawlId . ' failed: ' . $e->getMessage());
            }
        }

        // PHASE 4: Async job for the OTHER crawls of the project (history
        //          versions the user isn't currently looking at). Skip when
        //          there are none — most projects have a single active crawl.
        //          (CH needs nothing here: PHASE 2 already propagated the live
        //          config to every crawl. This is the PG cat_id rebuild only.)
        $otherCrawls = array_filter($allCrawls, fn($c) => (int)$c->id !== (int)$crawlId);

        $jobId = null;
        if (!$useCh && !empty($otherCrawls)) {
            $jobManager = new \App\Job\JobManager();
            $jobId = $jobManager->createJob(
                $projectDir,
                "Batch Categorization",
                "batch-categorize-project:{$projectId}"
            );
            $jobManager->updateJobStatus($jobId, 'queued');
            $jobManager->addLog($jobId,
                "Queued categorization for " . count($otherCrawls) . " other crawl(s) in the project "
                . "(current crawl #{$crawlId} was already processed synchronously)",
                'info'
            );
        }

        // If the synchronous apply failed, surface it. The save itself
        // succeeded (project config is persisted), but the user must know
        // that their current view won't reflect the new rules until the
        // worker rescues it.
        if ($currentError !== null) {
            $this->success([
                'categorized_count'    => 0,
                'current_crawl_error'  => $currentError,
                'batch_job_created'    => $jobId !== null,
                'job_id'               => $jobId,
                'other_crawls'         => count($otherCrawls),
                'async'                => $jobId !== null,
            ], 'Configuration enregistrée, mais l\'application au crawl courant a échoué : '
                . $currentError
                . ($jobId !== null ? '. Le worker reprendra automatiquement.' : '.'));
            return;
        }

        $this->success([
            'categorized_count'    => $currentCategorized,
            'current_crawl_id'     => $crawlId,
            'batch_job_created'    => $jobId !== null,
            'job_id'               => $jobId,
            'other_crawls'         => count($otherCrawls),
            'async'                => $jobId !== null,
        ], $jobId !== null
            ? "Catégorisation appliquée ({$currentCategorized} pages). Les " . count($otherCrawls) . " autre(s) crawl(s) du projet sont en cours de traitement en arrière-plan."
            : "Catégorisation appliquée ({$currentCategorized} pages).");
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

        $result = \App\Database\CrawlStore::usesClickHouse((int)$crawlId)
            ? $service->testCategorizationCH($crawlId, $rules)
            : $service->testCategorization($crawlId, $rules);

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

        // Category colours (project metadata) keyed by NAME — used by both stores.
        $colors = [];
        $cstmt = $this->db->prepare("SELECT cat, color FROM crawl_categories WHERE project_id = :pid");
        $cstmt->execute([':pid' => $crawlRecord->project_id]);
        while ($row = $cstmt->fetch(PDO::FETCH_ASSOC)) {
            $colors[$row['cat']] = $row['color'];
        }

        if (\App\Database\CrawlStore::usesClickHouse((int)$crawlId)) {
            // CH: counts computed LIVE from the saved rules (no stored cat_id; PG
            // pages purged). category = '' / NULL → "uncategorised".
            $catExpr = (new \App\Analysis\CategoryExpr($this->db))->forCrawl((int)$crawlId);
            $ch = \App\Database\ClickHouseDatabase::getInstance();
            $db = $ch->getDatabase();
            $rows = $ch->select("SELECT {$catExpr} AS category, count() AS count
                FROM (SELECT url FROM {$db}.pages WHERE crawl_id = " . (int)$crawlId . " AND external = 0 LIMIT 1 BY id)
                GROUP BY category ORDER BY count DESC");
            $stats = [];
            foreach ($rows as $r) {
                $name = (($r['category'] ?? '') !== '') ? $r['category'] : __('categorize.uncategorized');
                $stats[] = [
                    'category' => $name,
                    'color'    => $colors[$r['category'] ?? ''] ?? '#95a5a6',
                    'count'    => (int)$r['count'],
                ];
            }
            $this->success(['stats' => $stats]);
            return;
        }

        $stmt = $this->db->prepare("
            SELECT
                c.cat as category,
                COALESCE(c.color, '#95a5a6') as color,
                COUNT(p.id) as count
            FROM pages p
            LEFT JOIN crawl_categories c ON p.cat_id = c.id
            WHERE p.crawl_id = :crawl_id2 AND p.external = false
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
        
        // CH crawls have no stored cat_id: filter on the LIVE `category` name
        // (exposed by the ChPdo shim). Legacy PG crawls filter on cat_id.
        $useCh = \App\Database\CrawlStore::usesClickHouse((int)$crawlId);

        // Construire le WHERE avec le filtre de catégorie
        $catWhereConditions = ["c.external = false"];
        $catParams = [];

        if (!empty($filterCat)) {
            if ($useCh) {
                if ($filterCat === 'none') {
                    $catWhereConditions[] = "(c.category IS NULL OR c.category = '')";
                } else {
                    $catWhereConditions[] = "c.category = :filter_cat_name";
                    $catParams[':filter_cat_name'] = $filterCat;
                }
            } elseif ($filterCat === 'none') {
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

        $pdo = $useCh ? new \App\Database\ChPdo((int)$crawlId) : $this->db;
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
