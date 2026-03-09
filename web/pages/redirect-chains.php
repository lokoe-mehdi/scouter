<?php
/**
 * ============================================================================
 * PAGE REDIRECT CHAINS - Analyse des chaînes de redirection
 * ============================================================================
 * Utilise les données pré-calculées dans redirect_chains et crawls.
 * Les calculs sont faits en post-traitement (PostProcessor::redirectChainAnalysis).
 */

// ============================================================================
// GARDE CONDITIONNELLE - Vérifier si follow_redirects est activé
// ============================================================================
$configData = is_string($crawlRecord->config) ? json_decode($crawlRecord->config, true) : (array)$crawlRecord->config;
$followRedirects = $configData['advanced']['follow_redirects'] ?? true;

if (!$followRedirects) {
    ?>
    <h1 class="page-title"><?= __('redirects.page_title') ?></h1>
    <div style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <span class="material-symbols-outlined" style="font-size: 4rem; color: var(--text-secondary); opacity: 0.5;">link_off</span>
        <h2 style="margin: 1rem 0; color: var(--text-primary);"><?= __('redirects.disabled_title') ?></h2>
        <p style="color: var(--text-secondary);"><?= __('redirects.disabled_desc') ?></p>
    </div>
    <?php
    return;
}

// ============================================================================
// DONNÉES PRÉ-CALCULÉES - Depuis crawls
// ============================================================================
$totalRedirectUrls = (int)($globalStats->redirect_total ?? 0);
$totalChains = (int)($globalStats->redirect_chains_count ?? 0);
$totalErrors = (int)($globalStats->redirect_chains_errors ?? 0);

/**
 * ============================================================================
 * AFFICHAGE HTML
 * ============================================================================
 */
?>

<h1 class="page-title"><?= __('redirects.page_title') ?></h1>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <!-- ========================================
         SECTION 1 : Cartes statistiques
         ======================================== -->
    <div class="scorecards">
        <?php
        Component::card([
            'color' => 'primary',
            'icon' => 'redo',
            'title' => __('redirects.card_redirect_urls'),
            'value' => number_format($totalRedirectUrls),
            'desc' => __('redirects.card_redirect_urls_desc')
        ]);

        Component::card([
            'color' => 'info',
            'icon' => 'route',
            'title' => __('redirects.card_chains'),
            'value' => number_format($totalChains),
            'desc' => __('redirects.card_chains_desc')
        ]);

        Component::card([
            'color' => 'danger',
            'icon' => 'warning',
            'title' => __('redirects.card_errors'),
            'value' => number_format($totalErrors),
            'desc' => __('redirects.card_errors_desc')
        ]);
        ?>
    </div>

    <!-- ========================================
         SECTION 2 : Tableau des chaînes de redirection
         ======================================== -->
    <?php
    Component::redirectTable([
        'title' => __('redirects.table_title'),
        'id' => 'redirectchainstable',
        'pdo' => $pdo,
        'crawlId' => $crawlId,
        'perPage' => 100,
        'defaultColumns' => ['source_url', 'source_code', 'hops', 'final_url', 'final_code', 'compliant'],
        'projectDir' => $_GET['project'] ?? ''
    ]);
    ?>

</div>
