<?php
/**
 * Fichier d'initialisation pour les pages dans le dossier pages/
 * Usage: require_once(__DIR__ . '/init.php');
 * 
 * Ce fichier fait 3 choses :
 * 1. Charge l'autoloader
 * 2. Vérifie l'authentification
 * 3. Vérifie l'accès au projet si le paramètre 'project' est présent
 */

// Charger l'autoloader
require_once(__DIR__ . "/../../vendor/autoload.php");

use App\Auth\Auth;

// Vérifier l'authentification
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    // Sauvegarder l'URL demandée pour redirection après login
    $requestedUrl = $_SERVER['REQUEST_URI'];
    header('Location: ../login.php?redirect=' . urlencode($requestedUrl));
    exit;
}

// Rendre l'objet auth et les infos utilisateur disponibles pour toutes les pages
$currentEmail = $auth->getCurrentEmail();
$currentUserId = $auth->getCurrentUserId();
$isAdmin = $auth->isAdmin();
$isViewer = $auth->isViewer();
$canCreate = $auth->canCreate();

// ============================================
// SÉCURITÉ : Vérification de l'accès au projet
// ============================================
$crawlIdParam = isset($_GET['crawl']) ? (int)$_GET['crawl'] : null;
$projectPath = $_GET['project'] ?? null;
$canManageCurrentProject = false;

if ($crawlIdParam) {
    // Nouveau mode : par ID de crawl
    $auth->requireCrawlAccessById($crawlIdParam, false);
    $canManageCurrentProject = $auth->canManageCrawlById($crawlIdParam);
} elseif ($projectPath) {
    // Ancien mode : par path (rétrocompatibilité)
    $auth->requireCrawlAccess($projectPath, false);
    $canManageCurrentProject = $auth->canManageCrawlByPath($projectPath);
}
