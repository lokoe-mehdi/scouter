<?php

namespace App\Notification;

use PDO;
use App\Database\PostgresDatabase;

/**
 * Émetteur unique des notifications, appelé en boucle par le worker PHP
 * (app/bin/worker.php). Plutôt que d'éparpiller des appels d'émission dans
 * chaque contrôleur / dans le worker Go, on réconcilie l'état de la table
 * `jobs` vers la table `notifications` :
 *
 *   - crawl démarré         → job command='crawl' passé en 'running'
 *   - crawl terminé (rapport prêt) → job 'precompute-reports:<id>' 'completed'
 *   - crawl échoué          → job command='crawl' 'failed' (pas 'stopped' = volontaire)
 *   - catégorisation IA      → job 'batch-categorize-project:<projectId>' 'completed'
 *   - rapport recalculé      → job 'precompute-reports-project:<projectId>' 'completed'
 *   - génération IA en masse → job 'bulk-ai-generate:<id>' 'completed'
 *
 * Chaque émission est idempotente : INSERT ... SELECT ... ON CONFLICT (event_key)
 * DO NOTHING. Le destinataire est TOUJOURS le propriétaire du projet
 * (projects.user_id) → un utilisateur n'est jamais notifié des crawls d'un
 * autre, même admin. Les jobs de suppression (delete-*) ne notifient pas.
 *
 * @package    Scouter
 * @subpackage Notification
 */
class NotificationReconciler
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? PostgresDatabase::getInstance()->getConnection();
    }

    public function run(): void
    {
        // Crawls : fenêtres courtes (récent) pour éviter de notifier du stale
        // après un long arrêt du worker. La dédup event_key fait le reste.
        $this->crawlStarted();
        $this->crawlFailed();
        $this->crawlFinished();

        // Jobs annexes.
        $this->projectJobFinished('batch-categorize-project:%', 'categorization_finished');
        $this->projectJobFinished('precompute-reports-project:%', 'report_finished');
        $this->bulkAiFinished();
    }

    private function exec(string $sql): void
    {
        try {
            $this->db->exec($sql);
        } catch (\Throwable $e) {
            // Un job au command malformé ne doit jamais casser la boucle worker.
            error_log('[NotificationReconciler] ' . $e->getMessage());
        }
    }

    private function crawlStarted(): void
    {
        $this->exec("
            INSERT INTO notifications
                (user_id, type, action, project_id, crawl_id, job_id, domain, project_name, project_dir, event_key)
            SELECT p.user_id, 'crawl_started', 'logs', c.project_id, c.id, j.id, c.domain, p.name, c.path,
                   'crawl_started:' || j.id
            FROM jobs j
            JOIN crawls c   ON c.path = j.project_dir
            JOIN projects p ON p.id = c.project_id AND p.deleted_at IS NULL
            WHERE j.command = 'crawl' AND j.status = 'running'
              AND j.started_at > NOW() - INTERVAL '15 minutes'
            ON CONFLICT (event_key) DO NOTHING
        ");
    }

    private function crawlFailed(): void
    {
        $this->exec("
            INSERT INTO notifications
                (user_id, type, action, project_id, crawl_id, job_id, domain, project_name, project_dir, event_key)
            SELECT p.user_id, 'crawl_failed', 'logs', c.project_id, c.id, j.id, c.domain, p.name, c.path,
                   'crawl_failed:' || j.id
            FROM jobs j
            JOIN crawls c   ON c.path = j.project_dir
            JOIN projects p ON p.id = c.project_id AND p.deleted_at IS NULL
            -- Le crawler Go marque l'échec via SetError (status='failed') sans
            -- forcément renseigner finished_at, et une resync peut donner 'error'.
            -- On ancre donc la fenêtre de fraîcheur sur finished_at OU started_at.
            WHERE j.command = 'crawl' AND j.status IN ('failed', 'error')
              AND COALESCE(j.finished_at, j.started_at) > NOW() - INTERVAL '6 hours'
            ON CONFLICT (event_key) DO NOTHING
        ");
    }

    /**
     * Le rapport n'est réellement disponible qu'à la fin du précalcul, déclenché
     * par le crawler Go en fin de crawl ('precompute-reports:<crawlId>'). On
     * notifie donc ici, pas à la fin du crawl lui-même. Clé sur le crawl_id pour
     * ne notifier qu'une fois même si le précalcul est relancé.
     */
    private function crawlFinished(): void
    {
        $this->exec("
            INSERT INTO notifications
                (user_id, type, action, project_id, crawl_id, job_id, domain, project_name, project_dir, event_key)
            SELECT p.user_id, 'crawl_finished', 'dashboard', c.project_id, c.id, j.id, c.domain, p.name, c.path,
                   'crawl_finished:' || c.id
            FROM jobs j
            JOIN crawls c   ON c.id = CAST(split_part(j.command, ':', 2) AS INTEGER)
            JOIN projects p ON p.id = c.project_id AND p.deleted_at IS NULL
            WHERE j.command LIKE 'precompute-reports:%' AND j.status = 'completed'
              AND j.finished_at > NOW() - INTERVAL '30 minutes'
            ON CONFLICT (event_key) DO NOTHING
        ");
    }

    /**
     * Jobs dont le projet est identifié par l'id dans la commande
     * ('<command>:<projectId>'). Lien vers la page projet.
     */
    private function projectJobFinished(string $commandLike, string $type): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO notifications
                (user_id, type, action, project_id, crawl_id, job_id, domain, project_name, project_dir, event_key)
            SELECT p.user_id, :type, 'project', p.id, NULL, j.id, NULL, p.name, j.project_dir,
                   :type2 || ':' || j.id
            FROM jobs j
            JOIN projects p ON p.id = CAST(split_part(j.command, ':', 2) AS INTEGER) AND p.deleted_at IS NULL
            WHERE j.command LIKE :cmd AND j.status = 'completed'
              AND j.finished_at > NOW() - INTERVAL '30 minutes'
            ON CONFLICT (event_key) DO NOTHING
        ");
        try {
            $stmt->execute([':type' => $type, ':type2' => $type, ':cmd' => $commandLike]);
        } catch (\Throwable $e) {
            error_log('[NotificationReconciler] ' . $e->getMessage());
        }
    }

    /**
     * 'bulk-ai-generate:<bulkJobId>' — l'id de la commande n'est PAS un projet,
     * on résout donc le projet via le répertoire du crawl.
     */
    private function bulkAiFinished(): void
    {
        $this->exec("
            INSERT INTO notifications
                (user_id, type, action, project_id, crawl_id, job_id, domain, project_name, project_dir, event_key)
            SELECT p.user_id, 'bulk_ai_finished', 'project', c.project_id, NULL, j.id, c.domain, p.name, j.project_dir,
                   'bulk_ai_finished:' || j.id
            FROM jobs j
            JOIN crawls c   ON c.path = j.project_dir
            JOIN projects p ON p.id = c.project_id AND p.deleted_at IS NULL
            WHERE j.command LIKE 'bulk-ai-generate:%' AND j.status = 'completed'
              AND j.finished_at > NOW() - INTERVAL '30 minutes'
            ON CONFLICT (event_key) DO NOTHING
        ");
    }
}
