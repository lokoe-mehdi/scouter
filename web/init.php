<?php
/**
 * Fichier d'initialisation à inclure dans TOUTES les pages
 * Usage: require_once(__DIR__ . '/init.php');
 * 
 * Ce fichier fait 3 choses :
 * 1. Charge l'autoloader
 * 2. Vérifie l'authentification (sauf pour login.php)
 * 3. Vérifie l'accès au projet si le paramètre 'project' est présent
 */

// Charger l'autoloader
require_once(__DIR__ . "/../vendor/autoload.php");

use App\Auth\Auth;

// Obtenir le nom du fichier actuel
$currentFile = basename($_SERVER['PHP_SELF']);

// Pages qui ne nécessitent PAS d'authentification
$publicPages = ['login.php'];

// Si ce n'est pas une page publique, vérifier l'authentification
if (!in_array($currentFile, $publicPages)) {
    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        // Sauvegarder l'URL demandée pour redirection après login
        $requestedUrl = $_SERVER['REQUEST_URI'];
        header('Location: login.php?redirect=' . urlencode($requestedUrl));
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
}
