<?php

namespace App\Job;

use PDO;
use App\Database\PostgresDatabase;

/**
 * Gestionnaire de jobs de crawl asynchrones
 * 
 * Cette classe gère la file d'attente des crawls :
 * - Création de jobs (pending -> queued -> running -> completed)
 * - Suivi du statut et de la progression
 * - Logs par job
 * - Synchronisation avec le statut du crawl
 * 
 * Utilisé par l'architecture Docker Worker pour exécuter
 * les crawls de manière asynchrone.
 * 
 * @package    Scouter
 * @subpackage Jobs
 * @author     Mehdi Colin
 * @version    2.0.0
 * @since      2.0.0
 * 
 * @see app/bin/worker.php Pour le worker qui exécute les jobs
 */
class JobManager
{
    private $db;

    public function __construct()
    {
        $this->db = PostgresDatabase::getInstance()->getConnection();
        $this->ensureTables();
    }

    private function ensureTables()
    {
        // Créer les tables si elles n'existent pas
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id SERIAL PRIMARY KEY,
                project_dir TEXT NOT NULL,
                project_name TEXT NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                progress INTEGER DEFAULT 0,
                pid INTEGER DEFAULT NULL,
                command TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                started_at TIMESTAMP DEFAULT NULL,
                finished_at TIMESTAMP DEFAULT NULL,
                error TEXT DEFAULT NULL
            )
        ");
        
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS job_logs (
                id SERIAL PRIMARY KEY,
                job_id INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
                message TEXT,
                type VARCHAR(20) DEFAULT 'info',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Index pour les recherches fréquentes
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_project_dir ON jobs(project_dir)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_job_logs_job_id ON job_logs(job_id)");
        
        // Index composite pour le polling des workers (status + created_at)
        // Optimise la requête: SELECT ... WHERE status = 'queued' ORDER BY created_at
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status_created ON jobs(status, created_at)");
    }

    public function createJob($projectDir, $projectName, $command)
    {
        $stmt = $this->db->prepare("
            INSERT INTO jobs (project_dir, project_name, command, status)
            VALUES (:project_dir, :project_name, :command, 'pending')
            RETURNING id
        ");
        
        $stmt->execute([
            ':project_dir' => $projectDir,
            ':project_name' => $projectName,
            ':command' => $command
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        return $result->id;
    }

    public function updateJobStatus($jobId, $status, $pid = null)
    {
        $sql = "UPDATE jobs SET status = :status";
        $params = [':status' => $status, ':job_id' => $jobId];
        
        if ($status === 'running' && $pid !== null) {
            $sql .= ", pid = :pid, started_at = CURRENT_TIMESTAMP";
            $params[':pid'] = $pid;
        }
        
        if ($status === 'completed' || $status === 'failed' || $status === 'stopped') {
            $sql .= ", finished_at = CURRENT_TIMESTAMP";
        }
        
        $sql .= " WHERE id = :job_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        // SYNC: Toujours mettre à jour le crawl status en même temps
        // Job status -> Crawl status mapping
        $crawlStatusMap = [
            'queued' => 'queued',
            'running' => 'running',
            'stopping' => 'stopping',
            'stopped' => 'stopped',
            'completed' => 'finished',
            'failed' => 'error',
            'pending' => 'pending'
        ];
        
        $crawlStatus = $crawlStatusMap[$status] ?? $status;
        $inProgress = in_array($status, ['queued', 'running', 'stopping', 'pending']) ? 1 : 0;
        
        // Get project_dir from job to find crawl
        $jobStmt = $this->db->prepare("SELECT project_dir FROM jobs WHERE id = :job_id");
        $jobStmt->execute([':job_id' => $jobId]);
        $projectDir = $jobStmt->fetchColumn();
        
        if ($projectDir) {
            $crawlSql = "UPDATE crawls SET status = :status, in_progress = :in_progress";
            if (in_array($status, ['completed', 'failed', 'stopped'])) {
                $crawlSql .= ", finished_at = CURRENT_TIMESTAMP";
            }
            $crawlSql .= " WHERE path = :path";
            
            $crawlStmt = $this->db->prepare($crawlSql);
            $crawlStmt->execute([
                ':status' => $crawlStatus,
                ':in_progress' => $inProgress,
                ':path' => $projectDir
            ]);
        }
    }

    public function updateJobProgress($jobId, $progress)
    {
        $stmt = $this->db->prepare("UPDATE jobs SET progress = :progress WHERE id = :job_id");
        $stmt->execute([':progress' => $progress, ':job_id' => $jobId]);
    }

    public function setJobError($jobId, $error)
    {
        $stmt = $this->db->prepare("UPDATE jobs SET error = :error, status = 'failed' WHERE id = :job_id");
        $stmt->execute([':error' => $error, ':job_id' => $jobId]);
    }

    public function addLog($jobId, $message, $type = 'info')
    {
        $stmt = $this->db->prepare("
            INSERT INTO job_logs (job_id, message, type)
            VALUES (:job_id, :message, :type)
        ");
        
        $stmt->execute([
            ':job_id' => $jobId,
            ':message' => $message,
            ':type' => $type
        ]);
    }

    public function getJob($jobId)
    {
        $stmt = $this->db->prepare("SELECT * FROM jobs WHERE id = :job_id");
        $stmt->execute([':job_id' => $jobId]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }

    public function getJobByProject($projectDir)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM jobs 
            WHERE project_dir = :project_dir 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([':project_dir' => $projectDir]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }

    public function getJobLogs($jobId, $limit = 100, $offset = 0)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM job_logs 
            WHERE job_id = :job_id 
            ORDER BY created_at ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':job_id', $jobId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public function getAllJobs()
    {
        $stmt = $this->db->query("SELECT * FROM jobs ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Récupère tous les jobs en cours d'exécution ou en attente
     * 
     * @return array Liste des jobs avec status running, queued ou pending
     */
    public function getRunningJobs()
    {
        // Tri: running en premier, puis stopping, puis queued/pending, par ordre chronologique (plus vieux en premier)
        $stmt = $this->db->query("
            SELECT * FROM jobs 
            WHERE status IN ('running', 'queued', 'pending', 'stopping')
            ORDER BY 
                CASE status 
                    WHEN 'running' THEN 1 
                    WHEN 'stopping' THEN 2 
                    WHEN 'queued' THEN 3 
                    WHEN 'pending' THEN 4 
                END,
                created_at ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public function isProcessRunning($pid)
    {
        if ($pid === null) {
            return false;
        }
        
        // Check if process exists using posix_kill (works in Docker/Linux)
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        
        return false;
    }

    public function cleanupStaleJobs()
    {
        // DISABLED: With Docker worker architecture, PID checks don't work across containers.
        // The worker is responsible for updating job status when crawls complete.
        // This method was causing false 'failed' status because PIDs from worker container
        // don't exist in the scouter container.
        return;
    }

    public function deleteJob($jobId)
    {
        // Delete logs first (foreign key constraint)
        $stmt = $this->db->prepare("DELETE FROM job_logs WHERE job_id = :job_id");
        $stmt->execute([':job_id' => $jobId]);
        
        // Delete job
        $stmt = $this->db->prepare("DELETE FROM jobs WHERE id = :job_id");
        $stmt->execute([':job_id' => $jobId]);
    }
}
