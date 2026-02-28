<?php
/**
 * Composant réutilisable : Modale Scope SQL
 * Affiche le scope des données et la requête SQL
 * Utilisé par : chart.php, url-table.php, link-table.php
 */

// Ce composant ne doit être inclus qu'une seule fois
if (defined('SCOPE_MODAL_INCLUDED')) {
    return;
}
define('SCOPE_MODAL_INCLUDED', true);
?>

<!-- Modale Scope SQL (singleton) -->
<div id="scope-modal" class="scope-modal">
    <div class="scope-modal-content">
        <div class="scope-modal-header">
            <h3>
                <span class="material-symbols-outlined">database</span>
                <span id="scope-modal-title">Scope des données</span>
            </h3>
            <button class="scope-modal-close" onclick="closeScopeModal()">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="scope-modal-body">
            <div id="scope-container" class="scope-section">
                <div class="scope-section-title">
                    <span class="material-symbols-outlined">filter_alt</span>
                    Scope des données
                </div>
                <div id="scope-text" class="scope-section-text"></div>
            </div>
            <div id="scope-order-container" class="scope-section" style="display: none;">
                <div class="scope-section-title">
                    <span class="material-symbols-outlined">sort</span>
                    Ordre de tri
                </div>
                <div id="scope-order-text" class="scope-section-text"></div>
            </div>
            <div id="scope-sql-container" class="scope-sql-container" style="display: none;">
                <div class="scope-sql-header">
                    <div class="scope-sql-title">
                        <span class="material-symbols-outlined">code</span>
                        Requête SQL (compatible SQL Explorer)
                    </div>
                    <button class="scope-sql-copy-btn" onclick="copyScopeSql()">
                        <span class="material-symbols-outlined">content_copy</span>
                        Copier
                    </button>
                </div>
                <pre id="scope-sql-content" class="scope-sql-query"></pre>
                <div class="scope-sql-hint">
                    <span class="material-symbols-outlined" style="color: var(--primary-color, #26a69a);">open_in_new</span>
                    <a href="#" id="scope-sql-explorer-link">Exécutez-la dans le SQL Explorer</a> pour explorer les données.
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.scope-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    padding: 2rem;
}

.scope-modal.active {
    display: flex;
}

.scope-modal-content {
    background: #2C3E50;
    border-radius: 12px;
    max-width: 800px;
    width: 90%;
    max-height: 80vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.scope-modal-header {
    background: linear-gradient(135deg, #1a252f 0%, #2C3E50 100%);
    color: white;
    padding: 1rem 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 12px 12px 0 0;
}

.scope-modal-header h3 {
    margin: 0;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.scope-modal-close {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.8);
    transition: all 0.2s;
}

.scope-modal-close:hover {
    background: rgba(231, 76, 60, 0.9);
    border-color: rgba(231, 76, 60, 0.9);
    color: white;
}

.scope-modal-body {
    padding: 1.5rem;
    overflow-y: auto;
    flex: 1;
    background: var(--card-bg, white);
}

.scope-section {
    background: var(--background, #f5f7fa);
    border-left: 4px solid var(--primary-color, #26a69a);
    padding: 1rem 1.25rem;
    border-radius: 0 8px 8px 0;
    margin-bottom: 1rem;
}

.scope-section-title {
    font-weight: 600;
    color: var(--primary-color, #26a69a);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
}

.scope-section-text {
    color: var(--text-primary, #333);
    line-height: 1.6;
}

.scope-sql-container {
    margin-top: 1rem;
}

.scope-sql-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.scope-sql-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: var(--text-primary, #333);
}

.scope-sql-copy-btn {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.5rem 1rem;
    background: var(--primary-color, #26a69a);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 500;
    transition: background 0.2s;
}

.scope-sql-copy-btn:hover {
    background: var(--primary-dark, #1a8a7e);
}

.scope-sql-query {
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 1rem;
    border-radius: 8px;
    font-family: 'Fira Code', 'Consolas', monospace;
    font-size: 0.85rem;
    line-height: 1.5;
    overflow-x: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
    margin: 0;
}

.scope-sql-hint {
    margin-top: 1rem;
    padding: 0.75rem 1rem;
    background: var(--background, #f5f7fa);
    border-radius: 6px;
    font-size: 0.9rem;
    color: var(--text-secondary, #6c757d);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.scope-sql-hint a {
    color: var(--primary-color, #26a69a);
    font-weight: 500;
    text-decoration: none;
}

.scope-sql-hint a:hover {
    text-decoration: underline;
}

/* Coloration syntaxique SQL */
.sql-keyword { color: #569cd6; font-weight: bold; }
.sql-function { color: #dcdcaa; }
.sql-string { color: #ce9178; }
.sql-number { color: #b5cea8; }
.sql-comment { color: #6a9955; font-style: italic; }
.sql-operator { color: #d4d4d4; }
</style>

<script>
(function() {
    // Fonction de coloration syntaxique SQL
    function highlightSQL(sql) {
        if (!sql) return '';
        
        let highlighted = sql
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
        
        // Mots-clés SQL
        const keywords = ['SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'IN', 'IS', 'NULL', 
                         'ORDER BY', 'GROUP BY', 'HAVING', 'LIMIT', 'OFFSET', 'AS', 'ON',
                         'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN', 'JOIN', 'DISTINCT',
                         'ASC', 'DESC', 'LIKE', 'BETWEEN', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END',
                         'TRUE', 'FALSE', 'UNION', 'ALL', 'EXISTS', 'ANY', 'SOME'];
        
        keywords.forEach(kw => {
            const regex = new RegExp('\\b(' + kw + ')\\b', 'gi');
            highlighted = highlighted.replace(regex, '<span class="sql-keyword">$1</span>');
        });
        
        // Fonctions SQL
        const functions = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'ROUND', 'COALESCE', 'CAST', 
                          'CONCAT', 'SUBSTRING', 'LENGTH', 'UPPER', 'LOWER', 'TRIM', 'NOW',
                          'DATE', 'EXTRACT', 'TO_CHAR', 'PERCENTILE_CONT', 'WITHIN GROUP'];
        
        functions.forEach(fn => {
            const regex = new RegExp('\\b(' + fn + ')\\s*\\(', 'gi');
            highlighted = highlighted.replace(regex, '<span class="sql-function">$1</span>(');
        });
        
        // Chaînes
        highlighted = highlighted.replace(/'([^']*)'/g, '<span class="sql-string">\'$1\'</span>');
        
        // Nombres
        highlighted = highlighted.replace(/\b(\d+\.?\d*)\b/g, '<span class="sql-number">$1</span>');
        
        return highlighted;
    }
    
    // Générer le HTML du scope
    function generateScopeHtml(scopeItems) {
        if (scopeItems && scopeItems.length > 0) {
            return 'Données filtrées sur : ' + scopeItems.map(item => 
                '<mark style="background: #e0f2f1; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 0.9em;">' + 
                item.replace(/</g, '&lt;').replace(/>/g, '&gt;') + 
                '</mark>'
            ).join(' + ');
        }
        return '<mark style="background: #e0f2f1; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 0.9em;">Toutes les données</mark> — Aucun filtre appliqué';
    }
    
    // Ouvrir la modale de scope
    window.openScopeModal = function(options) {
        const modal = document.getElementById('scope-modal');
        if (!modal) return;
        
        // Titre
        document.getElementById('scope-modal-title').textContent = options.title || 'Scope des données';
        
        // Scope
        document.getElementById('scope-text').innerHTML = generateScopeHtml(options.scopeItems);
        
        // Ordre (optionnel)
        const orderContainer = document.getElementById('scope-order-container');
        if (options.orderBy) {
            let orderDisplay = options.orderBy.replace(/^ORDER BY\s*/i, '').replace(/\b\w+\./g, '');
            document.getElementById('scope-order-text').innerHTML = 
                '<mark style="background: #e0f2f1; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 0.9em;">' + orderDisplay + '</mark>';
            orderContainer.style.display = 'block';
        } else {
            orderContainer.style.display = 'none';
        }
        
        // SQL (optionnel)
        const sqlContainer = document.getElementById('scope-sql-container');
        if (options.sqlQuery) {
            document.getElementById('scope-sql-content').innerHTML = highlightSQL(options.sqlQuery);
            document.getElementById('scope-sql-content').setAttribute('data-raw-sql', options.sqlQuery);
            
            // Lien SQL Explorer
            const crawl = new URLSearchParams(window.location.search).get('crawl') || '';
            const encodedQuery = encodeURIComponent(options.sqlQuery);
            document.getElementById('scope-sql-explorer-link').href = 
                'dashboard.php?crawl=' + crawl + '&page=sql-explorer&query=' + encodedQuery;
            
            sqlContainer.style.display = 'block';
        } else {
            sqlContainer.style.display = 'none';
        }
        
        modal.classList.add('active');
    };
    
    // Fermer la modale
    window.closeScopeModal = function() {
        const modal = document.getElementById('scope-modal');
        if (modal) {
            modal.classList.remove('active');
        }
    };
    
    // Copier le SQL
    window.copyScopeSql = function() {
        const sqlContent = document.getElementById('scope-sql-content');
        const rawSql = sqlContent.getAttribute('data-raw-sql');
        
        navigator.clipboard.writeText(rawSql).then(() => {
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus('Requête SQL copiée !', 'success');
            }
        }).catch(err => {
            console.error('Erreur copie:', err);
        });
    };
    
    // Fermer en cliquant en dehors
    document.getElementById('scope-modal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeScopeModal();
        }
    });
    
    // Fermer avec Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeScopeModal();
        }
    });
})();
</script>
