<?php
// Connexion à la base de données (déjà établie dans dashboard.php via $pdo)
// Vérifier que $pdo existe
if(!isset($pdo)) {
    echo "<div class='status-message status-error'>Erreur: Connexion à la base de données non établie</div>";
    return;
}

// Récupérer le mapping global des couleurs de catégories
$categoryColors = $GLOBALS['categoryColors'] ?? [];

// Récupérer le domaine depuis le crawl
$crawledDomain = $crawlRecord->domain ?? '';

// Lecture de la config de catégorisation depuis PostgreSQL
$catYmlContent = "# Définissez vos catégories ici\n# Format:\n# Nom de la catégorie:\n#   - pattern1\n#   - pattern2\n";
$yamlCategories = [];

try {
    $stmt = $pdo->prepare("SELECT config FROM categorization_config WHERE crawl_id = :crawl_id");
    $stmt->execute([':crawl_id' => $crawlId]);
    $configRow = $stmt->fetch(PDO::FETCH_OBJ);
    
    if ($configRow && !empty($configRow->config)) {
        $catYmlContent = $configRow->config;
        
        // Parser le YAML pour récupérer les catégories
        $yamlData = \Spyc::YAMLLoadString($catYmlContent);
        if(is_array($yamlData)) {
            foreach($yamlData as $catName => $rules) {
                $yamlCategories[] = $catName;
            }
        }
    }
} catch(Exception $e) {
    // Ignorer les erreurs
}

// Récupération des catégories actuelles en base (PostgreSQL) avec couleurs
try {
    $stmt = $pdo->prepare("SELECT categories.id, categories.cat, categories.color, COUNT(pages.id) as url_count FROM categories 
        LEFT JOIN pages ON categories.id = pages.cat_id AND pages.crawl_id = :crawl_id2
        WHERE categories.crawl_id = :crawl_id
        GROUP BY categories.id, categories.cat, categories.color 
        ORDER BY categories.id");
    $stmt->execute([':crawl_id' => $crawlId, ':crawl_id2' => $crawlId]);
    $categories = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    // Construire le mapping des couleurs depuis la base
    foreach ($categories as $cat) {
        $categoryColors[$cat->cat] = $cat->color ?? '#aaaaaa';
    }
} catch(PDOException $e) {
    echo "<div class='status-message status-error'>Erreur SQL: " . htmlspecialchars($e->getMessage()) . "</div>";
    $categories = [];
}

// Filtre de catégorie (utilisé par le composant url-table et le graphique)
$filterCat = isset($_GET['filter_cat']) ? $_GET['filter_cat'] : '';

// Stats pour le graphique de répartition (sans jointure)
try {
    $stmt = $pdo->prepare("SELECT 
        cat_id,
        COUNT(*) as count
        FROM pages
        WHERE crawl_id = :crawl_id AND crawled = true
        GROUP BY cat_id
        ORDER BY count DESC");
    $stmt->execute([':crawl_id' => $crawlId]);
    $categoryStatsRaw = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    // Convertir cat_id en nom de catégorie
    $categoriesMap = $GLOBALS['categoriesMap'] ?? [];
    $categoryStats = [];
    foreach ($categoryStatsRaw as $row) {
        $catInfo = $categoriesMap[$row->cat_id] ?? null;
        $obj = new stdClass();
        $obj->category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
        $obj->count = $row->count;
        $categoryStats[] = $obj;
    }
} catch(PDOException $e) {
    echo "<div class='status-message status-error'>Erreur SQL: " . htmlspecialchars($e->getMessage()) . "</div>";
    $categoryStats = [];
}
?>

<style>
body {
    overflow: hidden;
}

/* Override main-content pour cette page */
.main-content {
    overflow: hidden;
    max-width: none;
    padding: 0 !important;
    margin: 0 !important;
    width: 100%;
}

.categorize-layout {
    display: grid;
    grid-template-columns: 400px 1fr;
    gap: 0;
    height: calc(100vh - 72px);
    max-height: calc(100vh - 72px);
    border-radius: 0;
    overflow: hidden;
}

.categorize-panel {
    background: var(--card-bg);
    border-radius: 0;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    max-height: 100%;
    min-height: 0;
}

/* Panneau Éditeur - Style sidebar-panel (légèrement plus clair que la nav principale) */
.categorize-panel:first-child {
    background: #34495e; /* 5% plus clair que #2C3E50 pour distinction */
    color: rgba(255, 255, 255, 0.9);
    border-left: 1px solid rgba(0, 0, 0, 0.3); /* Séparation avec la nav principale */
}

/* Header style sidebar-panel */
.categorize-panel:first-child h3 {
    color: white;
    font-size: 0.95rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: -1rem -1rem 1rem -1rem;
    padding: 1.2rem 1.25rem;
    background: rgba(0, 0, 0, 0.2);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.categorize-panel:first-child h3 .material-symbols-outlined {
    color: var(--primary-color);
    font-size: 20px;
}

.categorize-panel:first-child .editor-mode-toggle {
    background: rgba(0, 0, 0, 0.2);
    border-radius: 8px;
}

.categorize-panel:first-child .mode-btn {
    color: rgba(255, 255, 255, 0.6);
}

.categorize-panel:first-child .mode-btn:hover {
    color: rgba(255, 255, 255, 0.9);
    background: rgba(255,255,255,0.05);
}

.categorize-panel:first-child .mode-btn.active {
    background: rgba(78, 205, 196, 0.2);
    color: var(--primary-color);
}

/* Scrollbar style sidebar pour le panneau éditeur */
.categorize-panel:first-child ::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

.categorize-panel:first-child ::-webkit-scrollbar-track {
    background: rgba(0, 0, 0, 0.1);
}

.categorize-panel:first-child ::-webkit-scrollbar-thumb {
    background: rgba(78, 205, 196, 0.3);
    border-radius: 10px;
}

.categorize-panel:first-child ::-webkit-scrollbar-thumb:hover {
    background: rgba(78, 205, 196, 0.5);
}

.categorize-panel:first-child .rules-container {
    scrollbar-color: rgba(78, 205, 196, 0.3) rgba(0, 0, 0, 0.1);
}

.categorize-panel:first-child .btn-help {
    border-color: rgba(255, 255, 255, 0.2);
    color: rgba(255, 255, 255, 0.6);
    transition: all 0.2s ease;
}

.categorize-panel:first-child .btn-help:hover {
    background: rgba(78, 205, 196, 0.15);
    color: var(--primary-color);
    border-color: var(--primary-color);
}

/* Rule cards - style sidebar */
.categorize-panel:first-child .rule-card {
    background: rgba(0, 0, 0, 0.15);
    border-color: rgba(255, 255, 255, 0.1);
}

.categorize-panel:first-child .rule-card:hover {
    border-color: rgba(78, 205, 196, 0.5);
}

.categorize-panel:first-child .rule-name {
    color: white;
}

.categorize-panel:first-child .rule-drag-handle,
.categorize-panel:first-child .rule-expand-icon {
    color: rgba(255, 255, 255, 0.4);
}

.categorize-panel:first-child .rule-meta-badge {
    background: rgba(0, 0, 0, 0.2);
    color: rgba(255, 255, 255, 0.7);
}

.categorize-panel:first-child .rule-meta-badge-domain {
    background: rgba(0, 0, 0, 0.2);
    color: rgba(255, 255, 255, 0.7);
}

.categorize-panel:first-child .rule-header-bottom {
    border-top-color: rgba(255, 255, 255, 0.1);
}

.categorize-panel:first-child .rule-delete-btn {
    color: rgba(255, 255, 255, 0.4);
}

.categorize-panel:first-child .rule-delete-btn:hover {
    background: rgba(231, 76, 60, 0.2);
    color: #e74c3c;
}

/* Rule card body - style sidebar */
.categorize-panel:first-child .rule-card-body {
    background: rgba(0, 0, 0, 0.1);
    border-top-color: rgba(255, 255, 255, 0.1);
}

.categorize-panel:first-child .rule-field-label {
    color: rgba(255, 255, 255, 0.6);
}

.categorize-panel:first-child .rule-domain-input,
.categorize-panel:first-child .rule-name-input {
    background: rgba(0, 0, 0, 0.2);
    border-color: rgba(255, 255, 255, 0.1);
    color: white;
}

.categorize-panel:first-child .rule-domain-input:focus,
.categorize-panel:first-child .rule-name-input:focus {
    border-color: var(--primary-color);
}

.categorize-panel:first-child .pattern-item {
    background: rgba(0, 0, 0, 0.2);
    border-color: rgba(255, 255, 255, 0.1);
}

.categorize-panel:first-child .pattern-item input {
    color: white;
}

.categorize-panel:first-child .pattern-add-btn {
    border-color: rgba(255, 255, 255, 0.2);
    color: rgba(255, 255, 255, 0.5);
}

.categorize-panel:first-child .pattern-add-btn:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background: rgba(78, 205, 196, 0.1);
}

.categorize-panel:first-child .pattern-remove-btn {
    color: rgba(255, 255, 255, 0.4);
}

.categorize-panel:first-child .pattern-remove-btn:hover {
    background: rgba(231, 76, 60, 0.2);
    color: #e74c3c;
}

/* Add rule button - style sidebar */
.categorize-panel:first-child .add-rule-btn {
    background: rgba(0, 0, 0, 0.15);
    border-color: rgba(255, 255, 255, 0.15);
    color: rgba(255, 255, 255, 0.7);
}

.categorize-panel:first-child .add-rule-btn:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background: rgba(78, 205, 196, 0.1);
}

/* Editor actions buttons - style sidebar */
.categorize-panel:first-child .editor-actions .btn {
    border: none;
}

.categorize-panel:first-child .editor-actions .btn-primary {
    background: rgba(255, 255, 255, 0.1);
    color: white;
}

.categorize-panel:first-child .editor-actions .btn-primary:hover {
    background: rgba(255, 255, 255, 0.15);
}

.categorize-panel:first-child .editor-actions .btn-success {
    background: var(--primary-color);
    color: white;
}

.categorize-panel:first-child .editor-actions .btn-success:hover {
    background: var(--primary-dark);
}

/* Empty state - style sidebar */
.categorize-panel:first-child .rules-empty {
    color: rgba(255, 255, 255, 0.5);
}

/* YAML editor wrapper - style sidebar */
.categorize-panel:first-child .yaml-editor-wrapper {
    border: none;
    background: rgba(0, 0, 0, 0.2);
    border-radius: 8px;
}

/* CodeMirror scrollbar - style sidebar avec accent vert */
.categorize-panel:first-child .CodeMirror-simplescroll-vertical,
.categorize-panel:first-child .CodeMirror-simplescroll-horizontal {
    background: rgba(0, 0, 0, 0.2);
}

.categorize-panel:first-child .CodeMirror-simplescroll-vertical div,
.categorize-panel:first-child .CodeMirror-simplescroll-horizontal div {
    background: rgba(78, 205, 196, 0.5) !important;
    border-radius: 10px;
}

.categorize-panel:first-child .yaml-editor-wrapper ::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.categorize-panel:first-child .yaml-editor-wrapper ::-webkit-scrollbar-track {
    background: rgba(0, 0, 0, 0.2);
}

.categorize-panel:first-child .yaml-editor-wrapper ::-webkit-scrollbar-thumb {
    background: rgba(78, 205, 196, 0.5);
    border-radius: 10px;
}

.categorize-panel:first-child .yaml-editor-wrapper ::-webkit-scrollbar-thumb:hover {
    background: rgba(78, 205, 196, 0.7);
}

.categorize-panel:first-child .CodeMirror-vscrollbar,
.categorize-panel:first-child .CodeMirror-hscrollbar {
    scrollbar-color: rgba(78, 205, 196, 0.5) rgba(0, 0, 0, 0.2);
}

/* Panneau Table avec barre de stats en haut */
.categorize-panel-table {
    padding: 0;
    display: flex;
    flex-direction: column;
}

/* Barre de filtres par catégorie */
.stats-bar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 1rem;
    background: #f8f9fa;
    border-bottom: 1px solid var(--border-color);
    flex-shrink: 0;
    flex-wrap: wrap;
}

/* Liste des catégories (pilules cliquables) */
.category-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    flex: 1;
}

.category-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.65rem;
    border-radius: 14px;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.15s ease;
    border: 2px solid transparent;
    font-weight: 500;
}

.category-pill:hover {
    opacity: 0.85;
    transform: translateY(-1px);
}

.category-pill.active {
    box-shadow: 0 0 0 2px var(--primary-color);
}

.category-pill.pill-disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.category-pill-name {
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.category-pill-pct {
    font-size: 0.7rem;
    opacity: 0.8;
}

/* Boutons navigation pilules - centrés verticalement */
.pills-nav-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border: none;
    background: transparent;
    color: var(--text-secondary);
    cursor: pointer;
    border-radius: 4px;
    transition: all 0.15s;
    padding: 0;
    margin-top: 8px;
    flex-shrink: 0;
}

.pills-nav-btn:hover:not(.disabled) {
    background: rgba(0,0,0,0.08);
    color: var(--text-primary);
}

.pills-nav-btn.disabled {
    opacity: 0.3;
    cursor: default;
}

.pills-nav-btn .material-symbols-outlined {
    font-size: 16px;
}

/* Séparateur entre Non catégorisé et les autres */
.pills-separator {
    display: inline-block;
    width: 1px;
    height: 20px;
    background: var(--border-color);
    margin: 0 0.5rem;
    vertical-align: middle;
}

/* Bouton reset filtre */
.stats-bar .chart-reset-btn {
    padding: 0.35rem 0.6rem;
    font-size: 0.8rem;
    margin-left: auto;
}

/* Test mode notice compact */
.stats-bar .test-mode-notice {
    margin: 0;
    padding: 0.3rem 0.6rem;
    font-size: 0.75rem;
}

/* Zone du tableau - Layout fixe avec scroll central */
.table-container-wrapper {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 0;
}

/* Le conteneur du tableau doit prendre toute la place disponible */
.categorize-panel-table .table-scroll-area,
.table-container-wrapper > div:first-child {
    flex: 1;
    overflow: auto;
    min-height: 0;
}

/* Structure pour mode light avec hideTitle */
.table-container-wrapper {
    position: relative;
}

.table-container-wrapper > .data-table,
.table-container-wrapper table.data-table {
    width: 100%;
}


/* Scrollbar style cohérent - thème clair */
.categorize-panel-table .table-scroll-area::-webkit-scrollbar,
.table-container-wrapper::-webkit-scrollbar,
.table-container-wrapper > div::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

.categorize-panel-table .table-scroll-area::-webkit-scrollbar-track,
.table-container-wrapper::-webkit-scrollbar-track,
.table-container-wrapper > div::-webkit-scrollbar-track {
    background: transparent;
}

.categorize-panel-table .table-scroll-area::-webkit-scrollbar-thumb,
.table-container-wrapper::-webkit-scrollbar-thumb,
.table-container-wrapper > div::-webkit-scrollbar-thumb {
    background: rgba(0, 0, 0, 0.15);
    border-radius: 10px;
}

.categorize-panel-table .table-scroll-area::-webkit-scrollbar-thumb:hover,
.table-container-wrapper::-webkit-scrollbar-thumb:hover,
.table-container-wrapper > div::-webkit-scrollbar-thumb:hover {
    background: rgba(0, 0, 0, 0.25);
}

/* Sticky header pour le tableau - collé en haut */
.categorize-panel-table .data-table,
.table-container-wrapper .data-table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
}

.categorize-panel-table .data-table thead,
.table-container-wrapper .data-table thead {
    position: sticky;
    top: 0;
    z-index: 10;
}

.categorize-panel-table .data-table thead th,
.table-container-wrapper .data-table thead th {
    background: #f8f9fa;
    border-bottom: 2px solid var(--border-color);
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

/* Row hover effect */
.categorize-panel-table .data-table tbody tr,
.table-container-wrapper .data-table tbody tr {
    transition: background-color 0.15s ease;
}

.categorize-panel-table .data-table tbody tr:hover,
.table-container-wrapper .data-table tbody tr:hover {
    background-color: #f0f7ff !important;
}

/* Barre du bas fixe */
.categorize-panel-table .table-footer-bar,
.table-container-wrapper .table-footer-bar {
    flex-shrink: 0;
    border-top: 1px solid var(--border-color);
    background: #f8f9fa;
}


.categorize-panel h3 {
    margin: 0 0 0.75rem 0;
    color: var(--text-primary);
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-shrink: 0;
}

.btn-help-group {
    margin-left: auto;
    display: flex;
    gap: 4px;
}

.btn-help {
    background: transparent;
    border: 1px solid var(--border-color);
    border-radius: 50%;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    color: var(--text-secondary);
}

.btn-help:hover {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}

.btn-help .material-symbols-outlined {
    font-size: 20px;
}

.yaml-editor-wrapper {
    flex: 1;
    min-height: 0;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    overflow: hidden;
}

.yaml-editor-wrapper .CodeMirror {
    height: 100%;
    font-size: 13px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
}

/* CodeMirror Dialog (Search) - Dark Mode */
.yaml-editor-wrapper .CodeMirror-dialog {
    background: #1e1e2e;
    border-bottom: 1px solid #3d3d5c;
    padding: 8px 12px;
    color: #cdd6f4;
}

.yaml-editor-wrapper .CodeMirror-dialog input {
    background: #313244;
    border: 1px solid #45475a;
    border-radius: 4px;
    padding: 4px 8px;
    color: #cdd6f4;
    font-family: inherit;
    font-size: 13px;
    outline: none;
}

.yaml-editor-wrapper .CodeMirror-dialog input:focus {
    border-color: #f78c6c;
    box-shadow: 0 0 0 2px rgba(247, 140, 108, 0.2);
}

.yaml-editor-wrapper .CodeMirror-dialog button {
    background: #45475a;
    border: none;
    border-radius: 4px;
    padding: 4px 10px;
    color: #cdd6f4;
    cursor: pointer;
    margin-left: 6px;
    font-size: 12px;
}

.yaml-editor-wrapper .CodeMirror-dialog button:hover {
    background: #585b70;
}

/* Highlight des résultats de recherche */
.yaml-editor-wrapper .cm-searching {
    background: rgba(247, 140, 108, 0.4);
    border-radius: 2px;
}

/* Cacher le texte "(Use /re/ syntax for regexp search)" */
.yaml-editor-wrapper .CodeMirror-dialog span:last-child {
    display: none;
}

/* Style pour les hints de raccourci clavier */
.shortcut-hint {
    font-size: 0.65rem;
    font-weight: 500;
    margin-left: 0.5rem;
    padding: 0.2rem 0.5rem;
    background: rgba(255, 255, 255, 0.25);
    border-radius: 4px;
    letter-spacing: 0.5px;
}

.editor-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.75rem;
    flex-shrink: 0;
}

/* Le panneau central avec url-table */
.categorize-panel-table {
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.categorize-panel-table .url-table-card {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
}

.categorize-panel-table .url-table-wrapper {
    flex: 1;
    min-height: 0;
    overflow: auto;
}

.url-cell a {
    color: var(--primary-color);
    text-decoration: none;
}

.url-cell a:hover {
    text-decoration: underline;
}

.category-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
    background: var(--primary-color);
    color: white;
}

.stats-info {
    padding: 0.5rem 0.75rem;
    background: var(--background);
    border-radius: 6px;
    margin-bottom: 0.75rem;
    font-size: 0.85rem;
    color: var(--text-secondary);
    flex-shrink: 0;
}

#categoryChart {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    min-height: 100px;
}

/* Séparateur et graphique Donut */
.donut-separator {
    height: 1px;
    background: linear-gradient(to right, transparent, var(--border-color), transparent);
    margin: 1rem 0;
    flex-shrink: 0;
}

.donut-chart-container {
    height: 200px;
    flex-shrink: 0;
}

/* Chart Header */
.chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
    flex-shrink: 0;
}

.chart-stats {
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.chart-stats span {
    font-weight: 700;
    color: var(--text-primary);
}

.chart-hint {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin: -0.25rem 0 0.75rem 0;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.chart-reset-btn {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.35rem 0.6rem;
    background: var(--background);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.75rem;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s;
}

.chart-reset-btn:hover {
    background: white;
    border-color: var(--primary-color);
    color: var(--primary-color);
}

.chart-reset-btn .material-symbols-outlined {
    font-size: 16px;
}

/* Test Mode Notice */
.test-mode-notice {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    border: 1px solid #f0c36d;
    border-radius: 6px;
    font-size: 0.75rem;
    color: #856404;
    margin-bottom: 0.75rem;
    flex-shrink: 0;
}

.test-mode-notice .material-symbols-outlined {
    font-size: 16px;
}

/* Category Chart */
.category-chart {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
}

/* Category Item */
.category-item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    padding: 0.5rem 0.75rem;
    margin-bottom: 0.6rem;
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.category-item:hover {
    border-color: var(--primary-color);
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.category-item.active {
    border-color: var(--primary-color);
    background: rgba(78, 205, 196, 0.05);
    box-shadow: 0 0 0 2px rgba(78, 205, 196, 0.2);
}

.category-item.temporary {
    opacity: 0.6;
    cursor: not-allowed;
    border-style: dashed;
}

.category-item.temporary:hover {
    border-color: var(--border-color);
    box-shadow: none;
}

.category-item-line1 {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.category-item-line2 {
    font-size: 0.7rem;
    color: var(--text-secondary);
    margin-top: 1px;
}

.category-color-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

.category-item-name {
    flex: 1;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.category-item-count {
    font-size: 0.75rem;
    color: var(--text-secondary);
    font-weight: 500;
}

.category-item-bar {
    display: block !important;
    height: 4px !important;
    background-color: #e9ecef !important;
    border-radius: 2px;
    margin-top: 4px;
    width: 100%;
}

.category-item-bar-fill {
    display: block !important;
    height: 4px !important;
    border-radius: 2px;
    transition: width 0.4s ease;
    min-width: 2px;
}

/* Temporary badge */
.category-temp-badge {
    font-size: 0.65rem;
    padding: 0.1rem 0.6rem;
    background: #fff3cd;
    color: #856404;
    border-radius: 4px;
    font-weight: 500;
}

/* Styles pour la modale d'aide - Template URL Modal */
.help-modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    overflow: auto;
}

.help-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.help-modal-content {
    background-color: #2C3E50;
    margin: 2rem;
    padding: 0;
    border-radius: 12px;
    width: 90%;
    max-width: 900px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    overflow: hidden;
}

.help-modal-header {
    padding: 1.25rem 2rem;
    border-bottom: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    background: linear-gradient(135deg, #1a252f 0%, #2C3E50 100%);
    border-radius: 12px 12px 0 0;
}

.help-modal-header h2 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: white;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.help-modal-header h2 .material-symbols-outlined {
    color: var(--primary-color);
    font-size: 22px;
    flex-shrink: 0;
}

.help-modal-close {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    cursor: pointer;
    padding: 0.5rem;
    color: rgba(255, 255, 255, 0.8);
    transition: all 0.2s;
    border-radius: 8px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.help-modal-close .material-symbols-outlined {
    font-size: 20px;
}

.help-modal-close:hover {
    background: rgba(231, 76, 60, 0.9);
    border-color: rgba(231, 76, 60, 0.9);
    color: white;
}

.help-modal-body {
    padding: 2rem;
    overflow-y: auto;
    flex: 1;
    background: white;
}

.help-modal-body h3 {
    color: var(--primary-color);
    margin: 2rem 0 1rem 0;
    font-size: 1.3rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.help-modal-body h3:first-child {
    margin-top: 0;
}

.help-modal-body h4 {
    color: var(--text-primary);
    margin: 1.5rem 0 0.75rem 0;
    font-size: 1.1rem;
}

.help-modal-body p {
    line-height: 1.6;
    color: var(--text-secondary);
    margin: 0.75rem 0;
}

.help-modal-body ul {
    margin: 0.75rem 0;
    padding-left: 1.5rem;
}

.help-modal-body li {
    margin: 0.5rem 0;
    line-height: 1.6;
    color: var(--text-secondary);
}

.help-modal-body code {
    background: #f5f5f5;
    padding: 0.2rem 0.6rem;
    border-radius: 4px;
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 0.9em;
    color: #e74c3c;
}

.help-modal-body pre {
    background: #282c34;
    color: #abb2bf;
    padding: 1rem;
    border-radius: 8px;
    overflow-x: auto;
    margin: 1rem 0;
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 0.9rem;
    line-height: 1.5;
}

.help-modal-body pre code {
    background: transparent;
    padding: 0;
    color: inherit;
}

.help-box {
    background: #e3f2fd;
    border-left: 4px solid #2196f3;
    padding: 1rem;
    margin: 1rem 0;
    border-radius: 4px;
}

.help-box-warning {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
}

.help-box-success {
    background: #d4edda;
    border-left: 4px solid #28a745;
}

/* Mode Toggle */
.editor-mode-toggle {
    display: flex;
    gap: 0;
    margin-bottom: 0.75rem;
    background: var(--background);
    border-radius: 8px;
    padding: 4px;
    flex-shrink: 0;
}

.mode-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border: none;
    background: transparent;
    color: var(--text-secondary);
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    border-radius: 6px;
    transition: all 0.2s;
}

.mode-btn:hover {
    color: var(--text-primary);
    background: rgba(0,0,0,0.05);
}

.mode-btn.active {
    background: var(--primary-color);
    color: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.mode-btn .material-symbols-outlined {
    font-size: 18px;
}

/* Visual Editor */
.visual-editor-wrapper {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.rules-container {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: 4px;
}

/* Rule Card */
.rule-card {
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    margin-bottom: 0.5rem;
    transition: all 0.2s;
    overflow: hidden;
}

.rule-card:hover {
    border-color: var(--primary-color);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.rule-card.dragging {
    opacity: 0.4;
    transform: scale(0.98);
    cursor: grabbing;
}

/* Indicateur de drop - ligne horizontale verte */
.drop-indicator {
    height: 4px;
    margin: 4px 0;
    position: relative;
}

.drop-indicator-line {
    position: absolute;
    left: 0;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    height: 3px;
    background: var(--primary-color);
    border-radius: 2px;
    box-shadow: 0 0 8px rgba(78, 205, 196, 0.6);
    animation: dropIndicatorPulse 0.8s ease-in-out infinite;
}

.drop-indicator-line::before,
.drop-indicator-line::after {
    content: '';
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 10px;
    height: 10px;
    background: var(--primary-color);
    border-radius: 50%;
    box-shadow: 0 0 6px rgba(78, 205, 196, 0.8);
}

.drop-indicator-line::before {
    left: -5px;
}

.drop-indicator-line::after {
    right: -5px;
}

@keyframes dropIndicatorPulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

.rule-card-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem;
    cursor: pointer;
    user-select: none;
}

.rule-header-top {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    min-width: 100%;
}

.rule-header-top .rule-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}

.rule-header-bottom {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    padding-top: 0.25rem;
    border-top: 1px dashed var(--border-color);
    margin-top: 0.25rem;
}

.rule-header-bottom .rule-meta-badge-domain {
    font-size: 0.7rem;
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.rule-header-bottom .rule-meta {
    margin-left: auto;
}

.rule-drag-handle {
    color: var(--text-secondary);
    cursor: grab;
    padding: 0.25rem;
    display: flex;
    align-items: center;
    opacity: 0.5;
    transition: opacity 0.2s;
}

.rule-card:hover .rule-drag-handle {
    opacity: 1;
}

.rule-drag-handle:active {
    cursor: grabbing;
}

.rule-drag-handle .material-symbols-outlined {
    font-size: 20px;
}

.rule-color-picker {
    width: 18px;
    height: 18px;
    padding: 0;
    border: 2px solid #333;
    border-radius: 3px;
    cursor: crosshair;
    background: none;
    flex-shrink: 0;
}

.rule-color-picker::-webkit-color-swatch-wrapper {
    padding: 0;
}

.rule-color-picker::-webkit-color-swatch {
    border: none;
    border-radius: 1px;
}

.rule-color-picker::-moz-color-swatch {
    border: none;
    border-radius: 1px;
}

.rule-color-picker:hover {
    border-color: var(--primary-color);
    transform: scale(1.1);
}

.rule-name {
    flex: 1;
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--text-primary);
}

.rule-name-input {
    flex: 1;
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    padding: 0.25rem 0.5rem;
    background: var(--background);
}

.rule-name-input:focus {
    outline: none;
    border-color: var(--primary-color);
}

.rule-meta {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-left: auto;
}

.rule-meta-badge {
    background: var(--background);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.rule-meta-badge .material-symbols-outlined {
    font-size: 14px;
}

.rule-expand-icon {
    color: var(--text-secondary);
    transition: transform 0.2s;
}

.rule-card.expanded .rule-expand-icon {
    transform: rotate(180deg);
}

.rule-delete-btn {
    background: transparent;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 0.25rem;
    display: flex;
    align-items: center;
    border-radius: 4px;
    transition: all 0.2s;
}

.rule-delete-btn:hover {
    background: rgba(231, 76, 60, 0.1);
    color: var(--danger);
}

.rule-delete-btn .material-symbols-outlined {
    font-size: 18px;
}

/* Rule Card Body */
.rule-card-body {
    display: none;
    padding:0.75rem;
    border-top: 1px solid var(--border-color);
    background: var(--background);
}

.rule-card.expanded .rule-card-body {
    display: block;
}

.rule-field {
    margin-bottom: 0.75rem;
}

.rule-field:last-child {
    margin-bottom: 0;
}

.rule-field-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.rule-field-label .material-symbols-outlined {
    font-size: 16px;
}

.rule-domain-input {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.85rem;
    background: white;
}

.rule-domain-input:focus {
    outline: none;
    border-color: var(--primary-color);
}

/* Pattern List */
.pattern-list {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.pattern-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 0.25rem 0.5rem;
}

.pattern-item input {
    flex: 1;
    border: none;
    font-size: 0.85rem;
    font-family: 'Consolas', 'Monaco', monospace;
    background: transparent;
    padding: 0.25rem;
}

.pattern-item input:focus {
    outline: none;
}

.pattern-remove-btn {
    background: transparent;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 0.25rem;
    display: flex;
    align-items: center;
    border-radius: 4px;
    transition: all 0.2s;
}

.pattern-remove-btn:hover {
    background: rgba(231, 76, 60, 0.1);
    color: var(--danger);
}

.pattern-remove-btn .material-symbols-outlined {
    font-size: 16px;
}

.pattern-add-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.25rem;
    padding: 0.35rem;
    border: 1px dashed var(--border-color);
    border-radius: 6px;
    background: transparent;
    color: var(--text-secondary);
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.2s;
    margin-top: 0.25rem;
}

.pattern-add-btn:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background: rgba(52, 152, 219, 0.05);
}

.pattern-add-btn .material-symbols-outlined {
    font-size: 16px;
}

/* Include/Exclude styling */
.rule-field.include .pattern-item {
    border-left: 3px solid var(--success);
}

.rule-field.exclude .pattern-item {
    border-left: 3px solid var(--danger);
}

/* Empty state */
.rules-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 2rem;
    color: var(--text-secondary);
    text-align: center;
    flex: 1;
}

.rules-empty .material-symbols-outlined {
    font-size: 48px;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.rules-empty p {
    margin: 0;
    font-size: 0.9rem;
}

/* Add rule button */
.add-rule-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.75rem;
    margin-top: 0.5rem;
    background: white;
    border: 2px dashed var(--border-color);
    border-radius: 8px;
    color: var(--text-secondary);
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    flex-shrink: 0;
}

.add-rule-btn:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background: rgba(52, 152, 219, 0.05);
}

.add-rule-btn .material-symbols-outlined {
    font-size: 20px;
}

@media (max-width: 1400px) {
    .categorize-layout {
        grid-template-columns: 1fr;
        height: auto;
    }
    
    .categorize-panel {
        height: 400px;
    }
    
    .help-modal-content {
        width: 95%;
        max-height: 95vh;
    }
    
    .help-modal-body {
        padding: 1.5rem;
    }
}
</style>

<div class="categorize-layout">
    <!-- Panneau gauche: Éditeur YAML -->
    <div class="categorize-panel">
        <h3>
            <span class="material-symbols-outlined">edit_note</span>
            Éditeur cat.yml
            <div class="btn-help-group">
                <button class="btn-help" onclick="generateColors()" title="Générer les couleurs automatiquement">
                    <span class="material-symbols-outlined">palette</span>
                </button>
                <button class="btn-help" onclick="showHelp()" title="Aide sur la catégorisation">
                    <span class="material-symbols-outlined">help</span>
                </button>
            </div>
        </h3>
        
        <!-- Toggle Switch Mode -->
        <div class="editor-mode-toggle">
            <button class="mode-btn" data-mode="code" onclick="switchEditorMode('code')">
                <span class="material-symbols-outlined">code</span>
                Code
            </button>
            <button class="mode-btn active" data-mode="visual" onclick="switchEditorMode('visual')">
                <span class="material-symbols-outlined">dashboard_customize</span>
                Visuel
            </button>
        </div>
        
        <!-- Mode Code (CodeMirror) -->
        <div id="yamlEditorWrapper" class="yaml-editor-wrapper" style="display: none;">
            <textarea id="yamlEditor" style="display:none;"><?= htmlspecialchars($catYmlContent) ?></textarea>
        </div>
        
        <!-- Mode Visuel (WYSIWYG) -->
        <div id="visualEditorWrapper" class="visual-editor-wrapper">
            <div id="rulesContainer" class="rules-container"></div>
            <button class="add-rule-btn" onclick="addNewRule()">
                <span class="material-symbols-outlined">add</span>
                Ajouter une règle
            </button>
        </div>
        <div class="editor-actions">
            <button class="btn btn-primary" onclick="testCategorization()" style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                <span class="material-symbols-outlined">science</span>
                Tester
            </button>
            <button class="btn btn-success" onclick="saveCategorization()" style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                <span class="material-symbols-outlined">save</span>
                Sauvegarder
                <span class="shortcut-hint">Ctrl+S</span>
            </button>
        </div>
    </div>

    <!-- Panneau central: Stats bar + Liste des URLs -->
    <div class="categorize-panel categorize-panel-table">
        <!-- Barre de filtres par catégorie -->
        <div class="stats-bar">
            <!-- Pilules de catégories cliquables -->
            <div id="categoryPills" class="category-pills"></div>
            
            <button class="chart-reset-btn" id="chartResetBtn" onclick="resetCategoryFilter()" style="display: none;">
                <span class="material-symbols-outlined">filter_alt_off</span>
                Reset
            </button>
            
            <div id="testModeNotice" class="test-mode-notice" style="display: none;">
                <span class="material-symbols-outlined">science</span>
                <span>Mode test</span>
            </div>
        </div>
        
        <!-- Tableau des URLs -->
        <div class="table-container-wrapper">
        <?php
        // Construire le WHERE avec le filtre de catégorie
        $catWhereConditions = ["c.crawled = true"];
        $catParams = [];
        
        if(!empty($filterCat)) {
            if($filterCat === 'none') {
                $catWhereConditions[] = "c.cat_id IS NULL";
            } else {
                // Trouver l'ID de la catégorie à partir du nom
                $categoriesMap = $GLOBALS['categoriesMap'] ?? [];
                $filterCatId = null;
                foreach ($categoriesMap as $id => $info) {
                    if ($info['cat'] === $filterCat) {
                        $filterCatId = $id;
                        break;
                    }
                }
                if ($filterCatId !== null) {
                    $catWhereConditions[] = "c.cat_id = :filter_cat_id";
                    $catParams[':filter_cat_id'] = $filterCatId;
                }
            }
        }
        
        $catWhereClause = implode(' AND ', $catWhereConditions);
        
        $urlTableConfig = [
            'title' => '',
            'id' => 'categorize_table',
            'whereClause' => 'WHERE ' . $catWhereClause,
            'orderBy' => 'ORDER BY c.cat_id, c.url',
            'sqlParams' => $catParams,
            'defaultColumns' => ['url', 'code', 'category'],
            'perPage' => 50,
            'pdo' => $pdo,
            'crawlId' => $crawlId,
            'projectDir' => $projectDir,
            'light' => true,
            'copyUrl' => true,
            'hideTitle' => true
        ];
        
        include __DIR__ . '/../components/url-table.php';
        ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../components/batch-job-notification.php'; ?>

<!-- Modale d'aide -->
<div id="helpModal" class="help-modal" onclick="if(event.target === this) hideHelp()">
    <div class="help-modal-content">
        <div class="help-modal-header">
            <h2>
                <span class="material-symbols-outlined">help</span>
                Guide de catégorisation YAML
            </h2>
            <button class="help-modal-close" onclick="hideHelp()">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="help-modal-body">
            <h3>📚 Introduction</h3>
            <p>
                Le fichier <code>cat.yml</code> permet de catégoriser automatiquement les URLs de votre site web en fonction de patterns (motifs). 
                Chaque catégorie contient des règles qui définissent quelles URLs appartiennent à cette catégorie.
            </p>

            <h3>🏗️ Structure de base</h3>
            <p>Chaque catégorie suit cette structure :</p>
            <pre><code>Nom_de_la_categorie:
  dom: votre-domaine.com
  include:
    - pattern1
    - pattern2
  exclude:
    - pattern_a_exclure</code></pre>

            <h4>Les 3 éléments essentiels :</h4>
            <ul>
                <li><strong>dom</strong> : Le domaine à cibler (obligatoire)</li>
                <li><strong>include</strong> : Liste des patterns qui DOIVENT matcher (obligatoire)</li>
                <li><strong>exclude</strong> : Liste des patterns qui NE DOIVENT PAS matcher (optionnel)</li>
            </ul>

            <h3>🎯 Exemples pratiques</h3>

            <h4>Exemple 1 : Catégorie "Blog"</h4>
            <pre><code>blog:
  dom: monsite.com
  include:
    - ^/blog/
    - ^/articles/</code></pre>
            <p>✅ Matche : <code>https://monsite.com/blog/mon-article</code></p>
            <p>✅ Matche : <code>https://monsite.com/articles/guide</code></p>
            <p>❌ Ne matche pas : <code>https://monsite.com/produits</code></p>

            <h4>Exemple 2 : Produits avec exclusion</h4>
            <pre><code>produits:
  dom: monsite.com
  include:
    - ^/produits/
  exclude:
    - /archive/
    - /test/</code></pre>
            <p>✅ Matche : <code>https://monsite.com/produits/chaise</code></p>
            <p>❌ Ne matche pas : <code>https://monsite.com/produits/archive/ancien</code></p>

            <h4>Exemple 3 : Homepage</h4>
            <pre><code>homepage:
  dom: monsite.com
  include:
    - ^/$
    - ^/index</code></pre>
            <p>✅ Matche : <code>https://monsite.com/</code></p>
            <p>✅ Matche : <code>https://monsite.com/index.html</code></p>

            <h3>🔧 Patterns et Regex</h3>
            
            <div class="help-box">
                <strong>💡 Astuce :</strong> Les patterns utilisent les expressions régulières (regex). Voici les symboles les plus utiles :
            </div>

            <h4>Symboles de base :</h4>
            <ul>
                <li><code>^</code> : Début de l'URL (après le domaine)</li>
                <li><code>$</code> : Fin de l'URL</li>
                <li><code>.</code> : N'importe quel caractère</li>
                <li><code>*</code> : 0 ou plusieurs fois le caractère précédent</li>
                <li><code>+</code> : 1 ou plusieurs fois le caractère précédent</li>
                <li><code>?</code> : 0 ou 1 fois le caractère précédent</li>
                <li><code>|</code> : OU logique</li>
                <li><code>[abc]</code> : Un des caractères a, b ou c</li>
                <li><code>[0-9]</code> : Un chiffre</li>
                <li><code>\d</code> : Un chiffre (équivalent à [0-9])</li>
                <li><code>\w</code> : Un caractère alphanumérique</li>
            </ul>

            <h4>Exemples de patterns avancés :</h4>
            <pre><code># URLs se terminant par .pdf
\.pdf$

# URLs contenant un ID numérique
/produit-\d+

# URLs avec paramètres UTM
\?utm_source=

# URLs avec plusieurs variantes
^/(blog|articles|news)/

# URLs de pagination
/page-[0-9]+</code></pre>

            <h3>📋 Exemples complets</h3>

            <h4>Site e-commerce :</h4>
            <pre><code>homepage:
  dom: shop.com
  include:
    - ^/$

categories_produits:
  dom: shop.com
  include:
    - ^/categorie/
  exclude:
    - /archive/

fiches_produits:
  dom: shop.com
  include:
    - ^/produit-\d+
    - ^/p/

panier_checkout:
  dom: shop.com
  include:
    - ^/panier
    - ^/checkout
    - ^/commande

compte_client:
  dom: shop.com
  include:
    - ^/mon-compte
    - ^/profil</code></pre>

            <h4>Site de contenu :</h4>
            <pre><code>articles:
  dom: blog.com
  include:
    - ^/\d{4}/\d{2}/
  exclude:
    - /brouillon/

auteurs:
  dom: blog.com
  include:
    - ^/auteur/

tags:
  dom: blog.com
  include:
    - ^/tag/
    - ^/categorie/</code></pre>

            <h3>⚠️ Bonnes pratiques</h3>

            <div class="help-box help-box-warning">
                <strong>⚠️ Attention à l'ordre !</strong><br>
                Les catégories sont appliquées dans l'ordre du fichier. Une URL ne peut appartenir qu'à UNE seule catégorie (la première qui matche).
            </div>

            <ul>
                <li>✅ Mettez les catégories les plus spécifiques en premier</li>
                <li>✅ Utilisez <code>^</code> pour matcher depuis le début</li>
                <li>✅ Utilisez <code>$</code> pour matcher jusqu'à la fin</li>
                <li>✅ Testez vos patterns avant de sauvegarder</li>
                <li>❌ Évitez les patterns trop larges qui matchent tout</li>
                <li>❌ N'oubliez pas d'échapper les caractères spéciaux : <code>\.</code> <code>\?</code> <code>\+</code></li>
            </ul>

            <h3>🧪 Workflow recommandé</h3>

            <div class="help-box help-box-success">
                <strong>✅ Processus en 4 étapes :</strong>
                <ol style="margin: 0.5rem 0; padding-left: 1.5rem;">
                    <li>Éditez votre fichier YAML</li>
                    <li>Cliquez sur <strong>"Tester"</strong> pour voir le résultat</li>
                    <li>Vérifiez le graphique et le tableau des URLs</li>
                    <li>Si tout est OK, cliquez sur <strong>"Sauvegarder"</strong></li>
                </ol>
            </div>

            <h3>❓ Besoin d'aide ?</h3>
            <p>
                Si vous avez des difficultés avec les expressions régulières, vous pouvez utiliser des outils en ligne comme 
                <a href="https://regex101.com/" target="_blank" style="color: var(--primary-color);">regex101.com</a> 
                pour tester vos patterns.
            </p>
        </div>
    </div>
</div>

<script>
// Mapping global des couleurs de catégories (depuis PHP)
const globalCategoryColors = <?= json_encode($categoryColors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const TEMP_CATEGORY_COLOR = '#95a5a6'; // Gris pour les nouvelles catégories temporaires

// Variables pour l'AJAX
const categorizeProjectDir = <?= json_encode($projectDir) ?>;
const categorizeCrawlId = <?= json_encode($crawlId) ?>;

// État du graphique
let isTestMode = false;
let activeFilter = <?= json_encode($filterCat ?: null) ?>;

function Categorize_getCategoryColor(categoryName) {
    // D'abord chercher dans les règles éditées (mode visuel)
    if (typeof rulesData !== 'undefined' && rulesData.length > 0) {
        const rule = rulesData.find(r => r.name === categoryName);
        if (rule && rule.color) {
            return rule.color;
        }
    }
    // Sinon utiliser les couleurs globales de la base
    if (globalCategoryColors[categoryName]) {
        return globalCategoryColors[categoryName];
    }
    return TEMP_CATEGORY_COLOR;
}

function isTemporaryCategory(categoryName) {
    // "Non catégorisé" n'est pas une catégorie temporaire, c'est une catégorie système
    if (categoryName === 'Non catégorisé') return false;
    return !globalCategoryColors[categoryName];
}

// Variables pour la pagination des pilules
let allCategoryData = [];
let pillsPage = 0;
const PILLS_PER_PAGE = 6;

function renderChart(data, testMode = false) {
    const pillsContainer = document.getElementById('categoryPills');
    const testNotice = document.getElementById('testModeNotice');
    const resetBtn = document.getElementById('chartResetBtn');
    
    isTestMode = testMode;
    
    // Afficher/masquer le notice de test
    if (testNotice) {
        testNotice.style.display = testMode ? 'flex' : 'none';
    }
    
    // Reset le filtre en mode test
    if (testMode) {
        activeFilter = null;
        if (resetBtn) resetBtn.style.display = 'none';
    }
    
    if(!data || data.length === 0) {
        if (pillsContainer) pillsContainer.innerHTML = '<span style="color: var(--text-secondary); font-size: 0.8rem;">Aucune catégorie</span>';
        return;
    }
    
    // Trier : "Non catégorisé" en premier, puis par count décroissant
    allCategoryData = [...data].sort((a, b) => {
        if (a.category === 'Non catégorisé') return -1;
        if (b.category === 'Non catégorisé') return 1;
        return parseInt(b.count) - parseInt(a.count);
    });
    
    pillsPage = 0;
    renderPillsPage();
}

function renderPillsPage() {
    const pillsContainer = document.getElementById('categoryPills');
    if (!pillsContainer || allCategoryData.length === 0) return;
    
    const total = allCategoryData.reduce((sum, item) => sum + parseInt(item.count), 0);
    
    // Séparer "Non catégorisé" des autres
    const nonCatItem = allCategoryData.find(item => item.category === 'Non catégorisé');
    const otherCategories = allCategoryData.filter(item => item.category !== 'Non catégorisé');
    
    const totalPages = Math.ceil(otherCategories.length / PILLS_PER_PAGE);
    const startIdx = pillsPage * PILLS_PER_PAGE;
    const pageData = otherCategories.slice(startIdx, startIdx + PILLS_PER_PAGE);
    
    let html = '';
    
    // "Non catégorisé" TOUJOURS affiché en premier
    if (nonCatItem) {
        const count = parseInt(nonCatItem.count);
        const percentage = ((count / total) * 100).toFixed(1);
        const color = Categorize_getCategoryColor(nonCatItem.category);
        const textColor = getContrastTextColor(color);
        const isActive = activeFilter === nonCatItem.category;
        const activeClass = isActive ? 'active' : '';
        const clickHandler = count > 0 ? `onclick="filterByChartCategory('${escapeHtml(nonCatItem.category)}')"` : '';
        const disabledClass = count > 0 ? '' : 'pill-disabled';
        
        html += `
            <div class="category-pill ${activeClass} ${disabledClass}" style="background: ${color}; color: ${textColor};" ${clickHandler} title="${escapeHtml(nonCatItem.category)}: ${count} URLs (${percentage}%)">
                <span class="category-pill-name">${escapeHtml(nonCatItem.category)}</span>
                <span class="category-pill-pct">${percentage}%</span>
            </div>
        `;
        
        // Séparateur après Non catégorisé
        if (otherCategories.length > 0) {
            html += `<span class="pills-separator"></span>`;
        }
    } else {
        // Si pas de nonCatItem dans les données, créer un badge à 0%
        const color = Categorize_getCategoryColor('Non catégorisé');
        const textColor = getContrastTextColor(color);
        
        html += `
            <div class="category-pill pill-disabled" style="background: ${color}; color: ${textColor};" title="Non catégorisé: 0 URLs (0.0%)">
                <span class="category-pill-name">Non catégorisé</span>
                <span class="category-pill-pct">0.0%</span>
            </div>
        `;
        
        // Séparateur
        if (otherCategories.length > 0) {
            html += `<span class="pills-separator"></span>`;
        }
    }
    
    // Bouton précédent (après Non catégorisé, avant les autres catégories)
    if (totalPages > 1) {
        html += `<button class="pills-nav-btn ${pillsPage === 0 ? 'disabled' : ''}" onclick="changePillsPage(-1)" ${pillsPage === 0 ? 'disabled' : ''}>
            <span class="material-symbols-outlined">chevron_left</span>
        </button>`;
    }
    
    // Autres catégories (paginées)
    pageData.forEach((item) => {
        const count = parseInt(item.count);
        const percentage = ((count / total) * 100).toFixed(1);
        const color = Categorize_getCategoryColor(item.category);
        const textColor = getContrastTextColor(color);
        const isTemp = isTemporaryCategory(item.category);
        const isActive = activeFilter === item.category;
        
        const activeClass = isActive ? 'active' : '';
        const clickHandler = isTemp ? '' : `onclick="filterByChartCategory('${escapeHtml(item.category)}')"`;
        
        html += `
            <div class="category-pill ${activeClass}" style="background: ${color}; color: ${textColor};" ${clickHandler} title="${escapeHtml(item.category)}: ${count} URLs (${percentage}%)">
                <span class="category-pill-name">${escapeHtml(item.category)}</span>
                <span class="category-pill-pct">${percentage}%</span>
            </div>
        `;
    });
    
    // Bouton suivant
    if (totalPages > 1) {
        html += `<button class="pills-nav-btn ${pillsPage >= totalPages - 1 ? 'disabled' : ''}" onclick="changePillsPage(1)" ${pillsPage >= totalPages - 1 ? 'disabled' : ''}>
            <span class="material-symbols-outlined">chevron_right</span>
        </button>`;
    }
    
    pillsContainer.innerHTML = html;
}

function changePillsPage(delta) {
    const otherCategories = allCategoryData.filter(item => item.category !== 'Non catégorisé');
    const totalPages = Math.ceil(otherCategories.length / PILLS_PER_PAGE);
    pillsPage = Math.max(0, Math.min(totalPages - 1, pillsPage + delta));
    renderPillsPage();
}

// Même fonction que getTextColorForBackground en PHP (seuil 0.75)
function getContrastTextColor(bgColor) {
    const hex = bgColor.replace('#', '');
    const r = parseInt(hex.substr(0, 2), 16);
    const g = parseInt(hex.substr(2, 2), 16);
    const b = parseInt(hex.substr(4, 2), 16);
    // Formule de luminance relative W3C - seuil élevé pour privilégier le texte blanc
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luminance > 0.75 ? '#000000' : '#ffffff';
}

// Fonction de reload pour le composant url-table (utilisée par applyColumns)
window.reloadTable_categorize_table = function() {
    loadCategorizeTable(activeFilter || '');
};

// Charger le tableau via AJAX
function loadCategorizeTable(filterCat = '') {
    const container = document.querySelector('.table-container-wrapper');
    if (!container) return;
    
    // Afficher un loading
    container.style.opacity = '0.5';
    
    // Récupérer les paramètres actuels de l'URL (colonnes, tri, etc.)
    const currentParams = new URLSearchParams(window.location.search);
    let apiUrl = `../api/categorization/table?project=${encodeURIComponent(categorizeProjectDir)}&crawl_id=${encodeURIComponent(categorizeCrawlId)}&filter_cat=${encodeURIComponent(filterCat)}`;
    
    // Ajouter les colonnes si présentes
    if (currentParams.has('columns_categorize_table')) {
        apiUrl += `&columns_categorize_table=${encodeURIComponent(currentParams.get('columns_categorize_table'))}`;
    }
    // Ajouter le tri si présent
    if (currentParams.has('sort_categorize_table')) {
        apiUrl += `&sort_categorize_table=${encodeURIComponent(currentParams.get('sort_categorize_table'))}`;
        apiUrl += `&dir_categorize_table=${encodeURIComponent(currentParams.get('dir_categorize_table') || 'ASC')}`;
    }
    
    fetch(apiUrl)
        .then(response => response.text())
        .then(html => {
            container.innerHTML = html;
            container.style.opacity = '1';
            
            // Réattacher les handlers pour les URLs cliquables (modal)
            if (typeof refreshUrlModalHandlers === 'function') {
                refreshUrlModalHandlers();
            }
            
            // Mettre à jour l'URL sans recharger (pour le bookmark)
            const url = new URL(window.location.href);
            if (filterCat) {
                url.searchParams.set('filter_cat', filterCat);
            } else {
                url.searchParams.delete('filter_cat');
            }
            window.history.replaceState({}, '', url.toString());
        })
        .catch(error => {
            console.error('Erreur AJAX:', error);
            container.style.opacity = '1';
        });
}

// Filtrer par catégorie en cliquant sur une pilule
function filterByChartCategory(category) {
    if (isTestMode) return;
    
    // Convertir "Non catégorisé" en "none" pour l'URL
    const filterValue = category === 'Non catégorisé' ? 'none' : category;
    
    // Toggle: si déjà actif, désactiver
    if (activeFilter === category) {
        activeFilter = null;
        loadCategorizeTable('');
    } else {
        activeFilter = category;
        loadCategorizeTable(filterValue);
    }
    
    // Mettre à jour l'affichage actif des pilules
    document.querySelectorAll('.category-pill').forEach(pill => {
        const name = pill.querySelector('.category-pill-name')?.textContent;
        const fullName = pill.getAttribute('title')?.split(':')[0];
        pill.classList.toggle('active', fullName === activeFilter || name === activeFilter);
    });
    
    // Afficher/masquer le bouton reset
    const resetBtn = document.getElementById('chartResetBtn');
    if (resetBtn) {
        resetBtn.style.display = activeFilter ? 'flex' : 'none';
    }
}

// Reset le filtre
function resetCategoryFilter() {
    activeFilter = null;
    loadCategorizeTable('');
    
    // Mettre à jour l'affichage actif des pilules
    document.querySelectorAll('.category-pill').forEach(pill => {
        pill.classList.remove('active');
    });
    
    // Masquer le bouton reset
    const resetBtn = document.getElementById('chartResetBtn');
    if (resetBtn) {
        resetBtn.style.display = 'none';
    }
}

// Rafraîchir le graphique et le tableau après sauvegarde
function refreshCategorizationView() {
    // Reset le filtre actif
    activeFilter = null;
    
    // Récupérer les nouvelles stats
    fetch(`../api/categorization/stats?project=${encodeURIComponent(categorizeProjectDir)}&crawl_id=${encodeURIComponent(categorizeCrawlId)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Mettre à jour les couleurs globales AVANT le rendu du graphique
                data.stats.forEach(s => {
                    if (s.color && s.category !== 'Non catégorisé') {
                        globalCategoryColors[s.category] = s.color;
                    }
                });
                
                // Mettre à jour le graphique (maintenant les couleurs sont définies)
                const chartData = data.stats.map(s => ({ category: s.category, count: s.count }));
                renderChart(chartData, false);
                
                // Rafraîchir le tableau
                loadCategorizeTable('');
            }
        })
        .catch(error => {
            console.error('Erreur rafraîchissement:', error);
        });
}

// Éditeur CodeMirror
let yamlEditor = null;

// Fonctions pour la modale d'aide
function showHelp() {
    document.getElementById('helpModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function hideHelp() {
    document.getElementById('helpModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Palette de 20 couleurs pastel pour les catégories
const pastelColors = [
    '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7',
    '#DDA0DD', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E9',
    '#F8B500', '#00CED1', '#FF7F50', '#9370DB', '#20B2AA',
    '#FFB6C1', '#87CEEB', '#98FB98', '#DEB887', '#FFA07A'
];

// Générer les couleurs automatiquement pour toutes les catégories
function generateColors() {
    // Synchroniser le code vers rulesData
    syncCodeToVisual();
    
    if (rulesData.length === 0) {
        showGlobalStatus('Aucune catégorie trouvée', 'warning');
        return;
    }
    
    // Assigner une couleur à chaque règle
    rulesData.forEach((rule, index) => {
        rule.color = pastelColors[index % pastelColors.length];
    });
    
    // Regénérer le YAML proprement
    const newYaml = rulesToYaml(rulesData);
    yamlEditor.setValue(newYaml);
    
    // Mettre à jour le mode visuel
    renderRules();
    
    showGlobalStatus('Couleurs générées pour ' + rulesData.length + ' catégories', 'success');
}

// Initialiser le graphique et l'éditeur au chargement
document.addEventListener('DOMContentLoaded', function() {
    const initialData = <?= json_encode($categoryStats) ?>;
    if(initialData && initialData.length > 0) {
        // Pré-initialiser le mapping avec les données initiales
        initialData.forEach(item => {
            Categorize_getCategoryColor(item.category);
        });
        renderChart(initialData);
    }
    
    // Afficher le bouton reset si un filtre est actif
    if (activeFilter) {
        const resetBtn = document.getElementById('chartResetBtn');
        if (resetBtn) resetBtn.style.display = 'flex';
    }
    
    // Initialiser CodeMirror
    const textarea = document.getElementById('yamlEditor');
    yamlEditor = CodeMirror.fromTextArea(textarea, {
        mode: 'yaml',
        theme: 'material-darker',
        lineNumbers: true,
        lineWrapping: true,
        indentUnit: 2,
        tabSize: 2,
        indentWithTabs: false,
        autoCloseBrackets: true,
        matchBrackets: true
    });
    
    // Initialiser le mode visuel par défaut
    syncCodeToVisual();
    
    // Raccourci Ctrl+S pour sauvegarder
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveCategorization();
        }
    });
});


// Tester la catégorisation
function testCategorization() {
    // Synchroniser le YAML vers rulesData pour avoir les couleurs à jour
    syncCodeToVisual();
    
    // Convertir les tabulations en double espaces (YAML n'accepte que les espaces)
    const yamlContent = yamlEditor.getValue().replace(/\t/g, '  ');
    
    showGlobalStatus('Analyse de la catégorisation en cours...', 'warning');
    
    fetch('../api/categorization/test', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            project: '<?= $projectDir ?>',
            yaml: yamlContent
        })
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            showGlobalStatus('✓ Test réussi ! ' + data.categories_count + ' catégories détectées. Sauvegardez pour voir le tableau mis à jour.', 'success');
            
            // Mettre à jour les graphiques en mode test (utilise les couleurs de rulesData)
            renderChart(data.stats, true);
        } else {
            showGlobalStatus('✗ Erreur: ' + data.error, 'error');
        }
    })
    .catch(error => {
        showGlobalStatus('✗ Erreur de communication: ' + error, 'error');
    });
}

// Sauvegarder la catégorisation
async function saveCategorization() {
    const confirmed = await customConfirm(
        'Êtes-vous sûr de vouloir sauvegarder et appliquer cette catégorisation ?',
        'Sauvegarder la catégorisation',
        'Sauvegarder',
        'primary'
    );
    
    if(!confirmed) {
        return;
    }
    
    // Convertir les tabulations en double espaces (YAML n'accepte que les espaces)
    const yamlContent = yamlEditor.getValue().replace(/\t/g, '  ');
    
    showGlobalStatus('Sauvegarde et application en cours...', 'warning');
    
    fetch('../api/categorization/save', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            project: '<?= $projectDir ?>',
            yaml: yamlContent
        })
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            const categorizedCount = data.categorized_count || 0;
            const otherCrawls = data.other_crawls || 0;

            if (otherCrawls > 0) {
                showGlobalStatus(`✓ Catégorisation appliquée (${categorizedCount} URLs). Batch en cours pour ${otherCrawls} autre(s) crawl(s)...`, 'success');
            } else {
                showGlobalStatus(`✓ Catégorisation appliquée avec succès (${categorizedCount} URLs) !`, 'success');
            }

            // Rafraîchir le graphique et le tableau en AJAX
            refreshCategorizationView();

            // Démarrer le polling du job batch si créé
            if (data.batch_job_created && data.job_id) {
                startBatchPolling(data.job_id);
            }
        } else {
            showGlobalStatus('✗ Erreur: ' + data.error, 'error');
        }
    })
    .catch(error => {
        showGlobalStatus('✗ Erreur de communication: ' + error, 'error');
    });
}


// =====================================================
// MODE VISUEL (WYSIWYG)
// =====================================================

let currentEditorMode = 'visual';
let rulesData = [];
let draggedElement = null;

// Switch entre les modes
function switchEditorMode(mode) {
    if (mode === currentEditorMode) return;
    
    // Update buttons
    document.querySelectorAll('.mode-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.mode === mode);
    });
    
    const codeWrapper = document.getElementById('yamlEditorWrapper');
    const visualWrapper = document.getElementById('visualEditorWrapper');
    
    if (mode === 'visual') {
        // Sync code to visual
        syncCodeToVisual();
        codeWrapper.style.display = 'none';
        visualWrapper.style.display = 'flex';
    } else {
        // Sync visual to code
        syncVisualToCode();
        visualWrapper.style.display = 'none';
        codeWrapper.style.display = 'block';
        yamlEditor.refresh();
    }
    
    currentEditorMode = mode;
}

// Parse YAML simple (sans librairie externe)
function parseYamlToRules(yamlContent) {
    const rules = [];
    const lines = yamlContent.split('\n');
    let currentRule = null;
    let currentSection = null;
    
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const trimmed = line.trim();
        
        // Skip empty lines and comments
        if (!trimmed || trimmed.startsWith('#')) continue;
        
        // Count indentation
        const indent = line.search(/\S/);
        
        // Category name (no indentation, ends with :)
        if (indent === 0 && trimmed.endsWith(':') && !trimmed.startsWith('-')) {
            if (currentRule) {
                rules.push(currentRule);
            }
            currentRule = {
                name: trimmed.slice(0, -1),
                dom: '',
                color: '#aaaaaa',
                include: [],
                exclude: []
            };
            currentSection = null;
        }
        // Section (dom, color, include, exclude)
        else if (currentRule && indent > 0) {
            if (trimmed.startsWith('dom:')) {
                currentRule.dom = trimmed.substring(4).trim();
                currentSection = null;
            }
            else if (trimmed.startsWith('color:')) {
                // Enlever les guillemets si présents
                let colorVal = trimmed.substring(6).trim();
                colorVal = colorVal.replace(/^["']|["']$/g, '');
                currentRule.color = colorVal || '#aaaaaa';
                currentSection = null;
            }
            else if (trimmed === 'include:') {
                currentSection = 'include';
            }
            else if (trimmed === 'exclude:') {
                currentSection = 'exclude';
            }
            else if (trimmed.startsWith('- ') && currentSection) {
                currentRule[currentSection].push(trimmed.substring(2));
            }
        }
    }
    
    // Don't forget the last rule
    if (currentRule) {
        rules.push(currentRule);
    }
    
    return rules;
}

// Convert rules to YAML
function rulesToYaml(rules) {
    let yaml = '';
    
    rules.forEach((rule, index) => {
        if (index > 0) yaml += '\n';
        
        yaml += `${rule.name}:\n`;
        yaml += `  color: "${rule.color || '#aaaaaa'}"\n`;
        yaml += `  dom: ${rule.dom}\n`;
        
        if (rule.include.length > 0) {
            yaml += '  include:\n';
            rule.include.forEach(pattern => {
                yaml += `    - ${pattern}\n`;
            });
        }
        
        if (rule.exclude.length > 0) {
            yaml += '  exclude:\n';
            rule.exclude.forEach(pattern => {
                yaml += `    - ${pattern}\n`;
            });
        }
    });
    
    return yaml;
}

// Sync CodeMirror to Visual
function syncCodeToVisual() {
    const yamlContent = yamlEditor.getValue();
    rulesData = parseYamlToRules(yamlContent);
    renderRules();
}

// Sync Visual to CodeMirror
function syncVisualToCode() {
    const yaml = rulesToYaml(rulesData);
    yamlEditor.setValue(yaml);
}

// Render rules in visual mode
function renderRules() {
    const container = document.getElementById('rulesContainer');
    
    if (rulesData.length === 0) {
        container.innerHTML = `
            <div class="rules-empty">
                <span class="material-symbols-outlined">category</span>
                <p>Aucune règle définie</p>
                <p style="font-size: 0.8rem; margin-top: 0.5rem;">Cliquez sur "Ajouter une règle" pour commencer</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = rulesData.map((rule, index) => createRuleCard(rule, index)).join('');
    
    // Setup drag and drop
    setupDragAndDrop();
}

// Create rule card HTML
function createRuleCard(rule, index) {
    const includeCount = rule.include.length;
    const excludeCount = rule.exclude.length;
    const ruleColor = rule.color || '#aaaaaa';
    
    return `
        <div class="rule-card" data-index="${index}" draggable="true">
            <div class="rule-card-header" onclick="toggleRuleCard(${index})">
                <div class="rule-header-top">
                    <div class="rule-drag-handle" onmousedown="event.stopPropagation()">
                        <span class="material-symbols-outlined">drag_indicator</span>
                    </div>
                    <input type="color" class="rule-color-picker" value="${ruleColor}" 
                           onclick="event.stopPropagation()" 
                           onchange="updateRuleColor(${index}, this.value)">
                    <span class="rule-name">${escapeHtml(rule.name)}</span>
                    <span class="material-symbols-outlined rule-expand-icon">expand_more</span>
                </div>
                <div class="rule-header-bottom">
                    <span class="rule-meta-badge rule-meta-badge-domain" title="${escapeHtml(rule.dom) || 'Non défini'}">
                        <span class="material-symbols-outlined">language</span>
                        ${escapeHtml(rule.dom) || '—'}
                    </span>
                    <div class="rule-meta">
                        <span class="rule-meta-badge" title="Patterns include" style="color: var(--success);">
                            <span class="material-symbols-outlined">add_circle</span>
                            ${includeCount}
                        </span>
                        ${excludeCount > 0 ? `
                            <span class="rule-meta-badge" title="Patterns exclude" style="color: var(--danger);">
                                <span class="material-symbols-outlined">remove_circle</span>
                                ${excludeCount}
                            </span>
                        ` : ''}
                        <button class="rule-delete-btn" onclick="deleteRule(${index}); event.stopPropagation();" title="Supprimer">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="rule-card-body">
                <div class="rule-field">
                    <label class="rule-field-label">
                        <span class="material-symbols-outlined">badge</span>
                        Nom de la catégorie
                    </label>
                    <input type="text" class="rule-domain-input" value="${escapeHtml(rule.name)}" 
                           onchange="updateRuleName(${index}, this.value)" placeholder="Nom de la catégorie">
                </div>
                <div class="rule-field">
                    <label class="rule-field-label">
                        <span class="material-symbols-outlined">language</span>
                        Domaine
                    </label>
                    <input type="text" class="rule-domain-input" value="${escapeHtml(rule.dom)}" 
                           onchange="updateRuleDom(${index}, this.value)" placeholder="example.com">
                </div>
                <div class="rule-field include">
                    <label class="rule-field-label">
                        <span class="material-symbols-outlined">check_circle</span>
                        Include (patterns à inclure)
                    </label>
                    <div class="pattern-list">
                        ${rule.include.map((pattern, pIndex) => `
                            <div class="pattern-item">
                                <input type="text" value="${escapeHtml(pattern)}" 
                                       onchange="updatePattern(${index}, 'include', ${pIndex}, this.value)"
                                       placeholder="^/chemin/">
                                <button class="pattern-remove-btn" onclick="removePattern(${index}, 'include', ${pIndex})" title="Supprimer">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>
                        `).join('')}
                        <button class="pattern-add-btn" onclick="addPattern(${index}, 'include')">
                            <span class="material-symbols-outlined">add</span>
                            Ajouter un pattern
                        </button>
                    </div>
                </div>
                <div class="rule-field exclude">
                    <label class="rule-field-label">
                        <span class="material-symbols-outlined">block</span>
                        Exclude (patterns à exclure)
                    </label>
                    <div class="pattern-list">
                        ${rule.exclude.map((pattern, pIndex) => `
                            <div class="pattern-item">
                                <input type="text" value="${escapeHtml(pattern)}" 
                                       onchange="updatePattern(${index}, 'exclude', ${pIndex}, this.value)"
                                       placeholder="/archive/">
                                <button class="pattern-remove-btn" onclick="removePattern(${index}, 'exclude', ${pIndex})" title="Supprimer">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>
                        `).join('')}
                        <button class="pattern-add-btn" onclick="addPattern(${index}, 'exclude')">
                            <span class="material-symbols-outlined">add</span>
                            Ajouter un pattern
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Toggle rule card expanded state
function toggleRuleCard(index) {
    const card = document.querySelector(`.rule-card[data-index="${index}"]`);
    if (card) {
        card.classList.toggle('expanded');
    }
}

// Add new rule
function addNewRule() {
    // Domaine crawlé pré-rempli
    const crawledDomain = '<?= $crawledDomain ?>';
    
    rulesData.push({
        name: 'nouvelle_categorie',
        dom: crawledDomain,
        include: ['^/product/\\d+\\.html$'],
        exclude: []
    });
    renderRules();
    syncVisualToCode();
    
    // Expand the new rule
    setTimeout(() => {
        const newCard = document.querySelector(`.rule-card[data-index="${rulesData.length - 1}"]`);
        if (newCard) {
            newCard.classList.add('expanded');
            newCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, 50);
}

// Delete rule
async function deleteRule(index) {
    const ruleName = rulesData[index]?.name || 'cette règle';
    const confirmed = await customConfirm(
        `Êtes-vous sûr de vouloir supprimer la règle "${ruleName}" ?`,
        'Supprimer la règle',
        'Supprimer',
        'danger'
    );
    
    if (confirmed) {
        rulesData.splice(index, 1);
        renderRules();
        syncVisualToCode();
    }
}

// Update rule name
function updateRuleName(index, value) {
    rulesData[index].name = value.trim().replace(/\s+/g, '_') || 'sans_nom';
    renderRules();
    syncVisualToCode();
}

// Update rule domain
function updateRuleDom(index, value) {
    rulesData[index].dom = value.trim();
    syncVisualToCode();
    // Update the meta badge
    const card = document.querySelector(`.rule-card[data-index="${index}"]`);
    if (card) {
        const domBadge = card.querySelector('.rule-meta-badge');
        if (domBadge) {
            domBadge.innerHTML = `<span class="material-symbols-outlined">language</span> ${escapeHtml(value) || '—'}`;
        }
    }
}

// Update rule color
function updateRuleColor(index, value) {
    rulesData[index].color = value;
    syncVisualToCode();
}

// Update pattern
function updatePattern(ruleIndex, section, patternIndex, value) {
    rulesData[ruleIndex][section][patternIndex] = value;
    syncVisualToCode();
}

// Add pattern
function addPattern(ruleIndex, section) {
    rulesData[ruleIndex][section].push('');
    renderRules();
    syncVisualToCode();
    
    // Keep the card expanded and focus the new input
    setTimeout(() => {
        const card = document.querySelector(`.rule-card[data-index="${ruleIndex}"]`);
        if (card) {
            card.classList.add('expanded');
            const inputs = card.querySelectorAll(`.rule-field.${section} .pattern-item input`);
            if (inputs.length > 0) {
                inputs[inputs.length - 1].focus();
            }
        }
    }, 50);
}

// Remove pattern
function removePattern(ruleIndex, section, patternIndex) {
    rulesData[ruleIndex][section].splice(patternIndex, 1);
    renderRules();
    syncVisualToCode();
    
    // Keep the card expanded
    setTimeout(() => {
        const card = document.querySelector(`.rule-card[data-index="${ruleIndex}"]`);
        if (card) {
            card.classList.add('expanded');
        }
    }, 50);
}

// Setup drag and drop avec indicateur visuel
let dropIndicator = null;
let dropTargetIndex = -1;

function setupDragAndDrop() {
    const container = document.getElementById('rulesContainer');
    const cards = document.querySelectorAll('.rule-card');
    
    // Créer l'indicateur de drop (ligne horizontale)
    if (!dropIndicator) {
        dropIndicator = document.createElement('div');
        dropIndicator.className = 'drop-indicator';
        dropIndicator.innerHTML = '<div class="drop-indicator-line"></div>';
    }
    
    cards.forEach(card => {
        card.addEventListener('dragstart', handleDragStart);
        card.addEventListener('dragend', handleDragEnd);
    });
    
    // Écouter sur le container pour une meilleure détection
    container.addEventListener('dragover', handleContainerDragOver);
    container.addEventListener('dragleave', handleContainerDragLeave);
    container.addEventListener('drop', handleContainerDrop);
}

function handleDragStart(e) {
    draggedElement = this;
    this.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', this.dataset.index);
    
    // Délai pour que le style s'applique après le drag ghost
    setTimeout(() => {
        this.style.opacity = '0.4';
    }, 0);
}

function handleDragEnd(e) {
    this.classList.remove('dragging');
    this.style.opacity = '';
    
    // Retirer l'indicateur
    if (dropIndicator && dropIndicator.parentNode) {
        dropIndicator.parentNode.removeChild(dropIndicator);
    }
    dropTargetIndex = -1;
    draggedElement = null;
}

function handleContainerDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    
    if (!draggedElement) return;
    
    const container = document.getElementById('rulesContainer');
    const cards = Array.from(container.querySelectorAll('.rule-card:not(.dragging)'));
    
    // Trouver la carte la plus proche
    let closestCard = null;
    let closestOffset = Number.NEGATIVE_INFINITY;
    let insertAfter = false;
    
    cards.forEach(card => {
        const rect = card.getBoundingClientRect();
        const cardMiddle = rect.top + rect.height / 2;
        const offset = e.clientY - cardMiddle;
        
        if (offset < 0 && offset > closestOffset) {
            closestOffset = offset;
            closestCard = card;
            insertAfter = false;
        } else if (offset >= 0) {
            // On est sous le milieu de cette carte
            if (!closestCard || offset < Math.abs(closestOffset)) {
                closestCard = card;
                insertAfter = true;
            }
        }
    });
    
    // Positionner l'indicateur
    if (closestCard) {
        const newIndex = parseInt(closestCard.dataset.index) + (insertAfter ? 1 : 0);
        
        if (newIndex !== dropTargetIndex) {
            dropTargetIndex = newIndex;
            
            if (insertAfter) {
                closestCard.parentNode.insertBefore(dropIndicator, closestCard.nextSibling);
            } else {
                closestCard.parentNode.insertBefore(dropIndicator, closestCard);
            }
        }
    } else if (cards.length === 0) {
        // Container vide
        container.appendChild(dropIndicator);
        dropTargetIndex = 0;
    }
}

function handleContainerDragLeave(e) {
    // Vérifier si on quitte vraiment le container
    const container = document.getElementById('rulesContainer');
    const rect = container.getBoundingClientRect();
    
    if (e.clientX < rect.left || e.clientX > rect.right || 
        e.clientY < rect.top || e.clientY > rect.bottom) {
        if (dropIndicator && dropIndicator.parentNode) {
            dropIndicator.parentNode.removeChild(dropIndicator);
        }
        dropTargetIndex = -1;
    }
}

function handleContainerDrop(e) {
    e.preventDefault();
    
    if (draggedElement && dropTargetIndex >= 0) {
        const fromIndex = parseInt(draggedElement.dataset.index);
        let toIndex = dropTargetIndex;
        
        // Ajuster l'index si on déplace vers le bas
        if (fromIndex < toIndex) {
            toIndex--;
        }
        
        if (fromIndex !== toIndex) {
            // Reorder the rules array
            const [movedRule] = rulesData.splice(fromIndex, 1);
            rulesData.splice(toIndex, 0, movedRule);
            
            renderRules();
            syncVisualToCode();
        }
    }
    
    // Nettoyer
    if (dropIndicator && dropIndicator.parentNode) {
        dropIndicator.parentNode.removeChild(dropIndicator);
    }
    dropTargetIndex = -1;
}

</script>
