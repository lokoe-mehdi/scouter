<?php
/**
 * SQL Explorer (PostgreSQL)
 * L'utilisateur voit les tables "pages" et "links" virtuelles
 * Les requêtes sont automatiquement transformées pour utiliser les partitions
 */

use App\Database\PostgresDatabase;

// Structure des tables virtuelles (sans crawl_id qui est masqué)
$tables = [
    'pages' => [
        ['name' => 'id', 'type' => 'CHAR(8)'],
        ['name' => 'url', 'type' => 'TEXT'],
        ['name' => 'domain', 'type' => 'VARCHAR(255)'],
        ['name' => 'code', 'type' => 'INTEGER'],
        ['name' => 'depth', 'type' => 'INTEGER'],
        ['name' => 'crawled', 'type' => 'BOOLEAN'],
        ['name' => 'compliant', 'type' => 'BOOLEAN'],
        ['name' => 'external', 'type' => 'BOOLEAN'],
        ['name' => 'blocked', 'type' => 'BOOLEAN'],
        ['name' => 'noindex', 'type' => 'BOOLEAN'],
        ['name' => 'nofollow', 'type' => 'BOOLEAN'],
        ['name' => 'canonical', 'type' => 'BOOLEAN'],
        ['name' => 'canonical_value', 'type' => 'TEXT'],
        ['name' => 'redirect_to', 'type' => 'TEXT'],
        ['name' => 'content_type', 'type' => 'VARCHAR(100)'],
        ['name' => 'response_time', 'type' => 'FLOAT'],
        ['name' => 'inlinks', 'type' => 'INTEGER'],
        ['name' => 'outlinks', 'type' => 'INTEGER'],
        ['name' => 'pri', 'type' => 'FLOAT'],
        ['name' => 'title', 'type' => 'TEXT'],
        ['name' => 'title_status', 'type' => 'VARCHAR(50)'],
        ['name' => 'h1', 'type' => 'TEXT'],
        ['name' => 'h1_status', 'type' => 'VARCHAR(50)'],
        ['name' => 'metadesc', 'type' => 'TEXT'],
        ['name' => 'metadesc_status', 'type' => 'VARCHAR(50)'],
        ['name' => 'h1_multiple', 'type' => 'BOOLEAN'],
        ['name' => 'headings_missing', 'type' => 'BOOLEAN'],
        ['name' => 'simhash', 'type' => 'BIGINT'],
        ['name' => 'is_html', 'type' => 'BOOLEAN'],
        ['name' => 'cat_id', 'type' => 'INTEGER'],
        ['name' => 'extracts', 'type' => 'JSONB'],
        ['name' => 'schemas', 'type' => 'TEXT[]'],
        ['name' => 'date', 'type' => 'TIMESTAMP'],
    ],
    'links' => [
        ['name' => 'src', 'type' => 'CHAR(8)'],
        ['name' => 'target', 'type' => 'CHAR(8)'],
        ['name' => 'anchor', 'type' => 'TEXT'],
        ['name' => 'type', 'type' => 'VARCHAR(50)'],
        ['name' => 'external', 'type' => 'BOOLEAN'],
        ['name' => 'nofollow', 'type' => 'BOOLEAN'],
    ],
    'categories' => [
        ['name' => 'id', 'type' => 'SERIAL'],
        ['name' => 'cat', 'type' => 'VARCHAR(255)'],
        ['name' => 'color', 'type' => 'VARCHAR(7)'],
    ],
    'duplicate_clusters' => [
        ['name' => 'id', 'type' => 'SERIAL'],
        ['name' => 'similarity', 'type' => 'INTEGER'],
        ['name' => 'page_count', 'type' => 'INTEGER'],
        ['name' => 'page_ids', 'type' => 'TEXT[]'],
    ],
    'page_schemas' => [
        ['name' => 'page_id', 'type' => 'CHAR(8)'],
        ['name' => 'schema_type', 'type' => 'VARCHAR(100)'],
    ]
];

// Requête passée en paramètre GET (depuis la modale scope)
$initialQuery = isset($_GET['query']) ? urldecode($_GET['query']) : 'SELECT * FROM pages LIMIT 100';

// Requêtes pré-enregistrées (adaptées pour PostgreSQL)
$savedQueries = [
    [
        'name' => 'Répartition par code de réponse',
        'description' => 'Nombre d\'URLs par code de statut HTTP',
        'category' => 'Analyse',
        'query' => "SELECT\n\tcode,\n\tCOUNT(url) AS urls\nFROM pages\nWHERE crawled = true\nGROUP BY code\nORDER BY urls DESC"
    ],
    [
        'name' => 'Distribution par niveau de profondeur',
        'description' => 'Répartition des URLs compliant par profondeur',
        'category' => 'Analyse',
        'query' => "SELECT\n\tdepth,\n\tCOUNT(url) AS urls\nFROM pages\nWHERE compliant = true\nGROUP BY depth\nORDER BY depth ASC"
    ],
    [
        'name' => 'Top 20 URLs Pagerank',
        'description' => 'Pages les plus populaires du site',
        'category' => 'SEO',
        'query' => "SELECT\n\turl,\n\tpri AS pagerank,\n\tinlinks,\n\tcode\nFROM pages\nWHERE crawled = true AND compliant = true\nORDER BY pagerank DESC\nLIMIT 20"
    ],
    [
        'name' => 'Tous les liens',
        'description' => 'Liste complète des liens avec source et cible',
        'category' => 'Liens',
        'query' => "SELECT\n\ts.url AS source_url,\n\tcs.cat AS source_cat,\n\tt.url AS target_url,\n\tct.cat AS target_cat,\n\tl.anchor,\n\tl.type,\n\tl.external,\n\tl.nofollow\nFROM links l\nLEFT JOIN pages s ON l.src = s.id\nLEFT JOIN pages t ON l.target = t.id\nLEFT JOIN categories cs ON cs.id = s.cat_id\nLEFT JOIN categories ct ON ct.id = t.cat_id\nLIMIT 100"
    ],
    [
        'name' => 'URLs non indexables',
        'description' => 'Pages bloquées ou avec noindex',
        'category' => 'SEO',
        'query' => "SELECT\n\turl,\n\tcode,\n\tblocked,\n\tnoindex,\n\tcanonical\nFROM pages\nWHERE crawled = true AND compliant = false"
    ],
    [
        'name' => 'Répartition par catégorie',
        'description' => 'Nombre d\'URLs par catégorie',
        'category' => 'Analyse',
        'query' => "SELECT\n\tc.cat AS category,\n\tCOUNT(*) AS urls\nFROM pages p\nLEFT JOIN categories c ON c.id = p.cat_id\nWHERE p.crawled = true\nGROUP BY category\nORDER BY urls DESC"
    ]
];
?>

<style>
/* === SQL WORKSPACE - Interface IDE Plein Écran === */

/* Override main-content pour pleine largeur */
.main-content:has(.sql-workspace-container) {
    max-width: 100%;
    padding: 0;
}

/* Container qui override le padding du main-content */
.sql-workspace-container {
    height: calc(100vh - 72px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.sql-explorer-layout {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 0;
    flex: 1;
    overflow: hidden;
    background: var(--background);
    transition: grid-template-columns 0.3s ease;
}

.sql-explorer-layout.sidebar-collapsed {
    grid-template-columns: 0 1fr;
}

.sql-sidebar {
    background: #f8f9fb;
    border-right: 1px solid var(--border-color);
    overflow-y: auto;
    overflow-x: hidden;
    font-size: 0.8rem;
    display: flex;
    flex-direction: column;
    transition: transform 0.3s ease, opacity 0.3s ease;
}

.sql-explorer-layout.sidebar-collapsed .sql-sidebar {
    transform: translateX(-100%);
    opacity: 0;
    pointer-events: none;
}

.sql-sidebar h3 {
    margin: 0;
    padding: 0.6rem 0.75rem;
    color: #374151;
    font-size: 0.7rem;
    font-weight: 700;
    background: #e8ecf1;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 0.4rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.sql-sidebar h3 .material-symbols-outlined {
    font-size: 16px;
    color: var(--primary-color);
}

/* Bouton toggle sidebar - positionné dans sql-main */
.sidebar-toggle {
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    z-index: 10;
    width: 16px;
    height: 50px;
    background: #eef1f5;
    border: 1px solid var(--border-color);
    border-left: none;
    border-radius: 0 4px 4px 0;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    color: var(--text-secondary);
    padding: 0;
}

.sidebar-toggle:hover {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}

.sidebar-toggle .material-symbols-outlined {
    font-size: 14px;
    transition: transform 0.3s ease;
}

.sql-explorer-layout.sidebar-collapsed .sidebar-toggle .material-symbols-outlined {
    transform: rotate(180deg);
}

.table-item {
    border-bottom: 1px solid rgba(0,0,0,0.06);
}

.table-item:last-child {
    border-bottom: none;
}

.table-header {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.75rem;
    cursor: pointer;
    transition: background-color 0.15s;
    user-select: none;
    margin-bottom: 0;
}

.table-header:hover {
    background: rgba(0,0,0,0.04);
}

.table-header.active {
    background: rgba(0,0,0,0.04);
}

.table-name {
    flex: 1;
    font-weight: 500;
    font-size: 0.8rem;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.table-name .material-symbols-outlined {
    font-size: 14px;
    color: #9CA3AF;
}

.table-header:hover .table-name .material-symbols-outlined {
    color: var(--primary-color);
}

.table-icon {
    font-size: 14px;
    color: var(--text-secondary);
    transition: transform 0.15s;
}

.table-header.active .table-icon {
    transform: rotate(90deg);
}

.table-columns {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.2s ease;
    background: rgba(0,0,0,0.02);
    margin-top: 0;
}

.table-columns.expanded {
    max-height: 300px;
    overflow-y: auto;
}

.column-item {
    padding: 0.25rem 0.75rem 0.25rem 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.75rem;
    border-bottom: 1px solid rgba(0,0,0,0.03);
}

.column-item:last-child {
    border-bottom: none;
}

.column-item:hover {
    background: rgba(0,0,0,0.02);
}

.column-name {
    color: var(--text-primary);
    font-family: 'Fira Code', 'Consolas', 'Courier New', monospace;
    font-size: 0.72rem;
}

.column-type {
    color: var(--text-secondary);
    font-size: 0.65rem;
    text-transform: uppercase;
    font-weight: 500;
    opacity: 0.7;
}

.sql-main {
    display: flex;
    flex-direction: column;
    gap: 0;
    overflow: hidden;
    min-height: 0;
    height: 100%;
    background: var(--card-bg);
    position: relative;
}

.sql-editor-container {
    background: var(--card-bg);
    display: flex;
    flex-direction: column;
    border-bottom: 1px solid var(--border-color);
    flex-shrink: 0;
}

/* Toolbar unifié pour l'éditeur */
.sql-editor-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.4rem 0.75rem;
    background: #f8f9fb;
    border-bottom: 1px solid var(--border-color);
    gap: 0.5rem;
}

.toolbar-left {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    min-width: 0;
}

.toolbar-right {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

/* Bouton Exécuter compact style Play */
.execute-btn {
    background: var(--primary-color);
    color: white;
    border: none;
    padding: 0.35rem 0.75rem;
    border-radius: 4px;
    font-weight: 500;
    font-size: 0.8rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    transition: all 0.15s;
}

.execute-btn:hover {
    background: var(--primary-dark);
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

.execute-btn:active {
    transform: scale(0.98);
}

.execute-btn .material-symbols-outlined {
    font-size: 18px;
}

.execute-btn .shortcut {
    font-size: 0.65rem;
    opacity: 0.85;
    background: rgba(255,255,255,0.2);
    padding: 0.1rem 0.35rem;
    border-radius: 2px;
    margin-left: 0.25rem;
}

/* Bouton aide compact */
.help-btn {
    background: transparent;
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    padding: 0.35rem;
    border-radius: 4px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
}

.help-btn:hover {
    background: var(--background);
    color: var(--text-primary);
    border-color: var(--text-secondary);
}

.help-btn .material-symbols-outlined {
    font-size: 18px;
}

#sqlEditor {
    border: none;
    font-family: 'Fira Code', 'Consolas', 'Courier New', monospace;
    font-size: 13px;
    min-height: 120px;
}

.CodeMirror {
    height: 180px;
    font-family: 'Fira Code', 'Consolas', 'Courier New', monospace;
    font-size: 13px;
    border: none;
}

.CodeMirror-gutters {
    background: #f8f9fb;
    border-right: 1px solid var(--border-color);
}

/* Styles pour les onglets - plus compacts */
.tabs-container {
    display: flex;
    background: #eef1f5;
    overflow-x: auto;
    overflow-y: hidden;
    flex-shrink: 0;
    border-bottom: 1px solid var(--border-color);
    scrollbar-width: thin;
}

.tabs-container::-webkit-scrollbar {
    height: 3px;
}

.tabs-container::-webkit-scrollbar-thumb {
    background: rgba(0,0,0,0.15);
    border-radius: 3px;
}

.tabs-container::-webkit-scrollbar-track {
    background: transparent;
}

.tab {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.75rem;
    background: transparent;
    border-right: 1px solid rgba(0,0,0,0.08);
    cursor: pointer;
    font-size: 0.78rem;
    white-space: nowrap;
    transition: all 0.15s;
    min-width: 90px;
    max-width: 150px;
    user-select: none;
    color: var(--text-secondary);
}

.tab:hover {
    background: rgba(255,255,255,0.5);
    color: var(--text-primary);
}

.tab.active {
    background: var(--card-bg);
    color: var(--text-primary);
    border-bottom: 2px solid var(--primary-color);
    margin-bottom: -1px;
}

.tab-title {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
}

.tab-title-input {
    flex: 1;
    background: transparent;
    border: 1px solid var(--primary-color);
    border-radius: 2px;
    padding: 0.15rem 0.25rem;
    font-size: 0.78rem;
    color: var(--text-primary);
    outline: none;
}

.tab-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    font-size: 11px;
    opacity: 0;
    transition: all 0.15s;
}

.tab:hover .tab-close {
    opacity: 0.6;
}

.tab-close:hover {
    opacity: 1 !important;
    background: rgba(255, 0, 0, 0.1);
    color: #ff4444;
}

.tab-add {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.45rem 0.5rem;
    background: transparent;
    cursor: pointer;
    font-size: 14px;
    color: var(--text-secondary);
    transition: all 0.15s;
}

.tab-add:hover {
    background: rgba(255,255,255,0.5);
    color: var(--primary-color);
}

.tab-add .material-symbols-outlined {
    font-size: 16px;
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
    max-width: 1000px;
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

.help-modal-body::-webkit-scrollbar {
    width: 12px;
}

.help-modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.help-modal-body::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 6px;
}

.help-modal-body::-webkit-scrollbar-thumb:hover {
    background: #555;
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
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    color: #e74c3c;
}

.help-modal-body pre {
    background: #282c34;
    color: #abb2bf;
    padding: 1rem;
    border-radius: 6px;
    overflow-x: auto;
    margin: 1rem 0;
    font-family: 'Courier New', monospace;
    line-height: 1.5;
}

.help-modal-body pre code {
    background: transparent;
    padding: 0;
    color: inherit;
}

.schema-table {
    width: 100%;
    border-collapse: collapse;
    margin: 1rem 0;
    font-size: 0.9rem;
}

.schema-table th,
.schema-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.schema-table th {
    background: var(--background);
    font-weight: 600;
    color: var(--text-primary);
}

.schema-table td {
    color: var(--text-secondary);
}

.schema-table code {
    background: var(--background);
    color: var(--primary-color);
    font-weight: 500;
}

/* Styles pour le layout avec graphique intégré */
.results-with-chart {
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.results-chart-split {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    flex: 1;
    overflow: hidden;
    background: #F9FAFB;
    padding: 0.5rem;
}

.results-chart-split .results-table-wrapper {
    margin: 0;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

/* Panneau graphique - Card avec bordure */
.chart-panel {
    background: white;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.chart-panel-header {
    padding: 0.6rem 1rem;
    border-bottom: 1px solid #E5E7EB;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: white;
    flex-shrink: 0;
}

.chart-panel-header h4 {
    margin: 0;
    font-size: 0.95rem;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 700;
}

.chart-panel-header h4 .material-symbols-outlined {
    font-size: 20px;
    color: var(--primary-color);
}

.chart-type-switch {
    display: flex;
    gap: 2px;
    background: transparent;
}

.chart-type-btn {
    padding: 0.3rem;
    border: 1px solid var(--border-color);
    background: white;
    color: var(--text-secondary);
    cursor: pointer;
    border-radius: 4px;
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
}

.chart-type-btn .material-symbols-outlined {
    font-size: 16px;
}

.chart-type-btn:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
}

.chart-type-btn.active {
    background: var(--primary-color);
    border-color: var(--primary-color);
    color: white;
}

.chart-container-inline {
    flex: 1;
    padding: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: white;
}

.chart-container-inline canvas {
    max-width: 100%;
    max-height: 100%;
}

/* Styles pour l'autocomplétion */
.CodeMirror-hints {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    max-height: 200px;
    overflow-y: auto;
}

.CodeMirror-hint {
    padding: 0.5rem 0.75rem;
    color: var(--text-primary);
    cursor: pointer;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}

.CodeMirror-hint:last-child {
    border-bottom: none;
}

.CodeMirror-hint-active {
    background: var(--primary-color);
    color: white;
}

.CodeMirror-hint:hover {
    background: var(--background);
}

.CodeMirror-hint-active:hover {
    background: var(--primary-color);
}

/* Styles spécifiques par type de suggestion */
.hint-table {
    color: #2196F3;
    font-weight: 600;
}

.hint-column {
    color: #4CAF50;
}

.hint-column-alias {
    color: #FF9800;
    font-style: italic;
}

.hint-column-full {
    color: #9C27B0;
    font-family: 'Courier New', monospace;
}

/* Styles pour les requêtes sauvegardées - compactes */
.saved-queries-section {
    border-top: 1px solid var(--border-color);
    flex: 1;
    overflow-y: auto;
}

.query-item {
    padding: 0.5rem 0.75rem;
    padding-left: 0.6rem;
    cursor: pointer;
    transition: background-color 0.15s;
    border-bottom: 1px solid rgba(0,0,0,0.04);
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.query-item .query-icon {
    font-size: 14px;
    color: #9CA3AF;
    margin-top: 2px;
    flex-shrink: 0;
}

.query-item:hover .query-icon {
    color: var(--primary-color);
}

.query-item-content {
    flex: 1;
    min-width: 0;
}

.query-item:hover {
    background: rgba(0,0,0,0.03);
}

.query-item:active {
    background: var(--primary-color);
    color: white;
}

.query-name {
    font-size: 0.78rem;
    font-weight: 500;
    color: var(--text-primary);
    margin-bottom: 0.15rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.query-description {
    font-size: 0.68rem;
    color: var(--text-secondary);
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.query-item:hover .query-description {
    color: var(--text-primary);
}

.query-item:active .query-name,
.query-item:active .query-description,
.query-item:active .query-icon {
    color: white;
}

.sql-results-container {
    background: #F9FAFB;
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 0;
    border-top: 1px solid var(--border-color);
}

/* Toolbar de résultats fine */
.sql-results-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.35rem 0.75rem;
    background: #f8f9fb;
    border-bottom: 1px solid var(--border-color);
    flex-shrink: 0;
}

.result-info {
    color: var(--text-secondary);
    font-size: 0.78rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.result-info .material-symbols-outlined {
    font-size: 16px;
}

/* Bouton secondaire (Copier) */
.btn-secondary-action {
    background: transparent;
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    padding: 0.3rem 0.6rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.3rem;
    transition: all 0.15s;
}

.btn-secondary-action:hover:not(:disabled) {
    background: var(--background);
    color: var(--text-primary);
    border-color: var(--text-secondary);
}

.btn-secondary-action:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.btn-secondary-action .material-symbols-outlined {
    font-size: 16px;
}

/* Bouton principal (Export CSV) - Style Outline Primary */
.btn-primary-action {
    background: rgba(78, 205, 196, 0.08);
    color: var(--primary-color);
    border: 1.5px solid var(--primary-color);
    padding: 0.35rem 0.75rem;
    border-radius: 5px;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    transition: all 0.15s;
}

.btn-primary-action:hover:not(:disabled) {
    background: var(--primary-color);
    color: white;
    box-shadow: 0 2px 8px rgba(78, 205, 196, 0.35);
}

.btn-primary-action:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    background: transparent;
}

.btn-primary-action .material-symbols-outlined {
    font-size: 16px;
}

.toolbar-actions {
    display: flex;
    gap: 0.4rem;
    align-items: center;
}

/* Alerte de troncature fixe */
.truncation-alert {
    padding: 0.5rem 1rem;
    background: #fff3cd;
    border-top: 2px solid #ffc107;
    color: #856404;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-shrink: 0;
}

.truncation-alert .material-symbols-outlined {
    font-size: 18px;
    color: #d39e00;
}

.results-table-wrapper {
    flex: 1;
    overflow: auto;
    background: white;
}

.results-table-wrapper::-webkit-scrollbar {
    width: 12px;
    height: 12px;
}

.results-table-wrapper::-webkit-scrollbar-track {
    background: var(--background);
}

.results-table-wrapper::-webkit-scrollbar-thumb {
    background: var(--border-color);
    border-radius: 6px;
}

.results-table-wrapper::-webkit-scrollbar-thumb:hover {
    background: var(--text-secondary);
}

.results-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}

.results-table thead {
    background: #f8f9fb;
}

.results-table th {
    padding: 0.5rem 0.75rem;
    text-align: left;
    font-weight: 600;
    font-size: 0.75rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
    background: #f8f9fb;
    position: sticky;
    top: 0;
    z-index: 10;
}

.results-table td {
    padding: 0.45rem 0.75rem;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    color: var(--text-primary);
    max-width: 400px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.results-table tbody tr:hover {
    background: rgba(78, 205, 196, 0.05);
}

.results-table tbody tr:nth-child(even) {
    background: rgba(0,0,0,0.015);
}

.results-table tbody tr:nth-child(even):hover {
    background: rgba(78, 205, 196, 0.05);
}

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    min-height: 150px;
    color: var(--text-secondary);
    gap: 0.5rem;
    background: #fafbfc;
}

.empty-state .material-symbols-outlined {
    font-size: 40px;
    opacity: 0.25;
}

.empty-state p {
    font-size: 0.85rem;
    margin: 0;
}

.error-message {
    background: #FEE;
    color: #C33;
    padding: 1rem;
    border-radius: 6px;
    border-left: 4px solid #C33;
}

.success-message {
    background: #EFE;
    color: #3C3;
    padding: 1rem;
    border-radius: 6px;
    border-left: 4px solid #3C3;
}

.loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 2rem;
    color: var(--text-secondary);
    height: 100%;
    background: #fafbfc;
}

.spinner {
    border: 2px solid var(--border-color);
    border-top-color: var(--primary-color);
    border-radius: 50%;
    width: 20px;
    height: 20px;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.spinning {
    animation: spin 1s linear infinite;
}

#resultsContent {
    min-width: 100%;
}
</style>

<div class="sql-workspace-container">
<div class="sql-explorer-layout" id="sqlLayout">
    <!-- Sidebar with tables -->
    <div class="sql-sidebar">
        <h3>
            <span class="material-symbols-outlined">table_view</span>
            Tables
        </h3>
        
        <?php if (!empty($tables)): ?>
            <?php 
            $tableIcons = ['pages' => 'description', 'links' => 'link', 'categories' => 'folder', 'duplicate_clusters' => 'content_copy', 'page_schemas' => 'data_object'];
            foreach ($tables as $tableName => $columns): 
                $icon = $tableIcons[$tableName] ?? 'table_chart';
            ?>
            <div class="table-item">
                <div class="table-header" onclick="toggleTable('<?= $tableName ?>')">
                    <span class="table-name">
                        <span class="material-symbols-outlined"><?= $icon ?></span>
                        <?= htmlspecialchars($tableName) ?>
                    </span>
                    <span class="material-symbols-outlined table-icon">chevron_right</span>
                </div>
                <div class="table-columns" id="columns-<?= htmlspecialchars($tableName) ?>">
                    <?php foreach ($columns as $column): ?>
                    <div class="column-item">
                        <span class="column-name"><?= htmlspecialchars($column['name']) ?></span>
                        <span class="column-type"><?= htmlspecialchars($column['type']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-outlined">database</span>
                <p>Aucune table trouvée</p>
            </div>
        <?php endif; ?>
        
        <!-- Requêtes sauvegardées -->
        <div class="saved-queries-section">
            <h3>
                <span class="material-symbols-outlined">bookmark</span>
                Requêtes sauvegardées
            </h3>
            
            <?php foreach ($savedQueries as $index => $query): ?>
            <div class="query-item" onclick="loadSavedQuery(<?= $index ?>)">
                <span class="material-symbols-outlined query-icon">code</span>
                <div class="query-item-content">
                    <div class="query-name"><?= htmlspecialchars($query['name']) ?></div>
                    <div class="query-description"><?= htmlspecialchars($query['description']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Main content -->
    <div class="sql-main">
        <!-- Bouton toggle sidebar -->
        <button class="sidebar-toggle" onclick="toggleSidebar()" title="Masquer/Afficher la sidebar">
            <span class="material-symbols-outlined">chevron_left</span>
        </button>
        <!-- SQL Editor -->
        <div class="sql-editor-container">
            <!-- Onglets en haut -->
            <div class="tabs-container" id="tabsContainer">
                <div class="tab active" data-tab-id="0" onmousedown="handleTabMouseDown(event)" onauxclick="handleTabMiddleClick(0, event)">
                    <span class="tab-title">Requête 1</span>
                    <span class="tab-close" onclick="closeTab(0, event)">×</span>
                </div>
                <div class="tab-add" onclick="addNewTab()">
                    <span class="material-symbols-outlined">add</span>
                </div>
            </div>
            
            <textarea id="sqlEditor"><?= htmlspecialchars($initialQuery) ?></textarea>
            
            <!-- Toolbar unifié sous l'éditeur -->
            <div class="sql-editor-toolbar">
                <div class="toolbar-left">
                    <button class="execute-btn" onclick="executeQuery()">
                        <span class="material-symbols-outlined">play_arrow</span>
                        Exécuter
                        <span class="shortcut">Ctrl+Enter</span>
                    </button>
                </div>
                <div class="toolbar-right">
                    <button class="help-btn" onclick="showSQLHelp()" title="Aide SQL">
                        <span class="material-symbols-outlined">help</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Results -->
        <div class="sql-results-container">
            <!-- Toolbar de résultats -->
            <div class="sql-results-toolbar">
                <div class="result-info" id="resultInfo">
                    <span class="material-symbols-outlined">table_chart</span>
                    <span>Résultats</span>
                </div>
                <div class="toolbar-actions">
                    <button id="copyBtn" class="btn-secondary-action" onclick="copyTableToClipboard()" disabled title="Copier le tableau">
                        <span class="material-symbols-outlined">content_copy</span>
                        Copier
                    </button>
                    <button id="exportBtn" class="btn-primary-action" onclick="exportToCSV()" disabled>
                        <span class="material-symbols-outlined">download</span>
                        Export CSV
                    </button>
                </div>
            </div>
            
            <!-- Layout avec graphique (caché par défaut) -->
            <div id="resultsWithChart" class="results-with-chart" style="display: none;">
                <div class="results-chart-split">
                    <div class="results-table-wrapper">
                        <div id="resultsContentChart" class="empty-state">
                            <span class="material-symbols-outlined">play_circle</span>
                            <p>Exécutez une requête SQL pour voir les résultats</p>
                        </div>
                    </div>
                    <div class="chart-panel">
                        <div class="chart-panel-header">
                            <h4>
                                <span class="material-symbols-outlined">donut_small</span>
                                Graphique
                            </h4>
                            <div class="chart-type-switch">
                                <button class="chart-type-btn active" data-type="doughnut" onclick="changeChartType('doughnut')">
                                    <span class="material-symbols-outlined">donut_small</span>
                                </button>
                                <button class="chart-type-btn" data-type="bar" onclick="changeChartType('bar')">
                                    <span class="material-symbols-outlined">bar_chart</span>
                                </button>
                                <button class="chart-type-btn" data-type="horizontalBar" onclick="changeChartType('horizontalBar')">
                                    <span class="material-symbols-outlined">align_horizontal_left</span>
                                </button>
                            </div>
                        </div>
                        <div class="chart-container-inline">
                            <canvas id="resultChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Layout classique (par défaut) -->
            <div id="resultsClassic" class="results-table-wrapper">
                <div id="resultsContent" class="empty-state">
                    <span class="material-symbols-outlined">play_circle</span>
                    <p>Exécutez une requête SQL pour voir les résultats</p>
                </div>
            </div>
            
            <!-- Alerte de troncature (fixe en bas, hors du scroll) -->
            <div id="truncationAlert" class="truncation-alert" style="display: none;"></div>
        </div>
    </div>
</div>
</div><!-- Fin sql-workspace-container -->

<!-- Modale d'aide -->
<div id="sqlHelpModal" class="help-modal" onclick="if(event.target === this) hideSQLHelp()">
    <div class="help-modal-content">
        <div class="help-modal-header">
            <h2>
                <span class="material-symbols-outlined">help</span>
                Guide SQL Explorer
            </h2>
            <button class="help-modal-close" onclick="hideSQLHelp()">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="help-modal-body">
            <h3>🚀 Introduction</h3>
            <p>
                Le SQL Explorer vous permet d'interroger directement la base de données du crawl avec des requêtes SQL personnalisées. 
                Utilisez l'autocomplétion intelligente et les requêtes pré-enregistrées pour explorer vos données efficacement.
            </p>

            <h3>📊 Schéma de la base de données PostgreSQL</h3>
            <p><strong>Architecture :</strong> Les données sont partitionnées par crawl. Les tables <code>pages</code>, <code>links</code> et <code>categories</code> sont des tables virtuelles qui pointent automatiquement vers la partition du crawl actuel.</p>
            
            <h4>Table principale : <code>pages</code></h4>
            <p>Cette table contient toutes les informations sur les URLs crawlées :</p>
            
            <table class="schema-table">
                <thead>
                    <tr>
                        <th>Champ</th>
                        <th>Type</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>id</code></td><td>CHAR(8)</td><td>Identifiant unique de l'URL</td></tr>
                    <tr><td><code>url</code></td><td>TEXT</td><td>URL complète de la page</td></tr>
                    <tr><td><code>domain</code></td><td>VARCHAR(255)</td><td>Domaine de l'URL</td></tr>
                    <tr><td><code>code</code></td><td>INTEGER</td><td>Code HTTP (200, 404, 301...). <code>311</code> = redirection JS</td></tr>
                    <tr><td><code>depth</code></td><td>INTEGER</td><td>Profondeur de crawl (0 = accueil)</td></tr>
                    <tr><td><code>crawled</code></td><td>BOOLEAN</td><td>true si crawlée</td></tr>
                    <tr><td><code>compliant</code></td><td>BOOLEAN</td><td>true si indexable</td></tr>
                    <tr><td><code>external</code></td><td>BOOLEAN</td><td>true si URL externe</td></tr>
                    <tr><td><code>blocked</code></td><td>BOOLEAN</td><td>true si bloquée par robots.txt</td></tr>
                    <tr><td><code>noindex</code></td><td>BOOLEAN</td><td>true si meta noindex</td></tr>
                    <tr><td><code>nofollow</code></td><td>BOOLEAN</td><td>true si meta nofollow</td></tr>
                    <tr><td><code>canonical</code></td><td>BOOLEAN</td><td>true si l'URL = sa canonical</td></tr>
                    <tr><td><code>canonical_value</code></td><td>TEXT</td><td>URL de la balise canonical</td></tr>
                    <tr><td><code>redirect_to</code></td><td>TEXT</td><td>URL de redirection (si 3xx)</td></tr>
                    <tr><td><code>content_type</code></td><td>VARCHAR(100)</td><td>Content-Type HTTP</td></tr>
                    <tr><td><code>response_time</code></td><td>FLOAT</td><td>Temps de réponse (ms)</td></tr>
                    <tr><td><code>inlinks</code></td><td>INTEGER</td><td>Liens entrants</td></tr>
                    <tr><td><code>outlinks</code></td><td>INTEGER</td><td>Liens sortants</td></tr>
                    <tr><td><code>pri</code></td><td>FLOAT</td><td>Score PageRank interne</td></tr>
                    <tr><td><code>title</code></td><td>TEXT</td><td>Balise &lt;title&gt;</td></tr>
                    <tr><td><code>title_status</code></td><td>VARCHAR(50)</td><td>unique / empty / duplicate</td></tr>
                    <tr><td><code>h1</code></td><td>TEXT</td><td>Premier H1</td></tr>
                    <tr><td><code>h1_status</code></td><td>VARCHAR(50)</td><td>unique / empty / duplicate</td></tr>
                    <tr><td><code>metadesc</code></td><td>TEXT</td><td>Meta description</td></tr>
                    <tr><td><code>metadesc_status</code></td><td>VARCHAR(50)</td><td>unique / empty / duplicate</td></tr>
                    <tr><td><code>h1_multiple</code></td><td>BOOLEAN</td><td>true si plusieurs H1</td></tr>
                    <tr><td><code>headings_missing</code></td><td>BOOLEAN</td><td>true si mauvaise structure hn</td></tr>
                    <tr><td><code>simhash</code></td><td>BIGINT</td><td>Hash de similarité (duplicate detection)</td></tr>
                    <tr><td><code>is_html</code></td><td>BOOLEAN</td><td>true si contenu HTML</td></tr>
                    <tr><td><code>cat_id</code></td><td>INTEGER</td><td>FK → categories.id</td></tr>
                    <tr><td><code>extracts</code></td><td>JSONB</td><td>Extractions XPath/Regex</td></tr>
                    <tr><td><code>schemas</code></td><td>TEXT[]</td><td>Types Schema.org (JSON-LD @type)</td></tr>
                    <tr><td><code>date</code></td><td>TIMESTAMP</td><td>Date de crawl</td></tr>
                </tbody>
            </table>

            <h4>Table des catégories : <code>categories</code></h4>
            <p>Stocke les catégories et leurs couleurs personnalisées :</p>
            
            <table class="schema-table">
                <thead>
                    <tr>
                        <th>Champ</th>
                        <th>Type</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>id</code></td><td>INTEGER</td><td>Identifiant unique de la catégorie</td></tr>
                    <tr><td><code>cat</code></td><td>VARCHAR(255)</td><td>Nom de la catégorie</td></tr>
                    <tr><td><code>color</code></td><td>VARCHAR(7)</td><td>Couleur hexadécimale (ex: #3498db)</td></tr>
                </tbody>
            </table>

            <h4>Table des liens : <code>links</code></h4>
            <p>Stocke tous les liens découverts lors du crawl :</p>
            
            <table class="schema-table">
                <thead>
                    <tr>
                        <th>Champ</th>
                        <th>Type</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>src</code></td><td>CHAR(8)</td><td>ID page source (FK → pages.id)</td></tr>
                    <tr><td><code>target</code></td><td>CHAR(8)</td><td>ID page cible (FK → pages.id)</td></tr>
                    <tr><td><code>anchor</code></td><td>TEXT</td><td>Texte d'ancre du lien</td></tr>
                    <tr><td><code>type</code></td><td>VARCHAR(50)</td><td>Type de lien (ahref, canonical, redirect)</td></tr>
                    <tr><td><code>external</code></td><td>BOOLEAN</td><td>true si lien externe</td></tr>
                    <tr><td><code>nofollow</code></td><td>BOOLEAN</td><td>true si attribut nofollow</td></tr>
                </tbody>
            </table>

            <h4>Table des clusters de duplication : <code>duplicate_clusters</code></h4>
            <p>Stocke les groupes de pages dupliquées (exactes ou similaires) :</p>
            
            <table class="schema-table">
                <thead>
                    <tr>
                        <th>Champ</th>
                        <th>Type</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>id</code></td><td>SERIAL</td><td>Identifiant unique du cluster</td></tr>
                    <tr><td><code>similarity</code></td><td>INTEGER</td><td>% de similarité (100 = exact, &lt;100 = near-duplicate)</td></tr>
                    <tr><td><code>page_count</code></td><td>INTEGER</td><td>Nombre de pages dans le cluster</td></tr>
                    <tr><td><code>page_ids</code></td><td>TEXT[]</td><td>Array des IDs de pages (CHAR(8))</td></tr>
                </tbody>
            </table>

            <h4>Table des données structurées : <code>page_schemas</code></h4>
            <p>Table de liaison pour les types Schema.org (JSON-LD) trouvés sur chaque page :</p>
            
            <table class="schema-table">
                <thead>
                    <tr>
                        <th>Champ</th>
                        <th>Type</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>page_id</code></td><td>CHAR(8)</td><td>ID de la page (FK → pages.id)</td></tr>
                    <tr><td><code>schema_type</code></td><td>VARCHAR(100)</td><td>Type Schema.org (@type: Article, Product...)</td></tr>
                </tbody>
            </table>

            <h3>🔗 Relations entre tables</h3>
            <p>Les tables sont liées par des clés étrangères :</p>
            <pre><code>-- Liens internes
links.src → pages.id       (page source du lien)
links.target → pages.id    (page cible du lien)

-- Catégories
pages.cat_id → categories.id (catégorie de la page)

-- Clusters de duplication
duplicate_clusters.page_ids contient des IDs de pages

-- Données structurées
page_schemas.page_id → pages.id (schemas d'une page)

-- Jointures utiles
LEFT JOIN categories c ON pages.cat_id = c.id
-- Pour les clusters: WHERE pages.id = ANY(duplicate_clusters.page_ids)
-- Pour les schemas: LEFT JOIN page_schemas ps ON ps.page_id = p.id</code></pre>
            <p>
                <strong>Notes :</strong>
            </p>
            <ul>
                <li>Pour obtenir le nom de catégorie, faites une jointure avec <code>categories</code></li>
                <li>Les extractions personnalisées sont en JSONB dans <code>extracts</code></li>
                <li><code>page_ids</code> et <code>schemas</code> sont des tableaux PostgreSQL (TEXT[]), utilisez <code>ANY()</code> ou <code>@&gt;</code></li>
                <li>La table <code>page_schemas</code> permet des GROUP BY rapides sur les types de données structurées</li>
            </ul>

            <h3>💡 Exemples de requêtes utiles</h3>
            
            <h4>Analyse des codes de réponse</h4>
            <pre><code>SELECT 
    code,
    COUNT(*) AS nb_urls
FROM pages 
WHERE crawled = true 
GROUP BY code 
ORDER BY nb_urls DESC;</code></pre>

            <h4>Pages les plus populaires</h4>
            <pre><code>SELECT 
    url,
    inlinks,
    title,
    code
FROM pages 
WHERE crawled = true AND compliant = true 
ORDER BY inlinks DESC 
LIMIT 20;</code></pre>

            <h4>Analyse par catégorie</h4>
            <pre><code>-- Répartition des URLs par catégorie
SELECT 
    COALESCE(c.cat, 'Non catégorisé') AS categorie,
    COUNT(*) AS nb_urls,
    AVG(p.inlinks) AS inlinks_moyen,
    AVG(p.response_time) AS temps_reponse_moyen
FROM pages p
LEFT JOIN categories c ON c.id = p.cat_id
WHERE p.crawled = true AND p.compliant = true
GROUP BY c.cat
ORDER BY nb_urls DESC;

-- Avec la couleur de catégorie
SELECT 
    COALESCE(c.cat, 'Non catégorisé') AS categorie,
    c.color AS couleur,
    COUNT(*) AS nb_urls
FROM pages p
LEFT JOIN categories c ON c.id = p.cat_id
WHERE p.crawled = true
GROUP BY c.cat, c.color
ORDER BY nb_urls DESC;</code></pre>

            <h4>Détection de problèmes SEO</h4>
            <pre><code>-- URLs sans titre
SELECT url, code FROM pages 
WHERE crawled = true AND (title IS NULL OR title = '') 
LIMIT 50;

-- URLs avec titre dupliqué
SELECT title, COUNT(*) AS nb_pages
FROM pages 
WHERE crawled = true AND compliant = true AND title IS NOT NULL
GROUP BY title 
HAVING COUNT(*) > 1
ORDER BY nb_pages DESC;</code></pre>

            <h4>Analyse des liens internes</h4>
            <pre><code>-- Top 20 des ancres les plus utilisées
SELECT 
    anchor,
    COUNT(*) AS nb_liens,
    COUNT(DISTINCT src) AS nb_pages_source
FROM links
WHERE anchor IS NOT NULL AND anchor != ''
GROUP BY anchor
ORDER BY nb_liens DESC
LIMIT 20;

-- Pages avec le plus de liens sortants
SELECT 
    p.url,
    p.title,
    COUNT(l.id) AS nb_liens_sortants
FROM pages p
LEFT JOIN links l ON p.id = l.src
WHERE p.crawled = true
GROUP BY p.id, p.url, p.title
ORDER BY nb_liens_sortants DESC
LIMIT 20;</code></pre>

            <h4>Exploitation des extractions personnalisées (JSONB)</h4>
            <p>Les extractions XPath/Regex sont stockées en JSONB. Utilisez l'opérateur <code>-&gt;&gt;</code> pour extraire une valeur texte :</p>
            <pre><code>-- Exemple 1 : Extraire un champ spécifique (ex: 'price')
SELECT 
    url,
    title,
    extracts->>'price' AS prix,
    extracts->>'stock' AS stock
FROM pages
WHERE extracts->>'price' IS NOT NULL
ORDER BY (extracts->>'price')::NUMERIC DESC
LIMIT 50;

-- Exemple 2 : Lister toutes les clés d'extraction disponibles
SELECT DISTINCT 
    jsonb_object_keys(extracts) AS extraction_name
FROM pages
WHERE extracts IS NOT NULL AND extracts != '{}'::jsonb;

-- Exemple 3 : Filtrer sur une extraction
SELECT 
    url,
    extracts->>'author' AS auteur,
    extracts->>'date' AS date_publication
FROM pages
WHERE extracts->>'author' LIKE '%John%'
LIMIT 50;

-- Exemple 4 : Compter les pages avec extraction
SELECT 
    COUNT(*) AS total_pages,
    COUNT(CASE WHEN extracts->>'price' IS NOT NULL THEN 1 END) AS pages_avec_prix
FROM pages
WHERE crawled = true;</code></pre>

            <h4>Analyse des données structurées (Schema.org)</h4>
            <p>Les types Schema.org sont stockés dans la colonne <code>schemas</code> (tableau) et la table <code>page_schemas</code> pour les stats :</p>
            <pre><code>-- Exemple 1 : Distribution des types de schemas
SELECT 
    schema_type,
    COUNT(*) AS nb_pages
FROM page_schemas
GROUP BY schema_type
ORDER BY nb_pages DESC;

-- Exemple 2 : Pages avec un type spécifique (via colonne schemas)
SELECT 
    url,
    title,
    schemas
FROM pages
WHERE 'Article' = ANY(schemas)
LIMIT 50;

-- Exemple 3 : Pages sans données structurées
SELECT 
    url,
    inlinks
FROM pages
WHERE crawled = true 
    AND compliant = true 
    AND (schemas IS NULL OR array_length(schemas, 1) IS NULL)
ORDER BY inlinks DESC
LIMIT 50;

-- Exemple 4 : Nombre de types par page
SELECT 
    url,
    array_length(schemas, 1) AS nb_schemas,
    schemas
FROM pages
WHERE schemas IS NOT NULL AND array_length(schemas, 1) > 0
ORDER BY nb_schemas DESC
LIMIT 20;</code></pre>

            <h3>⚡ Fonctionnalités de l'éditeur</h3>
            <ul>
                <li><strong>Autocomplétion intelligente</strong> : Tapez pour voir les suggestions de tables et colonnes</li>
                <li><strong>Coloration syntaxique</strong> : Code SQL coloré pour une meilleure lisibilité</li>
                <li><strong>Raccourcis clavier</strong> : 
                    <ul>
                        <li><code>Ctrl+Enter</code> : Exécuter la requête</li>
                        <li><code>Ctrl+Space</code> : Forcer l'autocomplétion</li>
                        <li><code>Tab</code> : Indentation</li>
                    </ul>
                </li>
                <li><strong>Onglets multiples</strong> : Travaillez sur plusieurs requêtes simultanément</li>
                <li><strong>Requêtes sauvegardées</strong> : Cliquez sur une requête pré-définie pour la charger</li>
                <li><strong>Export CSV</strong> : Exportez tous les résultats (même si l'affichage est limité à 500 lignes)</li>
            </ul>

            <h3>🎯 Conseils d'utilisation</h3>
            <ul>
                <li>Utilisez <code>LIMIT</code> pour limiter les résultats lors de vos tests</li>
                <li>Filtrez sur <code>crawled=1</code> pour ne voir que les pages crawlées</li>
                <li>Filtrez sur <code>compliant=1</code> pour ne voir que les pages indexables</li>
                <li>Utilisez <code>LEFT JOIN</code> pour inclure les URLs sans catégorie</li>
                <li>Les fonctions PostgreSQL sont disponibles : <code>COUNT()</code>, <code>AVG()</code>, <code>SUM()</code>, <code>COALESCE()</code>, etc.</li>
                <li>Pour les JSONB : utilisez <code>-&gt;&gt;</code> pour extraire du texte, <code>-&gt;</code> pour extraire du JSON</li>
                <li>Convertissez les types avec <code>::NUMERIC</code>, <code>::INTEGER</code>, <code>::DATE</code>, etc.</li>
            </ul>

            <h3>⚠️ Limitations et sécurité</h3>
            <ul>
                <li><strong>Base de données en lecture seule</strong> : Seules les requêtes <code>SELECT</code> sont autorisées</li>
                <li><strong>Modifications interdites</strong> : Les commandes <code>INSERT</code>, <code>UPDATE</code>, <code>DELETE</code>, <code>DROP</code>, <code>CREATE</code>, etc. sont bloquées</li>
                <li><strong>Affichage limité</strong> : 500 lignes maximum pour les performances (export CSV complet disponible)</li>
                <li><strong>Timeout automatique</strong> : Les requêtes trop longues sont interrompues</li>
                <li><strong>Protection multi-niveaux</strong> : Validation des requêtes + connexion en lecture seule</li>
            </ul>
        </div>
    </div>
</div>


<script>
// Préparer les données d'autocomplétion
const sqlHintData = {
    tables: <?= json_encode(array_keys($tables)) ?>,
    defaultTable: <?= json_encode($tables) ?>
};

// Requêtes sauvegardées
const savedQueries = <?= json_encode($savedQueries) ?>;

// Système d'onglets
let tabs = [
    { id: 0, title: 'Requête 1', query: <?= json_encode($initialQuery) ?>, editor: null }
];
let activeTabId = 0;
let nextTabId = 1;

// Créer un objet avec toutes les colonnes pour l'autocomplétion
const allColumns = {};
<?php foreach ($tables as $tableName => $columns): ?>
allColumns['<?= $tableName ?>'] = [
    <?php foreach ($columns as $column): ?>
    '<?= $column['name'] ?>',
    <?php endforeach; ?>
];
<?php endforeach; ?>

// Fonction d'autocomplétion personnalisée
function customSQLHint(editor, options) {
    const cursor = editor.getCursor();
    const token = editor.getTokenAt(cursor);
    const line = editor.getLine(cursor.line);
    const lineUpToCursor = line.slice(0, cursor.ch);
    
    // Détecter le contexte (après FROM, JOIN, etc.)
    const fromMatch = lineUpToCursor.match(/(?:FROM|JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?\s*$/i);
    const selectMatch = lineUpToCursor.match(/SELECT\s+(?:.*,\s*)?(\w*)$/i);
    const whereMatch = lineUpToCursor.match(/WHERE\s+(?:.*\s+(?:AND|OR)\s+)?(\w*)$/i);
    
    let suggestions = [];
    
    // Si on est après FROM ou JOIN, proposer les tables
    if (fromMatch || token.string.match(/^\w*$/) && lineUpToCursor.match(/(?:FROM|JOIN)\s*\w*$/i)) {
        suggestions = Object.keys(allColumns).map(table => ({
            text: table,
            displayText: table + ' (table)',
            className: 'hint-table'
        }));
    }
    // Si on est dans SELECT ou WHERE, proposer les colonnes
    else if (selectMatch || whereMatch || token.string.match(/^\w+$/)) {
        // Trouver toutes les tables mentionnées dans la requête
        const fullQuery = editor.getValue();
        const tableMatches = fullQuery.match(/(?:FROM|JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/gi);
        
        if (tableMatches) {
            tableMatches.forEach(match => {
                const parts = match.match(/(?:FROM|JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i);
                const tableName = parts[1];
                const alias = parts[2];
                
                if (allColumns[tableName]) {
                    allColumns[tableName].forEach(column => {
                        suggestions.push({
                            text: column,
                            displayText: column + ' (' + tableName + ')',
                            className: 'hint-column'
                        });
                        
                        // Ajouter aussi avec l'alias si présent
                        if (alias) {
                            suggestions.push({
                                text: alias + '.' + column,
                                displayText: alias + '.' + column + ' (' + tableName + ')',
                                className: 'hint-column-alias'
                            });
                        }
                        
                        // Ajouter avec le nom de table complet
                        suggestions.push({
                            text: tableName + '.' + column,
                            displayText: tableName + '.' + column,
                            className: 'hint-column-full'
                        });
                    });
                }
            });
        }
        
        // Ajouter aussi les tables si aucune colonne trouvée
        if (suggestions.length === 0) {
            suggestions = Object.keys(allColumns).map(table => ({
                text: table,
                displayText: table + ' (table)',
                className: 'hint-table'
            }));
        }
    }
    
    // Filtrer selon ce qui est déjà tapé
    const typed = token.string.toLowerCase();
    if (typed) {
        suggestions = suggestions.filter(s => 
            s.text.toLowerCase().includes(typed) || 
            s.displayText.toLowerCase().includes(typed)
        );
    }
    
    // Supprimer les doublons
    const seen = new Set();
    suggestions = suggestions.filter(s => {
        if (seen.has(s.text)) return false;
        seen.add(s.text);
        return true;
    });
    
    return {
        list: suggestions,
        from: {line: cursor.line, ch: token.start},
        to: {line: cursor.line, ch: token.end}
    };
}

// Initialiser CodeMirror
let sqlEditor;
if (typeof CodeMirror !== 'undefined') {
    sqlEditor = CodeMirror.fromTextArea(document.getElementById('sqlEditor'), {
        mode: 'text/x-sql',
        theme: 'default',
        lineNumbers: true,
        lineWrapping: true,
        autofocus: true,
        indentWithTabs: false,
        indentUnit: 2,
        matchBrackets: true,
        autoCloseBrackets: true,
        highlightSelectionMatches: {showToken: /\w/, annotateScrollbar: true},
        hintOptions: {
            hint: customSQLHint,
            completeSingle: false
        },
        extraKeys: {
            "Ctrl-Enter": executeQuery,
            "Cmd-Enter": executeQuery,
            "Ctrl-Space": function(cm) {
                CodeMirror.showHint(cm, customSQLHint, {completeSingle: false});
            },
            "Tab": function(cm) {
                if (cm.somethingSelected()) {
                    cm.indentSelection("add");
                } else {
                    cm.replaceSelection("  ");
                }
            }
        }
    });
    
    // Sauvegarder l'éditeur dans le premier onglet
    tabs[0].editor = sqlEditor;
    
    // Autocomplétion automatique pendant la frappe
    sqlEditor.on("inputRead", function(cm, change) {
        if (!cm.state.completionActive && 
            change.text[0].match(/[a-zA-Z.]/)) {
            setTimeout(() => {
                if (!cm.state.completionActive) {
                    CodeMirror.showHint(cm, customSQLHint, {completeSingle: false});
                }
            }, 100);
        }
    });
    
    // Sauvegarder le contenu quand on change d'onglet
    sqlEditor.on("change", function(cm) {
        const activeTab = tabs.find(tab => tab.id === activeTabId);
        if (activeTab) {
            activeTab.query = cm.getValue();
        }
    });
    
} else {
    // Fallback si CodeMirror n'est pas chargé
    console.warn('CodeMirror non disponible, utilisation du textarea basique');
}

// Gestion du clic molette sur les tabs
function handleTabMouseDown(e) {
    // Empêcher le scroll automatique du navigateur sur clic molette
    if (e.button === 1) {
        e.preventDefault();
    }
}

function handleTabMiddleClick(tabId, e) {
    if (e.button === 1) { // Bouton du milieu
        e.preventDefault();
        e.stopPropagation();
        closeTab(tabId, e);
    }
}

// Toggle sidebar
function toggleSidebar() {
    const layout = document.getElementById('sqlLayout');
    layout.classList.toggle('sidebar-collapsed');
    
    // Redimensionner CodeMirror après l'animation
    setTimeout(() => {
        if (sqlEditor) {
            sqlEditor.refresh();
        }
    }, 350);
}

// Toggle table columns
function toggleTable(tableName) {
    const columnsDiv = document.getElementById('columns-' + tableName);
    const header = columnsDiv.previousElementSibling;
    
    if (columnsDiv.classList.contains('expanded')) {
        columnsDiv.classList.remove('expanded');
        header.classList.remove('active');
    } else {
        // Fermer tous les autres
        document.querySelectorAll('.table-columns').forEach(col => {
            col.classList.remove('expanded');
        });
        document.querySelectorAll('.table-header').forEach(h => {
            h.classList.remove('active');
        });
        
        // Ouvrir celui-ci
        columnsDiv.classList.add('expanded');
        header.classList.add('active');
    }
}

// Fonctions de gestion des onglets
function switchTab(tabId) {
    // Sauvegarder le contenu de l'onglet actuel
    const currentTab = tabs.find(tab => tab.id === activeTabId);
    if (currentTab && sqlEditor) {
        currentTab.query = sqlEditor.getValue();
    }
    
    // Changer l'onglet actif
    activeTabId = tabId;
    const newTab = tabs.find(tab => tab.id === tabId);
    
    if (newTab && sqlEditor) {
        sqlEditor.setValue(newTab.query);
        sqlEditor.focus();
    }
    
    // Mettre à jour l'interface
    updateTabsUI();
}

function addNewTab() {
    const newTab = {
        id: nextTabId,
        title: `Requête ${nextTabId + 1}`,
        query: '',
        editor: null
    };
    
    tabs.push(newTab);
    activeTabId = nextTabId;
    nextTabId++;
    
    // Mettre à jour l'interface et charger le contenu
    updateTabsUI();
    if (sqlEditor) {
        sqlEditor.setValue('');
        sqlEditor.focus();
    }
}

function closeTab(tabId, event) {
    event.stopPropagation();
    
    // Ne pas fermer s'il n'y a qu'un onglet
    if (tabs.length <= 1) return;
    
    const tabIndex = tabs.findIndex(tab => tab.id === tabId);
    if (tabIndex === -1) return;
    
    // Si on ferme l'onglet actif, basculer vers un autre
    if (tabId === activeTabId) {
        const newActiveIndex = tabIndex > 0 ? tabIndex - 1 : 0;
        const newActiveTab = tabs[newActiveIndex === tabIndex ? 1 : newActiveIndex];
        activeTabId = newActiveTab.id;
        
        if (sqlEditor) {
            sqlEditor.setValue(newActiveTab.query);
        }
    }
    
    // Supprimer l'onglet
    tabs.splice(tabIndex, 1);
    updateTabsUI();
}

function updateTabsUI() {
    const container = document.getElementById('tabsContainer');
    const addButton = container.querySelector('.tab-add');
    
    // Supprimer tous les onglets existants
    container.querySelectorAll('.tab:not(.tab-add)').forEach(tab => tab.remove());
    
    // Recréer les onglets
    tabs.forEach(tab => {
        const tabElement = document.createElement('div');
        tabElement.className = `tab ${tab.id === activeTabId ? 'active' : ''}`;
        tabElement.setAttribute('data-tab-id', tab.id);
        tabElement.onclick = () => switchTab(tab.id);
        tabElement.ondblclick = (e) => {
            // Ne pas déclencher le renommage si on double-clique sur le bouton de fermeture
            if (!e.target.classList.contains('tab-close')) {
                startRenameTab(tab.id, e);
            }
        };
        // Empêcher le scroll auto au clic molette
        tabElement.onmousedown = handleTabMouseDown;
        // Clic du milieu pour fermer le tab
        tabElement.onauxclick = (e) => handleTabMiddleClick(tab.id, e);
        
        const titleElement = document.createElement('span');
        titleElement.className = 'tab-title';
        titleElement.textContent = tab.title;
        
        const closeElement = document.createElement('span');
        closeElement.className = 'tab-close';
        closeElement.textContent = '×';
        closeElement.onclick = (e) => closeTab(tab.id, e);
        
        tabElement.appendChild(titleElement);
        tabElement.appendChild(closeElement);
        
        container.insertBefore(tabElement, addButton);
    });
}

function startRenameTab(tabId, event) {
    event.stopPropagation();
    
    const tab = tabs.find(t => t.id === tabId);
    if (!tab) return;
    
    const tabElement = document.querySelector(`[data-tab-id="${tabId}"]`);
    const titleElement = tabElement.querySelector('.tab-title');
    
    // Créer l'input de renommage
    const input = document.createElement('input');
    input.className = 'tab-title-input';
    input.value = tab.title;
    input.type = 'text';
    
    // Remplacer le titre par l'input
    titleElement.style.display = 'none';
    tabElement.insertBefore(input, titleElement);
    
    // Focus et sélection du texte
    input.focus();
    input.select();
    
    // Fonction pour valider le renommage
    function finishRename() {
        const newTitle = input.value.trim() || tab.title;
        tab.title = newTitle;
        
        // Restaurer l'affichage normal
        titleElement.textContent = newTitle;
        titleElement.style.display = '';
        input.remove();
    }
    
    // Événements pour valider ou annuler
    input.onblur = finishRename;
    input.onkeydown = function(e) {
        if (e.key === 'Enter') {
            finishRename();
        } else if (e.key === 'Escape') {
            // Annuler sans sauvegarder
            titleElement.style.display = '';
            input.remove();
        }
        e.stopPropagation();
    };
    
    // Empêcher le clic sur l'onglet pendant l'édition
    input.onclick = (e) => e.stopPropagation();
}

// Charger une requête sauvegardée
function loadSavedQuery(index) {
    console.log('Loading query index:', index, savedQueries[index]);
    const query = savedQueries[index];
    if (query) {
        // Créer un nouvel onglet avec la requête
        const newTab = {
            id: nextTabId,
            title: query.name.substring(0, 20) + (query.name.length > 20 ? '...' : ''),
            query: query.query,
            editor: null
        };
        
        tabs.push(newTab);
        activeTabId = nextTabId;
        nextTabId++;
        
        // Mettre à jour l'interface et charger le contenu
        updateTabsUI();
        if (sqlEditor) {
            sqlEditor.setValue(query.query);
            sqlEditor.focus();
        }
        
        // Petit effet visuel
        const queryItems = document.querySelectorAll('.saved-queries-section .query-item');
        if (queryItems[index]) {
            queryItems[index].style.background = 'var(--primary-color)';
            queryItems[index].style.color = 'white';
            setTimeout(() => {
                queryItems[index].style.background = '';
                queryItems[index].style.color = '';
            }, 200);
        }
    }
}

/**
 * Convertit les doubles quotes en simples quotes pour PostgreSQL
 * - Remplace uniquement les guillemets d'encadrement
 * - Échappe les simples quotes internes (les double pour PG)
 * - Déséchape les doubles quotes internes (\" → ")
 */
function convertDoubleQuotesToSingleQuotes(sql) {
    // Regex pour capturer les chaînes entre doubles quotes
    // Gère les séquences échappées comme \"
    return sql.replace(/"((?:[^"\\]|\\.)*)"/g, function(match, content) {
        // Déséchapper les doubles quotes : \" → "
        let converted = content.replace(/\\"/g, '"');
        // Échapper les simples quotes pour PostgreSQL : ' → ''
        converted = converted.replace(/'/g, "''");
        // Retourner avec des simples quotes
        return "'" + converted + "'";
    });
}

// Exécuter la requête
function executeQuery() {
    let query = sqlEditor ? sqlEditor.getValue() : document.getElementById('sqlEditor').value;
    
    if (!query.trim()) {
        showError('Veuillez entrer une requête SQL');
        return;
    }
    
    // Pré-traiter la requête : convertir les doubles quotes en simples quotes
    query = convertDoubleQuotesToSingleQuotes(query);
    
    // Afficher le loader
    document.getElementById('resultsContent').innerHTML = `
        <div class="loading">
            <div class="spinner"></div>
            <span>Exécution de la requête...</span>
        </div>
    `;
    document.getElementById('resultInfo').innerHTML = `
        <span class="material-symbols-outlined spinning">progress_activity</span>
        <span>Exécution...</span>
    `;
    document.getElementById('truncationAlert').style.display = 'none';
    
    // Envoyer la requête
    fetch('../api/query/execute', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            query: query,
            project: '<?= htmlspecialchars($projectDir) ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            showError(data.error);
        } else {
            displayResults(data);
        }
    })
    .catch(error => {
        showError('Erreur lors de l\'exécution de la requête : ' + error.message);
    });
}

// Variable globale pour stocker les données actuelles
let currentResultData = null;

// Afficher les résultats
function displayResults(data) {
    const resultsContent = document.getElementById('resultsContent');
    const resultsContentChart = document.getElementById('resultsContentChart');
    const resultInfo = document.getElementById('resultInfo');
    const exportBtn = document.getElementById('exportBtn');
    const resultsClassic = document.getElementById('resultsClassic');
    const resultsWithChart = document.getElementById('resultsWithChart');
    
    if (data.type === 'select') {
        currentResultData = data; // Stocker TOUTES les données pour l'export
        
        if (data.rows.length === 0) {
            resultsClassic.style.display = 'flex';
            resultsWithChart.style.display = 'none';
            resultsContent.className = 'empty-state';
            resultsContent.innerHTML = `
                <span class="material-symbols-outlined">inbox</span>
                <p>Aucun résultat</p>
            `;
            resultInfo.innerHTML = `
                <span class="material-symbols-outlined">table_chart</span>
                <span>0 ligne</span>
            `;
            exportBtn.disabled = true;
            document.getElementById('copyBtn').disabled = true;
            document.getElementById('truncationAlert').style.display = 'none';
            return;
        }
        
        // Vérifier si on doit afficher le graphique
        // Conditions : exactement 2 colonnes ET la 2ème colonne est numérique ET max 20 lignes
        const shouldShowChart = data.columns.length === 2 && 
                                data.rows.length > 0 && 
                                data.rows.length <= 20 &&
                                !isNaN(parseFloat(data.rows[0][data.columns[1]]));
        
        if (shouldShowChart) {
            // Mode avec graphique
            resultsClassic.style.display = 'none';
            resultsWithChart.style.display = 'flex';
        } else {
            // Mode classique (prend tout l'espace)
            resultsClassic.style.display = 'flex';
            resultsWithChart.style.display = 'none';
        }
        
        // Limiter l'affichage à 500 lignes maximum
        const maxDisplayRows = 500;
        const displayRows = data.rows.slice(0, maxDisplayRows);
        const isLimited = data.rows.length > maxDisplayRows;
        
        // Créer le tableau avec les lignes limitées
        const columns = data.columns;
        let html = '<table class="results-table"><thead><tr>';
        
        columns.forEach(col => {
            html += `<th>${escapeHtml(col)}</th>`;
        });
        
        html += '</tr></thead><tbody>';
        
        displayRows.forEach(row => {
            html += '<tr>';
            columns.forEach(col => {
                const value = row[col];
                html += `<td>${value !== null ? escapeHtml(String(value)) : '<em style="color: var(--text-secondary);">NULL</em>'}</td>`;
            });
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        
        // Afficher dans le bon conteneur selon le mode
        if (shouldShowChart) {
            resultsContentChart.className = '';
            resultsContentChart.innerHTML = html;
            const wrapper = resultsContentChart.closest('.results-table-wrapper');
            if (wrapper) {
                wrapper.scrollTop = 0;
                wrapper.scrollLeft = 0;
            }
            // Générer le graphique
            generateChart('doughnut');
        } else {
            resultsContent.className = '';
            resultsContent.innerHTML = html;
            const wrapper = resultsContent.closest('.results-table-wrapper');
            if (wrapper) {
                wrapper.scrollTop = 0;
                wrapper.scrollLeft = 0;
            }
        }
        
        // Gérer le message d'alerte de troncature (en dehors du scroll)
        const alertContainer = document.getElementById('truncationAlert');
        if (isLimited) {
            alertContainer.innerHTML = `
                <span class="material-symbols-outlined">info</span>
                <strong>Affichage limité :</strong> ${maxDisplayRows} premières lignes sur ${data.rows.length} au total. Utilisez l'export CSV ou Copier pour obtenir toutes les données.
            `;
            alertContainer.style.display = 'flex';
        } else {
            alertContainer.style.display = 'none';
        }
        
        // Mise à jour du result info avec la nouvelle structure
        resultInfo.innerHTML = `
            <span class="material-symbols-outlined">table_chart</span>
            <span>${data.rows.length} ligne${data.rows.length > 1 ? 's' : ''}${isLimited ? ` (${maxDisplayRows} affichées)` : ''}</span>
        `;
        exportBtn.disabled = false; // Activer le bouton d'export
        document.getElementById('copyBtn').disabled = false;
    } else {
        // Requête non-SELECT (UPDATE, DELETE, etc.)
        currentResultData = null;
        resultsContent.innerHTML = `
            <div class="success-message">
                <strong>Succès !</strong> ${data.affected_rows} ligne${data.affected_rows > 1 ? 's' : ''} affectée${data.affected_rows > 1 ? 's' : ''}
            </div>
        `;
        resultInfo.innerHTML = `
            <span class="material-symbols-outlined">table_chart</span>
            <span>Résultats</span>
        `;
        exportBtn.disabled = true;
        document.getElementById('copyBtn').disabled = true;
    }
}

// Afficher une erreur
function showError(message) {
    const resultsClassic = document.getElementById('resultsClassic');
    const resultsWithChart = document.getElementById('resultsWithChart');
    resultsClassic.style.display = 'flex';
    resultsWithChart.style.display = 'none';
    
    document.getElementById('resultsContent').innerHTML = `
        <div class="error-message">
            <strong>Erreur :</strong> ${escapeHtml(message)}
        </div>
    `;
    document.getElementById('resultInfo').innerHTML = `
        <span class="material-symbols-outlined">error</span>
        <span style="color: var(--danger);">Erreur</span>
    `;
    document.getElementById('exportBtn').disabled = true;
    document.getElementById('copyBtn').disabled = true;
    document.getElementById('truncationAlert').style.display = 'none';
}

// Copier le tableau dans le presse-papier
function copyTableToClipboard() {
    if (!currentResultData || !currentResultData.rows.length) {
        return;
    }
    
    const copyBtn = document.getElementById('copyBtn');
    const originalContent = copyBtn.innerHTML;
    
    try {
        // Créer le contenu tab-separated (pour coller dans Excel/Sheets)
        const columns = currentResultData.columns;
        let content = columns.join('\t') + '\n';
        
        // Limiter au nombre de lignes affichées (500 max)
        const maxRows = Math.min(currentResultData.rows.length, 500);
        for (let i = 0; i < maxRows; i++) {
            const row = currentResultData.rows[i];
            const rowData = columns.map(col => {
                const value = row[col];
                if (value === null) return '';
                return String(value).replace(/\t/g, ' ').replace(/\n/g, ' ');
            });
            content += rowData.join('\t') + '\n';
        }
        
        // Copier dans le presse-papier
        navigator.clipboard.writeText(content).then(() => {
            // Feedback visuel
            copyBtn.innerHTML = '<span class="material-symbols-outlined">check</span> Copié !';
            copyBtn.style.color = 'var(--success)';
            copyBtn.style.borderColor = 'var(--success)';
            
            setTimeout(() => {
                copyBtn.innerHTML = originalContent;
                copyBtn.style.color = '';
                copyBtn.style.borderColor = '';
            }, 1500);
        }).catch(err => {
            console.error('Erreur copie:', err);
            alert('Impossible de copier dans le presse-papier');
        });
    } catch (error) {
        console.error('Erreur:', error);
    }
}

// Exporter vers CSV
function exportToCSV() {
    if (!currentResultData || !currentResultData.rows.length) {
        alert('Aucune donnée à exporter');
        return;
    }
    
    // Récupérer le bouton et sauvegarder son contenu
    const exportBtn = document.getElementById('exportBtn');
    const originalContent = exportBtn.innerHTML;
    
    // Afficher l'animation de chargement
    exportBtn.disabled = true;
    exportBtn.innerHTML = '<span class="material-symbols-outlined spinning">progress_activity</span> Export en cours...';
    
    // Utiliser setTimeout pour permettre à l'UI de se mettre à jour
    setTimeout(() => {
        try {
            // Créer le CSV
            const columns = currentResultData.columns;
            let csvContent = columns.join(',') + '\n';
            
            currentResultData.rows.forEach(row => {
                const csvRow = columns.map(col => {
                    const value = row[col];
                    if (value === null) return '';
                    // Échapper les guillemets et entourer de guillemets si nécessaire
                    const stringValue = String(value);
                    if (stringValue.includes(',') || stringValue.includes('"') || stringValue.includes('\n')) {
                        return '"' + stringValue.replace(/"/g, '""') + '"';
                    }
                    return stringValue;
                }).join(',');
                csvContent += csvRow + '\n';
            });
            
            // Créer un blob et télécharger
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', 'sql_export_' + new Date().toISOString().slice(0,19).replace(/:/g, '-') + '.csv');
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            }
            
            // Restaurer le bouton après un court délai
            setTimeout(() => {
                exportBtn.disabled = false;
                exportBtn.innerHTML = originalContent;
            }, 500);
            
        } catch (error) {
            // En cas d'erreur, restaurer le bouton immédiatement
            exportBtn.disabled = false;
            exportBtn.innerHTML = originalContent;
            alert('Erreur lors de l\'export: ' + error.message);
        }
    }, 100);
}

// Échapper le HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Fonctions pour la modale d'aide
function showSQLHelp() {
    document.getElementById('sqlHelpModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function hideSQLHelp() {
    document.getElementById('sqlHelpModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Fermer les modales avec Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideSQLHelp();
    }
});

// Variables pour le graphique
let currentChart = null;
let currentChartType = 'doughnut';

function changeChartType(type) {
    currentChartType = type;
    
    // Mettre à jour les boutons actifs
    document.querySelectorAll('.chart-type-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`[data-type="${type}"]`).classList.add('active');
    
    // Régénérer le graphique
    generateChart(type);
}

function generateChart(type) {
    const canvas = document.getElementById('resultChart');
    const ctx = canvas.getContext('2d');
    
    // Détruire l'ancien graphique
    if (currentChart) {
        currentChart.destroy();
    }
    
    // Préparer les données
    const data = currentResultData;
    const columns = data.columns;
    
    // Trouver la première colonne texte (labels) et la première colonne numérique (valeurs)
    let labelColumn = columns[0];
    let valueColumn = columns.length > 1 ? columns[1] : columns[0];
    
    // Vérifier si la deuxième colonne est numérique
    const firstRow = data.rows[0];
    if (columns.length > 1 && !isNaN(parseFloat(firstRow[valueColumn]))) {
        // OK, on garde valueColumn
    } else {
        // Chercher la première colonne numérique
        for (let col of columns) {
            if (!isNaN(parseFloat(firstRow[col]))) {
                valueColumn = col;
                break;
            }
        }
    }
    
    const labels = data.rows.map(row => String(row[labelColumn]));
    const values = data.rows.map(row => parseFloat(row[valueColumn]) || 0);
    
    // Couleurs pastel (moins agressives, plus esthétiques)
    const colors = [
        '#93C5FD', '#86EFAC', '#FCD34D', '#FCA5A5', '#C4B5FD',
        '#F9A8D4', '#5EEAD4', '#FDBA74', '#67E8F9', '#BEF264',
        '#A5B4FC', '#FDA4AF', '#7DD3FC', '#86EFAC', '#D8B4FE',
        '#F0ABFC', '#5EEAD4', '#FCA5A5', '#A78BFA', '#6EE7B7'
    ];
    
    // Configuration du graphique
    const chartConfig = {
        type: type === 'horizontalBar' ? 'bar' : type,
        data: {
            labels: labels,
            datasets: [{
                label: valueColumn,
                data: values,
                backgroundColor: type === 'doughnut' ? colors : colors[0],
                borderColor: type === 'doughnut' ? colors : colors[0],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            indexAxis: type === 'horizontalBar' ? 'y' : 'x',
            plugins: {
                legend: {
                    display: type === 'doughnut',
                    position: 'right'
                },
                title: {
                    display: true,
                    text: `${labelColumn} vs ${valueColumn}`,
                    font: {
                        size: 16,
                        weight: 'bold'
                    }
                }
            },
            scales: type !== 'doughnut' ? {
                y: {
                    beginAtZero: true
                }
            } : {}
        }
    };
    
    // Créer le graphique
    currentChart = new Chart(ctx, chartConfig);
}
</script>
