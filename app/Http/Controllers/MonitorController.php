<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use PDO;

/**
 * Controller pour le monitoring et la prévisualisation
 * 
 * Gère la prévisualisation HTML et les statistiques système.
 * 
 * @package    Scouter
 * @subpackage Http\Controllers
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class MonitorController extends Controller
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
     * Affiche la prévisualisation HTML d'une page crawlée
     * 
     * Décode le HTML, convertit les URLs relatives en absolues
     * et ajoute une barre d'information.
     * 
     * @param Request $request Requête HTTP (project, id, nobar)
     * 
     * @return void
     */
    public function preview(Request $request): void
    {
        $project = $request->get('project');
        $id = $request->get('id');
        $nobar = $request->has('nobar');
        
        if (empty($project) || empty($id)) {
            Response::html('Paramètres manquants', 400);
        }
        
        if (is_numeric($project)) {
            $this->auth->requireCrawlAccessById((int)$project, false);
            $crawlRecord = CrawlDatabase::getCrawlById((int)$project);
        } else {
            $this->auth->requireCrawlAccess($project, false);
            $crawlRecord = CrawlDatabase::getCrawlByPath($project);
        }
        
        if (!$crawlRecord) {
            Response::html('Projet non trouvé', 404);
        }
        
        $crawlId = $crawlRecord->id;
        
        // Récupérer l'URL et la date
        $stmt = $this->db->prepare("SELECT url, date FROM pages WHERE crawl_id = :crawl_id AND id = :id");
        $stmt->execute([':crawl_id' => $crawlId, ':id' => $id]);
        $urlData = $stmt->fetch(PDO::FETCH_OBJ);
        
        if (!$urlData) {
            Response::html('URL non trouvée', 404);
        }
        
        $pageUrl = $urlData->url;
        $crawlDate = $urlData->date;
        
        $parsedUrl = parse_url($pageUrl);
        $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        if (isset($parsedUrl['port'])) {
            $baseUrl .= ':' . $parsedUrl['port'];
        }
        
        // Récupérer le HTML
        $stmt = $this->db->prepare("SELECT html FROM html WHERE crawl_id = :crawl_id AND id = :id");
        $stmt->execute([':crawl_id' => $crawlId, ':id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        
        if (!$result) {
            Response::html('HTML non trouvé pour cette URL', 404);
        }
        
        $decoded = base64_decode($result->html);
        $html = @gzinflate($decoded) ?: $decoded;
        
        $html = $this->convertRelativeToAbsolute($html, $baseUrl, $pageUrl);
        
        if (!$nobar) {
            $dateFormatted = date('d/m/Y à H:i', strtotime($crawlDate));
            $infoBar = $this->generateInfoBar($pageUrl, $dateFormatted);
            $html = preg_replace('/<body([^>]*)>/i', '<body$1>' . $infoBar, $html);
        }
        
        Response::html($html);
    }

    /**
     * Retourne les statistiques système
     * 
     * Inclut mémoire, version PHP, crawls et jobs actifs.
     * 
     * @param Request $request Requête HTTP
     * 
     * @return void
     */
    public function systemMonitor(Request $request): void
    {
        // Compter les jobs par status
        $stmt = $this->db->query("
            SELECT status, COUNT(*) as count 
            FROM jobs 
            GROUP BY status
        ");
        $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $stats = [
            'running' => (int)($statusCounts['running'] ?? 0),
            'queued' => (int)($statusCounts['queued'] ?? 0),
            'completed' => (int)($statusCounts['completed'] ?? 0),
            'failed' => (int)($statusCounts['failed'] ?? 0),
        ];
        
        // Récupérer les jobs actifs (running + queued)
        // Tri: running en premier, puis stopping, puis queued, par ordre chronologique (plus vieux en premier)
        $stmt = $this->db->query("
            SELECT 
                id,
                project_dir,
                project_name,
                status,
                created_at,
                started_at
            FROM jobs
            WHERE status IN ('running', 'queued', 'stopping')
            ORDER BY 
                CASE status 
                    WHEN 'running' THEN 1 
                    WHEN 'stopping' THEN 2 
                    WHEN 'queued' THEN 3 
                END,
                created_at ASC
            LIMIT 20
        ");
        $activeJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Pour chaque job, récupérer le nombre d'URLs crawlées depuis la table crawls
        foreach ($activeJobs as &$job) {
            $crawlStmt = $this->db->prepare("
                SELECT crawled FROM crawls 
                WHERE path = :path 
                ORDER BY started_at DESC 
                LIMIT 1
            ");
            $crawlStmt->execute([':path' => $job['project_dir']]);
            $job['progress'] = (int)($crawlStmt->fetchColumn() ?: 0);
        }
        
        // Calculer la durée pour chaque job
        foreach ($activeJobs as &$job) {
            $startTime = $job['started_at'] ?? $job['created_at'];
            if ($startTime) {
                $start = new \DateTime($startTime);
                $now = new \DateTime();
                $diff = $now->diff($start);
                
                if ($diff->h > 0) {
                    $job['duration'] = $diff->format('%hh %imin');
                } elseif ($diff->i > 0) {
                    $job['duration'] = $diff->format('%imin %ss');
                } else {
                    $job['duration'] = $diff->format('%ss');
                }
            } else {
                $job['duration'] = '-';
            }
            $job['progress'] = (int)$job['progress'];
        }
        
        $this->success([
            'stats' => $stats,
            'active_jobs' => $activeJobs,
            'workers_occupied' => $stats['running']
        ]);
    }

    /**
     * Convertit les URLs relatives en URLs absolues dans le HTML
     * 
     * @param string $html    Contenu HTML
     * @param string $baseUrl URL de base (scheme + host)
     * @param string $pageUrl URL complète de la page
     * 
     * @return string HTML avec URLs absolues
     */
    private function convertRelativeToAbsolute(string $html, string $baseUrl, string $pageUrl): string
    {
        $attributes = ['href', 'src', 'action', 'data-src', 'poster'];
        
        foreach ($attributes as $attr) {
            $html = preg_replace_callback(
                '/' . $attr . '=["\']([^"\']+)["\']/i',
                function($matches) use ($baseUrl, $pageUrl, $attr) {
                    $url = $matches[1];
                    
                    if (preg_match('/^(https?:|\/\/|data:|javascript:|mailto:|tel:|#)/i', $url)) {
                        return $matches[0];
                    }
                    
                    if (strpos($url, '/') === 0) {
                        $absoluteUrl = $baseUrl . $url;
                    } else {
                        $basePath = dirname(parse_url($pageUrl, PHP_URL_PATH));
                        $absoluteUrl = $baseUrl . $basePath . '/' . $url;
                    }
                    
                    return $attr . '="' . $absoluteUrl . '"';
                },
                $html
            );
        }
        
        return $html;
    }

    /**
     * Lance plusieurs crawls de test simultanés (pour debug)
     * 
     * @param Request $request Requête HTTP (count, url)
     * 
     * @return void
     */
    public function launchTestCrawls(Request $request): void
    {
        $count = (int)($request->get('count') ?? 5);
        $url = $request->get('url') ?? 'https://lokoe.fr';
        
        if ($count < 1 || $count > 20) {
            $this->error('Count doit être entre 1 et 20');
        }
        
        // Utiliser l'utilisateur connecté
        $userId = $this->auth->getCurrentUserId();
        
        // Récupérer ou créer le projet pour lokoe.fr
        $domain = parse_url($url, PHP_URL_HOST);
        $projectRepo = new \App\Database\ProjectRepository();
        $projectId = $projectRepo->getOrCreate($userId, $domain);
        
        $jobManager = new \App\Job\JobManager();
        $crawlRepo = new \App\Database\CrawlRepository();
        $created = [];
        
        for ($i = 1; $i <= $count; $i++) {
            // Format exact du path comme dans ProjectController::create
            $projectDir = $domain . '-' . date('Ymd') . '-' . date('His') . '-' . $i;
            
            // Config exacte comme dans ProjectController::create
            $config = [
                'general' => [
                    'start' => $url,
                    'depthMax' => 3,
                    'domains' => [$domain],
                    'crawl_speed' => 'fast',
                    'crawl_mode' => 'classic',
                    'user-agent' => 'Scouter/2.0 (Test Crawler)'
                ],
                'advanced' => [
                    'respect_robots' => true,
                    'respect_nofollow' => false,
                    'respect_canonical' => true,
                    'xPathExtractors' => [],
                    'regexExtractors' => []
                ]
            ];
            
            // Créer le crawl via CrawlRepository (comme ProjectController)
            $crawlId = $crawlRepo->insert([
                'domain' => $domain,
                'path' => $projectDir,
                'status' => 'pending',
                'config' => $config,
                'depth_max' => 3,
                'in_progress' => 0,
                'project_id' => $projectId
            ]);
            
            // Créer le job et le mettre en queue
            $jobId = $jobManager->createJob($projectDir, $domain, 'crawl');
            $jobManager->updateJobStatus($jobId, 'queued');
            $jobManager->addLog($jobId, "Test crawl queued", 'info');
            
            // Mettre le crawl en queued aussi
            $crawlRepo->update($crawlId, ['status' => 'queued']);
            
            $created[] = ['crawl_id' => $crawlId, 'job_id' => $jobId, 'path' => $projectDir];
            
            // Petit délai pour éviter les collisions de timestamp
            usleep(100000); // 100ms
        }
        
        $this->success([
            'message' => "$count crawls de test créés",
            'crawls' => $created
        ]);
    }

    /**
     * Génère la barre d'information Scouter
     * 
     * @param string $pageUrl       URL de la page
     * @param string $dateFormatted Date formatée du crawl
     * 
     * @return string HTML de la barre
     */
    private function generateInfoBar(string $pageUrl, string $dateFormatted): string
    {
        return <<<HTML
<div id="scouter-info-bar" style="position:fixed;top:0;left:0;right:0;background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);color:#fff;padding:10px 20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:13px;z-index:999999;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 10px rgba(0,0,0,0.3);">
    <div style="display:flex;align-items:center;gap:15px;">
        <span style="font-weight:bold;color:#4ECDC4;">🔍 Scouter Monitor</span>
        <span style="color:#aaa;">Crawlé le {$dateFormatted}</span>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <a href="{$pageUrl}" target="_blank" style="color:#4ECDC4;text-decoration:none;">Voir en live →</a>
        <button onclick="document.getElementById('scouter-info-bar').remove();document.body.style.marginTop='0';" style="background:#ff6b6b;border:none;color:#fff;padding:5px 10px;border-radius:4px;cursor:pointer;">✕</button>
    </div>
</div>
<style>body{margin-top:50px !important;}</style>
HTML;
    }
}
