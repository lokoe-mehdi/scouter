<?php
/**
 * Migration: table `notifications` pour le centre de notifications (cloche header).
 *
 * Chaque notification appartient à UN utilisateur (le propriétaire du projet
 * concerné) — on ne notifie jamais un user des crawls/jobs d'un autre, même
 * admin. Le texte n'est PAS stocké : on conserve `type` + les données
 * (domaine, projet, ids) et le rendu se fait côté client via i18n → la notif
 * suit la langue de l'utilisateur.
 *
 * `event_key` garantit l'idempotence de l'émetteur (NotificationReconciler) :
 * insertion en ON CONFLICT DO NOTHING, donc un même évènement ne crée jamais
 * de doublon même si le worker repasse dessus.
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id           SERIAL PRIMARY KEY,
            user_id      INTEGER NOT NULL,
            type         VARCHAR(32) NOT NULL,
            action       VARCHAR(16),                 -- 'dashboard' | 'project' | 'logs'
            project_id   INTEGER,
            crawl_id     INTEGER,
            job_id       INTEGER,
            domain       TEXT,
            project_name TEXT,
            project_dir  TEXT,
            event_key    TEXT UNIQUE,
            read_at      TIMESTAMP DEFAULT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Liste de la cloche : non-lues OU lues récentes, triées par date.
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user_created ON notifications(user_id, created_at DESC)");
    // Comptage rapide des non-lues (pastille).
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user_unread ON notifications(user_id) WHERE read_at IS NULL");

    echo "(created notifications table) ";
    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
