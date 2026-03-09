<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\PostgresDatabase;
use App\Job\JobManager;

// Configuration
$stateFile = __DIR__ . '/../logs/watchdog_state.json'; // Fichier pour stocker l'état précédent
$inactivityThreshold = '1 hour'; // Seuil d'inactivité avant de tuer
$dryRun = false;

// Récupération des options CLI
$options = getopt("", ["dry-run"]);
if (isset($options['dry-run'])) {
    $dryRun = true;
}

echo "=== Scouter Smart Watchdog ===\n";
echo "Checking for stuck jobs (no progress change)...\n";

try {
    $db = PostgresDatabase::getInstance()->getConnection();
    $jobManager = new JobManager();
    
    // 1. Charger l'état précédent
    $previousState = [];
    if (file_exists($stateFile)) {
        $previousState = json_decode(file_get_contents($stateFile), true) ?: [];
    }
    $newState = [];

    // 2. Récupérer tous les jobs en cours avec le vrai progrès depuis crawls
    // On prend le crawl le plus récent (id DESC) pour éviter de matcher un ancien crawl
    $stmt = $db->query("
        SELECT j.id, j.project_dir, j.progress, j.started_at,
               COALESCE(c.crawled, 0) as crawl_progress,
               c.id as crawl_id
        FROM jobs j
        LEFT JOIN LATERAL (
            SELECT id, crawled FROM crawls
            WHERE path = j.project_dir
            ORDER BY id DESC LIMIT 1
        ) c ON true
        WHERE j.status = 'running'
    ");
    $runningJobs = $stmt->fetchAll(PDO::FETCH_OBJ);

    if (count($runningJobs) === 0) {
        echo "✅ No running jobs.\n";
        // Nettoyer le fichier d'état s'il n'y a plus rien
        if (file_exists($stateFile)) unlink($stateFile);
        exit(0);
    }

    foreach ($runningJobs as $job) {
        $jobId = $job->id;
        // Use crawl progress (crawls.crawled) as source of truth, fallback to jobs.progress
        $currentProgress = max((int)$job->progress, (int)$job->crawl_progress);

        echo "🔍 Checking Job #{$jobId} ({$job->project_dir})...\n";
        echo "   Current Progress: $currentProgress URLs (job: {$job->progress}, crawl: {$job->crawl_progress})\n";

        // Si on a déjà vu ce job la dernière fois
        if (isset($previousState[$jobId])) {
            $lastCheckTime = $previousState[$jobId]['timestamp'];
            $lastProgress = $previousState[$jobId]['progress'];
            
            // Calculer le temps écoulé depuis le dernier check
            $timeDiff = time() - $lastCheckTime;
            $hoursElapsed = round($timeDiff / 3600, 1);
            
            echo "   Last check was $hoursElapsed hours ago (Progress was: $lastProgress)\n";

            // VÉRIFICATION : Est-ce que ça a bougé ?
            if ($currentProgress === $lastProgress) {
                // Ça n'a pas bougé !
                echo "   ⚠️ STUCK DETECTED: Progress hasn't changed ($currentProgress) since last check.\n";
                
                if (!$dryRun) {
                    echo "   🔪 Killing stuck job...\n";

                    $jobManager->updateJobStatus($job->id, 'failed');
                    $jobManager->setJobError($job->id, "WATCHDOG: Job killed because stuck at $currentProgress URLs for > 1 check cycle");
                    $jobManager->addLog($job->id, "💀 WATCHDOG: Job killed. No progress detected since last check.", 'error');

                    // Mettre à jour les stats du crawl pour que l'UI affiche les vrais chiffres
                    $crawlId = $job->crawl_id ?? null;
                    if ($crawlId) {
                        $db->prepare("
                            UPDATE crawls SET
                                urls = (SELECT COUNT(*) FROM pages WHERE crawl_id = :cid1),
                                crawled = (SELECT COUNT(*) FROM pages WHERE crawl_id = :cid2 AND crawled = true),
                                status = 'failed',
                                finished_at = CURRENT_TIMESTAMP,
                                in_progress = 0
                            WHERE id = :cid3
                        ")->execute([':cid1' => $crawlId, ':cid2' => $crawlId, ':cid3' => $crawlId]);
                        echo "   📊 Crawl #$crawlId stats updated.\n";
                    }

                    echo "   ✅ Job killed.\n";
                    continue; // Ne pas l'ajouter au newState
                } else {
                    echo "   [DRY RUN] Would kill this job.\n";
                }
            } else {
                echo "   ✅ Moving! ($lastProgress -> $currentProgress). Job is healthy.\n";
            }
        } else {
            echo "   🆕 First time seeing this job (or watchdog restart). Recording baseline.\n";
        }

        // Sauvegarder l'état actuel pour la prochaine fois
        $newState[$jobId] = [
            'progress' => $currentProgress,
            'timestamp' => time(),
            'project_dir' => $job->project_dir
        ];
    }

    // 3. Sauvegarder le nouvel état
    if (!$dryRun) {
        file_put_contents($stateFile, json_encode($newState, JSON_PRETTY_PRINT));
        echo "💾 State saved to $stateFile\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Done.\n";
