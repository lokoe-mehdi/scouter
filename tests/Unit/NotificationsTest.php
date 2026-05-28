<?php

/**
 * Tests du système de notifications (cloche header).
 *
 * S'appuie sur une vraie base Postgres (cf. .github/workflows/tests.yml qui
 * charge le schéma + les migrations), comme les autres tests DB du projet.
 * Couvre :
 *   - NotificationManager : idempotence, rétention (non-lues vs lues 24h),
 *     compteur de non-lues, marquage lu, purge + plafond par utilisateur.
 *   - NotificationReconciler : émission correcte par type d'évènement,
 *     idempotence, scoping au propriétaire du projet, silence des suppressions.
 */

use App\Database\PostgresDatabase;
use App\Notification\NotificationManager;
use App\Notification\NotificationReconciler;

const NOTIF_U1   = 90001;   // propriétaire principal
const NOTIF_U2   = 90002;   // autre utilisateur (scoping)
const NOTIF_P1   = 90001;
const NOTIF_P2   = 90002;
const NOTIF_C1   = 90001;
const NOTIF_C2   = 90002;
const NOTIF_DIR1 = '/test/notif-90001';
const NOTIF_DIR2 = '/test/notif-90002';

beforeEach(function () {
    $this->db = PostgresDatabase::getInstance()->getConnection();

    // La table `jobs` est créée à la volée par JobManager (pas dans init.sql) :
    // on l'instancie pour garantir son existence avant d'insérer des jobs.
    new \App\Job\JobManager();

    foreach ([[NOTIF_U1, 'notif-u1@test.com'], [NOTIF_U2, 'notif-u2@test.com']] as [$uid, $email]) {
        $this->db->exec("INSERT INTO users (id, email, password_hash) VALUES ($uid, '$email', 'hash') ON CONFLICT (id) DO NOTHING");
    }
    $this->db->exec("INSERT INTO projects (id, user_id, name) VALUES (" . NOTIF_P1 . ", " . NOTIF_U1 . ", 'Notif Project 1') ON CONFLICT (id) DO NOTHING");
    $this->db->exec("INSERT INTO projects (id, user_id, name) VALUES (" . NOTIF_P2 . ", " . NOTIF_U2 . ", 'Notif Project 2') ON CONFLICT (id) DO NOTHING");
    $this->db->exec("
        INSERT INTO crawls (id, project_id, domain, path, status, config)
        VALUES (" . NOTIF_C1 . ", " . NOTIF_P1 . ", 'notif1.com', '" . NOTIF_DIR1 . "', 'finished', '{}')
        ON CONFLICT (id) DO NOTHING
    ");
    $this->db->exec("
        INSERT INTO crawls (id, project_id, domain, path, status, config)
        VALUES (" . NOTIF_C2 . ", " . NOTIF_P2 . ", 'notif2.com', '" . NOTIF_DIR2 . "', 'finished', '{}')
        ON CONFLICT (id) DO NOTHING
    ");

    $this->manager = new NotificationManager($this->db);

    // Helper : insère un job avec id explicite (pour le contrôle du nettoyage).
    $this->insertJob = function (int $id, string $command, string $status, string $projectDir, array $opts = []) {
        $startedAt  = $opts['started_at']  ?? null;
        $finishedAt = $opts['finished_at'] ?? null;
        $stmt = $this->db->prepare("
            INSERT INTO jobs (id, project_dir, project_name, command, status, started_at, finished_at)
            VALUES (:id, :dir, :name, :cmd, :st, :sa, :fa)
            ON CONFLICT (id) DO UPDATE SET status = EXCLUDED.status,
                started_at = EXCLUDED.started_at, finished_at = EXCLUDED.finished_at
        ");
        $stmt->execute([
            ':id' => $id, ':dir' => $projectDir, ':name' => 'Notif Job',
            ':cmd' => $command, ':st' => $status, ':sa' => $startedAt, ':fa' => $finishedAt,
        ]);
    };
});

afterEach(function () {
    $this->db->exec("DELETE FROM notifications WHERE user_id IN (" . NOTIF_U1 . ", " . NOTIF_U2 . ")");
    $this->db->exec("DELETE FROM jobs WHERE id BETWEEN 90001 AND 90099");
    $this->db->exec("DELETE FROM crawls WHERE id IN (" . NOTIF_C1 . ", " . NOTIF_C2 . ")");
    $this->db->exec("DELETE FROM projects WHERE id IN (" . NOTIF_P1 . ", " . NOTIF_P2 . ")");
    $this->db->exec("DELETE FROM users WHERE id IN (" . NOTIF_U1 . ", " . NOTIF_U2 . ")");
});

// ============================================================
// NotificationManager
// ============================================================

test('createIfAbsent est idempotent via event_key', function () {
    $payload = [
        'user_id' => NOTIF_U1, 'type' => 'crawl_started', 'action' => 'logs',
        'project_id' => NOTIF_P1, 'crawl_id' => NOTIF_C1, 'domain' => 'notif1.com',
        'event_key' => 'crawl_started:90001',
    ];

    expect($this->manager->createIfAbsent($payload))->toBeTrue();
    expect($this->manager->createIfAbsent($payload))->toBeFalse();

    $count = (int) $this->db->query("SELECT COUNT(*) FROM notifications WHERE event_key = 'crawl_started:90001'")->fetchColumn();
    expect($count)->toBe(1);
});

test('unreadCount ne compte que les non-lues', function () {
    $this->manager->createIfAbsent(['user_id' => NOTIF_U1, 'type' => 'crawl_started', 'event_key' => 'k1']);
    $this->manager->createIfAbsent(['user_id' => NOTIF_U1, 'type' => 'crawl_started', 'event_key' => 'k2']);
    expect($this->manager->unreadCount(NOTIF_U1))->toBe(2);

    $this->db->exec("UPDATE notifications SET read_at = NOW() WHERE event_key = 'k1'");
    expect($this->manager->unreadCount(NOTIF_U1))->toBe(1);
});

test('markAllRead passe toutes les non-lues en lues', function () {
    $this->manager->createIfAbsent(['user_id' => NOTIF_U1, 'type' => 'crawl_started', 'event_key' => 'k1']);
    $this->manager->createIfAbsent(['user_id' => NOTIF_U1, 'type' => 'crawl_failed', 'event_key' => 'k2']);

    $this->manager->markAllRead(NOTIF_U1);

    expect($this->manager->unreadCount(NOTIF_U1))->toBe(0);
});

test('markAllRead ne touche pas les notifications d un autre utilisateur', function () {
    $this->manager->createIfAbsent(['user_id' => NOTIF_U1, 'type' => 'crawl_started', 'event_key' => 'k1']);
    $this->manager->createIfAbsent(['user_id' => NOTIF_U2, 'type' => 'crawl_started', 'event_key' => 'k2']);

    $this->manager->markAllRead(NOTIF_U1);

    expect($this->manager->unreadCount(NOTIF_U1))->toBe(0);
    expect($this->manager->unreadCount(NOTIF_U2))->toBe(1);
});

test('listForUser : non-lues toujours visibles, lues seulement < 24h', function () {
    // Non-lue ancienne → visible
    $this->manager->createIfAbsent(['user_id' => NOTIF_U1, 'type' => 'crawl_started', 'event_key' => 'unread_old']);
    $this->db->exec("UPDATE notifications SET created_at = NOW() - INTERVAL '25 hours' WHERE event_key = 'unread_old'");

    // Lue récente → visible
    $this->manager->createIfAbsent(['user_id' => NOTIF_U1, 'type' => 'crawl_finished', 'event_key' => 'read_recent']);
    $this->db->exec("UPDATE notifications SET read_at = NOW() WHERE event_key = 'read_recent'");

    // Lue ancienne → masquée
    $this->manager->createIfAbsent(['user_id' => NOTIF_U1, 'type' => 'crawl_failed', 'event_key' => 'read_old']);
    $this->db->exec("UPDATE notifications SET read_at = NOW() - INTERVAL '25 hours', created_at = NOW() - INTERVAL '25 hours' WHERE event_key = 'read_old'");

    $keys = array_map(fn($n) => $this->db->query("SELECT event_key FROM notifications WHERE id = {$n->id}")->fetchColumn(),
                      $this->manager->listForUser(NOTIF_U1));

    expect($keys)->toContain('unread_old');
    expect($keys)->toContain('read_recent');
    expect($keys)->not->toContain('read_old');
});

test('listForUser expose le flag unread', function () {
    $this->manager->createIfAbsent(['user_id' => NOTIF_U1, 'type' => 'crawl_started', 'event_key' => 'k1']);
    $list = $this->manager->listForUser(NOTIF_U1);
    expect($list)->toHaveCount(1);
    expect((bool) $list[0]->unread)->toBeTrue();
});

test('prune supprime les lues de plus de 24h mais garde les non-lues anciennes', function () {
    $this->manager->createIfAbsent(['user_id' => NOTIF_U1, 'type' => 'crawl_started', 'event_key' => 'keep_unread']);
    $this->db->exec("UPDATE notifications SET created_at = NOW() - INTERVAL '48 hours' WHERE event_key = 'keep_unread'");

    $this->manager->createIfAbsent(['user_id' => NOTIF_U1, 'type' => 'crawl_finished', 'event_key' => 'drop_read']);
    $this->db->exec("UPDATE notifications SET read_at = NOW() - INTERVAL '48 hours', created_at = NOW() - INTERVAL '48 hours' WHERE event_key = 'drop_read'");

    $this->manager->prune();

    $remaining = $this->db->query("SELECT event_key FROM notifications WHERE user_id = " . NOTIF_U1)->fetchAll(PDO::FETCH_COLUMN);
    expect($remaining)->toContain('keep_unread');
    expect($remaining)->not->toContain('drop_read');
});

test('prune plafonne l historique par utilisateur', function () {
    for ($i = 0; $i < 52; $i++) {
        $this->manager->createIfAbsent(['user_id' => NOTIF_U1, 'type' => 'crawl_started', 'event_key' => "cap_$i"]);
    }
    $this->manager->prune();

    $count = (int) $this->db->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . NOTIF_U1)->fetchColumn();
    expect($count)->toBe(50);
});

// ============================================================
// NotificationReconciler
// ============================================================

function notifTypesFor(PDO $db, int $userId): array
{
    return $db->query("SELECT type FROM notifications WHERE user_id = $userId ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);
}

test('reconciler émet crawl_started pour un job crawl running', function () {
    ($this->insertJob)(90010, 'crawl', 'running', NOTIF_DIR1, ['started_at' => date('Y-m-d H:i:s')]);

    (new NotificationReconciler($this->db))->run();

    $row = $this->db->query("SELECT * FROM notifications WHERE event_key = 'crawl_started:90010'")->fetch(PDO::FETCH_OBJ);
    expect($row)->not->toBeFalse();
    expect($row->user_id)->toBe(NOTIF_U1);
    expect($row->action)->toBe('logs');
    expect((int) $row->crawl_id)->toBe(NOTIF_C1);
    expect($row->domain)->toBe('notif1.com');
});

test('reconciler émet crawl_failed (finished_at NULL toléré)', function () {
    // Reproduit le cas Go SetError : status failed, pas de finished_at.
    ($this->insertJob)(90011, 'crawl', 'failed', NOTIF_DIR1, ['started_at' => date('Y-m-d H:i:s')]);

    (new NotificationReconciler($this->db))->run();

    $row = $this->db->query("SELECT action FROM notifications WHERE event_key = 'crawl_failed:90011'")->fetch(PDO::FETCH_OBJ);
    expect($row)->not->toBeFalse();
    expect($row->action)->toBe('logs');
});

test('reconciler émet crawl_finished à la fin du précalcul, clé sur le crawl', function () {
    ($this->insertJob)(90012, 'precompute-reports:' . NOTIF_C1, 'completed', NOTIF_DIR1, ['finished_at' => date('Y-m-d H:i:s')]);

    (new NotificationReconciler($this->db))->run();

    $row = $this->db->query("SELECT * FROM notifications WHERE event_key = 'crawl_finished:" . NOTIF_C1 . "'")->fetch(PDO::FETCH_OBJ);
    expect($row)->not->toBeFalse();
    expect($row->action)->toBe('dashboard');
    expect((int) $row->crawl_id)->toBe(NOTIF_C1);
});

test('reconciler émet la catégorisation et le rapport vers la page projet', function () {
    ($this->insertJob)(90013, 'batch-categorize-project:' . NOTIF_P1, 'completed', NOTIF_DIR1, ['finished_at' => date('Y-m-d H:i:s')]);
    ($this->insertJob)(90014, 'precompute-reports-project:' . NOTIF_P1, 'completed', NOTIF_DIR1, ['finished_at' => date('Y-m-d H:i:s')]);

    (new NotificationReconciler($this->db))->run();

    $types = notifTypesFor($this->db, NOTIF_U1);
    expect($types)->toContain('categorization_finished');
    expect($types)->toContain('report_finished');

    $action = $this->db->query("SELECT action FROM notifications WHERE event_key = 'categorization_finished:90013'")->fetchColumn();
    expect($action)->toBe('project');
});

test('reconciler renvoie la génération IA en masse vers l\'URL Explorer du crawl', function () {
    ($this->insertJob)(90015, 'bulk-ai-generate:777', 'completed', NOTIF_DIR1, ['finished_at' => date('Y-m-d H:i:s')]);

    (new NotificationReconciler($this->db))->run();

    $row = $this->db->query("SELECT action, crawl_id FROM notifications WHERE event_key = 'bulk_ai_finished:90015'")->fetch(PDO::FETCH_OBJ);
    expect($row)->not->toBeFalse();
    expect($row->action)->toBe('explorer');
    expect((int) $row->crawl_id)->toBe(NOTIF_C1);
});

test('reconciler ne notifie pas les jobs de suppression', function () {
    ($this->insertJob)(90016, 'delete-crawl:' . NOTIF_C1, 'completed', NOTIF_DIR1, ['finished_at' => date('Y-m-d H:i:s')]);
    ($this->insertJob)(90017, 'delete-project:' . NOTIF_P1, 'completed', 'project-' . NOTIF_P1, ['finished_at' => date('Y-m-d H:i:s')]);

    (new NotificationReconciler($this->db))->run();

    $count = (int) $this->db->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . NOTIF_U1)->fetchColumn();
    expect($count)->toBe(0);
});

test('reconciler est idempotent (run x2 ne duplique pas)', function () {
    ($this->insertJob)(90018, 'crawl', 'running', NOTIF_DIR1, ['started_at' => date('Y-m-d H:i:s')]);

    $reconciler = new NotificationReconciler($this->db);
    $reconciler->run();
    $reconciler->run();

    $count = (int) $this->db->query("SELECT COUNT(*) FROM notifications WHERE event_key = 'crawl_started:90018'")->fetchColumn();
    expect($count)->toBe(1);
});

test('reconciler scope au propriétaire du projet, jamais aux autres', function () {
    // Un crawl du projet 2 (propriétaire U2) ne doit notifier que U2, jamais U1.
    ($this->insertJob)(90019, 'crawl', 'running', NOTIF_DIR2, ['started_at' => date('Y-m-d H:i:s')]);

    (new NotificationReconciler($this->db))->run();

    $row = $this->db->query("SELECT user_id FROM notifications WHERE event_key = 'crawl_started:90019'")->fetch(PDO::FETCH_OBJ);
    expect($row)->not->toBeFalse();
    expect((int) $row->user_id)->toBe(NOTIF_U2);

    $u1count = (int) $this->db->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . NOTIF_U1)->fetchColumn();
    expect($u1count)->toBe(0);
});

test('reconciler ignore un crawl démarré il y a trop longtemps (anti-stale)', function () {
    ($this->insertJob)(90020, 'crawl', 'running', NOTIF_DIR1, ['started_at' => date('Y-m-d H:i:s', time() - 3600)]);

    (new NotificationReconciler($this->db))->run();

    $count = (int) $this->db->query("SELECT COUNT(*) FROM notifications WHERE event_key = 'crawl_started:90020'")->fetchColumn();
    expect($count)->toBe(0);
});

// ============================================================
// Partage de projet (notifyProjectShared)
// ============================================================

test('notifyProjectShared notifie le destinataire avec l acteur et le lien projet', function () {
    // U1 partage le projet 1 avec U2 → U2 reçoit la notif (pas U1).
    $created = $this->manager->notifyProjectShared(NOTIF_U2, NOTIF_P1, 'notif1.com', 'notif-u1@test.com');
    expect($created)->toBeTrue();

    $row = $this->db->query("SELECT * FROM notifications WHERE event_key = 'project_shared:" . NOTIF_P1 . ":" . NOTIF_U2 . "'")->fetch(PDO::FETCH_OBJ);
    expect($row)->not->toBeFalse();
    expect((int) $row->user_id)->toBe(NOTIF_U2);
    expect($row->type)->toBe('project_shared');
    expect($row->action)->toBe('project');
    expect((int) $row->project_id)->toBe(NOTIF_P1);
    expect($row->project_name)->toBe('notif1.com');
    expect($row->actor)->toBe('notif-u1@test.com');

    // Le partageur (U1) ne reçoit rien.
    expect($this->manager->unreadCount(NOTIF_U1))->toBe(0);
});

test('notifyProjectShared est idempotent pour un même couple projet/destinataire', function () {
    expect($this->manager->notifyProjectShared(NOTIF_U2, NOTIF_P1, 'notif1.com', 'notif-u1@test.com'))->toBeTrue();
    expect($this->manager->notifyProjectShared(NOTIF_U2, NOTIF_P1, 'notif1.com', 'notif-u1@test.com'))->toBeFalse();

    $count = (int) $this->db->query("SELECT COUNT(*) FROM notifications WHERE event_key = 'project_shared:" . NOTIF_P1 . ":" . NOTIF_U2 . "'")->fetchColumn();
    expect($count)->toBe(1);
});

test('listForUser renvoie actor pour les notifications de partage', function () {
    $this->manager->notifyProjectShared(NOTIF_U2, NOTIF_P1, 'notif1.com', 'notif-u1@test.com');
    $list = $this->manager->listForUser(NOTIF_U2);
    expect($list)->toHaveCount(1);
    expect($list[0]->actor)->toBe('notif-u1@test.com');
    expect($list[0]->type)->toBe('project_shared');
});
