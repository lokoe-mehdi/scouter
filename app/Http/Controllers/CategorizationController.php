<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
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

        // PHASE 2: Apply to current crawl (SYNC)
        $categorizedCount = $this->applyCategorization($crawlId, $yamlContent);

        // PHASE 3: Create batch job for other crawls (ASYNC)
        $crawlRepo = new \App\Database\CrawlRepository();
        $allCrawls = $crawlRepo->getByProjectId($projectId);
        $otherCrawls = array_filter($allCrawls, fn($c) => $c->id !== $crawlId);

        $jobId = null;
        if (count($otherCrawls) > 0) {
            $jobManager = new \App\Job\JobManager();
            $jobId = $jobManager->createJob(
                $projectDir,
                "Batch Categorization",
                "batch-categorize-project:{$projectId}"
            );
            $jobManager->updateJobStatus($jobId, 'queued');
            $jobManager->addLog($jobId,
                "Queued categorization for " . count($otherCrawls) . " crawl(s)",
                'info'
            );
        }

        $this->success([
            'categorized_count' => $categorizedCount,
            'batch_job_created' => count($otherCrawls) > 0,
            'job_id' => $jobId,
            'total_crawls' => count($allCrawls),
            'other_crawls' => count($otherCrawls)
        ], 'Catégorisation appliquée avec succès');
    }

    /**
     * Apply categorization to a single crawl
     *
     * Extracted from save() for reuse in batch processing.
     *
     * @param int $crawlId Crawl ID
     * @param string $yamlContent YAML configuration
     * @return int Number of categorized pages
     */
    private function applyCategorization(int $crawlId, string $yamlContent): int
    {
        $categories = \Spyc::YAMLLoadString($yamlContent);

        // Save to crawl-level config (backward compatibility)
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

        $this->db->beginTransaction();

        try {
            // Reset existing categorization
            $stmt = $this->db->prepare("UPDATE pages SET cat_id = NULL WHERE crawl_id = :crawl_id");
            $stmt->execute([':crawl_id' => $crawlId]);

            $stmt = $this->db->prepare("DELETE FROM categories WHERE crawl_id = :crawl_id");
            $stmt->execute([':crawl_id' => $crawlId]);

            $categorizedCount = 0;

            // Apply each category rule
            foreach ($categories as $catName => $rules) {
                if (!is_array($rules) || !isset($rules['dom']) || !isset($rules['include'])) {
                    continue;
                }

                $color = trim($rules['color'] ?? '#aaaaaa', '"\'');

                // Create category
                $stmt = $this->db->prepare("
                    INSERT INTO categories (crawl_id, cat, color)
                    VALUES (:crawl_id, :cat, :color)
                    RETURNING id
                ");
                $stmt->execute([':crawl_id' => $crawlId, ':cat' => $catName, ':color' => $color]);
                $catId = $stmt->fetch(PDO::FETCH_OBJ)->id;

                // Extract rules
                $domain = $rules['dom'];
                $includes = is_array($rules['include']) ? $rules['include'] : [$rules['include']];
                $excludes = isset($rules['exclude']) ?
                    (is_array($rules['exclude']) ? $rules['exclude'] : [$rules['exclude']]) : [];

                // Get uncategorized URLs
                $stmt = $this->db->prepare("
                    SELECT id, url
                    FROM pages
                    WHERE crawl_id = :crawl_id
                      AND cat_id IS NULL
                      AND crawled = true
                ");
                $stmt->execute([':crawl_id' => $crawlId]);
                $urls = $stmt->fetchAll(PDO::FETCH_OBJ);

                // Match and categorize
                foreach ($urls as $urlRow) {
                    $url = $urlRow->url;

                    // Check domain
                    if (!preg_match('#' . preg_quote($domain, '#') . '#i', $url)) {
                        continue;
                    }

                    // Normalize URL path
                    $domainPattern = '(.*\.)?' . preg_quote($domain, '#');
                    $urlPath = preg_replace('#^https?://' . $domainPattern . '#i', '', $url);

                    // Check includes
                    $includeMatch = false;
                    foreach ($includes as $include) {
                        if (preg_match('#' . $include . '#i', $urlPath)) {
                            $includeMatch = true;
                            break;
                        }
                    }
                    if (!$includeMatch) continue;

                    // Check excludes
                    $excludeMatch = false;
                    foreach ($excludes as $exclude) {
                        if (preg_match('#' . $exclude . '#i', $urlPath)) {
                            $excludeMatch = true;
                            break;
                        }
                    }
                    if ($excludeMatch) continue;

                    // Assign category
                    $updateStmt = $this->db->prepare("
                        UPDATE pages
                        SET cat_id = :cat_id
                        WHERE crawl_id = :crawl_id AND id = :id
                    ");
                    $updateStmt->execute([
                        ':cat_id' => $catId,
                        ':crawl_id' => $crawlId,
                        ':id' => $urlRow->id
                    ]);
                    $categorizedCount++;
                }
            }

            $this->db->commit();
            return $categorizedCount;

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
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
        
        // Récupérer toutes les URLs
        $stmt = $this->db->prepare("SELECT url, depth, code FROM pages WHERE crawl_id = :crawl_id AND crawled = true ORDER BY url");
        $stmt->execute([':crawl_id' => $crawlId]);
        $allUrls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Limiter à 500 pour l'affichage
        $urls = array_slice($allUrls, 0, 500);
        
        // Simuler la catégorisation sur TOUTES les URLs pour les stats
        $categoryStats = [];
        $urlCategories = [];
        
        foreach ($allUrls as $url) {
            $urlCategories[$url['url']] = null;
        }
        
        // Parcourir les catégories dans l'ordre
        foreach ($categories as $catName => $rules) {
            if (!is_array($rules) || !isset($rules['dom']) || !isset($rules['include'])) {
                continue;
            }
            
            $domain = $rules['dom'];
            $includes = is_array($rules['include']) ? $rules['include'] : [$rules['include']];
            $excludes = isset($rules['exclude']) ? (is_array($rules['exclude']) ? $rules['exclude'] : [$rules['exclude']]) : [];
            
            foreach ($allUrls as $url) {
                // Si déjà catégorisé, passer
                if ($urlCategories[$url['url']] !== null) {
                    continue;
                }
                
                // Vérifier le domaine
                if (!preg_match('#' . preg_quote($domain, '#') . '#i', $url['url'])) {
                    continue;
                }
                
                // Normaliser l'URL
                $domainPattern = '(.*\.)?' . preg_quote($domain, '#');
                $urlPath = preg_replace('#^https?://' . $domainPattern . '#i', '', $url['url']);
                
                // Vérifier les includes
                $includeMatch = false;
                foreach ($includes as $include) {
                    if (preg_match('#' . $include . '#i', $urlPath)) {
                        $includeMatch = true;
                        break;
                    }
                }
                
                if (!$includeMatch) continue;
                
                // Vérifier les excludes
                $excludeMatch = false;
                foreach ($excludes as $exclude) {
                    if (preg_match('#' . $exclude . '#i', $urlPath)) {
                        $excludeMatch = true;
                        break;
                    }
                }
                
                if ($excludeMatch) continue;
                
                // URL catégorisée
                $urlCategories[$url['url']] = $catName;
                if (!isset($categoryStats[$catName])) {
                    $categoryStats[$catName] = 0;
                }
                $categoryStats[$catName]++;
            }
        }
        
        // Compter les non catégorisées
        $nonCategorized = 0;
        foreach ($urlCategories as $cat) {
            if ($cat === null) {
                $nonCategorized++;
            }
        }
        if ($nonCategorized > 0) {
            $categoryStats['Non catégorisé'] = $nonCategorized;
        }
        
        // Appliquer la catégorisation sur les 500 URLs pour l'affichage
        $categorizedUrls = [];
        foreach ($urls as $url) {
            $url['category'] = isset($urlCategories[$url['url']]) && $urlCategories[$url['url']] !== null
                ? $urlCategories[$url['url']]
                : 'Non catégorisé';
            $categorizedUrls[] = $url;
        }
        
        // Formater les stats pour le graphique
        $stats = [];
        foreach ($categoryStats as $cat => $count) {
            $stats[] = [
                'category' => $cat,
                'count' => $count
            ];
        }
        
        // Trier par count décroissant
        usort($stats, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        // Préparer la liste des catégories
        $categoryList = [];
        foreach ($stats as $stat) {
            if ($stat['category'] !== 'Non catégorisé') {
                $categoryList[] = [
                    'name' => $stat['category'],
                    'count' => $stat['count']
                ];
            }
        }
        
        $this->success([
            'categories_count' => count($categories),
            'urls' => $categorizedUrls,
            'stats' => $stats,
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
                COALESCE(c.cat, 'Non catégorisé') as category,
                COALESCE(c.color, '#95a5a6') as color,
                COUNT(p.id) as count
            FROM pages p
            LEFT JOIN categories c ON p.cat_id = c.id AND c.crawl_id = :crawl_id
            WHERE p.crawl_id = :crawl_id2 AND p.crawled = true
            GROUP BY c.cat, c.color
            ORDER BY count DESC
        ");
        $stmt->execute([':crawl_id' => $crawlId, ':crawl_id2' => $crawlId]);
        
        $this->success(['stats' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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
        
        // Récupérer le mapping des catégories
        $categoriesMap = [];
        $stmt = $this->db->prepare("SELECT id, cat, color FROM categories WHERE crawl_id = :crawl_id");
        $stmt->execute([':crawl_id' => $crawlId]);
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
            'orderBy' => 'ORDER BY c.cat_id, c.url',
            'sqlParams' => $catParams,
            'defaultColumns' => ['url', 'code', 'category'],
            'perPage' => 50,
            'pdo' => $pdo,
            'crawlId' => $crawlId,
            'projectDir' => $projectDir,
            'light' => true,
            'copyUrl' => true,
            'hideTitle' => true,
            'embedMode' => true
        ];
        
        include __DIR__ . '/../../../web/components/url-table.php';
        exit;
    }
}
