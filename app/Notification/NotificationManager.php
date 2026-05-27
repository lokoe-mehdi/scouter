<?php

namespace App\Notification;

use PDO;
use App\Database\PostgresDatabase;

/**
 * Accès aux notifications utilisateur (centre de notifications / cloche header).
 *
 * Règles de rétention (cf. spec produit) :
 * - les notifications NON LUES sont toujours affichées ;
 * - les notifications LUES ne sont affichées que pendant 24h ;
 * - les lues de plus de 24h sont purgées en base, et on plafonne l'historique
 *   par utilisateur pour éviter une accumulation infinie.
 *
 * @package    Scouter
 * @subpackage Notification
 */
class NotificationManager
{
    /** Nombre max de notifications conservées par utilisateur. */
    private const MAX_PER_USER = 50;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Insère une notification si son `event_key` n'existe pas déjà (idempotent).
     *
     * @param array<string,mixed> $data
     * @return bool true si une ligne a été insérée
     */
    public function createIfAbsent(array $data): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO notifications
                (user_id, type, action, project_id, crawl_id, job_id, domain, project_name, project_dir, event_key)
            VALUES
                (:user_id, :type, :action, :project_id, :crawl_id, :job_id, :domain, :project_name, :project_dir, :event_key)
            ON CONFLICT (event_key) DO NOTHING
        ");
        $stmt->execute([
            ':user_id'      => $data['user_id'],
            ':type'         => $data['type'],
            ':action'       => $data['action'] ?? null,
            ':project_id'   => $data['project_id'] ?? null,
            ':crawl_id'     => $data['crawl_id'] ?? null,
            ':job_id'       => $data['job_id'] ?? null,
            ':domain'       => $data['domain'] ?? null,
            ':project_name' => $data['project_name'] ?? null,
            ':project_dir'  => $data['project_dir'] ?? null,
            ':event_key'    => $data['event_key'],
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Liste les notifications visibles d'un utilisateur :
     * non-lues OU lues il y a moins de 24h, les plus récentes d'abord.
     *
     * @return array<int,object>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, type, action, project_id, crawl_id, job_id,
                   domain, project_name, project_dir,
                   read_at, created_at,
                   (read_at IS NULL) AS unread
            FROM notifications
            WHERE user_id = :uid
              AND (read_at IS NULL OR created_at > NOW() - INTERVAL '24 hours')
            ORDER BY created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', self::MAX_PER_USER, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND read_at IS NULL");
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Marque toutes les notifications non-lues d'un utilisateur comme lues.
     */
    public function markAllRead(int $userId): void
    {
        $stmt = $this->db->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = :uid AND read_at IS NULL");
        $stmt->execute([':uid' => $userId]);
    }

    /**
     * Purge : supprime les lues de plus de 24h, puis plafonne l'historique
     * par utilisateur. Appelé périodiquement par le worker.
     */
    public function prune(): void
    {
        $this->db->exec("DELETE FROM notifications WHERE read_at IS NOT NULL AND created_at < NOW() - INTERVAL '24 hours'");

        // Plafond par utilisateur : on garde les MAX_PER_USER plus récentes.
        $this->db->exec("
            DELETE FROM notifications n
            USING (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY created_at DESC) AS rn
                    FROM notifications
                ) ranked
                WHERE ranked.rn > " . self::MAX_PER_USER . "
            ) excess
            WHERE n.id = excess.id
        ");
    }
}
