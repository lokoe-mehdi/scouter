<?php
/**
 * Migration: ajoute la colonne `actor` à `notifications`.
 *
 * Certaines notifications mentionnent QUI a déclenché l'évènement (et non le
 * propriétaire du projet). Cas d'usage : "X vous a partagé le projet Y" —
 * `actor` stocke l'identité (email) de l'utilisateur qui a partagé.
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS actor TEXT");
    echo "(added actor column to notifications) ";
    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
