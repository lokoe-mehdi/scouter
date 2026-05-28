<?php
/**
 * Migration: table `exports` pour le centre de téléchargements (icône header,
 * à gauche de la cloche).
 *
 * Chaque export (SQL Explorer, URL Explorer, Link Explorer, Redirect chains)
 * est désormais ASYNCHRONE : un job génère le CSV côté serveur, l'envoie sur le
 * blob store (S3/local) sous le préfixe `export/`, et cette table suit son état.
 * Le frontend (downloads.js) poll `/api/exports` toutes les 5s, comme la cloche.
 *
 * `params` (JSONB) stocke ce qu'il faut pour rejouer la requête côté worker
 * (type d'export + filtres/colonnes/sql). Le fichier n'est accessible que 24h :
 * `expires_at` = created_at + 24h, et un sweep supprime l'objet + la ligne après.
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exports (
            id          SERIAL PRIMARY KEY,
            user_id     INTEGER NOT NULL,
            project_id  INTEGER,
            crawl_id    INTEGER,
            job_id      INTEGER,
            type        VARCHAR(16) NOT NULL,         -- 'urls' | 'links' | 'redirects' | 'sql'
            label       TEXT,                          -- libellé lisible (domaine + type)
            params      JSONB NOT NULL DEFAULT '{}',   -- de quoi rejouer la requête
            status      VARCHAR(16) NOT NULL DEFAULT 'pending', -- pending|running|ready|failed
            filename    TEXT,                          -- nom du CSV (avec date)
            object_key  TEXT,                          -- clé blob store (export/<id>/<file>)
            row_count   INTEGER,
            size_bytes  BIGINT,
            error       TEXT,
            seen_at     TIMESTAMP DEFAULT NULL,         -- pour la pastille (vu / pas vu)
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ready_at    TIMESTAMP DEFAULT NULL,
            expires_at  TIMESTAMP DEFAULT NULL
        )
    ");

    // Liste du centre de téléchargements, par utilisateur, récents d'abord.
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_exports_user_created ON exports(user_id, created_at DESC)");
    // Sweep des exports périmés (objet + ligne) après 24h.
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_exports_expires ON exports(expires_at)");

    echo "(created exports table) ";
    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
