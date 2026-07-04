<?php

namespace App\Gsc;

use App\Database\PostgresDatabase;
use PDO;

/**
 * CRUD for Search Analytics custom events (project-scoped timeline annotations).
 *
 * An event is a {date, title, optional description} rendered as a vertical line
 * on the Search Analytics chart. Everything is scoped by project_id — every read
 * and write filters on it, so a user can never touch another project's events.
 *
 * The input normalisation ({@see sanitize}) is pure (no DB) and unit-tested.
 *
 * @package    Scouter
 * @subpackage Gsc
 */
class EventRepository
{
    /** Field caps (kept in sync with the DB TEXT columns; UI enforces the same). */
    public const TITLE_MAX = 120;
    public const DESC_MAX  = 1000;

    private PDO $db;

    public function __construct()
    {
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Validate + normalise raw user input for an event.
     *
     * Pure (no DB). Throws {@see \InvalidArgumentException} on a bad date or an
     * empty title. Trims and length-caps the strings; an empty/blank description
     * becomes null.
     *
     * @return array{event_date:string,title:string,description:?string}
     */
    public static function sanitize(string $date, string $title, ?string $description): array
    {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('invalid_date');
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        if (!checkdate($m, $d, $y)) {
            throw new \InvalidArgumentException('invalid_date');
        }

        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('empty_title');
        }
        $title = mb_substr($title, 0, self::TITLE_MAX);

        $description = $description === null ? null : trim($description);
        if ($description === '' || $description === null) {
            $description = null;
        } else {
            $description = mb_substr($description, 0, self::DESC_MAX);
        }

        return ['event_date' => $date, 'title' => $title, 'description' => $description];
    }

    /**
     * All events for a project (optionally restricted to a [from, to] window),
     * oldest first.
     *
     * @return array<int,array{id:int,event_date:string,title:string,description:?string}>
     */
    public function listByProject(int $projectId, ?string $from = null, ?string $to = null): array
    {
        $sql = "SELECT id, to_char(event_date, 'YYYY-MM-DD') AS event_date, title, description
                FROM gsc_events WHERE project_id = :pid";
        $params = [':pid' => $projectId];
        if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $sql .= " AND event_date >= :from";
            $params[':from'] = $from;
        }
        if ($to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $sql .= " AND event_date <= :to";
            $params[':to'] = $to;
        }
        $sql .= " ORDER BY event_date ASC, id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn($r) => [
            'id'          => (int) $r['id'],
            'event_date'  => (string) $r['event_date'],
            'title'       => (string) $r['title'],
            'description' => $r['description'] !== null ? (string) $r['description'] : null,
        ], $rows);
    }

    /**
     * Insert an event. $clean must come from {@see sanitize}.
     *
     * @param array{event_date:string,title:string,description:?string} $clean
     * @return array{id:int,event_date:string,title:string,description:?string}
     */
    public function create(int $projectId, array $clean, ?int $userId): array
    {
        $stmt = $this->db->prepare(
            "INSERT INTO gsc_events (project_id, event_date, title, description, created_by)
             VALUES (:pid, :date, :title, :desc, :uid) RETURNING id"
        );
        $stmt->execute([
            ':pid'   => $projectId,
            ':date'  => $clean['event_date'],
            ':title' => $clean['title'],
            ':desc'  => $clean['description'],
            ':uid'   => $userId,
        ]);
        return [
            'id'          => (int) $stmt->fetchColumn(),
            'event_date'  => $clean['event_date'],
            'title'       => $clean['title'],
            'description' => $clean['description'],
        ];
    }

    /**
     * Update an event (scoped by project). $clean must come from {@see sanitize}.
     *
     * @param array{event_date:string,title:string,description:?string} $clean
     */
    public function update(int $projectId, int $id, array $clean): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE gsc_events SET event_date = :date, title = :title, description = :desc,
                    updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND project_id = :pid"
        );
        $stmt->execute([
            ':date'  => $clean['event_date'],
            ':title' => $clean['title'],
            ':desc'  => $clean['description'],
            ':id'    => $id,
            ':pid'   => $projectId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Delete an event, scoped to its project (prevents cross-project deletes). */
    public function delete(int $projectId, int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM gsc_events WHERE id = :id AND project_id = :pid");
        $stmt->execute([':id' => $id, ':pid' => $projectId]);
        return $stmt->rowCount() > 0;
    }
}
