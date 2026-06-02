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
            WHERE status = 'running' AND command <> 'crawl'
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
        WHERE status = 'stopping' AND command <> 'crawl'
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

        // Émission des notifications utilisateur (cloche header) : réconcilie
        // l'état des jobs vers la table notifications. Isolé dans son propre
        // try/catch — une erreur ici ne doit jamais bloquer le traitement des jobs.
        try {
            (new \App\Notification\NotificationReconciler($db))->run();
            if ($pollCount % 50 === 0) {
                (new \App\Notification\NotificationManager($db))->prune();
            }
        } catch (\Throwable $e) {
            error_log('[Worker] notification reconcile failed: ' . $e->getMessage());
        }

        // Sweep des exports périmés (>24h) : supprime l'objet du blob store + la
        // ligne, pour que le CSV ne soit plus accessible. Throttlé, isolé.
        try {
            if ($pollCount % 50 === 0) {
                (new \App\Export\ExportService())->pruneExpired();
            }
        } catch (\Throwable $e) {
            error_log('[Worker] export prune failed: ' . $e->getMessage());
        }

        // Configuration timeout pour le polling :
        // - statement_timeout = 0 (pas de limite, car les checkpoints peuvent bloquer)
        // - lock_timeout = 60s (permissif pour plusieurs crawls simultanés)
        $db->exec("SET statement_timeout = '0'");
        $db->exec("SET lock_timeout = '60s'");
        
        $db->beginTransaction();

        // Atomic poll for a queued job
        // FOR UPDATE SKIP LOCKED ensures multiple workers don't grab the same job
        //
        // Le crawl est entièrement assuré par le worker Go (crawler-go) : le crawl
        // PHP a été retiré (cf. refacto.md §11). Le worker PHP ne prend donc JAMAIS
        // les jobs command='crawl' — il ne gère plus que delete / batch-categorize
        // / bulk-ai. Le worker Go claim symétriquement uniquement command='crawl'.
        $crawlFilter = " AND command <> 'crawl' ";
        $stmt = $db->query("
            SELECT * FROM jobs
            WHERE status = 'queued' $crawlFilter
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
                mkdir($logsDir, 0777, true);
            }
            chmod($logsDir, 0777);

            // Add start log
            $jobManager = new JobManager();
            $command = $job->command;
            $isBatchCategorize = strpos($command, 'batch-categorize-project:') === 0;
            $isBulkAiGenerate  = strpos($command, 'bulk-ai-generate:') === 0;
            $isDeleteJob = strpos($command, 'delete-crawl:') === 0 || strpos($command, 'delete-project:') === 0;
            $isPrecomputeProject = strpos($command, 'precompute-reports-project:') === 0;
            $isPrecompute = !$isPrecomputeProject && strpos($command, 'precompute-reports:') === 0;
            $isExport = strpos($command, 'export:') === 0;
            $isResume = ($command === 'resume');

            // Blob-store env (S3/local) forwarded to children that touch storage:
            // export jobs (write the CSV) and delete jobs (purge a crawl's HTML).
            // proc_open with an explicit $env does NOT inherit the parent's env.
            $storageEnv = array_filter([
                'STORAGE_PATH'         => getenv('STORAGE_PATH'),
                'S3_BUCKET'            => getenv('S3_BUCKET'),
                'S3_REGION'            => getenv('S3_REGION'),
                'S3_ENDPOINT'          => getenv('S3_ENDPOINT'),
                'S3_ACCESS_KEY_ID'     => getenv('S3_ACCESS_KEY_ID'),
                'S3_SECRET_ACCESS_KEY' => getenv('S3_SECRET_ACCESS_KEY'),
                'S3_PREFIX'            => getenv('S3_PREFIX'),
                'S3_USE_PATH_STYLE'    => getenv('S3_USE_PATH_STYLE'),
            ], fn($v) => $v !== false);

            if ($isResume) {
                $jobManager->addLog($job->id, "Worker $workerId resuming crawl", 'info');
                file_put_contents($logFile, "\n🔄 Reprise du crawl\n=== WORKER STARTED CRAWL ===\n", FILE_APPEND);
            } elseif ($isDeleteJob) {
                $jobManager->addLog($job->id, "Worker $workerId starting async deletion", 'info');
                file_put_contents($logFile, "\n🗑️ Async deletion\n=== WORKER STARTED JOB ===\n", FILE_APPEND);
            } elseif ($isBatchCategorize) {
                $jobManager->addLog($job->id, "Worker $workerId starting batch categorization", 'info');
                file_put_contents($logFile, "\n📂 Batch categorization\n=== WORKER STARTED JOB ===\n", FILE_APPEND);
            } elseif ($isBulkAiGenerate) {
                $jobManager->addLog($job->id, "Worker $workerId starting bulk AI generation", 'info');
                file_put_contents($logFile, "\n✨ Bulk AI generation\n=== WORKER STARTED JOB ===\n", FILE_APPEND);
            } elseif ($isPrecompute || $isPrecomputeProject) {
                $jobManager->addLog($job->id, "Worker $workerId starting report precompute", 'info');
                file_put_contents($logFile, "\n📊 Report precompute\n=== WORKER STARTED JOB ===\n", FILE_APPEND);
            } elseif ($isExport) {
                $jobManager->addLog($job->id, "Worker $workerId starting CSV export", 'info');
                file_put_contents($logFile, "\n⬇️ CSV export\n=== WORKER STARTED JOB ===\n", FILE_APPEND);
            } else {
                $jobManager->addLog($job->id, "Worker $workerId started processing", 'info');
                file_put_contents($logFile, "\n=== WORKER STARTED CRAWL ===\n", FILE_APPEND);
            }
            // Make log file writable by all (scouter runs as www-data, worker as root)
            @chmod($logFile, 0666);

            // Build command with proper environment
            $phpBin = '/usr/local/bin/php';
            $scouterScript = $basePath . '/scouter.php';

            // Use proc_open for proper blocking execution
            $descriptors = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['file', $logFile, 'a'],  // stdout -> log file
                2 => ['file', $logFile, 'a']   // stderr -> log file
            ];

            if ($isDeleteJob) {
                // Async deletion job
                $deleteModule = strpos($command, 'delete-crawl:') === 0 ? 'delete-crawl' : 'delete-project';
                echo "[Worker $workerId] Executing async deletion: $command\n";

                $env = array_merge([
                    'DATABASE_URL' => getenv('DATABASE_URL'),
                    'PATH' => getenv('PATH'),
                    'JOB_ID' => $job->id
                ], $storageEnv); // purges the crawl's HTML blobs → needs storage env

                $process = proc_open(
                    [$phpBin, $scouterScript, $deleteModule, $command],
                    $descriptors,
                    $pipes,
                    $basePath,
                    $env
                );
            } elseif ($isExport) {
                // CSV export job: regenerates the CSV (reads ClickHouse via ChPdo)
                // and uploads it to the blob store → needs CLICKHOUSE_* + S3/local env.
                echo "[Worker $workerId] Executing CSV export: $command\n";
                $env = array_merge(array_filter([
                    'DATABASE_URL'           => getenv('DATABASE_URL'),
                    'PATH'                   => getenv('PATH'),
                    'JOB_ID'                 => $job->id,
                    'CLICKHOUSE_URL'         => getenv('CLICKHOUSE_URL'),
                    'CLICKHOUSE_DB'          => getenv('CLICKHOUSE_DB'),
                    'CLICKHOUSE_USER'        => getenv('CLICKHOUSE_USER'),
                    'CLICKHOUSE_PASSWORD'    => getenv('CLICKHOUSE_PASSWORD'),
                    'CLICKHOUSE_QUERY_CACHE' => getenv('CLICKHOUSE_QUERY_CACHE'),
                ], fn($v) => $v !== false), $storageEnv);
                $process = proc_open(
                    [$phpBin, $scouterScript, 'export', $command],
                    $descriptors,
                    $pipes,
                    $basePath,
                    $env
                );
            } elseif ($isBatchCategorize) {
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
            } elseif ($isBulkAiGenerate) {
                // Bulk AI generation job
                echo "[Worker $workerId] Executing bulk AI generation: $command\n";
                // CRITICAL : proc_open with an explicit $env array does NOT inherit
                // the parent's environment — anything not listed here disappears in
                // the child. We must forward:
                //   - SCOUTER_ENCRYPTION_KEY → else the OpenRouter API key can't be
                //     decrypted (AppSettings::get returns null).
                //   - CLICKHOUSE_* → else ClickHouseDatabase::enabled() is false in
                //     the child, CrawlStore::usesClickHouse() returns false, and
                //     BulkGenerator writes results via the Postgres path. For a
                //     CH-backed crawl the pages live in ClickHouse, so that UPDATE
                //     hits 0 rows: the job completes "done" with 0 failures yet
                //     nothing is persisted anywhere (and context is built from an
                //     empty PG read). Same forwarding the precompute branch does.
                $env = array_filter([
                    'DATABASE_URL'           => getenv('DATABASE_URL'),
                    'PATH'                   => getenv('PATH'),
                    'JOB_ID'                 => $job->id,
                    'SCOUTER_ENCRYPTION_KEY' => getenv('SCOUTER_ENCRYPTION_KEY'),
                    'CLICKHOUSE_URL'         => getenv('CLICKHOUSE_URL'),
                    'CLICKHOUSE_DB'          => getenv('CLICKHOUSE_DB'),
                    'CLICKHOUSE_USER'        => getenv('CLICKHOUSE_USER'),
                    'CLICKHOUSE_PASSWORD'    => getenv('CLICKHOUSE_PASSWORD'),
                    'CLICKHOUSE_QUERY_CACHE' => getenv('CLICKHOUSE_QUERY_CACHE'),
                ], fn($v) => $v !== false);
                $process = proc_open(
                    [$phpBin, $scouterScript, 'bulk-ai-generate', $command],
                    $descriptors,
                    $pipes,
                    $basePath,
                    $env
                );
            } elseif ($isPrecompute || $isPrecomputeProject) {
                // Report precompute job. The report queries read ClickHouse via
                // ChPdo, so the CLICKHOUSE_* vars MUST be forwarded — proc_open with
                // an explicit $env does NOT inherit the parent's environment.
                echo "[Worker $workerId] Executing report precompute: $command\n";
                $precomputeModule = $isPrecomputeProject ? 'precompute-reports-project' : 'precompute-reports';
                $env = array_filter([
                    'DATABASE_URL'           => getenv('DATABASE_URL'),
                    'PATH'                   => getenv('PATH'),
                    'JOB_ID'                 => $job->id,
                    'CLICKHOUSE_URL'         => getenv('CLICKHOUSE_URL'),
                    'CLICKHOUSE_DB'          => getenv('CLICKHOUSE_DB'),
                    'CLICKHOUSE_USER'        => getenv('CLICKHOUSE_USER'),
                    'CLICKHOUSE_PASSWORD'    => getenv('CLICKHOUSE_PASSWORD'),
                    'CLICKHOUSE_QUERY_CACHE' => getenv('CLICKHOUSE_QUERY_CACHE'),
                ], fn($v) => $v !== false);
                $process = proc_open(
                    [$phpBin, $scouterScript, $precomputeModule, $command],
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
                // Failure - extract real error from log file
                echo "[Worker $workerId] Job #{$job->id} failed with code $exitCode\n";
                if ($currentStatus !== 'stopped') {
                    // Detect known exit codes
                    $errorDetail = '';
                    if ($exitCode === 137) {
                        $errorDetail = 'Process killed (OOM or SIGKILL) - out of memory';
                    } elseif ($exitCode === 139) {
                        $errorDetail = 'Segmentation fault (SIGSEGV)';
                    } elseif ($exitCode === 255) {
                        $errorDetail = 'PHP fatal error';
                    }

                    // Read last lines of log file for actual error message
                    if (file_exists($logFile) && filesize($logFile) > 0) {
                        $logTail = '';
                        $fp = fopen($logFile, 'r');
                        if ($fp) {
                            // Read last 2KB to find error
                            $size = filesize($logFile);
                            $readFrom = max(0, $size - 2048);
                            fseek($fp, $readFrom);
                            $logTail = fread($fp, 2048);
                            fclose($fp);
                        }
                        // Look for ERROR/FATAL patterns in log tail
                        if (preg_match('/(?:FATAL ERROR|ERROR|Fatal error|Uncaught .+?Exception):\s*(.+)/i', $logTail, $m)) {
                            $errorDetail = trim($m[0]);
                            // Limit length
                            if (strlen($errorDetail) > 500) {
                                $errorDetail = substr($errorDetail, 0, 500) . '...';
                            }
                        } elseif (empty($errorDetail)) {
                            // No pattern found, use last non-empty line
                            $lines = array_filter(explode("\n", trim($logTail)));
                            if (!empty($lines)) {
                                $lastLine = trim(end($lines));
                                // Clean ANSI codes
                                $lastLine = preg_replace('/\x1b\[[0-9;]*m/', '', $lastLine);
                                if (!empty($lastLine) && strlen($lastLine) > 5) {
                                    $errorDetail = "Last output: $lastLine";
                                }
                            }
                        }
                    }

                    $errorMsg = $errorDetail ?: "Process exited with code $exitCode";
                    $jobManager->updateJobStatus($job->id, 'failed');
                    $jobManager->setJobError($job->id, $errorMsg);
                    $jobManager->addLog($job->id, $errorMsg, 'error');
                    echo "[Worker $workerId] Error: $errorMsg\n";

                    // An OOM-killed export subprocess (exit 137 / SIGKILL) never runs
                    // its own catch, so the export row stays 'running' and spins
                    // forever in the UI. Reconcile it here from the parent worker.
                    if ($isExport) {
                        try {
                            $flipped = (new \App\Export\ExportService())->failByJob((int)$job->id, $errorMsg);
                            if ($flipped > 0) {
                                echo "[Worker $workerId] Marked $flipped stuck export(s) for job #{$job->id} as failed\n";
                            }
                        } catch (\Throwable $e) {
                            echo "[Worker $workerId] Warning: could not reconcile export for job #{$job->id}: " . $e->getMessage() . "\n";
                        }
                    }
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
