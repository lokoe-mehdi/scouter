<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\PostgresDatabase;
use App\Job\JobManager;

// Configuration
$stateFile = __DIR__ . '/../logs/watchdog_state.json'; // Fichier pour stocker l'état précédent

// Un job n'est tué QUE si sa progression (crawls.crawled) est restée strictement
// identique pendant au moins $inactivitySeconds de temps réel écoulé. On ne se
// fie plus à « deux passages consécutifs du watchdog avec le même compteur » :
// avec un cron horaire ça tuait des crawls sains dont le compteur plafonnait
// brièvement ou qui venaient d'être (re)lancés. On mémorise donc l'instant du
// dernier changement de progression et on ne tue qu'après une vraie inactivité.
$inactivitySeconds = 2 * 3600; // 2 h sans le moindre changement de progression
// Période de grâce après le démarrage du job : on ne juge jamais un job qui vient
// de démarrer (le temps qu'il amorce le crawl et fasse grimper le compteur).
$graceSeconds = 15 * 60; // 15 min
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

    $now = time();

    foreach ($runningJobs as $job) {
        $jobId = $job->id;
        // Use crawl progress (crawls.crawled) as source of truth, fallback to jobs.progress
        $currentProgress = max((int)$job->progress, (int)$job->crawl_progress);

        echo "🔍 Checking Job #{$jobId} ({$job->project_dir})...\n";
        echo "   Current Progress: $currentProgress URLs (job: {$job->progress}, crawl: {$job->crawl_progress})\n";

        // --- Période de grâce : on ne juge pas un job fraîchement (re)démarré. ---
        $startedTs = !empty($job->started_at) ? strtotime($job->started_at) : false;
        if ($startedTs !== false && ($now - $startedTs) < $graceSeconds) {
            $ageMin = round(($now - $startedTs) / 60, 1);
            echo "   🐣 Grace period ({$ageMin} min < " . ($graceSeconds / 60) . " min since start). Skipping.\n";
            // On (ré)amorce la baseline avec l'instant courant comme dernier changement,
            // pour ne pas hériter d'un last_change périmé une fois la grâce terminée.
            $newState[$jobId] = [
                'progress'    => $currentProgress,
                'timestamp'   => $now,
                'last_change' => $now,
                'project_dir' => $job->project_dir,
            ];
            continue;
        }

        // --- Détection de blocage fondée sur la DURÉE RÉELLE d'inactivité. ---
        // last_change = dernier instant où la progression a effectivement bougé.
        if (isset($previousState[$jobId])) {
            $lastProgress = (int)($previousState[$jobId]['progress'] ?? 0);
            // Rétro-compat : anciens états sans 'last_change' -> on retombe sur 'timestamp'.
            $lastChange = (int)($previousState[$jobId]['last_change'] ?? $previousState[$jobId]['timestamp'] ?? $now);

            if ($currentProgress !== $lastProgress) {
                // La progression a bougé (dans un sens ou l'autre) -> job vivant, on réarme.
                echo "   ✅ Moving! ($lastProgress -> $currentProgress). Job is healthy.\n";
                $lastChange = $now;
            } else {
                // Compteur figé : on regarde DEPUIS COMBIEN DE TEMPS, pas juste « un cycle ».
                $stuckFor = $now - $lastChange;
                $stuckMin = round($stuckFor / 60, 1);
                echo "   ⏳ Progress unchanged ($currentProgress) for {$stuckMin} min (threshold " . round($inactivitySeconds / 60) . " min).\n";

                if ($stuckFor >= $inactivitySeconds) {
                    echo "   ⚠️ STUCK DETECTED: no progress for {$stuckMin} min.\n";

                    if (!$dryRun) {
                        echo "   🔪 Killing stuck job...\n";

                        $jobManager->updateJobStatus($job->id, 'failed');
                        $jobManager->setJobError($job->id, "WATCHDOG: Job killed because stuck at $currentProgress URLs for {$stuckMin} min");
                        $jobManager->addLog($job->id, "💀 WATCHDOG: Job killed. No progress for {$stuckMin} min.", 'error');

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
                    echo "   🙂 Within threshold — sparing the job (could be a slow depth, retries, or idle worker).\n";
                }
            }
        } else {
            echo "   🆕 First time seeing this job (or watchdog restart). Recording baseline.\n";
            $lastChange = $now;
        }

        // Sauvegarder l'état actuel pour la prochaine fois
        $newState[$jobId] = [
            'progress'    => $currentProgress,
            'timestamp'   => $now,
            'last_change' => $lastChange,
            'project_dir' => $job->project_dir,
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
