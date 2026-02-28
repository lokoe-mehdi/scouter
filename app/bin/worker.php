<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use App\Job\JobManager;
use App\Database\CrawlRepository;

// Configuration
$workerId = getenv('HOSTNAME') ?: 'worker-' . getmypid();
$maxConcurrentCurl = getenv('MAX_CONCURRENT_CURL') ?: 10;
$maxConcurrentChrome = getenv('MAX_CONCURRENT_CHROME') ?: 5;
$rendererUrl = getenv('RENDERER_URL') ?: 'http://renderer:3000';

echo "[Worker $workerId] Starting up...\n";
echo "[Worker $workerId] Config: Curl=$maxConcurrentCurl, Chrome=$maxConcurrentChrome\n";

// DB Connection
try {
    $db = PostgresDatabase::getInstance()->getConnection();
} catch (Exception $e) {
    echo "[Worker $workerId] FATAL: Could not connect to database. " . $e->getMessage() . "\n";
    exit(1);
}

// Recovery: Re-queue any orphaned 'running' jobs from a previous crash
// This ensures crawls can continue after Docker restarts
try {
    // 1. Re-queue running jobs (will be picked up again)
    // Utilisation de FOR UPDATE SKIP LOCKED pour éviter que tous les workers ne traitent le même orphelin
    $orphanStmt = $db->query("
        UPDATE jobs 
        SET status = 'queued', started_at = NULL, pid = NULL
        WHERE id IN (
            SELECT id FROM jobs 
            WHERE status = 'running' 
            FOR UPDATE SKIP LOCKED
        )
        RETURNING id, project_dir
    ");
    $orphans = $orphanStmt->fetchAll(PDO::FETCH_OBJ);
    
    if (count($orphans) > 0) {
        echo "[Worker $workerId] Recovered " . count($orphans) . " orphaned running job(s):\n";
        $jobManager = new JobManager();
        $crawlRepo = new CrawlRepository();
        
        foreach ($orphans as $orphan) {
            echo "  - Job #{$orphan->id} ({$orphan->project_dir}) -> re-queued\n";
            $jobManager->addLog($orphan->id, "🔄 Job recovered after restart - re-queued", 'warning');
            
            // Also update crawl status to queued
            $crawlStmt = $db->prepare("SELECT id FROM crawls WHERE path = :path");
            $crawlStmt->execute([':path' => $orphan->project_dir]);
            $crawlRecord = $crawlStmt->fetch(PDO::FETCH_OBJ);
            if ($crawlRecord) {
                $crawlRepo->update($crawlRecord->id, ['status' => 'queued']);
            }
        }
    }
    
    // 2. Mark 'stopping' jobs as 'stopped' (they were being stopped when crash happened)
    $stoppingStmt = $db->query("
        UPDATE jobs 
        SET status = 'stopped', finished_at = NOW()
        WHERE status = 'stopping'
        RETURNING id, project_dir
    ");
    $stoppingJobs = $stoppingStmt->fetchAll(PDO::FETCH_OBJ);
    
    if (count($stoppingJobs) > 0) {
        echo "[Worker $workerId] Completed " . count($stoppingJobs) . " interrupted stop(s):\n";
        $jobManager = $jobManager ?? new JobManager();
        $crawlRepo = $crawlRepo ?? new CrawlRepository();
        
        foreach ($stoppingJobs as $stoppingJob) {
            echo "  - Job #{$stoppingJob->id} ({$stoppingJob->project_dir}) -> stopped\n";
            $jobManager->addLog($stoppingJob->id, "⏹️ Stop completed after restart", 'warning');
            
            // Also update crawl status to stopped
            $crawlStmt = $db->prepare("SELECT id FROM crawls WHERE path = :path");
            $crawlStmt->execute([':path' => $stoppingJob->project_dir]);
            $crawlRecord = $crawlStmt->fetch(PDO::FETCH_OBJ);
            if ($crawlRecord) {
                $crawlRepo->update($crawlRecord->id, ['status' => 'stopped', 'in_progress' => 0]);
            }
        }
    }
} catch (Exception $e) {
    echo "[Worker $workerId] Warning: Could not check for orphaned jobs: " . $e->getMessage() . "\n";
}

// Handle shutdown signals
$running = true;
pcntl_signal(SIGTERM, function () use (&$running) {
    echo "\n[Worker] Received SIGTERM, shutting down gracefully...\n";
    $running = false;
});
pcntl_signal(SIGINT, function () use (&$running) {
    echo "\n[Worker] Received SIGINT, shutting down gracefully...\n";
    $running = false;
});

// ===========================================
// CONFIGURATION ROBUSTESSE POLLING
// ===========================================
$consecutiveErrors = 0;
$maxConsecutiveErrors = 10;  // Après 10 erreurs consécutives, restart worker
$pollCount = 0;
$heartbeatInterval = 100;    // Log "alive" tous les 100 polls (~3-4 minutes)
$lastHeartbeat = time();

/**
 * Vérifie si la connexion DB est toujours active
 */
function isConnectionAlive($pdo) {
    try {
        $pdo->query("SELECT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Tente de se reconnecter à la base de données
 */
function reconnectDatabase($workerId) {
    echo "[Worker $workerId] Attempting database reconnection...\n";
    try {
        PostgresDatabase::resetInstance();
        $newDb = PostgresDatabase::getInstance()->getConnection();
        echo "[Worker $workerId] ✓ Reconnected successfully\n";
        return $newDb;
    } catch (Exception $e) {
        echo "[Worker $workerId] ✗ Reconnection failed: " . $e->getMessage() . "\n";
        return null;
    }
}

// Main Loop
while ($running) {
    // Process signals
    pcntl_signal_dispatch();
    
    $pollCount++;
    
    // Heartbeat logging (tous les ~100 polls = 3-4 minutes)
    if ($pollCount % $heartbeatInterval === 0) {
        $uptime = time() - $lastHeartbeat;
        echo "[Worker $workerId] ♥ Alive - $pollCount polls, $consecutiveErrors errors, uptime {$uptime}s since last heartbeat\n";
        $lastHeartbeat = time();
    }

    try {
        // Vérifier que la connexion est toujours active
        if (!isConnectionAlive($db)) {
            echo "[Worker $workerId] Connection lost, reconnecting...\n";
            $db = reconnectDatabase($workerId);
            if ($db === null) {
                $consecutiveErrors++;
                sleep(5);
                continue;
            }
        }
        
        // Configuration timeout pour le polling :
        // - statement_timeout = 0 (pas de limite, car les checkpoints peuvent bloquer)
        // - lock_timeout = 60s (permissif pour plusieurs crawls simultanés)
        $db->exec("SET statement_timeout = '0'");
        $db->exec("SET lock_timeout = '60s'");
        
        $db->beginTransaction();

        // Atomic poll for a queued job
        // FOR UPDATE SKIP LOCKED ensures multiple workers don't grab the same job
        $stmt = $db->query("
            SELECT * FROM jobs 
            WHERE status = 'queued' 
            ORDER BY created_at ASC 
            LIMIT 1 
            FOR UPDATE SKIP LOCKED
        ");
        
        $job = $stmt->fetch(PDO::FETCH_OBJ);
        
        // Réactiver les timeouts normaux pour le reste des opérations
        $db->exec("SET statement_timeout = '120s'");
        $db->exec("SET lock_timeout = '60s'");
        
        // Reset compteur d'erreurs si on arrive ici (succès)
        $consecutiveErrors = 0;

        if ($job) {
            echo "[Worker $workerId] Picked up job #{$job->id} (Project: {$job->project_dir})\n";

            // Mark as running
            $updateStmt = $db->prepare("
                UPDATE jobs 
                SET status = 'running', 
                    started_at = NOW(), 
                    pid = :pid 
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':pid' => getmypid(), // Worker PID (container PID)
                ':id' => $job->id
            ]);

            $db->commit(); // Commit the 'running' status so UI sees it

            // Prepare execution environment
            $projectDir = $job->project_dir;
            $basePath = dirname(__DIR__, 2); // /var/www/html usually
            
            // Log file management
            $logFile = $basePath . "/logs/" . $projectDir . ".log";
            $logsDir = dirname($logFile);
            if (!is_dir($logsDir)) {
                mkdir($logsDir, 0755, true);
            }

            // Add start log
            $jobManager = new JobManager();
            $command = $job->command;
            $isBatchCategorize = strpos($command, 'batch-categorize-project:') === 0;
            $isResume = ($command === 'resume');

            if ($isResume) {
                $jobManager->addLog($job->id, "Worker $workerId resuming crawl", 'info');
                file_put_contents($logFile, "\n🔄 Reprise du crawl\n=== WORKER STARTED CRAWL ===\n", FILE_APPEND);
            } elseif ($isBatchCategorize) {
                $jobManager->addLog($job->id, "Worker $workerId starting batch categorization", 'info');
                file_put_contents($logFile, "\n📂 Batch categorization\n=== WORKER STARTED JOB ===\n", FILE_APPEND);
            } else {
                $jobManager->addLog($job->id, "Worker $workerId started processing", 'info');
                file_put_contents($logFile, "\n=== WORKER STARTED CRAWL ===\n", FILE_APPEND);
            }

            // Build command with proper environment
            $phpBin = '/usr/local/bin/php';
            $scouterScript = $basePath . '/scouter.php';

            // Use proc_open for proper blocking execution
            $descriptors = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['file', $logFile, 'a'],  // stdout -> log file
                2 => ['file', $logFile, 'a']   // stderr -> log file
            ];

            if ($isBatchCategorize) {
                // Batch categorization job
                echo "[Worker $workerId] Executing batch categorization: $command\n";

                // Set environment variables for the child process
                $env = [
                    'DATABASE_URL' => getenv('DATABASE_URL'),
                    'PATH' => getenv('PATH'),
                    'JOB_ID' => $job->id  // Pass job ID for progress tracking
                ];

                $process = proc_open(
                    [$phpBin, $scouterScript, 'batch-categorize-project', $command],
                    $descriptors,
                    $pipes,
                    $basePath,
                    $env
                );
            } else {
                // Regular crawl job - update crawl status and create partitions
                $crawlRepo = new CrawlRepository();
                $crawlStmt = $db->prepare("SELECT id FROM crawls WHERE path = :path");
                $crawlStmt->execute([':path' => $projectDir]);
                $crawlRecord = $crawlStmt->fetch(PDO::FETCH_OBJ);
                if ($crawlRecord) {
                    $crawlRepo->update($crawlRecord->id, ['status' => 'running']);

                    // IMPORTANT: Créer les partitions ICI dans le worker, AVANT de lancer le crawl
                    // Cela évite les deadlocks entre CREATE PARTITION et UPDATE pages
                    // L'advisory lock dans create_crawl_partitions sérialise les créations
                    echo "[Worker $workerId] Creating partitions for crawl #{$crawlRecord->id}...\n";
                    try {
                        $crawlDb = new CrawlDatabase($crawlRecord->id, []);
                        $crawlDb->createPartitions();
                        echo "[Worker $workerId] Partitions created successfully\n";
                    } catch (\Exception $e) {
                        echo "[Worker $workerId] Warning: Partition creation failed: " . $e->getMessage() . "\n";
                        // On continue quand même, le Crawler réessaiera
                    }
                }

                echo "[Worker $workerId] Executing crawl for: $projectDir\n";

                // Set environment variables for the child process
                $env = [
                    'MAX_CONCURRENT_CURL' => $maxConcurrentCurl,
                    'MAX_CONCURRENT_CHROME' => $maxConcurrentChrome,
                    'RENDERER_URL' => $rendererUrl,
                    'DATABASE_URL' => getenv('DATABASE_URL'),
                    'PATH' => getenv('PATH'),
                    'PARTITIONS_CREATED' => '1'  // Indique au Crawler que les partitions sont déjà créées
                ];

                $process = proc_open(
                    [$phpBin, $scouterScript, 'crawl', $projectDir],
                    $descriptors,
                    $pipes,
                    $basePath,
                    $env
                );
            }

            if (is_resource($process)) {
                // Close stdin
                if (isset($pipes[0])) {
                    fclose($pipes[0]);
                }

                // WAIT for process to complete (THIS IS THE KEY!)
                $exitCode = proc_close($process);
                
                echo "[Worker $workerId] Crawl finished with exit code: $exitCode\n";
            } else {
                $exitCode = 1;
                echo "[Worker $workerId] Failed to start process\n";
            }

            // Refresh job status from DB (in case it was stopped by user during execution)
            $checkStmt = $db->prepare("SELECT status FROM jobs WHERE id = :id");
            $checkStmt->execute([':id' => $job->id]);
            $currentStatus = $checkStmt->fetchColumn();
            
            echo "[Worker $workerId] Job #{$job->id} - DB status: '$currentStatus', exitCode: $exitCode\n";
            flush();

            if ($exitCode === 0) {
                // Success - check if it was stopped or completed normally
                if ($currentStatus === 'stopping') {
                    $jobManager->updateJobStatus($job->id, 'stopped');
                    $jobManager->addLog($job->id, "Crawl stopped by user", 'warning');
                    echo "[Worker $workerId] Job #{$job->id} stopped by user\n";
                } else if ($currentStatus === 'running') {
                    $jobManager->updateJobStatus($job->id, 'completed');
                    $jobManager->addLog($job->id, "Worker completed job successfully", 'success');
                    echo "[Worker $workerId] Job #{$job->id} completed successfully\n";
                }
                // If already 'stopped' or 'completed', don't change
            } else {
                // Failure
                echo "[Worker $workerId] Job #{$job->id} failed with code $exitCode\n";
                if ($currentStatus !== 'stopped') {
                    $jobManager->updateJobStatus($job->id, 'failed');
                    $jobManager->addLog($job->id, "Process exited with error code $exitCode", 'error');
                }
            }

        } else {
            // No job found
            $db->commit(); // Release transaction
            sleep(2); // Wait before next poll
        }

    } catch (Exception $e) {
        $consecutiveErrors++;
        
        if ($db->inTransaction()) {
            try {
                $db->rollBack();
            } catch (Exception $rollbackEx) {
                // Ignore rollback errors
            }
        }
        
        $errorMsg = $e->getMessage();
        echo "[Worker $workerId] Error ($consecutiveErrors/$maxConsecutiveErrors): $errorMsg\n";
        
        // Vérifier si trop d'erreurs consécutives
        if ($consecutiveErrors >= $maxConsecutiveErrors) {
            echo "[Worker $workerId] ⚠ Too many consecutive errors, restarting worker...\n";
            // Exit avec code 1 pour que Docker restart le container
            exit(1);
        }
        
        // Si c'est un timeout (57014), lock timeout (55P03) ou erreur connexion, reconnecter
        $needsReconnect = (
            strpos($errorMsg, '57014') !== false ||  // statement_timeout
            strpos($errorMsg, '55P03') !== false ||  // lock_timeout
            strpos($errorMsg, 'server closed') !== false ||
            strpos($errorMsg, 'connection') !== false ||
            strpos($errorMsg, 'gone away') !== false
        );
        
        if ($needsReconnect) {
            echo "[Worker $workerId] Connection issue detected, reconnecting...\n";
            $db = reconnectDatabase($workerId);
            if ($db === null) {
                // Attendre plus longtemps si reconnexion échouée
                sleep(10);
            }
        }
        
        // Backoff exponentiel : 2s, 4s, 8s, 16s, max 30s
        $sleepTime = min(30, pow(2, min($consecutiveErrors, 5)));
        echo "[Worker $workerId] Waiting {$sleepTime}s before retry...\n";
        sleep($sleepTime);
    }
}

echo "[Worker $workerId] Shutdown complete.\n";
