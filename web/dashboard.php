<?php
// Initialisation et vérification d'authentification automatique
require_once(__DIR__ . '/init.php');

// Charger la classe Component pour utilisation dans toutes les vues
require_once(__DIR__ . '/config/component.php');

// Charger la classe CategoryColors pour la gestion des couleurs de catégories
require_once(__DIR__ . '/../app/Util/CategoryColors.php');

// Charger la classe HttpCodes pour la gestion des codes HTTP
require_once(__DIR__ . '/../app/Util/HttpCodes.php');

// Fonction globale pour obtenir le label d'un code HTTP
function getCodeLabel($code) {
    return \App\Util\HttpCodes::getLabel($code);
}

// Fonction globale pour obtenir la couleur d'un code HTTP
function getCodeColor($code) {
    return \App\Util\HttpCodes::getColor($code);
}

// Fonction globale pour obtenir le label complet d'un code HTTP (code + description)
function getCodeFullLabel($code) {
    return \App\Util\HttpCodes::getFullLabel($code);
}

// Fonction globale pour obtenir la couleur de fond d'un badge de code HTTP (avec opacity)
function getCodeBackgroundColor($code, $opacity = 0.3) {
    return \App\Util\HttpCodes::getBackgroundColor($code, $opacity);
}

// Fonction globale pour obtenir la valeur d'affichage d'un code HTTP
// Retourne "JS Redirect" pour le code 311, sinon le code numérique
function getCodeDisplayValue($code) {
    return \App\Util\HttpCodes::getDisplayCode($code);
}

// Fonction globale pour obtenir la couleur d'une catégorie
function getCategoryColor($category) {
    $categoryColors = $GLOBALS['categoryColors'] ?? [];
    if(empty($category) || $category === 'N/A' || $category === 'Non catégorisé') {
        return '#95a5a6';
    }
    return $categoryColors[$category] ?? '#95a5a6';
}

// Fonction pour déterminer si le texte doit être blanc ou noir selon la luminosité de la couleur
function getTextColorForBackground($hexColor) {
    // Enlever le # si présent
    $hex = ltrim($hexColor, '#');
    
    // Convertir en RGB
    if (strlen($hex) === 3) {
        $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
        $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
        $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    
    // Calculer la luminosité relative (formule W3C)
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    
    // Seuil élevé (0.65) pour privilégier le texte blanc - noir seulement si vraiment clair
    return $luminance > 0.75 ? '#000000' : '#ffffff';
}

use App\Database\CrawlRepository;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;

// Récupération du crawl (par ID ou par path pour rétrocompatibilité)
$crawlId = isset($_GET['crawl']) ? (int)$_GET['crawl'] : null;
$projectDir = isset($_GET['project']) ? $_GET['project'] : '';

if (empty($crawlId) && empty($projectDir)) {
    header('Location: index.php');
    exit;
}

// Connexion à PostgreSQL
$pdo = PostgresDatabase::getInstance()->getConnection();

// Récupérer le crawl depuis PostgreSQL
if ($crawlId) {
    // Nouveau mode : par ID
    $crawlRecord = CrawlDatabase::getCrawlById($crawlId);
} else {
    // Ancien mode : par path (rétrocompatibilité)
    $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
}

if (!$crawlRecord) {
    die('Projet non trouvé');
}

// Rediriger vers index si le crawl n'est pas terminé ou arrêté
$crawlStatus = $crawlRecord->status ?? 'running';
if (!in_array($crawlStatus, ['finished', 'stopped', 'error'])) {
    header('Location: index.php');
    exit;
}

// Mettre à jour projectDir pour la compatibilité
$projectDir = $crawlRecord->path ?? $crawlRecord->id;

// ID du crawl pour les requêtes partitionnées
$crawlId = $crawlRecord->id;

// ============================================
// CHARGEMENT CENTRALISÉ DES CATÉGORIES
// Évite les jointures sur la table categories partout
// ============================================
$categoriesMap = [];
$categoryColors = [];
$stmt = $pdo->prepare("SELECT id, cat, color FROM categories WHERE crawl_id = :crawl_id");
$stmt->execute([':crawl_id' => $crawlId]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $categoriesMap[$row['id']] = [
        'cat' => $row['cat'],
        'color' => $row['color']
    ];
    $categoryColors[$row['cat']] = $row['color'];
}
$GLOBALS['categoriesMap'] = $categoriesMap;
$GLOBALS['categoryColors'] = $categoryColors;

// Charger les statistiques globales
$crawlRepo = new CrawlRepository();
$globalStats = $crawlRecord;

// Récupération de la page actuelle
$page = isset($_GET['page']) ? $_GET['page'] : 'home';

// Récupérer le vrai nom de domaine depuis le crawl
$projectName = $crawlRecord->domain ?? preg_replace("#-(\d{8})-(\d{6})$#", "", $projectDir);

// Récupérer tous les crawls de ce domaine depuis la base globale
$domainName = $projectName;
$domainCrawls = [];

try {
    $rawCrawls = [];
    
    // Si le crawl a un project_id, on filtre par projet (plus précis)
    if (!empty($crawlRecord->project_id)) {
        $rawCrawls = $crawlRepo->getByProjectId($crawlRecord->project_id);
    } else {
        // Fallback : filtrage par domaine (legacy)
        $allCrawls = $crawlRepo->getAll();
        foreach ($allCrawls as $c) {
            if ($c->domain === $domainName) {
                $rawCrawls[] = $c;
            }
        }
    }
    
    foreach ($rawCrawls as $crawl) {
        // Normalisation des ID (différence entre getAllDomainsWithCrawls et getCrawlsByProjectId)
        $cId = $crawl->id ?? $crawl->crawl_id ?? null;
        if (!$cId) continue;
        
        // Ne pas afficher les crawls avec 0 URLs (en cours ou échoués)
        if (empty($crawl->urls) || intval($crawl->urls) === 0) {
            continue;
        }
        
        // Date : préférer started_at, sinon parser le path
        $timestamp = 0;
        if (!empty($crawl->started_at)) {
            $timestamp = strtotime($crawl->started_at);
        } else {
            preg_match("#(\d{8})-(\d{6})$#", $crawl->path, $matches);
            if (!empty($matches[1])) {
                $timestamp = strtotime($matches[1].$matches[2]);
            }
        }
        
        // Config du crawl
        $configRaw = $crawl->config ?? '{}';
        $crawlConfig = is_string($configRaw) ? json_decode($configRaw, true) : $configRaw;
        $crawlConfig = $crawlConfig ?: [];
        
        $domainCrawls[] = [
            'crawl_id' => $cId,
            'dir' => $crawl->path,
            'date' => date('Y-m-d H:i:s', $timestamp),
            'timestamp' => $timestamp,
            'status' => $crawl->status ?? 'finished',
            'stats' => [
                'urls' => $crawl->urls,
                'crawled' => $crawl->crawled
            ],
            'config' => $crawlConfig
        ];
    }
    
    // Trier par date (plus récent en premier)
    usort($domainCrawls, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
} catch (Exception $e) {
    // En cas d'erreur, tableau vide
    $domainCrawls = [];
}

// Lire l'état des sections depuis les cookies
function isSectionCollapsed($sectionName) {
    $cookieName = 'sidebar-' . $sectionName;
    return isset($_COOKIE[$cookieName]) && $_COOKIE[$cookieName] === 'collapsed';
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scouter - <?= htmlspecialchars($projectName) ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/crawl-panel.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <script src="assets/tooltip.js?v=<?= time() ?>"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/eclipse.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/material-darker.min.css">
    <script src="https://code.highcharts.com/highcharts.js"></script>
    <script src="https://code.highcharts.com/modules/treemap.js"></script>
    <script src="https://code.highcharts.com/modules/sankey.js"></script>
    <script src="https://code.highcharts.com/modules/exporting.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/yaml/yaml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/sql/sql.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/js-beautify/1.14.9/beautify-html.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/matchbrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/closebrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/search/searchcursor.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/search/search.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/search/jump-to-line.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/search/match-highlighter.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/dialog/dialog.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/dialog/dialog.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/show-hint.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/sql-hint.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/show-hint.min.css">
</head>
<body>
    <!-- Header -->
    <?php $headerContext = 'dashboard'; include 'components/top-header.php'; ?>

    <?php
    // Déterminer la section active basée sur la page actuelle
    $activeSection = null; // Pas de défaut, on détermine précisément
    $reportPages = ['home', 'categories', 'codes', 'response-time', 'depth', 'inlinks', 'outlinks', 'pagerank', 'seo-tags', 'headings', 'duplication', 'extractions', 'structured-data'];
    $explorerPages = ['url-explorer', 'link-explorer', 'sql-explorer'];
    
    if (in_array($page, $reportPages)) {
        $activeSection = 'report';
    } elseif (in_array($page, $explorerPages)) {
        $activeSection = 'explorer';
    } elseif ($page === 'categorize') {
        $activeSection = 'categorize';
    } elseif ($page === 'config') {
        $activeSection = 'config';
    } else {
        // Par défaut si page inconnue
        $activeSection = 'report';
    }
    ?>
    
    <!-- Dashboard Layout -->
    <div class="dashboard-layout">
        <!-- Navigation latérale (icon-rail + sidebar-panel) -->
        <?php include 'components/sidebar-navigation.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <?php
            // Vérifier si le crawl est vide (aucune page indexable)
            $crawlIsEmpty = ($crawlRecord->compliant ?? 0) == 0;
            $pagesNeedingData = ['inlinks', 'outlinks', 'seo-tags', 'response-time', 'codes', 'depth', 'headings', 'duplication', 'content-richness', 'pagerank', 'pagerank-leak', 'accessibility', 'extractions', 'structured-data'];
            
            if ($crawlIsEmpty && in_array($page, $pagesNeedingData)) {
                // Afficher un message d'erreur pour les pages nécessitant des données
                ?>
                <div class="empty-crawl-message" style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
                    <div style="font-size: 4rem; margin-bottom: 1rem;">⚠️</div>
                    <h2 style="margin-bottom: 1rem; color: var(--text-primary);">Aucune page indexable</h2>
                    <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
                        Ce crawl n'a trouvé aucune page indexable. Les données de cette section ne sont pas disponibles.
                    </p>
                    <p style="color: var(--text-tertiary); font-size: 0.9rem;">
                        Causes possibles : crawl interrompu, toutes les pages en erreur/redirect, blocage robots.txt, ou URL de départ inaccessible.
                    </p>
                    <a href="?crawl=<?= $crawlId ?>&page=home" class="btn btn-primary" style="margin-top: 1.5rem; display: inline-block; padding: 0.75rem 1.5rem; background: var(--primary-color); color: white; border-radius: 8px; text-decoration: none;">
                        Retour à l'accueil
                    </a>
                </div>
                <?php
            } else {
            // Inclusion de la page demandée
            switch($page) {
                case 'home':
                    include 'pages/home.php';
                    break;
                case 'url-explorer':
                    include 'pages/url-explorer.php';
                    break;
                case 'link-explorer':
                    include 'pages/link-explorer.php';
                    break;
                case 'sql-explorer':
                    include 'pages/sql-explorer.php';
                    break;
                case 'depth':
                    include 'pages/depth.php';
                    break;
                case 'codes':
                    include 'pages/codes.php';
                    break;
                case 'seo-tags':
                    include 'pages/seo-tags.php';
                    break;
                case 'headings':
                    include 'pages/headings.php';
                    break;
                case 'duplication':
                    include 'pages/duplication.php';
                    break;
                case 'extractions':
                    include 'pages/extractions.php';
                    break;
                case 'structured-data':
                    include 'pages/structured-data.php';
                    break;
                case 'content-richness':
                    include 'pages/content-richness.php';
                    break;
                case 'accessibility':
                    include 'pages/accessibility.php';
                    break;
                case 'pagerank':
                    include 'pages/pagerank.php';
                    break;
                case 'pagerank-leak':
                    include 'pages/pagerank-leak.php';
                    break;
                case 'inlinks':
                    include 'pages/inlinks.php';
                    break;
                case 'outlinks':
                    include 'pages/outlinks.php';
                    break;
                case 'response-time':
                    include 'pages/response-time.php';
                    break;
                case 'categorize':
                    // SÉCURITÉ: Vérifier les droits de gestion
                    if (!$canManageCurrentProject) {
                        echo '<div class="error-page" style="padding: 2rem; text-align: center;"><h1>403 - Accès refusé</h1><p>Vous n\'avez pas les droits de modification sur ce projet.</p></div>';
                    } else {
                        include 'pages/categorize.php';
                    }
                    break;
                case 'config':
                    // SÉCURITÉ: Vérifier les droits de gestion
                    if (!$canManageCurrentProject) {
                        echo '<div class="error-page" style="padding: 2rem; text-align: center;"><h1>403 - Accès refusé</h1><p>Vous n\'avez pas les droits de modification sur ce projet.</p></div>';
                    } else {
                        include 'pages/config.php';
                    }
                    break;
                default:
                    include 'pages/home.php';
            }
            } // fin du else (crawl non vide)
            ?>
        </main>
    </div>

    <script src="assets/global-status.js"></script>
    <script src="assets/confirm-modal.js"></script>
    <script src="assets/crawl-panel.js?v=<?= time() ?>"></script>
    <script src="assets/app.js"></script>
    <script src="assets/url-modal-handler.js"></script>
    
    <?php include 'components/url-details-modal.php'; ?>
    <?php include 'components/quick-search.php'; ?>
    <?php include 'components/crawl-panel.php'; ?>
</body>
</html>
