<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Job\JobManager;
use App\Database\CrawlRepository;
use App\Database\CrawlDatabase;
use App\Database\PostgresDatabase;

/**
 * Controller pour la gestion des crawls
 * 
 * Gère le démarrage, l'arrêt, la reprise et la suppression des crawls.
 * 
 * @package    Scouter
 * @subpackage Http\Controllers
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class CrawlController extends Controller
{
    /**
     * Gestionnaire des jobs
     * 
     * @var JobManager
     */
    private JobManager $jobManager;

    /**
     * Repository des crawls
     * 
     * @var CrawlRepository
     */
    private CrawlRepository $crawls;

    /**
     * Constructeur
     * 
     * @param \App\Auth\Auth $auth Instance d'authentification
     */
    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->jobManager = new JobManager();
        $this->crawls = new CrawlRepository();
    }

    /**
     * Retourne les informations d'un crawl
     * 
     * Accepte un ID numérique ou un chemin de projet.
     * 
     * @param Request $request Requête HTTP (project)
     * 
     * @return void
     */
    public function info(Request $request): void
    {
        $projectDir = $request->get('project');
        
        if (empty($projectDir)) {
            $this->error('Projet non spécifié');
        }
        
        if (is_numeric($projectDir)) {
            $this->auth->requireCrawlAccessById((int)$projectDir, true);
            $crawlRecord = CrawlDatabase::getCrawlById((int)$projectDir);
        } else {
            $this->auth->requireCrawlAccess($projectDir, true);
            $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        }
        
        if (!$crawlRecord) {
            Response::notFound('Crawl non trouvé');
        }
        
        $this->success(['crawl' => $crawlRecord]);
    }

    /**
     * Démarre un crawl
     * 
     * Crée un job et le met en file d'attente pour le worker.
     * 
     * @param Request $request Requête HTTP (project_dir)
     * 
     * @return void
     */
    public function start(Request $request): void
    {
        $projectDir = $request->get('project_dir');
        
        if (empty($projectDir)) {
            $this->error('Missing project_dir');
        }
        
        $this->auth->requireCrawlManagement($projectDir, true);
        
        $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        if (!$crawlRecord) {
            Response::notFound('Crawl not found in database');
        }
        
        $existingJob = $this->jobManager->getJobByProject($projectDir);
        if ($existingJob && $existingJob->status === 'running') {
            $this->success([
                'job_id' => $existingJob->id
            ], 'A crawl is already running for this project');
            return;
        }
        
        if ($existingJob && $existingJob->status === 'pending') {
            $jobId = $existingJob->id;
        } else {
            $projectName = preg_replace("#-(\d{8})-(\d{6})$#", "", $projectDir);
            $jobId = $this->jobManager->createJob($projectDir, $projectName, 'crawl');
        }
        
        $this->crawls->update($crawlRecord->id, [
            'status' => 'queued',
            'in_progress' => 1
        ]);
        
        $this->jobManager->updateJobStatus($jobId, 'queued');
        $this->jobManager->addLog($jobId, "Crawl queued. Waiting for worker...", 'info');
        
        $this->success([
            'job_id' => $jobId,
            'crawl_id' => $crawlRecord->id
        ], 'Crawl queued successfully');
    }

    /**
     * Arrête un crawl en cours
     * 
     * Envoie un signal d'arrêt au worker. Le crawl terminera le batch en cours.
     * 
     * @param Request $request Requête HTTP (project_dir)
     * 
     * @return void
     */
    public function stop(Request $request): void
    {
        $projectDir = $request->get('project_dir');
        
        if (empty($projectDir)) {
            $this->error('Missing project_dir');
        }
        
        $this->auth->requireCrawlManagement($projectDir, true);
        
        $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        if (!$crawlRecord) {
            Response::notFound('Project not found');
        }
        
        $job = $this->jobManager->getJobByProject($projectDir);
        if (!$job) {
            Response::notFound('Job not found');
        }
        
        if (!in_array($job->status, ['running', 'queued', 'pending', 'stopping'])) {
            $this->error('Job is not running or queued');
        }
        
        if (in_array($job->status, ['pending', 'queued'])) {
            $this->jobManager->updateJobStatus($job->id, 'stopped');
            $this->jobManager->addLog($job->id, "Crawl cancelled by user", 'warning');
            $this->crawls->update($crawlRecord->id, ['status' => 'stopped', 'in_progress' => 0]);
            
            $this->success([
                'job_id' => $job->id,
                'project_dir' => $projectDir
            ], 'Crawl cancelled');
            return;
        }
        
        if ($job->status === 'stopping') {
            $this->jobManager->updateJobStatus($job->id, 'stopped');
            $this->jobManager->addLog($job->id, "Crawl force-stopped by user", 'warning');
            
            $this->success([
                'job_id' => $job->id,
                'project_dir' => $projectDir
            ], 'Crawl force-stopped');
            return;
        }
        
        $this->jobManager->updateJobStatus($job->id, 'stopping');
        $this->jobManager->addLog($job->id, "Stop signal sent to worker...", 'info');
        
        $this->success([
            'job_id' => $job->id,
            'project_dir' => $projectDir
        ], 'Stop signal sent. Crawl will finish current batch and stop.');
    }

    /**
     * Reprend un crawl arrêté
     * 
     * Crée un nouveau job pour continuer le crawl là où il s'est arrêté.
     * 
     * @param Request $request Requête HTTP (project_dir)
     * 
     * @return void
     */
    public function resume(Request $request): void
    {
        $projectDir = $request->get('project_dir');
        
        if (empty($projectDir)) {
            $this->error('Missing project_dir');
        }
        
        $this->auth->requireCrawlManagement($projectDir, true);
        
        $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        if (!$crawlRecord) {
            Response::notFound('Crawl not found');
        }
        
        $projectName = preg_replace("#-(\d{8})-(\d{6})$#", "", $projectDir);
        $jobId = $this->jobManager->createJob($projectDir, $projectName, 'crawl');
        
        $this->crawls->update($crawlRecord->id, [
            'status' => 'queued',
            'in_progress' => 1
        ]);
        
        $this->jobManager->updateJobStatus($jobId, 'queued');
        $this->jobManager->addLog($jobId, "Crawl resumed. Waiting for worker...", 'info');
        
        $this->success([
            'job_id' => $jobId
        ], 'Crawl resumed');
    }

    /**
     * Supprime un crawl et toutes ses données
     * 
     * Tue le processus si en cours, supprime le job et les données PostgreSQL.
     * 
     * @param Request $request Requête HTTP (project_dir)
     * 
     * @return void
     */
    public function delete(Request $request): void
    {
        $projectDir = $request->get('project_dir');
        
        if (empty($projectDir)) {
            $this->error('Missing project_dir');
        }
        
        $this->auth->requireCrawlManagement($projectDir, true);
        
        $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        if (!$crawlRecord) {
            Response::notFound('Crawl not found');
        }
        
        $crawlId = $crawlRecord->id;
        
        $job = $this->jobManager->getJobByProject($projectDir);
        if ($job) {
            if ($job->pid > 0) {
                exec("kill -9 " . intval($job->pid) . " 2>&1");
            }
            $this->jobManager->deleteJob($job->id);
        }
        
        $pdo = PostgresDatabase::getInstance()->getConnection();
        
        $stmt = $pdo->prepare("DELETE FROM categorization_config WHERE crawl_id = :crawl_id");
        $stmt->execute([':crawl_id' => $crawlId]);
        
        $stmt = $pdo->prepare("DELETE FROM crawls WHERE id = :crawl_id");
        $stmt->execute([':crawl_id' => $crawlId]);
        
        $this->success([], 'Crawl deleted successfully');
    }

    /**
     * Liste les crawls en cours d'exécution
     * 
     * Retourne tous les jobs avec statut running ou queued.
     * 
     * @param Request $request Requête HTTP
     * 
     * @return void
     */
    public function runningCrawls(Request $request): void
    {
        $jobs = $this->jobManager->getRunningJobs();
        $crawls = [];
        
        foreach ($jobs as $job) {
            $crawlRecord = CrawlDatabase::getCrawlByPath($job->project_dir);
            if ($crawlRecord) {
                $crawls[] = [
                    'job_id' => $job->id,
                    'project_dir' => $job->project_dir,
                    'status' => $job->status,
                    'crawl_id' => $crawlRecord->id,
                    'domain' => $crawlRecord->domain,
                    'urls' => $crawlRecord->urls ?? 0,
                    'crawled' => $crawlRecord->crawled ?? 0
                ];
            }
        }
        
        $this->success(['crawls' => $crawls]);
    }
}
