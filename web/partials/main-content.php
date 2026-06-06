<?php
/**
 * Contenu de #main-content du dashboard (le « fragment » d'une navigation htmx).
 *
 * Extrait du switch historique de dashboard.php pour avoir UNE seule source,
 * incluse à deux endroits :
 *   - en pleine page (dans <main id="main-content"> de dashboard.php)
 *   - en réponse à une requête htmx (branche HX-Request de dashboard.php)
 *
 * S'exécute dans le scope global de dashboard.php : $page, $crawlRecord,
 * $crawlId, $canManageCurrentProject, $pdo, etc. sont déjà disponibles.
 *
 * Les includes sont ABSOLUS (via __DIR__) : ce fichier vit dans web/partials/
 * alors que les pages sont dans web/pages/, et le cwd n'est pas garanti.
 */

$__pagesDir = __DIR__ . '/../pages/';

// Vérifier si le crawl est vide (aucune page indexable)
$crawlIsEmpty = ($crawlRecord->compliant ?? 0) == 0;
$pagesNeedingData = ['inlinks', 'outlinks', 'seo-tags', 'response-time', 'codes', 'depth', 'headings', 'duplication', 'content-richness', 'pagerank', 'pagerank-leak', 'accessibility', 'extractions', 'structured-data'];

if ($crawlIsEmpty && in_array($page, $pagesNeedingData)) {
    // Afficher un message d'erreur pour les pages nécessitant des données
    ?>
    <div class="empty-crawl-message" style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <div style="font-size: 4rem; margin-bottom: 1rem;">⚠️</div>
        <h2 style="margin-bottom: 1rem; color: var(--text-primary);"><?= __('dashboard.no_indexable_pages') ?></h2>
        <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
            <?= __('dashboard.no_indexable_pages_desc') ?>
        </p>
        <p style="color: var(--text-tertiary); font-size: 0.9rem;">
            <?= __('dashboard.no_indexable_pages_causes') ?>
        </p>
        <a href="?crawl=<?= $crawlId ?>&page=home" class="btn btn-primary" style="margin-top: 1.5rem; display: inline-block; padding: 0.75rem 1.5rem; background: var(--primary-color); color: white; border-radius: 8px; text-decoration: none;">
            <?= __('common.back_home') ?>
        </a>
    </div>
    <?php
} else {
// Inclusion de la page demandée
switch($page) {
    case 'home':
        include $__pagesDir . 'home.php';
        break;
    case 'url-explorer':
        include $__pagesDir . 'url-explorer.php';
        break;
    case 'link-explorer':
        include $__pagesDir . 'link-explorer.php';
        break;
    case 'sql-explorer':
        include $__pagesDir . 'sql-explorer.php';
        break;
    case 'depth':
        include $__pagesDir . 'depth.php';
        break;
    case 'codes':
        include $__pagesDir . 'codes.php';
        break;
    case 'seo-tags':
        include $__pagesDir . 'seo-tags.php';
        break;
    case 'headings':
        include $__pagesDir . 'headings.php';
        break;
    case 'redirect-chains':
        include $__pagesDir . 'redirect-chains.php';
        break;
    case 'sitemap':
        include $__pagesDir . 'sitemap.php';
        break;
    case 'duplication':
        include $__pagesDir . 'duplication.php';
        break;
    case 'extractions':
        include $__pagesDir . 'extractions.php';
        break;
    case 'structured-data':
        include $__pagesDir . 'structured-data.php';
        break;
    case 'content-richness':
        include $__pagesDir . 'content-richness.php';
        break;
    case 'accessibility':
        include $__pagesDir . 'accessibility.php';
        break;
    case 'pagerank':
        include $__pagesDir . 'pagerank.php';
        break;
    case 'pagerank-leak':
        include $__pagesDir . 'pagerank-leak.php';
        break;
    case 'inlinks':
        include $__pagesDir . 'inlinks.php';
        break;
    case 'outlinks':
        include $__pagesDir . 'outlinks.php';
        break;
    case 'response-time':
        include $__pagesDir . 'response-time.php';
        break;
    case 'categorize':
        // SÉCURITÉ: Vérifier les droits de gestion
        if (!$canManageCurrentProject) {
            echo '<div class="error-page" style="padding: 2rem; text-align: center;"><h1>' . __('dashboard.access_denied') . '</h1><p>' . __('dashboard.access_denied_desc') . '</p></div>';
        } else {
            include $__pagesDir . 'categorize.php';
        }
        break;
    case 'comparison-overview':
        include $__pagesDir . 'comparison-overview.php';
        break;
    case 'new-urls':
        include $__pagesDir . 'new-urls.php';
        break;
    case 'lost-urls':
        include $__pagesDir . 'lost-urls.php';
        break;
    case 'code-changes':
        include $__pagesDir . 'code-changes.php';
        break;
    case 'depth-comparison':
        include $__pagesDir . 'depth-comparison.php';
        break;
    case 'sitemap-comparison':
        include $__pagesDir . 'sitemap-comparison.php';
        break;
    case 'accessibility-comparison':
        include $__pagesDir . 'accessibility-comparison.php';
        break;
    case 'seo-tags-comparison':
        include $__pagesDir . 'seo-tags-comparison.php';
        break;
    case 'headings-comparison':
        include $__pagesDir . 'headings-comparison.php';
        break;
    case 'content-richness-comparison':
        include $__pagesDir . 'content-richness-comparison.php';
        break;
    case 'duplication-comparison':
        include $__pagesDir . 'duplication-comparison.php';
        break;
    case 'structured-data-comparison':
        include $__pagesDir . 'structured-data-comparison.php';
        break;
    case 'inlinks-comparison':
        include $__pagesDir . 'inlinks-comparison.php';
        break;
    case 'outlinks-comparison':
        include $__pagesDir . 'outlinks-comparison.php';
        break;
    case 'pagerank-comparison':
        include $__pagesDir . 'pagerank-comparison.php';
        break;
    case 'pagerank-leak-comparison':
        include $__pagesDir . 'pagerank-leak-comparison.php';
        break;
    case 'config':
        // SÉCURITÉ: Vérifier les droits de gestion
        if (!$canManageCurrentProject) {
            echo '<div class="error-page" style="padding: 2rem; text-align: center;"><h1>' . __('dashboard.access_denied') . '</h1><p>' . __('dashboard.access_denied_desc') . '</p></div>';
        } else {
            include $__pagesDir . 'config.php';
        }
        break;
    default:
        include $__pagesDir . 'home.php';
}
} // fin du else (crawl non vide)
