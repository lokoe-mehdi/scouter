<?php
/**
 * Composant réutilisable : Table de chaînes de redirection avec pagination AJAX
 *
 * Paramètres requis dans $redirectTableConfig :
 * - title : Titre du composant (string)
 * - id : ID unique du composant (string)
 * - pdo : Connexion PDO (objet)
 * - crawlId : ID du crawl (int)
 * - projectDir : Répertoire du projet (string)
 * - perPage : Nombre de résultats par page (int, défaut 100)
 * - defaultColumns : Colonnes visibles par défaut (array)
 */

if(!isset($redirectTableConfig) || !is_array($redirectTableConfig)) {
    die('Configuration manquante pour le composant redirect-table. Utilisez $redirectTableConfig = [...]');
}

// Charger le composant scope-modal
require_once __DIR__ . '/scope-modal.php';

// Extraction des paramètres
$componentTitle = $redirectTableConfig['title'] ?? __('redirects.table_title');
$componentId = $redirectTableConfig['id'] ?? 'redirect_table_' . uniqid();
$pdo = $redirectTableConfig['pdo'] ?? null;
$projectDir = $redirectTableConfig['projectDir'] ?? '';
$defaultColumns = $redirectTableConfig['defaultColumns'] ?? ['source_url', 'source_code', 'hops', 'final_url', 'final_code', 'compliant'];
$perPage = $redirectTableConfig['perPage'] ?? 100;
$crawlId = $redirectTableConfig['crawlId'] ?? null;

if(!$pdo) {
    die('pdo est obligatoire dans $redirectTableConfig');
}
if(!$crawlId) {
    die('crawlId est obligatoire dans $redirectTableConfig');
}

$crawlIdInt = intval($crawlId);

// Colonnes disponibles pour les chaînes de redirection
$availableColumns = [
    'source_url' => __('redirects.col_source_url'),
    'source_code' => __('redirects.col_source_code'),
    'hops' => __('redirects.col_hops'),
    'final_url' => __('redirects.col_final_url'),
    'final_code' => __('redirects.col_final_code'),
    'compliant' => __('redirects.col_compliant')
];

// Récupération des colonnes sélectionnées
$selectedColumns = isset($_GET['columns_' . $componentId]) ? explode(',', $_GET['columns_' . $componentId]) : $defaultColumns;
if(empty($selectedColumns)) {
    $selectedColumns = ['source_url'];
}

// Réordonner les colonnes sélectionnées selon l'ordre de $availableColumns
$orderedColumns = [];
foreach(array_keys($availableColumns) as $col) {
    if(in_array($col, $selectedColumns)) {
        $orderedColumns[] = $col;
    }
}
$selectedColumns = $orderedColumns;
if(empty($selectedColumns)) {
    $selectedColumns = ['source_url'];
}

// Récupération du tri depuis l'URL
$sortColumn = null;
$sortDirection = 'ASC';
if(isset($_GET['sort_' . $componentId])) {
    $sortColumn = $_GET['sort_' . $componentId];
    $sortDirection = isset($_GET['dir_' . $componentId]) && strtoupper($_GET['dir_' . $componentId]) === 'DESC' ? 'DESC' : 'ASC';
}

// Mapper les colonnes vers leurs vraies colonnes SQL
$columnMapping = [
    'source_url' => 'rc.source_url',
    'source_code' => 'p.code',
    'hops' => 'rc.hops',
    'final_url' => 'rc.final_url',
    'final_code' => 'rc.final_code',
    'compliant' => 'rc.final_compliant'
];

// Ordre par défaut : boucles en premier, puis par nombre de sauts décroissant
$orderBy = 'ORDER BY rc.is_loop DESC, rc.hops DESC';
if($sortColumn && isset($columnMapping[$sortColumn])) {
    $orderBy = 'ORDER BY ' . $columnMapping[$sortColumn] . ' ' . $sortDirection;
}

// Récupération du perPage depuis l'URL
if(isset($_GET['per_page_' . $componentId])) {
    $perPage = max(10, min(500, (int)$_GET['per_page_' . $componentId]));
}

// Pagination
$page_num = isset($_GET['p_' . $componentId]) ? max(1, (int)$_GET['p_' . $componentId]) : 1;
$offset = ($page_num - 1) * $perPage;

// Comptage total
$countQuery = "SELECT COUNT(*) as total FROM redirect_chains rc WHERE rc.crawl_id = $crawlIdInt";
$sqlCount = $pdo->query($countQuery);
$result = $sqlCount->fetch(PDO::FETCH_OBJ);
$totalResults = $result ? (int)$result->total : 0;
$totalPages = $totalResults > 0 ? ceil($totalResults / $perPage) : 1;

// Requête principale avec pagination (jointure sur pages pour source_code)
$query = "SELECT rc.id, rc.source_id, rc.source_url, rc.final_id, rc.final_url,
                 rc.final_code, rc.final_compliant, rc.hops, rc.is_loop, rc.chain_ids,
                 p.code AS source_code
          FROM redirect_chains rc
          LEFT JOIN pages p ON p.id = rc.source_id AND p.crawl_id = $crawlIdInt
          WHERE rc.crawl_id = $crawlIdInt
          $orderBy
          LIMIT $perPage OFFSET $offset";

$stmt = $pdo->query($query);
$rows = $stmt->fetchAll(PDO::FETCH_OBJ);

// Requête SQL pour le scope modal (sans alias pour SQL explorer)
$cleanedOrderBy = str_replace('rc.', '', $orderBy);
$cleanedOrderBy = str_replace('p.code', 'source_code', $cleanedOrderBy);
$tableSqlQuery = "SELECT source_url, hops, final_url, final_code, final_compliant, is_loop
FROM redirect_chains
$cleanedOrderBy";

$scopeItems = ['redirect_chains'];
?>

<!-- Formulaire caché pour l'export CSV -->
<form id="exportForm_<?= $componentId ?>" method="POST" action="api/export/redirect-chains-csv?project=<?= htmlspecialchars($crawlId ?? $projectDir) ?>" target="_blank" style="display: none;">
    <input type="hidden" name="crawl_id" value="<?= $crawlIdInt ?>">
    <input type="hidden" name="columns" id="exportColumns_<?= $componentId ?>" value="">
</form>

<!-- Résultats -->
<div class="table-card" id="tableCard_<?= $componentId ?>">
    <div class="table-header" style="padding: 0rem 0rem 0rem 0rem; display: block !important;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
            <!-- Gauche : Titre -->
            <h3 class="table-title" style="margin: 0;">
                <?= htmlspecialchars($componentTitle) ?> (<?= number_format($totalResults ?? 0) ?>)
            </h3>

            <!-- Droite : Scope + Copier + Export CSV -->
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <span onclick="showTableScope_<?= $componentId ?>()" class="chart-action-btn" title="<?= __('table.view_scope') ?>" style="cursor: pointer;">
                    <span class="material-symbols-outlined">database</span>
                </span>
                <button class="btn-table-action btn-copy" onclick="copyTableToClipboard_<?= $componentId ?>(event)">
                    <span class="material-symbols-outlined">content_copy</span>
                    <?= __('table.copy') ?>
                </button>
                <button class="btn-table-action btn-export" onclick="exportToCSV_<?= $componentId ?>()">
                    <span class="material-symbols-outlined">download</span>
                    Export CSV
                </button>
            </div>
        </div>

        <!-- Ligne du bas : Colonnes à gauche, Pagination à droite -->
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <!-- Gauche : Colonnes -->
            <div style="position: relative;">
                <button class="btn-table-action btn-columns-select" onclick="toggleColumnDropdown_<?= $componentId ?>()">
                    <span class="material-symbols-outlined">view_column</span>
                    <?= __('table.columns') ?>
                </button>
                <div id="columnDropdown_<?= $componentId ?>" class="column-dropdown-<?= $componentId ?>" style="display: none; position: absolute; left: 0; top: 100%; margin-top: 0.5rem; background: white; border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 250px; max-height: 450px; z-index: 1000; flex-direction: column;">
                    <!-- Header fixe -->
                    <div style="padding: 1rem 1rem 0.5rem 1rem; border-bottom: 1px solid var(--border-color); background: white; border-radius: 8px 8px 0 0;">
                        <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;"><?= __('table.select_columns') ?></div>
                        <div style="font-size: 0.85rem; color: var(--text-secondary);">
                            <a href="javascript:void(0)" onclick="toggleAllColumns_<?= $componentId ?>(true)" style="color: var(--primary-color); text-decoration: none; cursor: pointer;"><?= __('table.check_all') ?></a>
                            <span style="margin: 0 0.25rem; color: var(--border-color);">|</span>
                            <a href="javascript:void(0)" onclick="toggleAllColumns_<?= $componentId ?>(false)" style="color: var(--text-secondary); text-decoration: none; cursor: pointer;"><?= __('table.uncheck_all') ?></a>
                        </div>
                    </div>

                    <!-- Liste scrollable des colonnes -->
                    <div style="flex: 1; overflow-y: auto; padding: 0.5rem 1rem; max-height: 280px;">
                        <?php foreach($availableColumns as $key => $label): ?>
                        <label style="display: block; padding: 0.5rem; cursor: pointer; border-radius: 4px; transition: background 0.2s;" onmouseover="this.style.background='var(--background)'" onmouseout="this.style.background='transparent'">
                            <input type="checkbox" class="column-checkbox-<?= $componentId ?>" value="<?= $key ?>"
                                <?= in_array($key, $selectedColumns) ? 'checked' : '' ?>
                                <?= $key === 'source_url' ? 'disabled' : '' ?>
                                style="margin-right: 0.5rem; accent-color: var(--primary-color);">
                            <?= $label ?><?= $key === 'source_url' ? ' (' . __('table.mandatory') . ')' : '' ?>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- Footer fixe avec boutons -->
                    <div style="padding: 1rem; border-top: 1px solid var(--border-color); background: white; border-radius: 0 0 8px 8px; display: flex; gap: 0.5rem;">
                        <button class="btn" onclick="applyColumns_<?= $componentId ?>()" style="flex: 1; background: var(--primary-color); color: white; border: none; padding: 0.6rem; font-weight: 500;"><?= __('table.apply') ?></button>
                        <button class="btn" onclick="toggleColumnDropdown_<?= $componentId ?>()" style="flex: 1; background: #95a5a6; color: white; border: none; padding: 0.6rem; font-weight: 500;"><?= __('table.cancel') ?></button>
                    </div>
                </div>
            </div>

            <!-- Droite : Pagination -->
            <div id="paginationTop_<?= $componentId ?>" style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; color: var(--text-secondary);">
                <span id="paginationInfo_<?= $componentId ?>"><?= __('table.pagination_info', ['start' => number_format(min(($offset ?? 0) + 1, $totalResults)), 'end' => number_format(min(($offset ?? 0) + $perPage, $totalResults ?? 0)), 'total' => number_format($totalResults ?? 0)]) ?></span>
                <button onclick="changePage_<?= $componentId ?>(<?= max(1, $page_num - 1) ?>)" <?= $page_num <= 1 ? 'disabled' : '' ?> style="padding: 0.4rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; <?= $page_num <= 1 ? 'opacity: 0.4; cursor: default;' : '' ?>" onmouseover="<?= $page_num > 1 ? 'this.style.background=\"#f8f9fa\"; this.style.borderColor=\"#adb5bd\"' : '' ?>" onmouseout="<?= $page_num > 1 ? 'this.style.background=\"white\"; this.style.borderColor=\"#dee2e6\"' : '' ?>">
                    <span class="material-symbols-outlined" style="font-size: 20px;">chevron_left</span>
                </button>
                <button onclick="changePage_<?= $componentId ?>(<?= min($totalPages, $page_num + 1) ?>)" <?= $page_num >= $totalPages ? 'disabled' : '' ?> style="padding: 0.4rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; <?= $page_num >= $totalPages ? 'opacity: 0.4; cursor: default;' : '' ?>" onmouseover="<?= $page_num < $totalPages ? 'this.style.background=\"#f8f9fa\"; this.style.borderColor=\"#adb5bd\"' : '' ?>" onmouseout="<?= $page_num < $totalPages ? 'this.style.background=\"white\"; this.style.borderColor=\"#dee2e6\"' : '' ?>">
                    <span class="material-symbols-outlined" style="font-size: 20px;">chevron_right</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Barre de scroll horizontale du haut (se synchronise avec celle du bas) -->
    <div id="topScrollbar_<?= $componentId ?>" style="overflow-x: auto; overflow-y: hidden; margin-bottom: 0.5rem;">
        <div id="topScrollbarContent_<?= $componentId ?>" style="height: 1px;"></div>
    </div>

    <div id="tableContainer_<?= $componentId ?>" style="overflow-x: auto;">
        <table class="data-table" id="urlTable_<?= $componentId ?>">
            <thead>
                <tr>
                    <?php foreach($selectedColumns as $col): ?>
                        <?php if(isset($availableColumns[$col])): ?>
                            <th class="col-<?= $col ?>" style="cursor: pointer; user-select: none; position: relative;" onclick="sortByColumn_<?= $componentId ?>('<?= $col ?>')">
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
                <?php if(empty($rows)): ?>
                <tr>
                    <td colspan="<?= count($selectedColumns) ?>" style="text-align: center; padding: 4rem 2rem;">
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 1rem; color: var(--text-secondary);">
                            <span class="material-symbols-outlined" style="font-size: 64px; color: #95a5a6; opacity: 0.5;">check_circle</span>
                            <div style="font-size: 1.25rem; font-weight: 600; color: var(--text-primary);"><?= __('redirects.no_chains') ?></div>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach($rows as $chain): ?>
                <?php $isLoop = (bool)$chain->is_loop; ?>
                <tr>
                    <?php foreach($selectedColumns as $col): ?>
                        <?php if($col === 'source_url'): ?>
                            <td class="col-source_url" style="max-width: 400px; position: relative;">
                                <div style="display: flex; align-items: center; overflow: hidden;">
                                    <?php if($chain->source_url): ?>
                                    <span class="url-clickable" data-url="<?= htmlspecialchars($chain->source_url) ?>" style="cursor: pointer; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0;">
                                        <?= htmlspecialchars($chain->source_url) ?>
                                    </span>
                                    <a href="<?= htmlspecialchars($chain->source_url) ?>" target="_blank" rel="noopener noreferrer" title="<?= __('common.open_new_tab') ?>" style="display: inline-flex; align-items: center; color: var(--text-secondary); text-decoration: none; margin-left: 0.5rem; flex-shrink: 0;">
                                        <span class="material-symbols-outlined" style="font-size: 16px;">open_in_new</span>
                                    </a>
                                    <?php else: ?>
                                    <span style="color: #95a5a6;"><?= htmlspecialchars($chain->source_id) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php elseif($col === 'source_code'): ?>
                            <td class="col-source_code" style="text-align: center;">
                                <?php if($chain->source_code): ?>
                                    <?php
                                    $code = (int)$chain->source_code;
                                    $textColor = function_exists('getCodeColor') ? getCodeColor($code) : '#95a5a6';
                                    $bgColor = function_exists('getCodeBackgroundColor') ? getCodeBackgroundColor($code, 0.3) : 'rgba(149, 165, 166, 0.3)';
                                    $displayValue = function_exists('getCodeDisplayValue') ? getCodeDisplayValue($code) : $code;
                                    ?>
                                    <span class="badge" style="background: <?= $bgColor ?>; color: <?= $textColor ?>; font-weight: 600;">
                                        <?= htmlspecialchars($displayValue) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #95a5a6;">—</span>
                                <?php endif; ?>
                            </td>
                        <?php elseif($col === 'hops'): ?>
                            <td class="col-hops" style="text-align: center;">
                                <?php if($isLoop): ?>
                                    <span class="badge badge-danger"><?= __('redirects.infinite_loop') ?></span>
                                <?php else: ?>
                                    <span class="badge badge-info"><?= (int)$chain->hops ?></span>
                                <?php endif; ?>
                            </td>
                        <?php elseif($col === 'final_url'): ?>
                            <td class="col-final_url" style="max-width: 400px; position: relative;">
                                <?php if($isLoop): ?>
                                    <span style="color: #95a5a6; font-style: italic;"><?= __('redirects.loop_no_final') ?></span>
                                <?php elseif($chain->final_url): ?>
                                <div style="display: flex; align-items: center; overflow: hidden;">
                                    <span class="url-clickable" data-url="<?= htmlspecialchars($chain->final_url) ?>" style="cursor: pointer; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0;">
                                        <?= htmlspecialchars($chain->final_url) ?>
                                    </span>
                                    <a href="<?= htmlspecialchars($chain->final_url) ?>" target="_blank" rel="noopener noreferrer" title="<?= __('common.open_new_tab') ?>" style="display: inline-flex; align-items: center; color: var(--text-secondary); text-decoration: none; margin-left: 0.5rem; flex-shrink: 0;">
                                        <span class="material-symbols-outlined" style="font-size: 16px;">open_in_new</span>
                                    </a>
                                </div>
                                <?php else: ?>
                                    <span style="color: #95a5a6;">—</span>
                                <?php endif; ?>
                            </td>
                        <?php elseif($col === 'final_code'): ?>
                            <td class="col-final_code" style="text-align: center;">
                                <?php if($isLoop): ?>
                                    <span style="color: #95a5a6;">—</span>
                                <?php elseif($chain->final_code): ?>
                                    <?php
                                    $code = (int)$chain->final_code;
                                    $textColor = function_exists('getCodeColor') ? getCodeColor($code) : '#95a5a6';
                                    $bgColor = function_exists('getCodeBackgroundColor') ? getCodeBackgroundColor($code, 0.3) : 'rgba(149, 165, 166, 0.3)';
                                    $displayValue = function_exists('getCodeDisplayValue') ? getCodeDisplayValue($code) : $code;
                                    ?>
                                    <span class="badge" style="background: <?= $bgColor ?>; color: <?= $textColor ?>; font-weight: 600;">
                                        <?= htmlspecialchars($displayValue) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #95a5a6;">—</span>
                                <?php endif; ?>
                            </td>
                        <?php elseif($col === 'compliant'): ?>
                            <td class="col-compliant" style="text-align: center;">
                                <?php if($isLoop): ?>
                                    <span class="material-symbols-outlined" style="color: #e74c3c; font-size: 1.2rem; opacity: 0.8;">cancel</span>
                                <?php else: ?>
                                    <?= $chain->final_compliant ? '<span class="material-symbols-outlined" style="color: #6bd899; font-size: 1.2rem; opacity: 0.8;">check_circle</span>' : '<span class="material-symbols-outlined" style="color: #95a5a6; font-size: 1.2rem; opacity: 0.7;">cancel</span>' ?>
                                <?php endif; ?>
                            </td>
                        <?php else: ?>
                            <td class="col-<?= $col ?>"><?= htmlspecialchars($chain->$col ?? '') ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination en bas -->
    <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem;">
        <!-- Gauche : Sélecteur nombre par page -->
        <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: var(--text-secondary);">
            <span><?= __('table.show') ?> :</span>
            <div style="position: relative;">
                <button id="perPageBtn_<?= $componentId ?>" onclick="togglePerPageDropdown_<?= $componentId ?>()" style="padding: 0.4rem 0.8rem 0.4rem 0.6rem; border: 1px solid #dee2e6; border-radius: 4px; background: white; cursor: pointer; font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem; transition: all 0.2s ease; min-width: 60px;">
                    <span id="perPageValue_<?= $componentId ?>"><?= $perPage ?></span>
                    <span class="material-symbols-outlined" style="font-size: 16px; transition: transform 0.2s ease;">expand_more</span>
                </button>
                <div id="perPageDropdown_<?= $componentId ?>" class="per-page-dropdown-<?= $componentId ?>" style="display: none; position: absolute; left: 0; bottom: 100%; margin-bottom: 0.25rem; background: white; border: 1px solid #dee2e6; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 1000; min-width: 80px;">
                    <div onclick="selectPerPage_<?= $componentId ?>(10)" style="padding: 0.5rem 0.75rem; cursor: pointer; transition: background 0.15s ease; <?= $perPage == 10 ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $perPage == 10 ? '#f8f9fa' : 'white' ?>'">10</div>
                    <div onclick="selectPerPage_<?= $componentId ?>(50)" style="padding: 0.5rem 0.75rem; cursor: pointer; transition: background 0.15s ease; <?= $perPage == 50 ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $perPage == 50 ? '#f8f9fa' : 'white' ?>'">50</div>
                    <div onclick="selectPerPage_<?= $componentId ?>(100)" style="padding: 0.5rem 0.75rem; cursor: pointer; transition: background 0.15s ease; <?= $perPage == 100 ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $perPage == 100 ? '#f8f9fa' : 'white' ?>'">100</div>
                    <div onclick="selectPerPage_<?= $componentId ?>(500)" style="padding: 0.5rem 0.75rem; cursor: pointer; transition: background 0.15s ease; <?= $perPage == 500 ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $perPage == 500 ? '#f8f9fa' : 'white' ?>'">500</div>
                </div>
            </div>
            <span><?= __('table.per_page') ?></span>
        </div>

        <!-- Droite : Pagination -->
        <div id="paginationBottom_<?= $componentId ?>" style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; color: var(--text-secondary);">
            <span id="paginationInfoBottom_<?= $componentId ?>"><?= __('table.pagination_info', ['start' => number_format(min(($offset ?? 0) + 1, $totalResults)), 'end' => number_format(min(($offset ?? 0) + $perPage, $totalResults ?? 0)), 'total' => number_format($totalResults ?? 0)]) ?></span>
            <button onclick="changePage_<?= $componentId ?>(<?= max(1, $page_num - 1) ?>)" <?= $page_num <= 1 ? 'disabled' : '' ?> style="padding: 0.4rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; <?= $page_num <= 1 ? 'opacity: 0.4; cursor: default;' : '' ?>" onmouseover="<?= $page_num > 1 ? 'this.style.background=\"#f8f9fa\"; this.style.borderColor=\"#adb5bd\"' : '' ?>" onmouseout="<?= $page_num > 1 ? 'this.style.background=\"white\"; this.style.borderColor=\"#dee2e6\"' : '' ?>">
                <span class="material-symbols-outlined" style="font-size: 20px;">chevron_left</span>
            </button>
            <button onclick="changePage_<?= $componentId ?>(<?= min($totalPages, $page_num + 1) ?>)" <?= $page_num >= $totalPages ? 'disabled' : '' ?> style="padding: 0.4rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; <?= $page_num >= $totalPages ? 'opacity: 0.4; cursor: default;' : '' ?>" onmouseover="<?= $page_num < $totalPages ? 'this.style.background=\"#f8f9fa\"; this.style.borderColor=\"#adb5bd\"' : '' ?>" onmouseout="<?= $page_num < $totalPages ? 'this.style.background=\"white\"; this.style.borderColor=\"#dee2e6\"' : '' ?>">
                <span class="material-symbols-outlined" style="font-size: 20px;">chevron_right</span>
            </button>
        </div>
    </div>
</div>

<style>
/* Animation dropdown colonnes */
@keyframes slideInDown_<?= $componentId ?> {
    from { transform: translateY(-10px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.column-dropdown-<?= $componentId ?>.show {
    animation: slideInDown_<?= $componentId ?> 0.2s ease-out;
}

#perPageBtn_<?= $componentId ?>:hover {
    background: #f8f9fa !important;
    border-color: #adb5bd !important;
}

.per-page-dropdown-<?= $componentId ?>.show {
    animation: slideInUp 0.15s ease-out;
}

#urlTable_<?= $componentId ?> thead th {
    transition: background 0.15s ease;
}

#urlTable_<?= $componentId ?> thead th:hover {
    background: #f8f9fa;
}
</style>

<script>
(function() {
    const componentId = '<?= $componentId ?>';
    const totalPages = <?= $totalPages ?>;
    const totalResults = <?= $totalResults ?>;
    const perPage = <?= $perPage ?>;
    let currentPage = <?= $page_num ?>;
    let currentPerPage = <?= $perPage ?>;
    let currentTotalPages = <?= $totalPages ?>;
    let isLoading = false;

    function setPaginationLoading(loading) {
        isLoading = loading;
        const buttons = document.querySelectorAll('#paginationTop_' + componentId + ' button, #paginationBottom_' + componentId + ' button');
        buttons.forEach(btn => {
            btn.disabled = loading;
            btn.style.opacity = loading ? '0.5' : '1';
        });
    }

    // Changement du nombre par page en AJAX
    window['changePerPage_' + componentId] = function(newPerPage) {
        const params = new URLSearchParams(window.location.search);
        params.set('per_page_' + componentId, newPerPage);
        params.set('p_' + componentId, 1);

        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);

        fetch(newUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTableCard = doc.querySelector('#tableCard_' + componentId);

            if(newTableCard) {
                document.getElementById('tableCard_' + componentId).innerHTML = newTableCard.innerHTML;
                currentPage = 1;
                currentPerPage = newPerPage;
                currentTotalPages = Math.ceil(totalResults / newPerPage);
                attachPaginationHandlers();
                if(typeof refreshUrlModalHandlers === 'function') refreshUrlModalHandlers();
            }
        })
        .catch(error => console.error('Erreur:', error));
    };

    // Changement de page en AJAX
    window['changePage_' + componentId] = function(page) {
        if(page < 1 || page === currentPage || isLoading) return;

        currentPage = page;
        const params = new URLSearchParams(window.location.search);
        params.set('p_' + componentId, page);

        setPaginationLoading(true);

        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({page: page}, '', newUrl);

        fetch(newUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            const newTableContainer = doc.querySelector('#tableContainer_' + componentId);
            if(newTableContainer) {
                document.querySelector('#tableContainer_' + componentId).innerHTML = newTableContainer.innerHTML;
            }

            const urlPerPage = parseInt(new URLSearchParams(window.location.search).get('per_page_' + componentId)) || perPage;
            currentPerPage = urlPerPage;
            currentTotalPages = Math.ceil(totalResults / currentPerPage);

            const offset = (page - 1) * currentPerPage;
            const start = Math.min(offset + 1, totalResults);
            const end = Math.min(offset + currentPerPage, totalResults);
            const paginationText = __('table.pagination_info', {start: start.toLocaleString(), end: end.toLocaleString(), total: totalResults.toLocaleString()});

            document.getElementById('paginationInfo_' + componentId).textContent = paginationText;
            document.getElementById('paginationInfoBottom_' + componentId).textContent = paginationText;

            isLoading = false;
            updatePaginationButtons(page, currentTotalPages);

            if(typeof refreshUrlModalHandlers === 'function') refreshUrlModalHandlers();
        })
        .catch(error => {
            console.error('Erreur:', error);
            isLoading = false;
            updatePaginationButtons(currentPage, currentTotalPages);
        });
    };

    function updatePaginationButtons(page, currentTotalPages) {
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

    function attachPaginationHandlers() {
        const btns = [
            document.querySelector('#paginationTop_' + componentId + ' button:first-of-type'),
            document.querySelector('#paginationTop_' + componentId + ' button:last-of-type'),
            document.querySelector('#paginationBottom_' + componentId + ' button:first-of-type'),
            document.querySelector('#paginationBottom_' + componentId + ' button:last-of-type')
        ];

        btns.forEach(btn => {
            if(btn) {
                btn.removeAttribute('onclick');
                const newBtn = btn.cloneNode(true);
                btn.parentNode.replaceChild(newBtn, btn);
            }
        });

        const newBtns = [
            document.querySelector('#paginationTop_' + componentId + ' button:first-of-type'),
            document.querySelector('#paginationTop_' + componentId + ' button:last-of-type'),
            document.querySelector('#paginationBottom_' + componentId + ' button:first-of-type'),
            document.querySelector('#paginationBottom_' + componentId + ' button:last-of-type')
        ];

        if(newBtns[0]) newBtns[0].addEventListener('click', () => { if(currentPage > 1) window['changePage_' + componentId](currentPage - 1); });
        if(newBtns[1]) newBtns[1].addEventListener('click', () => { if(currentPage < currentTotalPages) window['changePage_' + componentId](currentPage + 1); });
        if(newBtns[2]) newBtns[2].addEventListener('click', () => { if(currentPage > 1) window['changePage_' + componentId](currentPage - 1); });
        if(newBtns[3]) newBtns[3].addEventListener('click', () => { if(currentPage < currentTotalPages) window['changePage_' + componentId](currentPage + 1); });

        updatePaginationButtons(currentPage, currentTotalPages);
    }

    // Copier le tableau
    window['copyTableToClipboard_' + componentId] = function(event) {
        const table = document.getElementById('urlTable_' + componentId);
        let text = '';

        function getCleanText(cell) {
            const clone = cell.cloneNode(true);
            const icons = clone.querySelectorAll('.material-symbols-outlined');
            icons.forEach(icon => icon.remove());

            if(cell.querySelector('.material-symbols-outlined')) {
                const icon = cell.querySelector('.material-symbols-outlined');
                const color = icon.style.color || window.getComputedStyle(icon).color;
                if(color.includes('107, 216, 153') || color.includes('#6bd899') || color.includes('rgb(107, 216, 153)')) return 'Oui';
                if(color.includes('231, 76, 60') || color.includes('#e74c3c') || color.includes('rgb(231, 76, 60)')) return 'Non';
                if(color.includes('149, 165, 166') || color.includes('#95a5a6') || color.includes('rgb(149, 165, 166)')) return 'Non';
            }

            let cleanText = clone.textContent.trim().replace(/\s+/g, ' ');
            if(cleanText === '—') return '';
            return cleanText;
        }

        const headers = table.querySelectorAll('thead th');
        text += Array.from(headers).map(th => getCleanText(th)).join('\t') + '\n';

        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            text += Array.from(cells).map(td => getCleanText(td)).join('\t') + '\n';
        });

        navigator.clipboard.writeText(text).then(() => {
            showGlobalStatus(__('table.text_copied'), 'success');
        }).catch(err => {
            console.error('Erreur:', err);
            showGlobalStatus(__('table.copy_error'), 'error');
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

    window['toggleAllColumns_' + componentId] = function(check) {
        const checkboxes = document.querySelectorAll('.column-checkbox-' + componentId);
        checkboxes.forEach(checkbox => {
            if(!checkbox.disabled) checkbox.checked = check;
        });
    };

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

    window['selectPerPage_' + componentId] = function(value) {
        window['changePerPage_' + componentId](value);
    };

    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('perPageDropdown_' + componentId);
        const button = document.getElementById('perPageBtn_' + componentId);
        if(button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
            const icon = button.querySelector('.material-symbols-outlined');
            if(icon) icon.style.transform = 'rotate(0deg)';
        }
    });

    // Tri par colonne en AJAX
    window['sortByColumn_' + componentId] = function(column) {
        const params = new URLSearchParams(window.location.search);
        const sortParam = 'sort_' + componentId;
        const dirParam = 'dir_' + componentId;

        const currentSort = params.get(sortParam);
        const currentDir = params.get(dirParam) || 'ASC';

        if(currentSort === column) {
            params.set(dirParam, currentDir === 'ASC' ? 'DESC' : 'ASC');
        } else {
            params.set(sortParam, column);
            params.set(dirParam, 'ASC');
        }

        params.set('p_' + componentId, 1);

        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);

        fetch(newUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTableCard = doc.querySelector('#tableCard_' + componentId);

            if(newTableCard) {
                document.getElementById('tableCard_' + componentId).innerHTML = newTableCard.innerHTML;
                if(typeof refreshUrlModalHandlers === 'function') refreshUrlModalHandlers();
            }
        })
        .catch(error => console.error('Erreur:', error));
    };

    // Appliquer colonnes en AJAX
    window['applyColumns_' + componentId] = function() {
        const checkboxes = document.querySelectorAll('.column-checkbox-' + componentId + ':checked');
        const columns = Array.from(checkboxes).map(cb => cb.value);

        const params = new URLSearchParams(window.location.search);
        params.set('columns_' + componentId, columns.join(','));

        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);

        fetch(newUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTableCard = doc.querySelector('#tableCard_' + componentId);

            if(newTableCard) {
                document.getElementById('tableCard_' + componentId).innerHTML = newTableCard.innerHTML;
                const newDropdown = document.getElementById('columnDropdown_' + componentId);
                if(newDropdown) newDropdown.style.display = 'none';
                if(typeof refreshUrlModalHandlers === 'function') refreshUrlModalHandlers();
                if(typeof window['initScrollbarSync_' + componentId] === 'function') window['initScrollbarSync_' + componentId]();
                showGlobalStatus(__('table.columns_updated'), 'success');
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert(__('table.columns_update_error'));
        });
    };

    // Export CSV
    window['exportToCSV_' + componentId] = function() {
        const selectedCols = [];
        document.querySelectorAll('.column-checkbox-' + componentId + ':checked').forEach(cb => {
            selectedCols.push(cb.value);
        });

        document.getElementById('exportColumns_' + componentId).value = JSON.stringify(selectedCols);
        document.getElementById('exportForm_' + componentId).submit();
    };

    // Synchronisation scrollbars
    window['scrollHandlers_' + componentId] = null;

    window['initScrollbarSync_' + componentId] = function() {
        const topScrollbar = document.getElementById('topScrollbar_' + componentId);
        const tableContainer = document.getElementById('tableContainer_' + componentId);
        const topScrollbarContent = document.getElementById('topScrollbarContent_' + componentId);
        const table = document.getElementById('urlTable_' + componentId);

        if (!topScrollbar || !tableContainer || !topScrollbarContent || !table) return;

        topScrollbarContent.style.width = table.offsetWidth + 'px';
        setTimeout(function() { topScrollbarContent.style.width = table.offsetWidth + 'px'; }, 100);

        if (window['scrollHandlers_' + componentId]) {
            const oldHandlers = window['scrollHandlers_' + componentId];
            if (topScrollbar && oldHandlers.topHandler) topScrollbar.removeEventListener('scroll', oldHandlers.topHandler);
            if (tableContainer && oldHandlers.tableHandler) tableContainer.removeEventListener('scroll', oldHandlers.tableHandler);
        }

        const topHandler = function() { const tc = document.getElementById('tableContainer_' + componentId); if (tc) tc.scrollLeft = this.scrollLeft; };
        const tableHandler = function() { const ts = document.getElementById('topScrollbar_' + componentId); if (ts) ts.scrollLeft = this.scrollLeft; };

        topScrollbar.addEventListener('scroll', topHandler);
        tableContainer.addEventListener('scroll', tableHandler);
        window['scrollHandlers_' + componentId] = { topHandler, tableHandler };
    };

    window['initScrollbarSync_' + componentId]();

    window.addEventListener('resize', function() {
        const topScrollbarContent = document.getElementById('topScrollbarContent_' + componentId);
        const table = document.getElementById('urlTable_' + componentId);
        if (table && topScrollbarContent) topScrollbarContent.style.width = table.offsetWidth + 'px';
    });

    // Scope modal
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
