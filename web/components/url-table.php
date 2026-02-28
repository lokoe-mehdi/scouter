<?php
/**
 * Composant réutilisable : Table d'URLs avec pagination AJAX
 * 
 * Paramètres requis dans $urlTableConfig :
 * - title : Titre du composant (string)
 * - id : ID unique du composant (string)
 * - sqlQuery : Requête SQL complète (string)
 * - sqlParams : Paramètres PDO (array)
 * - pdo : Connexion PDO (objet)
 * - projectDir : Répertoire du projet (string)
 * - defaultColumns : Colonnes visibles par défaut (array) - optionnel
 * 
 * @since 2.1.0 - Utilise les modules partagés table-core.php, data-table.js, data-table.css
 */

if(!isset($urlTableConfig) || !is_array($urlTableConfig)) {
    die('Configuration manquante pour le composant url-table. Utilisez $urlTableConfig = [...]');
}

// Charger les modules partagés
require_once __DIR__ . '/table-core.php';
require_once __DIR__ . '/scope-modal.php';

// Extraction des paramètres
$componentTitle = $urlTableConfig['title'] ?? 'Résultats';
$componentId = $urlTableConfig['id'] ?? 'table_' . uniqid();
$pdo = $urlTableConfig['pdo'] ?? null;
$projectDir = $urlTableConfig['projectDir'] ?? '';
$defaultColumns = $urlTableConfig['defaultColumns'] ?? ['url', 'depth', 'code', 'category'];
$perPage = $urlTableConfig['perPage'] ?? 100;
$crawlId = $urlTableConfig['crawlId'] ?? null;
$lightMode = $urlTableConfig['light'] ?? false;
$copyUrl = $urlTableConfig['copyUrl'] ?? false;
$hideTitle = $urlTableConfig['hideTitle'] ?? false;
$embedMode = $urlTableConfig['embedMode'] ?? false; // Mode AJAX - contenu minimal

if(!$pdo) {
    die('pdo est obligatoire dans $urlTableConfig');
}
if(!$crawlId) {
    die('crawlId est obligatoire dans $urlTableConfig');
}

// Récupérer les extracteurs personnalisés depuis JSONB automatiquement
$customExtractColumns = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT jsonb_object_keys(extracts) as key_name 
        FROM pages 
        WHERE crawl_id = :crawl_id AND extracts IS NOT NULL AND extracts != '{}'::jsonb
    ");
    $stmt->execute([':crawl_id' => $crawlId]);
    $customExtractColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Ignorer si pas d'extracteurs
}

// Utiliser le tableau centralisé des catégories (chargé dans dashboard.php)
$categoriesMap = $GLOBALS['categoriesMap'] ?? [];
$categoryColors = $GLOBALS['categoryColors'] ?? [];

// Stocker les paramètres pour construction SQL ultérieure
$useSimplifiedMode = isset($urlTableConfig['whereClause']);
$whereClause = $urlTableConfig['whereClause'] ?? 'WHERE c.crawled=1';
$orderBy = $urlTableConfig['orderBy'] ?? 'ORDER BY c.url';
$sqlParams = $urlTableConfig['sqlParams'] ?? [];
$sqlQuery = $urlTableConfig['sqlQuery'] ?? null;

// Extraire les conditions du WHERE pour le scope (fonction dans table-core.php)
$scopeItems = extractScopeFromWhereClause($whereClause);

// Construire la requête SQL pour le SQL Explorer
// 1. Colonnes basées sur defaultColumns
$sqlColumns = array_map(function($col) {
    // Mapper les noms de colonnes
    if ($col === 'category') return 'cat_id';
    return $col;
}, $defaultColumns);
$sqlColumnsStr = implode(', ', $sqlColumns);

// 2. WHERE sans crawl_id
$cleanedWhere = preg_replace('/\bc\./i', '', $whereClause);
$cleanedWhere = preg_replace('/\bcrawl_id\s*=\s*\d+\s+AND\s+/i', '', $cleanedWhere);
$cleanedWhere = preg_replace('/\s+AND\s+crawl_id\s*=\s*\d+/i', '', $cleanedWhere);
$cleanedWhere = preg_replace('/\bWHERE\s+crawl_id\s*=\s*\d+\s*$/i', '', $cleanedWhere);

// 3. ORDER BY sans alias
$cleanedOrderBy = preg_replace('/\bc\./i', '', $orderBy);

// 4. Construire la requête complète
$tableSqlQuery = "SELECT " . $sqlColumnsStr . "\nFROM pages\n" . $cleanedWhere . "\n" . $cleanedOrderBy;

// 5. Substituer les paramètres par leurs vraies valeurs (fonction dans table-core.php)
$tableSqlQuery = substituteParamsInSql($tableSqlQuery, $sqlParams);

// Substituer également dans les scopeItems
if (!empty($scopeItems) && !empty($sqlParams)) {
    $scopeItems = array_map(function($item) use ($sqlParams) {
        return substituteParamsInSql($item, $sqlParams);
    }, $scopeItems);
}

// Remplacer "category" par "c.cat_id" dans ORDER BY (plus de jointure sur categories)
$orderBy = preg_replace('/\bcategory\b/', 'c.cat_id', $orderBy);

// Colonnes disponibles
$availableColumns = [
    'url' => 'URL',
    'depth' => 'Profondeur',
    'code' => 'Code HTTP',
    'category' => 'Catégorie',
    'inlinks' => 'Liens entrants',
    'outlinks' => 'Liens sortants',
    'response_time' => 'TTFB (ms)',
    'schemas' => 'Données structurées',
    'compliant' => 'Indexable',
    'canonical' => 'Canonical',
    'canonical_value' => 'URL Canonical',
    'noindex' => 'Noindex',
    'nofollow' => 'Nofollow',
    'blocked' => 'Bloqué',
    'redirect_to' => 'Redirige vers',
    'content_type' => 'Type de contenu',
    'pri' => 'PageRank',
    'title_status' => 'Title Status',
    'title' => 'Title',
    'h1_status' => 'H1 Status',
    'h1' => 'H1',
    'metadesc_status' => 'Meta Desc Status',
    'metadesc' => 'Meta Description',
    'h1_multiple' => 'H1 Multiples',
    'headings_missing' => 'Mauvaise structure hn',
    'word_count' => 'Nb mots'
];

// Ajout des colonnes d'extracteurs JSONB aux colonnes disponibles
foreach($customExtractColumns as $columnName) {
    // Créer un label lisible
    $label = ucwords(str_replace('_', ' ', $columnName));
    $availableColumns['extract_' . $columnName] = 'Extracteur : ' . $label;
}

// Récupération des colonnes sélectionnées (compatibilité avec anciens paramètres)
if($componentId === 'main_explorer' && isset($_GET['columns'])) {
    $selectedColumns = explode(',', $_GET['columns']);
} else {
    $selectedColumns = isset($_GET['columns_' . $componentId]) ? explode(',', $_GET['columns_' . $componentId]) : $defaultColumns;
}
if(empty($selectedColumns)) {
    $selectedColumns = ['url'];
}

// Remplacer 'cstm' par toutes les colonnes cstm_* trouvées
if(in_array('cstm', $selectedColumns)) {
    $newSelectedColumns = [];
    foreach($selectedColumns as $col) {
        if($col === 'cstm') {
            // Remplacer par toutes les colonnes custom
            $newSelectedColumns = array_merge($newSelectedColumns, $customColumns);
        } else {
            $newSelectedColumns[] = $col;
        }
    }
    $selectedColumns = $newSelectedColumns;
}

// Réordonner les colonnes sélectionnées selon l'ordre de $availableColumns
$orderedColumns = [];
foreach(array_keys($availableColumns) as $col) {
    if(in_array($col, $selectedColumns)) {
        $orderedColumns[] = $col;
    }
}
// Ajouter les colonnes qui ne sont pas dans availableColumns (colonnes custom)
foreach($selectedColumns as $col) {
    if(!in_array($col, $orderedColumns)) {
        $orderedColumns[] = $col;
    }
}
$selectedColumns = $orderedColumns;

// Récupération du tri depuis l'URL
$sortColumn = null;
$sortDirection = 'ASC';
if($componentId === 'main_explorer' && isset($_GET['sort'])) {
    $sortColumn = $_GET['sort'];
    $sortDirection = isset($_GET['dir']) && strtoupper($_GET['dir']) === 'DESC' ? 'DESC' : 'ASC';
} elseif(isset($_GET['sort_' . $componentId])) {
    $sortColumn = $_GET['sort_' . $componentId];
    $sortDirection = isset($_GET['dir_' . $componentId]) && strtoupper($_GET['dir_' . $componentId]) === 'DESC' ? 'DESC' : 'ASC';
}

// Mapper les colonnes vers leurs vraies colonnes SQL
$columnMapping = [
    'url' => 'c.url',
    'depth' => 'c.depth',
    'code' => 'c.code',
    'inlinks' => 'c.inlinks',
    'outlinks' => 'c.outlinks',
    'response_time' => 'c.response_time',
    'schemas' => 'array_length(c.schemas, 1)',
    'compliant' => 'c.compliant',
    'canonical' => 'c.canonical',
    'canonical_value' => 'c.canonical_value',
    'noindex' => 'c.noindex',
    'nofollow' => 'c.nofollow',
    'blocked' => 'c.blocked',
    'redirect_to' => 'c.redirect_to',
    'content_type' => 'c.content_type',
    'pri' => 'c.pri',
    'title' => 'c.title',
    'title_status' => 'c.title_status',
    'h1' => 'c.h1',
    'h1_status' => 'c.h1_status',
    'metadesc' => 'c.metadesc',
    'metadesc_status' => 'c.metadesc_status',
    'category' => 'c.cat_id',
    'h1_multiple' => 'c.h1_multiple',
    'headings_missing' => 'c.headings_missing',
    'word_count' => 'c.word_count'
];

// Ajouter les colonnes extract_* au mapping pour le tri
foreach($customExtractColumns as $col) {
    $colAlias = 'extract_' . preg_replace('/[^a-z0-9_]/i', '_', $col);
    $columnMapping[$colAlias] = "c.extracts->>'" . addslashes($col) . "'";
}

// Si un tri est demandé, remplacer l'ORDER BY par défaut
if($sortColumn && isset($columnMapping[$sortColumn])) {
    $orderBy = 'ORDER BY ' . $columnMapping[$sortColumn] . ' ' . $sortDirection;
}

// Reconstruire la requête SQL avec le bon ORDER BY (PostgreSQL)
if($useSimplifiedMode) {
    // Injecter le crawl_id dans le WHERE
    $crawlIdCondition = "c.crawl_id = " . intval($crawlId);
    
    // Vérifier si WHERE existe déjà
    if(stripos($whereClause, 'WHERE') !== false) {
        // Ajouter le crawl_id après WHERE
        $whereClause = preg_replace('/WHERE\s+/i', 'WHERE ' . $crawlIdCondition . ' AND ', $whereClause);
    } else {
        $whereClause = 'WHERE ' . $crawlIdCondition;
    }
    
    // Construire les colonnes JSONB pour les extracteurs custom
    $jsonbColumns = '';
    foreach($customExtractColumns as $colName) {
        $jsonbColumns .= ", c.extracts->>'" . addslashes($colName) . "' as extract_" . preg_replace('/[^a-z0-9_]/i', '_', $colName);
    }
    
    // OPTIMISATION : Plus de jointure sur categories, on utilise le tableau PHP
    $sqlQuery = "SELECT 
        c.url,
        c.depth,
        c.code,
        c.inlinks,
        c.outlinks,
        c.response_time,
        c.schemas,
        c.compliant,
        c.canonical,
        c.canonical_value,
        c.noindex,
        c.nofollow,
        c.blocked,
        c.redirect_to,
        c.content_type,
        c.pri,
        c.title,
        c.title_status,
        c.h1,
        c.h1_status,
        c.metadesc,
        c.metadesc_status,
        c.cat_id,
        c.h1_multiple,
        c.headings_missing,
        c.word_count
        $jsonbColumns
        FROM pages c
        $whereClause
        $orderBy";
}

// Récupération du perPage depuis l'URL (compatibilité avec anciens paramètres)
if($componentId === 'main_explorer' && isset($_GET['per_page'])) {
    $perPage = max(10, min(500, (int)$_GET['per_page']));
} elseif(isset($_GET['per_page_' . $componentId])) {
    $perPage = max(10, min(500, (int)$_GET['per_page_' . $componentId]));
}

// Pagination (compatibilité avec anciens paramètres)
if($componentId === 'main_explorer' && isset($_GET['p'])) {
    $page_num = max(1, (int)$_GET['p']);
} else {
    $page_num = isset($_GET['p_' . $componentId]) ? max(1, (int)$_GET['p_' . $componentId]) : 1;
}
$offset = ($page_num - 1) * $perPage;

// Comptage total (extraire la requête COUNT depuis la requête principale)
$countQuery = preg_replace('/SELECT\s+.*?\s+FROM/is', 'SELECT COUNT(*) as total FROM', $sqlQuery);
$countQuery = preg_replace('/ORDER BY\s+.*/is', '', $countQuery);
$countQuery = preg_replace('/LIMIT\s+.*/is', '', $countQuery);

$sqlCount = $pdo->prepare($countQuery);
$sqlCount->execute($sqlParams);
$result = $sqlCount->fetch(PDO::FETCH_OBJ);
$totalResults = $result ? $result->total : 0;
$totalPages = ceil($totalResults / $perPage);

// Exécution de la requête principale avec pagination
$paginatedQuery = $sqlQuery;
if(!preg_match('/LIMIT/i', $paginatedQuery)) {
    $paginatedQuery .= " LIMIT $perPage OFFSET $offset";
} else {
    $paginatedQuery = preg_replace('/LIMIT \d+/i', "LIMIT $perPage", $paginatedQuery);
    $paginatedQuery = preg_replace('/OFFSET \d+/i', "OFFSET $offset", $paginatedQuery);
}

$sql = $pdo->prepare($paginatedQuery);
$sql->execute($sqlParams);
$urls = $sql->fetchAll(PDO::FETCH_OBJ);
?>

<!-- Formulaire caché pour l'export CSV -->
<form id="exportForm_<?= $componentId ?>" method="POST" action="api/export/csv?project=<?= htmlspecialchars($crawlId ?? $projectDir) ?>" target="_blank" style="display: none;">
    <input type="hidden" name="filters" value="">
    <input type="hidden" name="search" value="">
    <input type="hidden" name="columns" id="exportColumns_<?= $componentId ?>" value="">
</form>

<!-- Résultats -->
<?php if(!$lightMode): ?>
<div class="table-card" id="tableCard_<?= $componentId ?>">
<?php endif; ?>
<?php if(!$hideTitle): ?>
    <div class="table-header" style="padding: 0rem 0rem 0rem 0rem; display: block !important;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
            <!-- Gauche : Titre -->
            <h3 class="table-title" style="margin: 0;">
                <?= htmlspecialchars($componentTitle) ?> (<?= number_format($totalResults ?? 0) ?> URLs)
            </h3>
            
            <?php if(!$lightMode): ?>
            <!-- Droite : Scope + Copier + Export CSV -->
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <span onclick="showTableScope_<?= $componentId ?>()" class="chart-action-btn" title="Voir le scope des données" style="cursor: pointer;">
                    <span class="material-symbols-outlined">database</span>
                </span>
                <button class="btn-table-action btn-copy" onclick="copyTableToClipboard_<?= $componentId ?>(event)">
                    <span class="material-symbols-outlined">content_copy</span>
                    Copier
                </button>
                <button class="btn-table-action btn-export" onclick="exportToCSV_<?= $componentId ?>()">
                    <span class="material-symbols-outlined">download</span>
                    Export CSV
                </button>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Ligne du bas : Colonnes à gauche, Pagination à droite -->
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <!-- Gauche : Colonnes -->
            <div style="position: relative;">
                <button class="btn-table-action btn-columns-select" onclick="toggleColumnDropdown_<?= $componentId ?>()">
                    <span class="material-symbols-outlined">view_column</span>
                    Colonnes
                </button>
                <div id="columnDropdown_<?= $componentId ?>" class="column-dropdown-<?= $componentId ?>" style="display: none; position: absolute; left: 0; top: 100%; margin-top: 0.5rem; background: white; border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 250px; max-height: 450px; z-index: 1000; flex-direction: column;">
                    <!-- Header fixe -->
                    <div style="padding: 1rem 1rem 0.5rem 1rem; border-bottom: 1px solid var(--border-color); background: white; border-radius: 8px 8px 0 0;">
                        <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">Sélectionner les colonnes</div>
                        <div style="font-size: 0.85rem; color: var(--text-secondary);">
                            <a href="javascript:void(0)" onclick="toggleAllColumns_<?= $componentId ?>(true)" style="color: var(--primary-color); text-decoration: none; cursor: pointer;">tout cocher</a>
                            <span style="margin: 0 0.25rem; color: var(--border-color);">|</span>
                            <a href="javascript:void(0)" onclick="toggleAllColumns_<?= $componentId ?>(false)" style="color: var(--text-secondary); text-decoration: none; cursor: pointer;">tout décocher</a>
                        </div>
                    </div>
                    
                    <!-- Liste scrollable des colonnes -->
                    <div style="flex: 1; overflow-y: auto; padding: 0.5rem 1rem; max-height: 280px;">
                        <?php foreach($availableColumns as $key => $label): ?>
                        <label style="display: block; padding: 0.5rem; cursor: pointer; border-radius: 4px; transition: background 0.2s;" onmouseover="this.style.background='var(--background)'" onmouseout="this.style.background='transparent'">
                            <input type="checkbox" class="column-checkbox-<?= $componentId ?>" value="<?= $key ?>" 
                                <?= in_array($key, $selectedColumns) ? 'checked' : '' ?>
                                <?= $key === 'url' ? 'disabled' : '' ?>
                                style="margin-right: 0.5rem; accent-color: var(--primary-color);">
                            <?= $label ?><?= $key === 'url' ? ' (obligatoire)' : '' ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Footer fixe avec boutons -->
                    <div style="padding: 1rem; border-top: 1px solid var(--border-color); background: white; border-radius: 0 0 8px 8px; display: flex; gap: 0.5rem;">
                        <button class="btn" onclick="applyColumns_<?= $componentId ?>()" style="flex: 1; background: var(--primary-color); color: white; border: none; padding: 0.6rem; font-weight: 500;">Appliquer</button>
                        <button class="btn" onclick="toggleColumnDropdown_<?= $componentId ?>()" style="flex: 1; background: #95a5a6; color: white; border: none; padding: 0.6rem; font-weight: 500;">Annuler</button>
                    </div>
                </div>
            </div>
            
            <!-- Droite : Pagination -->
            <div id="paginationTop_<?= $componentId ?>" style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; color: var(--text-secondary);">
                <span id="paginationInfo_<?= $componentId ?>">Affichage de <?= number_format(($offset ?? 0) + 1) ?> à <?= number_format(min(($offset ?? 0) + $perPage, $totalResults ?? 0)) ?> sur <?= number_format($totalResults ?? 0) ?> URLs</span>
                <button onclick="changePage_<?= $componentId ?>(<?= max(1, $page_num - 1) ?>)" <?= $page_num <= 1 ? 'disabled' : '' ?> style="padding: 0.4rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; <?= $page_num <= 1 ? 'opacity: 0.4; cursor: default;' : '' ?>" onmouseover="<?= $page_num > 1 ? 'this.style.background=\"#f8f9fa\"; this.style.borderColor=\"#adb5bd\"' : '' ?>" onmouseout="<?= $page_num > 1 ? 'this.style.background=\"white\"; this.style.borderColor=\"#dee2e6\"' : '' ?>">
                    <span class="material-symbols-outlined" style="font-size: 20px;">chevron_left</span>
                </button>
                <button onclick="changePage_<?= $componentId ?>(<?= min($totalPages, $page_num + 1) ?>)" <?= $page_num >= $totalPages ? 'disabled' : '' ?> style="padding: 0.4rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; <?= $page_num >= $totalPages ? 'opacity: 0.4; cursor: default;' : '' ?>" onmouseover="<?= $page_num < $totalPages ? 'this.style.background=\"#f8f9fa\"; this.style.borderColor=\"#adb5bd\"' : '' ?>" onmouseout="<?= $page_num < $totalPages ? 'this.style.background=\"white\"; this.style.borderColor=\"#dee2e6\"' : '' ?>">
                    <span class="material-symbols-outlined" style="font-size: 20px;">chevron_right</span>
                </button>
            </div>
        </div>
    </div>
<?php endif; // hideTitle ?>

    <!-- Zone scrollable du tableau -->
    <div class="table-scroll-area" id="tableScrollArea_<?= $componentId ?>">
    <?php if(!$hideTitle): ?>
    <!-- Barre de scroll horizontale du haut (se synchronise avec celle du bas) -->
    <div id="topScrollbar_<?= $componentId ?>" style="overflow-x: auto; overflow-y: hidden; margin-bottom: 0.5rem;">
        <div id="topScrollbarContent_<?= $componentId ?>" style="height: 1px;"></div>
    </div>
    <?php endif; ?>

    <div id="tableContainer_<?= $componentId ?>" style="<?= $hideTitle ? '' : 'overflow-x: auto;' ?>">
        <table class="data-table" id="urlTable_<?= $componentId ?>">
            <?php
            // Tooltips pour les colonnes
            $columnTooltips = [
                'response_time' => 'Time To First Byte',
                'pri' => 'PageRank Interne - Score d\'autorité basé sur les liens internes',
                'compliant' => 'URL indexable (pas de noindex, canonical ok, non bloquée)',
            ];
            ?>
            <thead>
                <tr>
                    <?php foreach($selectedColumns as $col): ?>
                        <?php if(isset($availableColumns[$col])): ?>
                            <?php $tooltip = $columnTooltips[$col] ?? ''; ?>
                            <th class="col-<?= $col ?>" style="cursor: pointer; user-select: none; position: relative;" onclick="sortByColumn_<?= $componentId ?>('<?= $col ?>')" <?= $tooltip ? 'title="' . htmlspecialchars($tooltip) . '"' : '' ?>>
                                <div style="display: flex; align-items: center; gap: 0.3rem;">
                                    <span><?= $availableColumns[$col] ?></span>
                                    <?php if($sortColumn === $col): ?>
                                        <span class="material-symbols-outlined" style="font-size: 18px; color: var(--primary-color);">
                                            <?= $sortDirection === 'ASC' ? 'arrow_upward' : 'arrow_downward' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="material-symbols-outlined" style="font-size: 18px; color: #bdc3c7; opacity: 0.5;">
                                            unfold_more
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($urls)): ?>
                <tr>
                    <td colspan="<?= count($selectedColumns) ?>" style="text-align: center; padding: 4rem 2rem;">
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 1rem; color: var(--text-secondary);">
                            <span class="material-symbols-outlined" style="font-size: 64px; color: #95a5a6; opacity: 0.5;">search_off</span>
                            <div style="font-size: 1.25rem; font-weight: 600; color: var(--text-primary);">Aucun résultat</div>
                            <div style="font-size: 0.9rem; max-width: 400px; line-height: 1.5;">Aucune URL ne correspond aux critères de recherche actuels.</div>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach($urls as $url): ?>
                <tr>
                    <?php foreach($selectedColumns as $col): ?>
                        <?php if($col === 'url'): ?>
                            <td class="col-url" style="max-width: 400px; position: relative;">
                                <div style="display: flex; align-items: center; overflow: hidden;">
                                    <?php if($copyUrl): ?>
                                    <span class="copy-path-btn" data-path="<?= htmlspecialchars(parse_url($url->url, PHP_URL_PATH) ?: '/') ?>" title="Copier le chemin" style="cursor: pointer; color: var(--text-secondary); margin-right: 0.4rem; flex-shrink: 0;" onclick="event.preventDefault(); event.stopPropagation(); navigator.clipboard.writeText(this.dataset.path).then(() => { if(typeof showGlobalStatus === 'function') showGlobalStatus('Chemin copié', 'success'); })">
                                        <span class="material-symbols-outlined" style="font-size: 16px;">content_copy</span>
                                    </span>
                                    <?php endif; ?>
                                    <span class="url-clickable" data-url="<?= htmlspecialchars($url->url) ?>" style="cursor: pointer; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0;">
                                        <?= htmlspecialchars($url->url) ?>
                                    </span>
                                    <a href="<?= htmlspecialchars($url->url) ?>" target="_blank" rel="noopener noreferrer" title="Ouvrir l'URL dans un nouvel onglet" style="display: inline-flex; align-items: center; color: var(--text-secondary); text-decoration: none; margin-left: 0.5rem; flex-shrink: 0;">
                                        <span class="material-symbols-outlined" style="font-size: 16px;">open_in_new</span>
                                    </a>
                                </div>
                            </td>
                        <?php elseif($col === 'depth'): ?>
                            <td class="col-depth"><span class="badge badge-info"><?= $url->depth ?></span></td>
                        <?php elseif($col === 'code'): ?>
                            <td class="col-code">
                                <?php
                                $code = (int)$url->code;
                                $textColor = function_exists('getCodeColor') ? getCodeColor($code) : '#95a5a6';
                                $bgColor = function_exists('getCodeBackgroundColor') ? getCodeBackgroundColor($code, 0.3) : 'rgba(149, 165, 166, 0.3)';
                                // Utiliser getCodeDisplayValue pour afficher "JS Redirect" au lieu de 311
                                $displayValue = function_exists('getCodeDisplayValue') ? getCodeDisplayValue($code) : $url->code;
                                ?>
                                <span class="badge" style="background: <?= $bgColor ?>; color: <?= $textColor ?>; font-weight: 600;">
                                    <?= htmlspecialchars($displayValue) ?>
                                </span>
                            </td>
                        <?php elseif($col === 'category'): ?>
                            <td class="col-category">
                                <?php
                                // Utiliser le tableau centralisé au lieu de jointure SQL
                                $catId = $url->cat_id ?? null;
                                $catInfo = $categoriesMap[$catId] ?? null;
                                $category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
                                $bgColor = $catInfo ? ($catInfo['color'] ?? '#aaaaaa') : '#aaaaaa';
                                $textColor = function_exists('getTextColorForBackground') ? getTextColorForBackground($bgColor) : '#fff';
                                ?>
                                <span class="badge" style="background: <?= $bgColor ?>; color: <?= $textColor ?>;">
                                    <?= htmlspecialchars($category) ?>
                                </span>
                            </td>
                        <?php elseif($col === 'canonical_value' || $col === 'redirect_to'): ?>
                            <td class="col-<?= $col ?>" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($url->$col ?? '') ?>">
                                <?= htmlspecialchars($url->$col ?? '') ?>
                            </td>
                        <?php elseif($col === 'compliant' || $col === 'canonical' || $col === 'noindex' || $col === 'nofollow' || $col === 'blocked' || $col === 'h1_multiple' || $col === 'headings_missing'): ?>
                            <td class="col-<?= $col ?>" style="text-align: center;">
                                <?= $url->$col ? '<span class="material-symbols-outlined" style="color: #6bd899; font-size: 1.2rem; opacity: 0.8;">check_circle</span>' : '<span class="material-symbols-outlined" style="color: #95a5a6; font-size: 1.2rem; opacity: 0.7;">cancel</span>' ?>
                            </td>
                        <?php elseif($col === 'pri'): ?>
                            <td class="col-pri"><?= number_format(($url->pri ?? 0) * 100, 4) ?>%</td>
                        <?php elseif($col === 'response_time'): ?>
                            <td class="col-response_time"><?= round($url->response_time ?? 0, 2) ?> ms</td>
                        <?php elseif($col === 'schemas'): ?>
                            <?php
                            $schemasCount = 0;
                            if (!empty($url->schemas) && $url->schemas !== '{}') {
                                $schemasStr = trim($url->schemas, '{}');
                                if (!empty($schemasStr)) {
                                    $schemasCount = count(explode(',', $schemasStr));
                                }
                            }
                            ?>
                            <td class="col-schemas" style="text-align: center;"><?= $schemasCount ?></td>
                        <?php elseif($col === 'title' || $col === 'h1' || $col === 'metadesc'): ?>
                            <td class="col-<?= $col ?>" style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($url->$col ?? '') ?>">
                                <?= htmlspecialchars($url->$col ?? '') ?>
                            </td>
                        <?php elseif($col === 'title_status' || $col === 'h1_status' || $col === 'metadesc_status'): ?>
                            <td class="col-<?= $col ?>" style="text-align: center;">
                                <?php
                                $status = strtolower($url->$col ?? '');
                                if($status === 'unique') {
                                    echo '<span class="badge badge-success">Unique</span>';
                                } elseif($status === 'duplicate') {
                                    echo '<span class="badge badge-warning">Duplicate</span>';
                                } elseif($status === 'empty') {
                                    echo '<span class="badge badge-danger">Empty</span>';
                                } else {
                                    echo '<span style="color: #95a5a6;">—</span>';
                                }
                                ?>
                            </td>
                        <?php elseif($col === 'redirect_to'): ?>
                            <td class="col-redirect_to" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($url->redirect_to ?? '') ?>">
                                <?= htmlspecialchars($url->redirect_to ?? '') ?>
                            </td>
                        <?php elseif($col === 'word_count'): ?>
                            <?php
                            $wc = $url->word_count ?? 0;
                            // Couleurs selon tranches: Pauvre <=250, Moyen 250-500, Riche 500-1200, Premium 1200+
                            if ($wc <= 250) {
                                $wcColor = '#dc3545'; // Rouge - pauvre
                                $wcBg = 'rgba(220, 53, 69, 0.1)';
                            } elseif ($wc <= 500) {
                                $wcColor = '#fd7e14'; // Orange - moyen
                                $wcBg = 'rgba(253, 126, 20, 0.1)';
                            } elseif ($wc <= 1200) {
                                $wcColor = '#20c997'; // Vert clair - riche
                                $wcBg = 'rgba(32, 201, 151, 0.1)';
                            } else {
                                $wcColor = '#28a745'; // Vert foncé - premium
                                $wcBg = 'rgba(40, 167, 69, 0.1)';
                            }
                            ?>
                            <td class="col-word_count" style="text-align: right;">
                                <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.85em; font-weight: 500; color: <?= $wcColor ?>; background: <?= $wcBg ?>; border: 1px solid <?= $wcColor ?>33;">
                                    <?= number_format($wc, 0, ',', ' ') ?>
                                </span>
                            </td>
                        <?php elseif(strpos($col, 'cstm_') === 0 || strpos($col, 'extract_') === 0): ?>
                            <td class="col-<?= $col ?>" style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($url->$col ?? '') ?>">
                                <?= $url->$col ? htmlspecialchars($url->$col) : '<span style="color: #95A5A6;">—</span>' ?>
                            </td>
                        <?php else: ?>
                            <td class="col-<?= $col ?>"><?= htmlspecialchars($url->$col ?? '') ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    </div><!-- Fin table-scroll-area -->

    <!-- Barre de gestion en bas (fixe) -->
    <div class="table-footer-bar" style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem;">
        <!-- Gauche : Bouton colonnes (si hideTitle) + Sélecteur par page -->
        <div style="display: flex; align-items: center; gap: 1rem; font-size: 0.85rem; color: var(--text-secondary);">
            <?php if($hideTitle): ?>
            <!-- Bouton Colonnes -->
            <div style="position: relative;">
                <button class="btn-table-action btn-columns-select" onclick="toggleColumnDropdown_<?= $componentId ?>()">
                    <span class="material-symbols-outlined">view_column</span>
                    Colonnes
                </button>
                <div id="columnDropdown_<?= $componentId ?>" class="column-dropdown-<?= $componentId ?>" style="display: none; position: absolute; left: 0; bottom: 100%; margin-bottom: 0.5rem; background: white; border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 250px; max-height: 450px; z-index: 1000; flex-direction: column;">
                    <div style="padding: 1rem 1rem 0.5rem 1rem; border-bottom: 1px solid var(--border-color); background: white; border-radius: 8px 8px 0 0;">
                        <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">Sélectionner les colonnes</div>
                        <div style="font-size: 0.85rem; color: var(--text-secondary);">
                            <a href="javascript:void(0)" onclick="toggleAllColumns_<?= $componentId ?>(true)" style="color: var(--primary-color); text-decoration: none; cursor: pointer;">tout cocher</a>
                            <span style="margin: 0 0.25rem; color: var(--border-color);">|</span>
                            <a href="javascript:void(0)" onclick="toggleAllColumns_<?= $componentId ?>(false)" style="color: var(--text-secondary); text-decoration: none; cursor: pointer;">tout décocher</a>
                        </div>
                    </div>
                    <div style="flex: 1; overflow-y: auto; padding: 0.5rem 1rem; max-height: 280px;">
                        <?php foreach($availableColumns as $key => $label): ?>
                        <label style="display: block; padding: 0.5rem; cursor: pointer; border-radius: 4px; transition: background 0.2s;" onmouseover="this.style.background='var(--background)'" onmouseout="this.style.background='transparent'">
                            <input type="checkbox" class="column-checkbox-<?= $componentId ?>" value="<?= $key ?>" 
                                <?= in_array($key, $selectedColumns) ? 'checked' : '' ?>
                                <?= $key === 'url' ? 'disabled' : '' ?>
                                style="margin-right: 0.5rem; accent-color: var(--primary-color);">
                            <?= $label ?><?= $key === 'url' ? ' (obligatoire)' : '' ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="padding: 1rem; border-top: 1px solid var(--border-color); background: white; border-radius: 0 0 8px 8px; display: flex; gap: 0.5rem;">
                        <button class="btn" onclick="applyColumns_<?= $componentId ?>()" style="flex: 1; background: var(--primary-color); color: white; border: none; padding: 0.6rem; font-weight: 500;">Appliquer</button>
                        <button class="btn" onclick="toggleColumnDropdown_<?= $componentId ?>()" style="flex: 1; background: #95a5a6; color: white; border: none; padding: 0.6rem; font-weight: 500;">Annuler</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if(!$hideTitle): ?>
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span>Afficher</span>
                <div style="position: relative;">
                    <button id="perPageBtn_<?= $componentId ?>" onclick="togglePerPageDropdown_<?= $componentId ?>()" style="padding: 0.4rem 0.6rem; border: 1px solid #dee2e6; border-radius: 4px; background: white; cursor: pointer; font-size: 0.85rem; display: flex; align-items: center; gap: 0.3rem; transition: all 0.2s ease;">
                        <span id="perPageValue_<?= $componentId ?>"><?= $perPage ?></span>
                        <span class="material-symbols-outlined" style="font-size: 14px;">expand_more</span>
                    </button>
                    <div id="perPageDropdown_<?= $componentId ?>" class="per-page-dropdown-<?= $componentId ?>" style="display: none; position: absolute; left: 0; bottom: 100%; margin-bottom: 0.25rem; background: white; border: 1px solid #dee2e6; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 1000; min-width: 60px;">
                        <div onclick="selectPerPage_<?= $componentId ?>(10)" style="padding: 0.4rem 0.6rem; cursor: pointer; <?= $perPage == 10 ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $perPage == 10 ? '#f8f9fa' : 'white' ?>'">10</div>
                        <div onclick="selectPerPage_<?= $componentId ?>(50)" style="padding: 0.4rem 0.6rem; cursor: pointer; <?= $perPage == 50 ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $perPage == 50 ? '#f8f9fa' : 'white' ?>'">50</div>
                        <div onclick="selectPerPage_<?= $componentId ?>(100)" style="padding: 0.4rem 0.6rem; cursor: pointer; <?= $perPage == 100 ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $perPage == 100 ? '#f8f9fa' : 'white' ?>'">100</div>
                        <div onclick="selectPerPage_<?= $componentId ?>(500)" style="padding: 0.4rem 0.6rem; cursor: pointer; <?= $perPage == 500 ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $perPage == 500 ? '#f8f9fa' : 'white' ?>'">500</div>
                    </div>
                </div>
                <span>par page</span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Droite : Pagination -->
        <div id="paginationBottom_<?= $componentId ?>" style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: var(--text-secondary);">
            <span id="paginationInfoBottom_<?= $componentId ?>"><?= number_format(($offset ?? 0) + 1) ?>-<?= number_format(min(($offset ?? 0) + $perPage, $totalResults ?? 0)) ?> sur <?= number_format($totalResults ?? 0) ?></span>
            <button onclick="changePage_<?= $componentId ?>(<?= max(1, $page_num - 1) ?>)" <?= $page_num <= 1 ? 'disabled' : '' ?> style="padding: 0.3rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; <?= $page_num <= 1 ? 'opacity: 0.4; cursor: default;' : '' ?>">
                <span class="material-symbols-outlined" style="font-size: 18px;">chevron_left</span>
            </button>
            <button onclick="changePage_<?= $componentId ?>(<?= min($totalPages, $page_num + 1) ?>)" <?= $page_num >= $totalPages ? 'disabled' : '' ?> style="padding: 0.3rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; <?= $page_num >= $totalPages ? 'opacity: 0.4; cursor: default;' : '' ?>">
                <span class="material-symbols-outlined" style="font-size: 18px;">chevron_right</span>
            </button>
        </div>
    </div>
<?php if(!$lightMode): ?>
</div>
<?php endif; ?>

<?php if(!$embedMode): // Ne pas inclure styles/scripts en mode embed ?>
<style>
/* Animation dropdown colonnes */
@keyframes slideInDown {
    from {
        transform: translateY(-10px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.column-dropdown-<?= $componentId ?>.show {
    animation: slideInDown 0.2s ease-out;
}

/* Style bouton colonnes */
.btn-column-selector:hover {
    background: #e9ecef !important;
    border-color: #adb5bd !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.btn-column-selector:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

/* Style dropdown perPage */
#perPageBtn_<?= $componentId ?>:hover {
    background: #f8f9fa !important;
    border-color: #adb5bd !important;
}

.per-page-dropdown-<?= $componentId ?>.show {
    animation: slideInUp 0.15s ease-out;
}

/* Style en-têtes de colonnes triables */
#urlTable_<?= $componentId ?> thead th {
    transition: background 0.15s ease;
}

#urlTable_<?= $componentId ?> thead th:hover {
    background: #f8f9fa;
}

@keyframes slideInUp {
    from {
        transform: translateY(5px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}
</style>

<script>
(function() {
    const componentId = '<?= $componentId ?>';
    const totalPages = <?= $totalPages ?>;
    const totalResults = <?= $totalResults ?>;
    const perPage = <?= $perPage ?>;
    let currentPage = <?= $page_num ?>;
    let currentPerPage = <?= $perPage ?>; // Variable mutable pour le perPage actuel
    let currentTotalPages = <?= $totalPages ?>; // Variable mutable pour totalPages
    const isLightMode = <?= $lightMode ? 'true' : 'false' ?>;
    let isLoading = false; // Anti-spam pagination
    
    // Fonction pour désactiver/activer les boutons de pagination
    function setPaginationLoading(loading) {
        isLoading = loading;
        const buttons = document.querySelectorAll('#paginationTop_' + componentId + ' button, #paginationBottom_' + componentId + ' button');
        buttons.forEach(btn => {
            btn.disabled = loading;
            btn.style.opacity = loading ? '0.5' : '1';
        });
    }

    // Fonction de changement du nombre d'éléments par page en AJAX
    window['changePerPage_' + componentId] = function(newPerPage) {
        const params = new URLSearchParams(window.location.search);
        const perPageParam = (componentId === 'main_explorer') ? 'per_page' : 'per_page_' + componentId;
        params.set(perPageParam, newPerPage);
        
        // Revenir à la page 1 quand on change le perPage
        const pageParam = (componentId === 'main_explorer') ? 'p' : 'p_' + componentId;
        params.set(pageParam, 1);
        
        // En mode light, recharger la page directement
        if(isLightMode) {
            window.location.href = window.location.pathname + '?' + params.toString();
            return;
        }
        
        // Mettre à jour l'URL sans recharger
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);
        
        // Charger les données en AJAX
        fetch(newUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTableCard = doc.querySelector('#tableCard_' + componentId);
            
            if(newTableCard) {
                const currentTableCard = document.getElementById('tableCard_' + componentId);
                currentTableCard.innerHTML = newTableCard.innerHTML;
                
                // Réinitialiser currentPage à 1
                currentPage = 1;
                
                // Mettre à jour le perPage actuel
                currentPerPage = newPerPage;
                
                // Recalculer totalPages avec le nouveau perPage
                currentTotalPages = Math.ceil(totalResults / newPerPage);
                
                // Mettre à jour les boutons de pagination avec les bonnes valeurs
                attachPaginationHandlers();
                
                // Rafraîchir les handlers de la modale
                if(typeof refreshUrlModalHandlers === 'function') {
                    refreshUrlModalHandlers();
                }
            }
        })
        .catch(error => {
            console.error('Erreur lors du changement de perPage:', error);
        });
    };

    // Fonction de changement de page en AJAX
    window['changePage_' + componentId] = function(page) {
        if(page < 1 || page === currentPage || isLoading) return;
        
        currentPage = page;
        const params = new URLSearchParams(window.location.search);
        // Compatibilité avec anciens paramètres pour main_explorer
        const pageParam = (componentId === 'main_explorer') ? 'p' : 'p_' + componentId;
        params.set(pageParam, page);
        
        // En mode light, recharger la page directement
        if(isLightMode) {
            window.location.href = window.location.pathname + '?' + params.toString();
            return;
        }
        
        // Désactiver les boutons pendant le chargement
        setPaginationLoading(true);
        
        // Mettre à jour l'URL sans recharger
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({page: page}, '', newUrl);
        
        // Charger les données en AJAX
        fetch(newUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            const newTableContainer = doc.querySelector('#tableContainer_' + componentId);
            if(newTableContainer) {
                document.querySelector('#tableContainer_' + componentId).innerHTML = newTableContainer.innerHTML;
            }
            
            // Récupérer le perPage actuel depuis l'URL (au cas où il a été changé)
            const perPageParam = (componentId === 'main_explorer') ? 'per_page' : 'per_page_' + componentId;
            const urlPerPage = parseInt(params.get(perPageParam)) || perPage;
            
            // Mettre à jour les variables globales
            currentPerPage = urlPerPage;
            currentTotalPages = Math.ceil(totalResults / currentPerPage);
            
            const offset = (page - 1) * currentPerPage;
            const start = offset + 1;
            const end = Math.min(offset + currentPerPage, totalResults);
            
            const paginationInfoTop = document.getElementById('paginationInfo_' + componentId);
            const paginationInfoBottom = document.getElementById('paginationInfoBottom_' + componentId);
            
            // Format long pour le top, format court pour le bottom (mode hideTitle)
            if (paginationInfoTop) {
                paginationInfoTop.textContent = `Affichage de ${start.toLocaleString('fr-FR')} à ${end.toLocaleString('fr-FR')} sur ${totalResults.toLocaleString('fr-FR')} URLs`;
            }
            if (paginationInfoBottom) {
                paginationInfoBottom.textContent = `${start.toLocaleString('fr-FR')}-${end.toLocaleString('fr-FR')} sur ${totalResults.toLocaleString('fr-FR')}`;
            }
            
            // Réactiver les boutons puis mettre à jour leur état (disabled si première/dernière page)
            isLoading = false;
            updatePaginationButtons(page, currentTotalPages);
            
            if(typeof refreshUrlModalHandlers === 'function') {
                refreshUrlModalHandlers();
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            isLoading = false;
            updatePaginationButtons(currentPage, currentTotalPages);
        });
    };

    function updatePaginationButtons(page, currentTotalPages = totalPages) {
        const topPrev = document.querySelector('#paginationTop_' + componentId + ' button:first-of-type');
        const topNext = document.querySelector('#paginationTop_' + componentId + ' button:last-of-type');
        
        if(topPrev) {
            topPrev.disabled = page <= 1;
            topPrev.style.opacity = page <= 1 ? '0.5' : '1';
            topPrev.style.cursor = page <= 1 ? 'default' : 'pointer';
            topPrev.setAttribute('onclick', `changePage_${componentId}(${Math.max(1, page - 1)})`);
        }
        
        if(topNext) {
            topNext.disabled = page >= currentTotalPages;
            topNext.style.opacity = page >= currentTotalPages ? '0.5' : '1';
            topNext.style.cursor = page >= currentTotalPages ? 'default' : 'pointer';
            topNext.setAttribute('onclick', `changePage_${componentId}(${Math.min(currentTotalPages, page + 1)})`);
        }
        
        const bottomPrev = document.querySelector('#paginationBottom_' + componentId + ' button:first-of-type');
        const bottomNext = document.querySelector('#paginationBottom_' + componentId + ' button:last-of-type');
        
        if(bottomPrev) {
            bottomPrev.disabled = page <= 1;
            bottomPrev.style.opacity = page <= 1 ? '0.5' : '1';
            bottomPrev.style.cursor = page <= 1 ? 'default' : 'pointer';
            bottomPrev.setAttribute('onclick', `changePage_${componentId}(${Math.max(1, page - 1)})`);
        }
        
        if(bottomNext) {
            bottomNext.disabled = page >= currentTotalPages;
            bottomNext.style.opacity = page >= currentTotalPages ? '0.5' : '1';
            bottomNext.style.cursor = page >= currentTotalPages ? 'default' : 'pointer';
            bottomNext.setAttribute('onclick', `changePage_${componentId}(${Math.min(currentTotalPages, page + 1)})`);
        }
    }

    // Attacher les événements aux boutons de pagination après chargement AJAX
    function attachPaginationHandlers() {
        const topPrev = document.querySelector('#paginationTop_' + componentId + ' button:first-of-type');
        const topNext = document.querySelector('#paginationTop_' + componentId + ' button:last-of-type');
        const bottomPrev = document.querySelector('#paginationBottom_' + componentId + ' button:first-of-type');
        const bottomNext = document.querySelector('#paginationBottom_' + componentId + ' button:last-of-type');
        
        // Supprimer les anciens onclick et attacher de nouveaux événements
        [topPrev, topNext, bottomPrev, bottomNext].forEach(btn => {
            if(btn) {
                btn.removeAttribute('onclick');
                // Supprimer les anciens listeners en clonant le bouton
                const newBtn = btn.cloneNode(true);
                btn.parentNode.replaceChild(newBtn, btn);
            }
        });
        
        // Réattacher les événements
        const newTopPrev = document.querySelector('#paginationTop_' + componentId + ' button:first-of-type');
        const newTopNext = document.querySelector('#paginationTop_' + componentId + ' button:last-of-type');
        const newBottomPrev = document.querySelector('#paginationBottom_' + componentId + ' button:first-of-type');
        const newBottomNext = document.querySelector('#paginationBottom_' + componentId + ' button:last-of-type');
        
        if(newTopPrev) {
            newTopPrev.addEventListener('click', () => {
                if(currentPage > 1) window['changePage_' + componentId](currentPage - 1);
            });
        }
        
        if(newTopNext) {
            newTopNext.addEventListener('click', () => {
                if(currentPage < currentTotalPages) window['changePage_' + componentId](currentPage + 1);
            });
        }
        
        if(newBottomPrev) {
            newBottomPrev.addEventListener('click', () => {
                if(currentPage > 1) window['changePage_' + componentId](currentPage - 1);
            });
        }
        
        if(newBottomNext) {
            newBottomNext.addEventListener('click', () => {
                if(currentPage < currentTotalPages) window['changePage_' + componentId](currentPage + 1);
            });
        }
        
        // Mettre à jour l'état des boutons
        updatePaginationButtons(currentPage, currentTotalPages);
    }

    // Copier le tableau
    window['copyTableToClipboard_' + componentId] = function(event) {
        const table = document.getElementById('urlTable_' + componentId);
        let text = '';
        
        // Fonction pour extraire le texte propre d'une cellule (sans icônes)
        function getCleanText(cell) {
            // Cloner la cellule pour ne pas modifier l'original
            const clone = cell.cloneNode(true);
            
            // Supprimer tous les éléments Material Symbols (icônes)
            const icons = clone.querySelectorAll('.material-symbols-outlined');
            icons.forEach(icon => icon.remove());
            
            // Pour les colonnes booléennes (compliant, canonical, etc.), on veut juste "Oui" ou "Non"
            // Si la cellule contient uniquement une icône (qu'on vient de supprimer), détecter la couleur
            if(cell.querySelector('.material-symbols-outlined')) {
                const icon = cell.querySelector('.material-symbols-outlined');
                const color = icon.style.color || window.getComputedStyle(icon).color;
                // Vert = Oui, Rouge = Non
                if(color.includes('46, 204, 113') || color.includes('#2ECC71') || color.includes('rgb(46, 204, 113)')) {
                    return 'Oui';
                } else if(color.includes('231, 76, 60') || color.includes('#E74C3C') || color.includes('rgb(231, 76, 60)')) {
                    return 'Non';
                }
            }
            
            // Récupérer le texte restant et nettoyer
            let cleanText = clone.textContent.trim();
            
            // Remplacer les multiples espaces par un seul
            cleanText = cleanText.replace(/\s+/g, ' ');
            
            // Remplacer le tiret "—" par une chaîne vide (valeurs vides)
            if(cleanText === '—') {
                return '';
            }
            
            return cleanText;
        }
        
        const headers = table.querySelectorAll('thead th');
        const headerTexts = Array.from(headers).map(th => getCleanText(th));
        text += headerTexts.join('\t') + '\n';
        
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            const cellTexts = Array.from(cells).map(td => getCleanText(td));
            text += cellTexts.join('\t') + '\n';
        });
        
        navigator.clipboard.writeText(text).then(() => {
            showGlobalStatus('✓ Texte copié', 'success');
        }).catch(err => {
            console.error('Erreur:', err);
            showGlobalStatus('Erreur lors de la copie', 'error');
        });
    };


    // Toggle dropdown colonnes
    window['toggleColumnDropdown_' + componentId] = function() {
        const dropdown = document.getElementById('columnDropdown_' + componentId);
        if(dropdown.style.display === 'none' || dropdown.style.display === '') {
            dropdown.style.display = 'flex';
            dropdown.classList.add('show');
        } else {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
        }
    };

    // Tout cocher / Tout décocher
    window['toggleAllColumns_' + componentId] = function(check) {
        const checkboxes = document.querySelectorAll('.column-checkbox-' + componentId);
        checkboxes.forEach(checkbox => {
            if(!checkbox.disabled) {  // Ne pas toucher à la checkbox URL (obligatoire)
                checkbox.checked = check;
            }
        });
    };

    // Fermer dropdown colonnes si clic ailleurs
    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('columnDropdown_' + componentId);
        const button = e.target.closest('button[onclick="toggleColumnDropdown_' + componentId + '()"]');
        
        if(!button && dropdown && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });

    // Toggle dropdown perPage
    window['togglePerPageDropdown_' + componentId] = function() {
        const dropdown = document.getElementById('perPageDropdown_' + componentId);
        const button = document.getElementById('perPageBtn_' + componentId);
        const icon = button.querySelector('.material-symbols-outlined');
        
        if(dropdown.style.display === 'none' || dropdown.style.display === '') {
            dropdown.style.display = 'block';
            dropdown.classList.add('show');
            icon.style.transform = 'rotate(180deg)';
        } else {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
            icon.style.transform = 'rotate(0deg)';
        }
    };

    // Sélectionner perPage
    window['selectPerPage_' + componentId] = function(value) {
        window['changePerPage_' + componentId](value);
    };

    // Fermer dropdown perPage si clic ailleurs
    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('perPageDropdown_' + componentId);
        const button = document.getElementById('perPageBtn_' + componentId);
        
        if(!button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
            const icon = button.querySelector('.material-symbols-outlined');
            icon.style.transform = 'rotate(0deg)';
        }
    });

    // Tri par colonne en AJAX
    window['sortByColumn_' + componentId] = function(column) {
        const params = new URLSearchParams(window.location.search);
        
        // Paramètres de tri selon le composant
        const sortParam = (componentId === 'main_explorer') ? 'sort' : 'sort_' + componentId;
        const dirParam = (componentId === 'main_explorer') ? 'dir' : 'dir_' + componentId;
        
        // Récupérer le tri actuel
        const currentSort = params.get(sortParam);
        const currentDir = params.get(dirParam) || 'ASC';
        
        // Si on clique sur la même colonne, inverser la direction
        if(currentSort === column) {
            params.set(dirParam, currentDir === 'ASC' ? 'DESC' : 'ASC');
        } else {
            // Nouvelle colonne : tri ASC par défaut
            params.set(sortParam, column);
            params.set(dirParam, 'ASC');
        }
        
        // Revenir à la page 1 quand on change le tri
        const pageParam = (componentId === 'main_explorer') ? 'p' : 'p_' + componentId;
        params.set(pageParam, 1);
        
        // Mettre à jour l'URL sans recharger
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);
        
        // Si une fonction de reload custom existe, l'utiliser
        if (typeof window['reloadTable_' + componentId] === 'function') {
            window['reloadTable_' + componentId]();
            return;
        }
        
        // Charger les données en AJAX (mode standard avec tableCard)
        fetch(newUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTableCard = doc.querySelector('#tableCard_' + componentId);
            
            if(newTableCard) {
                const currentTableCard = document.getElementById('tableCard_' + componentId);
                currentTableCard.innerHTML = newTableCard.innerHTML;
                
                // Rafraîchir les handlers de la modale
                if(typeof refreshUrlModalHandlers === 'function') {
                    refreshUrlModalHandlers();
                }
            }
        })
        .catch(error => {
            console.error('Erreur lors du tri:', error);
        });
    };

    // Appliquer colonnes en AJAX
    window['applyColumns_' + componentId] = function() {
        const checkboxes = document.querySelectorAll('.column-checkbox-' + componentId + ':checked');
        const columns = Array.from(checkboxes).map(cb => cb.value);
        
        const params = new URLSearchParams(window.location.search);
        // Compatibilité avec anciens paramètres pour main_explorer
        const columnsParam = (componentId === 'main_explorer') ? 'columns' : 'columns_' + componentId;
        params.set(columnsParam, columns.join(','));
        
        // Fermer le dropdown
        const dropdown = document.getElementById('columnDropdown_' + componentId);
        if (dropdown) dropdown.style.display = 'none';
        
        // Si une fonction de reload custom existe (ex: loadCategorizeTable), l'utiliser
        if (typeof window['reloadTable_' + componentId] === 'function') {
            window.history.pushState({}, '', window.location.pathname + '?' + params.toString());
            window['reloadTable_' + componentId]();
            return;
        }
        
        // En mode light sans reload custom, recharger la page
        if(isLightMode) {
            window.location.href = window.location.pathname + '?' + params.toString();
            return;
        }
        
        // Mettre à jour l'URL sans recharger
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);
        
        // Charger les données en AJAX
        fetch(newUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            // Parser la réponse et extraire le contenu du tableau
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTableCard = doc.querySelector('#tableCard_' + componentId);
            
            if(newTableCard) {
                const currentTableCard = document.getElementById('tableCard_' + componentId);
                
                // Garder le dropdown ouvert si nécessaire
                const wasOpen = document.getElementById('columnDropdown_' + componentId)?.style.display === 'flex';
                
                // Remplacer le contenu
                currentTableCard.innerHTML = newTableCard.innerHTML;
                
                // Fermer le dropdown après remplacement
                const newDropdown = document.getElementById('columnDropdown_' + componentId);
                if(newDropdown) {
                    newDropdown.style.display = 'none';
                }
                
                // Réactiver les event handlers de la modale
                if(typeof refreshUrlModalHandlers === 'function') {
                    refreshUrlModalHandlers();
                }
                
                // Réinitialiser la synchronisation des scrollbars après le rechargement AJAX
                if(typeof window['initScrollbarSync_' + componentId] === 'function') {
                    window['initScrollbarSync_' + componentId]();
                }
                
                // Afficher un message de succès
                showGlobalStatus('✓ Colonnes mises à jour', 'success');
            }
        })
        .catch(error => {
            console.error('Erreur lors du chargement:', error);
            alert('Erreur lors de la mise à jour des colonnes');
        });
    };

    // Export CSV
    window['exportToCSV_' + componentId] = function() {
        const selectedCols = [];
        document.querySelectorAll('.column-checkbox-' + componentId + ':checked').forEach(cb => {
            selectedCols.push(cb.value);
        });
        
        // Récupérer les filtres et recherche depuis l'URL
        const params = new URLSearchParams(window.location.search);
        const filters = params.get('filters') || '';
        const search = params.get('search') || '';
        
        document.getElementById('exportForm_' + componentId).querySelector('[name="filters"]').value = filters;
        document.getElementById('exportForm_' + componentId).querySelector('[name="search"]').value = search;
        document.getElementById('exportColumns_' + componentId).value = JSON.stringify(selectedCols);
        document.getElementById('exportForm_' + componentId).submit();
    };

    // Stocker les références aux handlers pour pouvoir les retirer
    window['scrollHandlers_' + componentId] = null;

    // Fonction pour initialiser/réinitialiser la synchronisation des scrollbars
    window['initScrollbarSync_' + componentId] = function() {
        const topScrollbar = document.getElementById('topScrollbar_' + componentId);
        const tableContainer = document.getElementById('tableContainer_' + componentId);
        const topScrollbarContent = document.getElementById('topScrollbarContent_' + componentId);
        const table = document.getElementById('urlTable_' + componentId);

        if (!topScrollbar || !tableContainer || !topScrollbarContent || !table) {
            return;
        }

        // Synchroniser la largeur du contenu de la barre de scroll du haut
        topScrollbarContent.style.width = table.offsetWidth + 'px';
        
        // Synchroniser après un court délai (pour s'assurer que le DOM est complètement rendu)
        setTimeout(function() {
            topScrollbarContent.style.width = table.offsetWidth + 'px';
        }, 100);

        // Retirer les anciens handlers s'ils existent
        if (window['scrollHandlers_' + componentId]) {
            const oldHandlers = window['scrollHandlers_' + componentId];
            const oldTop = document.getElementById('topScrollbar_' + componentId);
            const oldTable = document.getElementById('tableContainer_' + componentId);
            if (oldTop && oldHandlers.topHandler) {
                oldTop.removeEventListener('scroll', oldHandlers.topHandler);
            }
            if (oldTable && oldHandlers.tableHandler) {
                oldTable.removeEventListener('scroll', oldHandlers.tableHandler);
            }
        }

        // Créer les nouveaux handlers
        const topHandler = function() {
            const tc = document.getElementById('tableContainer_' + componentId);
            if (tc) tc.scrollLeft = this.scrollLeft;
        };

        const tableHandler = function() {
            const ts = document.getElementById('topScrollbar_' + componentId);
            if (ts) ts.scrollLeft = this.scrollLeft;
        };

        // Ajouter les event listeners
        topScrollbar.addEventListener('scroll', topHandler);
        tableContainer.addEventListener('scroll', tableHandler);

        // Stocker les références
        window['scrollHandlers_' + componentId] = {
            topHandler: topHandler,
            tableHandler: tableHandler
        };
    };

    // Initialiser au chargement
    window['initScrollbarSync_' + componentId]();

    // Synchroniser lors du redimensionnement de la fenêtre
    window.addEventListener('resize', function() {
        const topScrollbarContent = document.getElementById('topScrollbarContent_' + componentId);
        const table = document.getElementById('urlTable_' + componentId);
        if (table && topScrollbarContent) {
            topScrollbarContent.style.width = table.offsetWidth + 'px';
        }
    });

    // Handler pour copier le chemin - utiliser la délégation d'événements sur document
    window['attachCopyHandlers_' + componentId] = function() {
        // Utiliser la délégation d'événements sur le document pour gérer les clics même après AJAX
        document.addEventListener('click', function(e) {
            const copyBtn = e.target.closest('.copy-path-btn');
            if (copyBtn) {
                // Vérifier que le bouton est dans notre tableau
                const tableCard = document.getElementById('tableCard_' + componentId);
                if (tableCard && tableCard.contains(copyBtn)) {
                    e.preventDefault();
                    e.stopPropagation();
                    const path = copyBtn.dataset.path;
                    if (path) {
                        navigator.clipboard.writeText(path).then(() => {
                            // Afficher notification
                            if (typeof showGlobalStatus === 'function') {
                                showGlobalStatus('Chemin copié : ' + path, 'success');
                            } else {
                                // Fallback notification
                                const notif = document.createElement('div');
                                notif.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#2ecc71;color:#fff;padding:10px 20px;border-radius:6px;z-index:10000;font-size:14px;';
                                notif.textContent = 'Chemin copié : ' + path;
                                document.body.appendChild(notif);
                                setTimeout(() => notif.remove(), 2000);
                            }
                        }).catch(err => {
                            console.error('Erreur copie:', err);
                            if (typeof showGlobalStatus === 'function') {
                                showGlobalStatus('Erreur lors de la copie', 'error');
                            }
                        });
                    }
                }
            }
        });
    };
    
    // Initialiser une seule fois
    if (!window['copyHandlersAttached_' + componentId]) {
        window['attachCopyHandlers_' + componentId]();
        window['copyHandlersAttached_' + componentId] = true;
    }

    // Fonction pour afficher la modale de scope (utilise le composant partagé)
    window['showTableScope_' + componentId] = function() {
        if (typeof openScopeModal === 'function') {
            openScopeModal({
                title: <?= json_encode($componentTitle, JSON_UNESCAPED_UNICODE) ?>,
                scopeItems: <?= json_encode($scopeItems, JSON_UNESCAPED_UNICODE) ?>,
                sqlQuery: <?= json_encode($tableSqlQuery, JSON_UNESCAPED_UNICODE) ?>
            });
        }
    };
})();
</script>
<?php endif; // embedMode ?>
