<?php
/**
 * Crawl Scheduler
 *
 * Runs every minute via cron. Checks crawl_schedules for due schedules,
 * creates crawl+job records for workers to pick up.
 *
 * Uses next_run_at approach: no minute-matching, no missed windows.
 * If cron is late, the schedule is caught up on the next pass.
 */

require_once __DIR__ . '/../Database/PostgresDatabase.php';

use App\Database\PostgresDatabase;

echo "[Scheduler] " . date('Y-m-d H:i:s') . " — checking schedules\n";

try {
    $db = PostgresDatabase::getInstance()->getConnection();
} catch (Exception $e) {
    echo "[Scheduler] DB connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Find all enabled schedules that are due
$stmt = $db->query("
    SELECT cs.*, p.name AS project_domain
    FROM crawl_schedules cs
    JOIN projects p ON p.id = cs.project_id
    WHERE cs.enabled = TRUE
      AND cs.next_run_at IS NOT NULL
      AND cs.next_run_at <= NOW()
");
$dueSchedules = $stmt->fetchAll(PDO::FETCH_OBJ);

if (empty($dueSchedules)) {
    echo "[Scheduler] No schedules due\n";
    exit(0);
}

echo "[Scheduler] " . count($dueSchedules) . " schedule(s) due\n";

foreach ($dueSchedules as $schedule) {
    $pid = $schedule->project_id;
    $domain = $schedule->project_domain;

    // Anti-double-fire: CAS update last_triggered_at + advance next_run_at
    $nextRun = computeNextRun($schedule);
    $stmt = $db->prepare("
        UPDATE crawl_schedules
        SET last_triggered_at = NOW(),
            next_run_at = :next_run,
            updated_at = NOW()
        WHERE id = :id
          AND (last_triggered_at IS NULL OR last_triggered_at < NOW() - INTERVAL '30 seconds')
        RETURNING id
    ");
    $stmt->execute([':id' => $schedule->id, ':next_run' => $nextRun]);
    if (!$stmt->fetch()) {
        echo "[Scheduler] Project #{$pid} ({$domain}): already triggered, skipping\n";
        continue;
    }

    // Check no scheduled crawl already running for this project
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM crawls
        WHERE project_id = :pid AND scheduled = true
          AND status IN ('pending', 'queued', 'running')
    ");
    $stmt->execute([':pid' => $pid]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo "[Scheduler] Project #{$pid} ({$domain}): scheduled crawl already in progress, skipping\n";
        continue;
    }

    // Create crawl record
    $config = $schedule->crawl_config;
    $depthMax = $schedule->depth_max ?? 30;
    $crawlType = $schedule->crawl_type ?? 'spider';
    $newPath = $domain . '-' . date('Ymd') . '-' . date('His');

    $stmt = $db->prepare("
        INSERT INTO crawls (domain, path, status, config, depth_max, crawl_type, in_progress, project_id, scheduled, started_at)
        VALUES (:domain, :path, 'queued', :config, :depth_max, :crawl_type, 1, :project_id, true, NOW())
        RETURNING id
    ");
    $stmt->execute([
        ':domain' => $domain,
        ':path' => $newPath,
        ':config' => $config, // already JSONB string from schedule
        ':depth_max' => $depthMax,
        ':crawl_type' => $crawlType,
        ':project_id' => $pid,
    ]);
    $crawlId = (int)$stmt->fetchColumn();

    // Create partitions for the new crawl
    $db->exec("SELECT create_crawl_partitions({$crawlId})");

    // Copy categorization config if present
    if (!empty($schedule->categorization_config)) {
        $stmt = $db->prepare("
            INSERT INTO categorization_config (crawl_id, config)
            VALUES (:crawl_id, :config)
            ON CONFLICT (crawl_id) DO UPDATE SET config = EXCLUDED.config
        ");
        $stmt->execute([':crawl_id' => $crawlId, ':config' => $schedule->categorization_config]);
    }

    // Create job for worker to pick up
    $stmt = $db->prepare("
        INSERT INTO jobs (project_dir, project_name, command, status, created_at)
        VALUES (:dir, :name, 'crawl', 'queued', NOW())
        RETURNING id
    ");
    $stmt->execute([':dir' => $newPath, ':name' => $domain]);
    $jobId = (int)$stmt->fetchColumn();

    // Add log entry
    $stmt = $db->prepare("
        INSERT INTO job_logs (job_id, message, level, created_at)
        VALUES (:job_id, :msg, 'info', NOW())
    ");
    $stmt->execute([
        ':job_id' => $jobId,
        ':msg' => "Scheduled crawl created (schedule #{$schedule->id}, frequency: {$schedule->frequency})"
    ]);

    echo "[Scheduler] Project #{$pid} ({$domain}): crawl #{$crawlId} created, job #{$jobId} queued (next: {$nextRun})\n";
}

echo "[Scheduler] Done\n";

/**
 * Compute the next run timestamp based on schedule frequency.
 */
function computeNextRun(object $schedule): string
{
    $now = new DateTime('now');
    $freq = $schedule->frequency;

    if ($freq === 'minute') {
        $next = clone $now;
        $next->modify('+1 minute');
        return $next->format('Y-m-d H:i:00');
    }

    $hour = (int)$schedule->hour;
    $minute = (int)$schedule->minute;

    if ($freq === 'daily') {
        $next = clone $now;
        $next->setTime($hour, $minute, 0);
        if ($next <= $now) {
            $next->modify('+1 day');
        }
        return $next->format('Y-m-d H:i:00');
    }

    if ($freq === 'weekly') {
        $dayMap = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
                   'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

        // Parse PostgreSQL text array {mon,wed,fri}
        $daysRaw = trim($schedule->days_of_week ?? '{mon}', '{}');
        $days = array_map('trim', explode(',', $daysRaw));

        $candidates = [];
        foreach ($days as $day) {
            $dayName = $dayMap[$day] ?? null;
            if (!$dayName) continue;

            // Try this week
            $candidate = new DateTime("this week {$dayName}");
            $candidate->setTime($hour, $minute, 0);
            if ($candidate <= $now) {
                // Try next week
                $candidate = new DateTime("next {$dayName}");
                $candidate->setTime($hour, $minute, 0);
            }
            $candidates[] = $candidate;
        }

        if (empty($candidates)) {
            // Fallback: next Monday
            $next = new DateTime('next Monday');
            $next->setTime($hour, $minute, 0);
            return $next->format('Y-m-d H:i:00');
        }

        // Return the earliest candidate
        usort($candidates, fn($a, $b) => $a <=> $b);
        return $candidates[0]->format('Y-m-d H:i:00');
    }

    if ($freq === 'monthly') {
        $dayOfMonth = max(1, min(28, (int)$schedule->day_of_month));
        $next = clone $now;
        $next->setDate((int)$next->format('Y'), (int)$next->format('m'), $dayOfMonth);
        $next->setTime($hour, $minute, 0);
        if ($next <= $now) {
            $next->modify('+1 month');
            $next->setDate((int)$next->format('Y'), (int)$next->format('m'), $dayOfMonth);
        }
        return $next->format('Y-m-d H:i:00');
    }

    // Fallback
    $next = clone $now;
    $next->modify('+1 day');
    return $next->format('Y-m-d H:i:00');
}
