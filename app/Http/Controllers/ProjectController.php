<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\ProjectRepository;
use App\Database\CrawlRepository;
use App\Database\CrawlDatabase;
use App\Job\JobManager;

/**
 * Controller pour la gestion des projets
 * 
 * Gère les opérations CRUD sur les projets et leur partage.
 * 
 * @package    Scouter
 * @subpackage Http\Controllers
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class ProjectController extends Controller
{
    /**
     * Repository des projets
     * 
     * @var ProjectRepository
     */
    private ProjectRepository $projects;

    /**
     * Constructeur
     * 
     * @param \App\Auth\Auth $auth Instance d'authentification
     */
    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->projects = new ProjectRepository();
    }

    /**
     * Liste les projets accessibles par l'utilisateur
     * 
     * Retourne les projets selon le rôle : admin voit tout, user voit ses projets
     * et ceux partagés, viewer voit uniquement les partagés.
     * 
     * @param Request $request Requête HTTP
     * 
     * @return void
     */
    public function index(Request $request): void
    {
        $isAdmin = $this->auth->isAdmin();
        
        $myProjects = [];
        $sharedProjects = [];
        $otherProjects = [];
        
        if ($isAdmin) {
            $myProjects = $this->projects->getForUser($this->userId);
            $allProjects = $this->projects->getAllWithOwner();
            
            foreach ($allProjects as $project) {
                if ($project->user_id != $this->userId) {
                    $otherProjects[] = $project;
                }
            }
        } elseif ($this->auth->hasRole('user')) {
            $myProjects = $this->projects->getForUser($this->userId);
            $sharedProjects = $this->projects->getSharedForUser($this->userId);
        } else {
            $sharedProjects = $this->projects->getSharedForUser($this->userId);
        }
        
        $this->success([
            'my_projects' => $myProjects,
            'shared_projects' => $sharedProjects,
            'other_projects' => $otherProjects,
            'role' => $this->auth->getCurrentRole()
        ]);
    }

    /**
     * Affiche les détails d'un projet
     * 
     * @param Request $request Requête HTTP (id en route)
     * 
     * @return void
     */
    public function show(Request $request): void
    {
        $projectId = (int)$request->param('id');
        
        if (!$projectId) {
            $this->error('ID projet invalide');
        }
        
        if (!$this->auth->canAccessProject($projectId)) {
            Response::forbidden('Accès refusé');
        }
        
        $project = $this->projects->getById($projectId);
        $this->success(['project' => $project]);
    }

    /**
     * Crée un nouveau projet et crawl
     * 
     * Accepte une URL de départ, extrait le domaine comme nom de projet,
     * crée le projet si nécessaire, puis crée le crawl avec la configuration.
     * 
     * @param Request $request Requête HTTP (start_url, depth_max, config options)
     * 
     * @return void
     */
    public function create(Request $request): void
    {
        // Vérifier si c'est une action share/unshare (ancien format API)
        $action = $request->get('action', '');
        if ($action === 'share') {
            $this->shareFromBody($request);
            return;
        }
        if ($action === 'unshare') {
            $this->unshareFromBody($request);
            return;
        }
        
        if (!$this->auth->canCreate()) {
            Response::forbidden('Vous n\'avez pas le droit de créer des projets');
        }

        $crawlType = $request->get('crawl_type', 'spider');
        if (!in_array($crawlType, ['spider', 'list'])) {
            $crawlType = 'spider';
        }

        $followRedirects = $request->get('follow_redirects', true);

        if ($crawlType === 'list') {
            // Mode Liste : crawler une liste d'URLs fournie
            $urlListRaw = $request->get('url_list', '');

            if (empty(trim($urlListRaw))) {
                $this->error('La liste d\'URLs est obligatoire en mode Liste');
            }

            // Sanitization de la liste
            $lines = explode("\n", $urlListRaw);
            $urls = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (strpos($line, 'http://') !== 0 && strpos($line, 'https://') !== 0) continue;
                $urls[] = mb_substr($line, 0, 2083);
            }
            $urls = array_values(array_unique($urls));

            if (empty($urls)) {
                $this->error('Aucune URL valide dans la liste (http:// ou https:// obligatoire)');
            }

            // Extraire le domaine de la premiere URL
            $parsedFirst = parse_url($urls[0]);
            $domain = $parsedFirst['host'] ?? '';
            if (empty($domain)) {
                $this->error('Impossible d\'extraire le domaine de la premiere URL');
            }

            // Collecter tous les domaines uniques
            $allDomains = [];
            foreach ($urls as $u) {
                $p = parse_url($u);
                if (!empty($p['host'])) {
                    $allDomains[] = $p['host'];
                }
            }
            $allDomains = array_values(array_unique($allDomains));

            $startUrl = $urls[0];
            $depthMax = (int)$request->get('depth_max', 30);
            $allowedDomains = $allDomains;
        } else {
            // Mode Spider : comportement existant
            $startUrl = trim($request->get('start_url', ''));

            if (empty($startUrl)) {
                $this->error('L\'URL de départ est obligatoire');
            }

            if (!filter_var($startUrl, FILTER_VALIDATE_URL)) {
                $this->error('URL invalide');
            }

            $parsedUrl = parse_url($startUrl);
            $domain = $parsedUrl['host'] ?? '';

            if (empty($domain)) {
                $this->error('Impossible d\'extraire le domaine de l\'URL');
            }

            $depthMax = (int)$request->get('depth_max', 30);
            $allowedDomains = $request->get('allowed_domains', []);
            if (empty($allowedDomains)) {
                $allowedDomains = [$domain];
            }
        }

        // Créer ou récupérer le projet pour ce domaine
        $projectId = $this->projects->getOrCreate($this->userId, $domain);

        // Générer le path unique pour ce crawl
        $projectDir = $domain . '-' . date('Ymd') . '-' . date('His');

        // Construire les extracteurs dans le format attendu par Cmder
        $extractors = $request->get('extractors', []);
        $xPathExtractors = [];
        $regexExtractors = [];
        foreach ($extractors as $ext) {
            if (($ext['type'] ?? 'xpath') === 'xpath') {
                $xPathExtractors[$ext['name']] = $ext['pattern'];
            } else {
                $regexExtractors[$ext['name']] = $ext['pattern'];
            }
        }

        // Construire la configuration dans le format attendu par Cmder.php
        $config = [
            'general' => [
                'start' => $startUrl,
                'depthMax' => $depthMax,
                'domains' => $allowedDomains,
                'crawl_speed' => $request->get('crawl_speed', 'fast'),
                'crawl_mode' => $request->get('crawl_mode', 'classic'),
                'crawl_type' => $crawlType,
                'user-agent' => $request->get('user_agent', 'Scouter/2.0 (Crawler by Lokoé; +https://lokoe.fr)')
            ],
            'advanced' => [
                'respect_robots' => $request->get('respect_robots', true),
                'respect_nofollow' => $request->get('respect_nofollow', false),
                'respect_canonical' => $request->get('respect_canonical', true),
                'follow_redirects' => $followRedirects,
                'retry_failed_urls' => $request->get('retry_failed_urls', true),
                'store_html' => $request->get('store_html', true),
                'custom_headers' => $request->get('custom_headers', []),
                'http_auth' => $request->get('http_auth'),
                'xPathExtractors' => $xPathExtractors,
                'regexExtractors' => $regexExtractors
            ]
        ];

        // Ajouter la liste d'URLs en mode liste
        if ($crawlType === 'list') {
            $config['general']['url_list'] = $urls;
        }

        // Créer le crawl dans la base de données
        $crawlRepo = new CrawlRepository();
        $crawlId = $crawlRepo->insert([
            'domain' => $domain,
            'path' => $projectDir,
            'status' => 'pending',
            'config' => $config,
            'depth_max' => $depthMax,
            'crawl_type' => $crawlType,
            'in_progress' => 0,
            'project_id' => $projectId
        ]);
        
        // Appliquer le template de catégorisation par défaut (cat.yml)
        $catYmlPath = dirname(__DIR__, 3) . '/cat.yml';
        if (file_exists($catYmlPath)) {
            $catYaml = file_get_contents($catYmlPath);
            if ($catYaml) {
                // Remplacer {dom} par le vrai domaine
                $catYaml = str_replace('{dom}', $domain, $catYaml);
                
                $db = \App\Database\PostgresDatabase::getInstance()->getConnection();
                $stmt = $db->prepare("
                    INSERT INTO categorization_config (crawl_id, config) 
                    VALUES (:crawl_id, :config)
                    ON CONFLICT (crawl_id) DO UPDATE SET config = :config2
                ");
                $stmt->execute([':crawl_id' => $crawlId, ':config' => $catYaml, ':config2' => $catYaml]);
            }
        }
        
        $this->success([
            'project_id' => $projectId,
            'crawl_id' => $crawlId,
            'project_dir' => $projectDir,
            'domain' => $domain
        ], 'Projet et crawl créés avec succès');
    }

    /**
     * Met à jour un projet (renommage)
     * 
     * @param Request $request Requête HTTP (id en route, name)
     * 
     * @return void
     */
    public function update(Request $request): void
    {
        $projectId = (int)$request->param('id');
        $name = trim($request->get('name', ''));
        
        if (!$projectId) {
            $this->error('ID projet invalide');
        }
        
        if (empty($name)) {
            $this->error('Le nom du projet est obligatoire');
        }
        
        if (!$this->auth->canManageProject($projectId)) {
            Response::forbidden('Droits insuffisants');
        }
        
        $this->projects->update($projectId, $name);
        $this->success([], 'Projet renommé avec succès');
    }

    /**
     * Supprime un projet
     * 
     * @param Request $request Requête HTTP (id en route)
     * 
     * @return void
     */
    public function delete(Request $request): void
    {
        $projectId = (int)$request->param('id');
        
        if (!$projectId) {
            $this->error('ID projet invalide');
        }
        
        if (!$this->auth->canManageProject($projectId)) {
            Response::forbidden('Droits insuffisants');
        }
        
        $this->projects->delete($projectId);
        $this->success([], 'Projet supprimé avec succès');
    }

    /**
     * Supprime un projet (ID dans le body pour compatibilité frontend)
     * 
     * @param Request $request Requête HTTP (project_id dans body)
     * 
     * @return void
     */
    public function deleteFromBody(Request $request): void
    {
        $projectId = (int)$request->get('project_id');
        
        if (!$projectId) {
            $this->error('ID projet invalide');
        }
        
        if (!$this->auth->canManageProject($projectId)) {
            Response::forbidden('Droits insuffisants');
        }
        
        $this->projects->delete($projectId);
        $this->success([], 'Projet supprimé avec succès');
    }

    /**
     * Liste les partages d'un projet
     * 
     * Retourne les utilisateurs avec qui le projet est partagé
     * et ceux disponibles pour le partage.
     * 
     * @param Request $request Requête HTTP (id en route)
     * 
     * @return void
     */
    public function shares(Request $request): void
    {
        $projectId = (int)$request->param('id');
        
        if (!$projectId) {
            $this->error('ID projet invalide');
        }
        
        if (!$this->auth->canManageProject($projectId)) {
            Response::forbidden('Droits insuffisants');
        }
        
        $shares = $this->projects->getShares($projectId);
        $availableUsers = $this->projects->getAvailableUsersForSharing($projectId);
        
        $this->success([
            'shares' => $shares,
            'available_users' => $availableUsers
        ]);
    }

    /**
     * Partage un projet avec un utilisateur
     * 
     * @param Request $request Requête HTTP (id en route, user_id)
     * 
     * @return void
     */
    public function share(Request $request): void
    {
        $projectId = (int)$request->param('id');
        $targetUserId = (int)$request->get('user_id');
        
        if (!$projectId || !$targetUserId) {
            $this->error('Paramètres invalides');
        }
        
        if (!$this->auth->canManageProject($projectId)) {
            Response::forbidden('Droits insuffisants');
        }
        
        $result = $this->projects->share($projectId, $targetUserId);
        if ($result) {
            $this->success([], 'Projet partagé avec succès');
        } else {
            $this->error('Ce projet est déjà partagé avec cet utilisateur');
        }
    }

    /**
     * Retire le partage d'un projet avec un utilisateur
     * 
     * @param Request $request Requête HTTP (id en route, user_id)
     * 
     * @return void
     */
    public function unshare(Request $request): void
    {
        $projectId = (int)$request->param('id');
        $targetUserId = (int)$request->get('user_id');
        
        if (!$projectId || !$targetUserId) {
            $this->error('Paramètres invalides');
        }
        
        if (!$this->auth->canManageProject($projectId)) {
            Response::forbidden('Droits insuffisants');
        }
        
        $this->projects->unshare($projectId, $targetUserId);
        $this->success([], 'Partage retiré avec succès');
    }

    /**
     * Partage un projet (project_id et user_id dans le body - ancien format API)
     * 
     * @param Request $request Requête HTTP (project_id, user_id)
     * 
     * @return void
     */
    public function shareFromBody(Request $request): void
    {
        $projectId = (int)$request->get('project_id');
        $targetUserId = (int)$request->get('user_id');
        
        if (!$projectId || !$targetUserId) {
            $this->error('Paramètres invalides');
        }
        
        if (!$this->auth->canManageProject($projectId)) {
            Response::forbidden('Droits insuffisants');
        }
        
        $result = $this->projects->share($projectId, $targetUserId);
        if ($result) {
            $this->success([], 'Projet partagé avec succès');
        } else {
            $this->error('Ce projet est déjà partagé avec cet utilisateur');
        }
    }

    /**
     * Retire le partage d'un projet (project_id et user_id dans le body - ancien format API)
     * 
     * @param Request $request Requête HTTP (project_id, user_id)
     * 
     * @return void
     */
    public function unshareFromBody(Request $request): void
    {
        $projectId = (int)$request->get('project_id');
        $targetUserId = (int)$request->get('user_id');
        
        if (!$projectId || !$targetUserId) {
            $this->error('Paramètres invalides');
        }
        
        if (!$this->auth->canManageProject($projectId)) {
            Response::forbidden('Droits insuffisants');
        }
        
        $this->projects->unshare($projectId, $targetUserId);
        $this->success([], 'Partage retiré avec succès');
    }

    /**
     * Retourne les statistiques d'un projet
     * 
     * @param Request $request Requête HTTP (id en route)
     * 
     * @return void
     */
    public function stats(Request $request): void
    {
        $projectId = (int)$request->param('id');
        
        if (!$projectId) {
            $this->error('ID projet invalide');
        }
        
        if (!$this->auth->canAccessProject($projectId)) {
            Response::forbidden('Accès refusé');
        }
        
        $project = $this->projects->getById($projectId);
        if (!$project) {
            Response::notFound('Projet non trouvé');
        }
        
        $this->success([
            'project' => $project,
            'stats' => [
                'crawls_count' => $project->crawls_count ?? 0
            ]
        ]);
    }

    /**
     * Duplique un crawl et démarre un nouveau crawl avec la même configuration
     * 
     * @param Request $request Requête HTTP (project = path du crawl source)
     * 
     * @return void
     */
    public function duplicate(Request $request): void
    {
        $projectDir = $request->get('project');
        
        if (empty($projectDir)) {
            $this->error('Projet non spécifié');
        }
        
        // Récupérer le crawl source
        $sourceCrawl = CrawlDatabase::getCrawlByPath($projectDir);
        if (!$sourceCrawl) {
            $this->error('Crawl source non trouvé');
        }
        
        // Vérifier l'accès au crawl source
        $this->auth->requireCrawlAccess($projectDir, true);
        
        // Récupérer la configuration du crawl source
        $sourceConfig = json_decode($sourceCrawl->config ?? '{}', true) ?: [];
        
        // Valider que la config source est complète
        if (empty($sourceConfig) || !isset($sourceConfig['general']) || !isset($sourceConfig['general']['start'])) {
            $this->error('Le crawl source n\'a pas de configuration valide. Créez un nouveau crawl au lieu de dupliquer.');
        }
        
        // Générer un nouveau path pour le nouveau crawl
        $domain = $sourceCrawl->domain;
        $newProjectDir = $domain . '-' . date('Ymd') . '-' . date('His');
        
        // Rattacher le nouveau crawl au même projet que le crawl source.
        // Important pour les projets partagés : on ne doit pas créer un projet personnel
        // pour l'utilisateur courant lorsqu'il lance un nouveau crawl.
        $projectId = (int)($sourceCrawl->project_id ?? 0);
        if ($projectId <= 0) {
            // Fallback legacy: anciens crawls sans project_id
            $projectId = $this->projects->getOrCreate($this->userId, $domain);
        }
        
        // Synchroniser depthMax dans le config JSON avec la colonne DB
        $depthMax = $sourceCrawl->depth_max ?? 30;
        $sourceConfig['general']['depthMax'] = $depthMax;

        // Créer le nouveau crawl avec la même configuration
        $crawlRepo = new CrawlRepository();
        $crawlId = $crawlRepo->insert([
            'domain' => $domain,
            'path' => $newProjectDir,
            'status' => 'queued',
            'config' => $sourceConfig,
            'depth_max' => $depthMax,
            'crawl_type' => $sourceCrawl->crawl_type ?? 'spider',
            'in_progress' => 1,
            'project_id' => $projectId
        ]);
        
        // Dupliquer la configuration de catégorisation si elle existe
        $db = \App\Database\PostgresDatabase::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT config FROM categorization_config WHERE crawl_id = :crawl_id");
        $stmt->execute([':crawl_id' => $sourceCrawl->id]);
        $catConfig = $stmt->fetch(\PDO::FETCH_OBJ);
        
        if ($catConfig && !empty($catConfig->config)) {
            $stmt = $db->prepare("
                INSERT INTO categorization_config (crawl_id, config) 
                VALUES (:crawl_id, :config)
                ON CONFLICT (crawl_id) DO UPDATE SET config = :config2
            ");
            $stmt->execute([':crawl_id' => $crawlId, ':config' => $catConfig->config, ':config2' => $catConfig->config]);
        }
        
        // Créer et mettre en queue le job
        $jobManager = new JobManager();
        $jobId = $jobManager->createJob($newProjectDir, $domain, 'crawl');
        $jobManager->updateJobStatus($jobId, 'queued');
        $jobManager->addLog($jobId, "Crawl dupliqué depuis $projectDir. En attente du worker...", 'info');
        
        $this->success([
            'project_id' => $projectId,
            'crawl_id' => $crawlId,
            'project_dir' => $newProjectDir,
            'domain' => $domain,
            'job_id' => $jobId,
            'source_crawl' => $projectDir
        ], 'Crawl dupliqué et mis en queue');
    }

    /**
     * Save or update crawl schedule for a project
     */
    public function saveSchedule(Request $request): void
    {
        $projectId = (int)$request->param('id');
        $this->auth->requireProjectManagement($projectId);

        $enabled = (bool)$request->get('enabled', false);
        $frequency = $request->get('frequency', 'weekly');
        $daysOfWeek = $request->get('days_of_week', ['mon']);
        $dayOfMonth = max(1, min(28, (int)$request->get('day_of_month', 1)));
        $hour = max(0, min(23, (int)$request->get('hour', 8)));
        $minute = max(0, min(59, (int)$request->get('minute', 0)));
        $templateCrawlId = $request->get('template_crawl_id');

        if (!in_array($frequency, ['minute', 'daily', 'weekly', 'monthly'])) {
            $this->error('Invalid frequency');
        }

        $db = \App\Database\PostgresDatabase::getInstance()->getConnection();

        // If disabling, just update enabled + next_run_at and return
        if (!$enabled) {
            $stmt = $db->prepare("
                UPDATE crawl_schedules SET enabled = false, next_run_at = NULL, updated_at = NOW()
                WHERE project_id = :pid
            ");
            $stmt->execute([':pid' => $projectId]);
            // Also handle case where no schedule exists yet (first save while off)
            if ($stmt->rowCount() === 0) {
                // Nothing to disable, just return success
            }
            $this->success(['next_run_at' => null, 'enabled' => false], 'Schedule disabled');
            return;
        }

        // Enabled: get crawl config from template
        $crawlConfig = '{}';
        $crawlType = 'spider';
        $depthMax = 30;
        $catConfig = null;

        if ($templateCrawlId) {
            $stmt = $db->prepare("SELECT config, crawl_type, depth_max, project_id FROM crawls WHERE id = :id");
            $stmt->execute([':id' => (int)$templateCrawlId]);
            $template = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$template || (int)$template->project_id !== $projectId) {
                $this->error('Template crawl not found or not in this project');
            }

            $crawlConfig = $template->config ?? '{}';
            $crawlType = $template->crawl_type ?? 'spider';
            $depthMax = $template->depth_max ?? 30;

            // Copy categorization config
            $stmt = $db->prepare("SELECT config FROM categorization_config WHERE crawl_id = :id");
            $stmt->execute([':id' => (int)$templateCrawlId]);
            $cat = $stmt->fetch(\PDO::FETCH_OBJ);
            $catConfig = $cat ? $cat->config : null;
        }

        // Compute next_run_at
        $nextRun = $this->computeNextRun($frequency, $daysOfWeek, $dayOfMonth, $hour, $minute);

        // Format days_of_week as PostgreSQL array
        $pgDays = '{' . implode(',', $daysOfWeek) . '}';

        $stmt = $db->prepare("
            INSERT INTO crawl_schedules (project_id, user_id, enabled, frequency, days_of_week, day_of_month, hour, minute, crawl_config, crawl_type, depth_max, categorization_config, next_run_at, updated_at)
            VALUES (:project_id, :user_id, :enabled, :frequency, :days_of_week, :day_of_month, :hour, :minute, :crawl_config, :crawl_type, :depth_max, :cat_config, :next_run, NOW())
            ON CONFLICT (project_id) DO UPDATE SET
                user_id = EXCLUDED.user_id,
                enabled = EXCLUDED.enabled,
                frequency = EXCLUDED.frequency,
                days_of_week = EXCLUDED.days_of_week,
                day_of_month = EXCLUDED.day_of_month,
                hour = EXCLUDED.hour,
                minute = EXCLUDED.minute,
                crawl_config = EXCLUDED.crawl_config,
                crawl_type = EXCLUDED.crawl_type,
                depth_max = EXCLUDED.depth_max,
                categorization_config = EXCLUDED.categorization_config,
                next_run_at = EXCLUDED.next_run_at,
                updated_at = NOW()
        ");

        $stmt->execute([
            ':project_id' => $projectId,
            ':user_id' => $this->userId,
            ':enabled' => $enabled ? 'true' : 'false',
            ':frequency' => $frequency,
            ':days_of_week' => $pgDays,
            ':day_of_month' => $dayOfMonth,
            ':hour' => $hour,
            ':minute' => $minute,
            ':crawl_config' => $crawlConfig,
            ':crawl_type' => $crawlType,
            ':depth_max' => $depthMax,
            ':cat_config' => $catConfig,
            ':next_run' => $nextRun,
        ]);

        $this->success([
            'next_run_at' => $nextRun,
            'enabled' => $enabled,
        ], 'Schedule saved');
    }

    /**
     * Get crawl schedule for a project
     */
    public function getSchedule(Request $request): void
    {
        $projectId = (int)$request->param('id');
        $this->auth->requireProjectAccess($projectId);

        $db = \App\Database\PostgresDatabase::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM crawl_schedules WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);
        $schedule = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->success(['schedule' => $schedule ?: null]);
    }

    /**
     * Compute next run timestamp
     */
    private function computeNextRun(string $freq, array $days, int $dayOfMonth, int $hour, int $minute): string
    {
        $now = new \DateTime('now');

        if ($freq === 'minute') {
            $next = clone $now;
            $next->modify('+1 minute');
            return $next->format('Y-m-d H:i:00');
        }

        if ($freq === 'daily') {
            $next = clone $now;
            $next->setTime($hour, $minute, 0);
            if ($next <= $now) $next->modify('+1 day');
            return $next->format('Y-m-d H:i:00');
        }

        if ($freq === 'weekly') {
            $dayMap = ['mon'=>'Monday','tue'=>'Tuesday','wed'=>'Wednesday',
                       'thu'=>'Thursday','fri'=>'Friday','sat'=>'Saturday','sun'=>'Sunday'];
            $candidates = [];
            foreach ($days as $d) {
                $dayName = $dayMap[$d] ?? null;
                if (!$dayName) continue;
                $c = new \DateTime("this week {$dayName}");
                $c->setTime($hour, $minute, 0);
                if ($c <= $now) { $c = new \DateTime("next {$dayName}"); $c->setTime($hour, $minute, 0); }
                $candidates[] = $c;
            }
            if (empty($candidates)) { $c = new \DateTime('next Monday'); $c->setTime($hour, $minute, 0); return $c->format('Y-m-d H:i:00'); }
            usort($candidates, fn($a, $b) => $a <=> $b);
            return $candidates[0]->format('Y-m-d H:i:00');
        }

        if ($freq === 'monthly') {
            $dom = max(1, min(28, $dayOfMonth));
            $next = clone $now;
            $next->setDate((int)$next->format('Y'), (int)$next->format('m'), $dom);
            $next->setTime($hour, $minute, 0);
            if ($next <= $now) { $next->modify('+1 month'); $next->setDate((int)$next->format('Y'), (int)$next->format('m'), $dom); }
            return $next->format('Y-m-d H:i:00');
        }

        $next = clone $now;
        $next->modify('+1 day');
        return $next->format('Y-m-d H:i:00');
    }
}
