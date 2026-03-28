<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Job\JobManager;
use App\Database\CrawlDatabase;

/**
 * Controller pour la gestion des jobs de crawl
 * 
 * Gère le statut et les logs des jobs.
 * 
 * @package    Scouter
 * @subpackage Http\Controllers
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class JobController extends Controller
{
    /**
     * Gestionnaire des jobs
     * 
     * @var JobManager
     */
    private JobManager $jobManager;

    /**
     * Constructeur
     * 
     * @param \App\Auth\Auth $auth Instance d'authentification
     */
    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->jobManager = new JobManager();
    }

    /**
     * Retourne le statut d'un job
     * 
     * Retourne les informations complètes du job associé au projet.
     * 
     * @param Request $request Requête HTTP (project_dir)
     * 
     * @return void
     */
    public function status(Request $request): void
    {
        $projectDir = $request->get('project_dir');
        
        if (empty($projectDir)) {
            $this->error('Missing project_dir');
        }
        
        $this->auth->requireCrawlAccess($projectDir, true);
        
        $job = $this->jobManager->getJobByProject($projectDir);
        
        if (!$job) {
            $this->json([
                'status' => 'not_found',
                'message' => 'No job found for this project'
            ]);
            return;
        }
        
        // Cross-check with crawl status: if the crawl is finished/stopped but the job
        // is stuck in stopping/running (e.g. due to lock errors killing the process),
        // sync the job status to match the crawl
        $status = $job->status;
        if (in_array($status, ['running', 'stopping'])) {
            $crawl = CrawlDatabase::getCrawlByPath($projectDir);
            if ($crawl && in_array($crawl->status, ['finished', 'stopped', 'error'])) {
                $finalStatus = $crawl->status === 'finished' ? 'completed' : $crawl->status;
                $this->jobManager->updateJobStatus($job->id, $finalStatus);
                $status = $finalStatus;
            }
        }

        $this->json([
            'job_id' => $job->id,
            'status' => $status,
            'progress' => $job->progress,
            'pid' => $job->pid,
            'created_at' => $job->created_at,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'error' => $job->error
        ]);
    }

    /**
     * Retourne les logs d'un job
     * 
     * Combine les logs de la base de données et du fichier de log.
     * 
     * @param Request $request Requête HTTP (project_dir, limit, offset)
     * 
     * @return void
     */
    public function logs(Request $request): void
    {
        $projectDir = $request->get('project_dir');
        $limit = (int)$request->get('limit', 100);
        $offset = (int)$request->get('offset', 0);
        
        if (empty($projectDir)) {
            $this->error('Missing project_dir');
        }
        
        $this->auth->requireCrawlAccess($projectDir, true);
        
        $job = $this->jobManager->getJobByProject($projectDir);
        
        if (!$job) {
            Response::notFound('Job not found');
        }
        
        $dbLogs = $this->jobManager->getJobLogs($job->id, $limit, $offset);
        
        $basePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        $safeDir = basename($projectDir); // Prevent path traversal
        $logFile = $basePath . "logs" . DIRECTORY_SEPARATOR . $safeDir . ".log";
        
        $fileLogs = [];
        if (file_exists($logFile)) {
            $fileLogs = $this->parseLogFile($logFile);
        }
        
        $this->json([
            'job_id' => $job->id,
            'status' => $job->status,
            'db_logs' => $dbLogs,
            'file_logs' => $fileLogs
        ]);
    }

    /**
     * Parse un fichier de log et extrait les messages pertinents
     * 
     * Nettoie les codes ANSI, déduplique les messages de progression
     * et retourne un tableau structuré.
     * 
     * @param string $logFile Chemin vers le fichier de log
     * 
     * @return array<int, array{message: string, type: string, created_at: string}>
     */
    private function parseLogFile(string $logFile): array
    {
        $fileContent = file_get_contents($logFile);
        $fileContent = str_replace("\r", "\n", $fileContent);
        $lines = explode("\n", $fileContent);
        
        $depthProgress = [];
        $inlinksProgress = null;
        $pagerankIterations = [];
        $redirectChainsProgress = null;
        $staticLogs = [];
        
        foreach ($lines as $line) {
            if (trim($line)) {
                $cleanLine = preg_replace('/\x1b\[[0-9;]*m/', '', $line);
                $cleanLine = str_replace("\r", '', $cleanLine);
                $cleanLine = trim($cleanLine);
                
                if (empty($cleanLine)) continue;
                
                if (preg_match('/Depth (\d+) : ([\d.]+) URLs\/sec \((\d+)\/(\d+)\)/', $cleanLine, $matches)) {
                    $depthProgress[$matches[1]] = $cleanLine;
                } elseif (preg_match('/Inlinks calcul\s*:\s*(\d+)/', $cleanLine)) {
                    $inlinksProgress = $cleanLine;
                } elseif (preg_match('/Pagerank calcul\s*:\s*Iteration\s+(\d+)/', $cleanLine, $matches)) {
                    $pagerankIterations[$matches[1]] = $cleanLine;
                } elseif (preg_match('/Redirect chains\s*:/', $cleanLine)) {
                    $redirectChainsProgress = $cleanLine;
                } elseif (!in_array($cleanLine, $staticLogs)) {
                    $staticLogs[] = $cleanLine;
                }
            }
        }
        
        $fileLogs = [];
        $now = date('Y-m-d H:i:s');
        
        foreach ($staticLogs as $message) {
            $fileLogs[] = ['message' => $message, 'type' => 'output', 'created_at' => $now];
        }
        
        ksort($depthProgress);
        foreach ($depthProgress as $message) {
            $fileLogs[] = ['message' => $message, 'type' => 'progress', 'created_at' => $now];
        }
        
        if ($inlinksProgress) {
            $fileLogs[] = ['message' => $inlinksProgress, 'type' => 'progress', 'created_at' => $now];
        }
        
        if (!empty($pagerankIterations)) {
            ksort($pagerankIterations);
            $fileLogs[] = ['message' => end($pagerankIterations), 'type' => 'progress', 'created_at' => $now];
        }

        if ($redirectChainsProgress) {
            $fileLogs[] = ['message' => $redirectChainsProgress, 'type' => 'progress', 'created_at' => $now];
        }

        return $fileLogs;
    }

    /**
     * Get job details by ID
     *
     * Returns complete job information for a specific job ID.
     * Used for batch job polling and status tracking.
     *
     * @param Request $request HTTP request (id as URL parameter)
     *
     * @return void
     */
    public function show(Request $request): void
    {
        $jobId = $request->param('id');

        if (!$jobId) {
            $this->error('Job ID required');
        }

        // Get job from database
        $job = $this->jobManager->getJob($jobId);

        if (!$job) {
            $this->error('Job not found', 404);
        }

        // Verify access: resolve project from job's project_dir
        if ($job->project_dir) {
            $crawlRecord = \App\Database\CrawlDatabase::getCrawlByPath($job->project_dir);
            if ($crawlRecord && $crawlRecord->project_id) {
                $this->auth->requireProjectAccess($crawlRecord->project_id);
            }
        }

        // Get recent logs
        $logs = $this->jobManager->getJobLogs($jobId, 10);

        $this->success([
            'job' => $job,
            'logs' => $logs
        ]);
    }
}
