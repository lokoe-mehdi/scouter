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
    if(empty($category) || $category === 'N/A' || $category === __('common.uncategorized')) {
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
    die(__('common.project_not_found'));
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

// Paramètre de comparaison de crawl
$compareId = isset($_GET['compare']) ? (int)$_GET['compare'] : null;
$compareRecord = null;
if ($compareId) {
    $compareRecord = CrawlDatabase::getCrawlById($compareId);
    if (!$compareRecord) {
        $compareId = null;
        $compareRecord = null;
    }
}

// ============================================
// CHARGEMENT CENTRALISÉ DES CATÉGORIES
// Évite les jointures sur la table categories partout
// ============================================
// Routing PG vs ClickHouse : un crawl `data_store=clickhouse` lit ses rapports
// MONO-crawl dans ClickHouse (via le shim ChPdo). Les vues de COMPARAISON
// (compareId présent) restent sur PostgreSQL — PG conserve les données pendant
// la transition (dual-write), et la comparaison interroge deux crawls.
$pdoPg = $pdo; // PostgreSQL handle (crawl-time data still present until backfill).
// Objectif : tous les rapports sur ClickHouse. PENDANT la transition (backfill en
// cours), un crawl pas encore migré (data_store != clickhouse) n'a pas ses données
// dans CH → on lit alors PG, mais via PgReportPdo qui calcule `category` en LIVE
// (jamais de cat_id), comme CH. Une fois le crawl migré, on bascule sur ChPdo.
// La comparaison va sur CH seulement si les DEUX crawls y sont.
$useCh = \App\Database\CrawlStore::usesClickHouse((int)$crawlId)
    && (empty($compareId) || \App\Database\CrawlStore::usesClickHouse((int)$compareId));

$categoriesMap = [];
$categoryColors = [];
if ($useCh) {
    $categoriesMap = \App\Database\ChPdo::categoriesMap((int)$crawlId);
    foreach ($categoriesMap as $row) {
        $categoryColors[$row['cat']] = $row['color'];
    }
    $pdo = new \App\Database\ChPdo((int)$crawlId, $compareId ? (int)$compareId : null);
    $GLOBALS['chReportPdo'] = $pdo; // chart.php : icône SQL en dialecte ClickHouse
} else {
    // Crawl pas encore migré → PG (qui a encore les données), catégorie live.
    $stmt = $pdo->prepare("SELECT id, cat, color FROM crawl_categories WHERE project_id = :project_id");
    $stmt->execute([':project_id' => $crawlRecord->project_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $categoriesMap[$row['id']] = ['cat' => $row['cat'], 'color' => $row['color']];
        $categoryColors[$row['cat']] = $row['color'];
    }
    $pdo = new \App\Database\PgReportPdo((int)$crawlId, $compareId ? (int)$compareId : null);
}
$GLOBALS['categoriesMap'] = $categoriesMap;
$GLOBALS['categoryColors'] = $categoryColors;

// NB : pas d'auto-warm en fond ici — le cache se remplit en lazy-warm au premier
// affichage de chaque rapport (ReportPrecompute::cached). L'ancien ensureWarm
// ré-enqueuait en boucle des jobs qui ne pouvaient rien précalculer (et flippait
// le statut du crawl).

// Charger les statistiques globales
$crawlRepo = new CrawlRepository();
$globalStats = $crawlRecord;

// Extraire le flag store_html depuis la config du crawl
$crawlConfigRaw = is_string($crawlRecord->config ?? '{}') ? json_decode($crawlRecord->config ?? '{}', true) : ($crawlRecord->config ?? []);
$storeHtml = $crawlConfigRaw['advanced']['store_html'] ?? true;

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
        
        // Ne masquer que les crawls RÉELLEMENT vides (0 URL ET 0 page crawlée).
        // En mode ClickHouse, le stat `urls` de la ligne PostgreSQL peut rester à 0
        // (non resynchronisé depuis CH) alors que le crawl a des données — sans ce
        // garde-fou, un crawl valide disparaissait du sélecteur (cf. bug #575/#625).
        if (intval($crawl->urls ?? 0) === 0 && intval($crawl->crawled ?? 0) === 0) {
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

// Auto-sélectionner le crawl de comparaison si non spécifié
// Priorité : crawl précédent (plus ancien), sinon crawl suivant (plus récent)
if (!$compareId && !empty($domainCrawls)) {
    $currentIndex = null;
    foreach ($domainCrawls as $i => $dc) {
        if ($dc['crawl_id'] == $crawlId) {
            $currentIndex = $i;
            break;
        }
    }

    if ($currentIndex !== null) {
        // Essayer le crawl précédent (index suivant car trié par date desc)
        if (isset($domainCrawls[$currentIndex + 1])) {
            $compareId = (int)$domainCrawls[$currentIndex + 1]['crawl_id'];
            $compareRecord = CrawlDatabase::getCrawlById($compareId);
        }
        // Sinon essayer le crawl suivant (index précédent car trié par date desc)
        elseif (isset($domainCrawls[$currentIndex - 1])) {
            $compareId = (int)$domainCrawls[$currentIndex - 1]['crawl_id'];
            $compareRecord = CrawlDatabase::getCrawlById($compareId);
        }
    }
}

// Flag store_html du crawl de comparaison
$compareStoreHtml = true;
if ($compareRecord) {
    $compareConfigRaw = is_string($compareRecord->config ?? '{}') ? json_decode($compareRecord->config ?? '{}', true) : ($compareRecord->config ?? []);
    $compareStoreHtml = $compareConfigRaw['advanced']['store_html'] ?? true;
}

// Pré-calculer les scorecards de comparaison une seule fois (utilisés par toutes les pages comparison)
$comparisonScorecardsComputed = false;
$compNewCount = 0;
$compLostCount = 0;
$compCommonCount = 0;

$comparisonPages = ['comparison-overview', 'new-urls', 'lost-urls', 'code-changes', 'depth-comparison', 'accessibility-comparison', 'sitemap-comparison', 'seo-tags-comparison', 'headings-comparison', 'content-richness-comparison', 'duplication-comparison', 'structured-data-comparison', 'inlinks-comparison', 'outlinks-comparison', 'pagerank-comparison', 'pagerank-leak-comparison'];

if ($compareId && in_array($page ?? '', $comparisonPages)) {
    $comparisonScorecardsComputed = true;
    $safeCompId = intval($compareId);
    $safeCrId = intval($crawlId);

    // NB : NOT IN / IN (non corrélé) plutôt que NOT EXISTS corrélé — ClickHouse ne
    // supporte pas les sous-requêtes corrélées, et PostgreSQL gère NOT IN(subquery)
    // efficacement (set hashé). url n'est jamais NULL ici.
    // New URLs: dans current, pas dans compare.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM pages a
        WHERE a.crawl_id = :current AND a.crawled = true AND a.in_crawl = TRUE
        AND a.url NOT IN (SELECT url FROM pages WHERE crawl_id = :compare AND crawled = true AND in_crawl = TRUE)
    ");
    $stmt->execute([':current' => $safeCrId, ':compare' => $safeCompId]);
    $compNewCount = (int)$stmt->fetchColumn();

    // Lost URLs: dans compare, pas dans current
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM pages a
        WHERE a.crawl_id = :compare AND a.crawled = true AND a.in_crawl = TRUE
        AND a.url NOT IN (SELECT url FROM pages WHERE crawl_id = :current AND crawled = true AND in_crawl = TRUE)
    ");
    $stmt->execute([':compare' => $safeCompId, ':current' => $safeCrId]);
    $compLostCount = (int)$stmt->fetchColumn();

    // Common URLs
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM pages a
        WHERE a.crawl_id = :current AND a.crawled = true AND a.in_crawl = TRUE
        AND a.url IN (SELECT url FROM pages WHERE crawl_id = :compare AND crawled = true AND in_crawl = TRUE)
    ");
    $stmt->execute([':current' => $safeCrId, ':compare' => $safeCompId]);
    $compCommonCount = (int)$stmt->fetchColumn();
}

// Lire l'état des sections depuis les cookies
function isSectionCollapsed($sectionName) {
    $cookieName = 'sidebar-' . $sectionName;
    return isset($_COOKIE[$cookieName]) && $_COOKIE[$cookieName] === 'collapsed';
}

// ============================================
// htmx — navigation fragment (Zone A, voir htmx.md §4)
// ============================================
// Placé APRÈS tout le bootstrap : le contenu a besoin de $crawlRecord, $page,
// des catégories et des scorecards de comparaison déjà calculés ci-dessus.
// On ne rend alors QUE l'intérieur de #main-content (le reste de la chrome
// — header, sidebar, widgets — reste en place côté client).
// On exclut la restauration d'historique (htmx veut alors la page pleine).
$isHtmxFragment = !empty($_SERVER['HTTP_HX_REQUEST'])
    && empty($_SERVER['HTTP_HX_HISTORY_RESTORE_REQUEST']);
if ($isHtmxFragment) {
    require __DIR__ . '/partials/main-content.php';
    exit;
}

?>
<!DOCTYPE html>
<html lang="<?= I18n::getInstance()->getLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scouter - <?= htmlspecialchars($projectName) ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/responsive.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/crawl-panel.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/vendor/material-symbols/material-symbols.css" />
    <script src="assets/i18n.js"></script>
    <script>ScouterI18n.init(<?= I18n::getInstance()->getJsTranslations() ?>, <?= json_encode(I18n::getInstance()->getLang()) ?>);</script>
    <script src="assets/tooltip.js?v=<?= time() ?>"></script>
    <link rel="stylesheet" href="assets/vendor/codemirror/codemirror.min.css">
    <link rel="stylesheet" href="assets/vendor/codemirror/theme/eclipse.min.css">
    <link rel="stylesheet" href="assets/vendor/codemirror/theme/material-darker.min.css">
    <script src="assets/highcharts.js"></script>
    <script src="assets/treemap.js"></script>
    <script src="assets/sankey.js"></script>
    <script src="assets/exporting.js"></script>
    <script src="assets/vendor/chartjs/chart.umd.min.js"></script>
    <script src="assets/vendor/codemirror/codemirror.min.js"></script>
    <script src="assets/vendor/codemirror/mode/yaml.min.js"></script>
    <script src="assets/vendor/codemirror/mode/sql.min.js"></script>
    <script src="assets/vendor/codemirror/mode/xml.min.js"></script>
    <script src="assets/vendor/codemirror/mode/javascript.min.js"></script>
    <script src="assets/vendor/codemirror/mode/css.min.js"></script>
    <script src="assets/vendor/codemirror/mode/htmlmixed.min.js"></script>
    <script src="assets/vendor/js-beautify/beautify-html.min.js"></script>
    <script src="assets/vendor/codemirror/addon/matchbrackets.min.js"></script>
    <script src="assets/vendor/codemirror/addon/closebrackets.min.js"></script>
    <script src="assets/vendor/codemirror/addon/searchcursor.min.js"></script>
    <script src="assets/vendor/codemirror/addon/search.min.js"></script>
    <script src="assets/vendor/codemirror/addon/jump-to-line.min.js"></script>
    <script src="assets/vendor/codemirror/addon/match-highlighter.min.js"></script>
    <script src="assets/vendor/codemirror/addon/dialog.min.js"></script>
    <link rel="stylesheet" href="assets/vendor/codemirror/addon/dialog.min.css">
    <script src="assets/vendor/codemirror/addon/show-hint.min.js"></script>
    <script src="assets/vendor/codemirror/addon/sql-hint.min.js"></script>
    <link rel="stylesheet" href="assets/vendor/codemirror/addon/show-hint.min.css">
    <?php include __DIR__ . '/partials/head-assets.php'; ?>
</head>
<body>
    <!-- Header -->
    <?php $headerContext = 'dashboard'; include 'components/top-header.php'; ?>

    <?php
    // Déterminer la section active basée sur la page actuelle
    $activeSection = null; // Pas de défaut, on détermine précisément
    $reportPages = ['home', 'categories', 'codes', 'response-time', 'depth', 'redirect-chains', 'sitemap', 'inlinks', 'outlinks', 'pagerank', 'seo-tags', 'headings', 'duplication', 'extractions', 'structured-data'];
    $explorerPages = ['url-explorer', 'link-explorer', 'sql-explorer'];

    if (in_array($page, $reportPages)) {
        $activeSection = 'report';
    } elseif (in_array($page, $explorerPages)) {
        $activeSection = 'explorer';
    } elseif (in_array($page, $comparisonPages)) {
        $activeSection = 'comparison';
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
        <main class="main-content" id="main-content">
            <?php require __DIR__ . '/partials/main-content.php'; ?>
        </main>
    </div>

    <script src="assets/global-status.js"></script>
    <script src="assets/confirm-modal.js"></script>
    <script src="assets/crawl-panel.js?v=<?= time() ?>"></script>
    <script src="assets/app.js"></script>
    <script src="assets/url-modal-handler.js?v=<?= time() ?>"></script>
    
    <?php include 'components/url-details-modal.php'; ?>
    <?php include 'components/quick-search.php'; ?>
    <?php include 'components/crawl-panel.php'; ?>
    <?php /* Dr. Brief chat assistant. Renders nothing if AI is not configured. */ ?>
    <?php include 'components/dr-brief-widget.php'; ?>
</body>
</html>
