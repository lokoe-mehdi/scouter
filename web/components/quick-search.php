<!-- Modal de recherche rapide (Ctrl+P) -->
<div id="quickSearchModal" class="quick-search-modal">
    <div class="quick-search-container">
        <div class="quick-search-input-wrapper">
            <span class="material-symbols-outlined">search</span>
            <input type="text" id="quickSearchInput" placeholder="Rechercher une URL..." autocomplete="off">
        </div>
        <div id="quickSearchResults" class="quick-search-results"></div>
        <div class="quick-search-hint">
            <span><kbd>↑</kbd><kbd>↓</kbd> Naviguer</span>
            <span><kbd>↵</kbd> Ouvrir</span>
            <span><kbd>ESC</kbd> Fermer</span>
        </div>
    </div>
</div>

<style>
.quick-search-modal {
    display: none;
    position: fixed;
    z-index: 99999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(2px);
}

.quick-search-modal.active {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding-top: 10vh;
}

.quick-search-container {
    background: white;
    width: 100%;
    max-width: 650px;
    border-radius: 12px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    overflow: hidden;
}

.quick-search-input-wrapper {
    display: flex;
    align-items: center;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e1e8ed;
    gap: 0.75rem;
}

.quick-search-input-wrapper .material-symbols-outlined {
    color: #657786;
    font-size: 24px;
}

.quick-search-input-wrapper input {
    flex: 1;
    border: none;
    outline: none;
    font-size: 1.1rem;
    color: var(--text-primary);
    background: transparent;
}

.quick-search-input-wrapper input::placeholder {
    color: #95a5a6;
}

.quick-search-input-wrapper kbd {
    background: #f0f0f0;
    border: 1px solid #d0d0d0;
    border-radius: 4px;
    padding: 0.2rem 0.5rem;
    font-size: 0.75rem;
    font-family: inherit;
    color: #657786;
}

.quick-search-results {
    overflow-y: auto;
}

.quick-search-item {
    display: flex;
    align-items: center;
    padding: 0.75rem 1.25rem;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
    gap: 0.75rem;
    transition: background 0.1s;
}

.quick-search-item:hover {
    background: #f0f8ff;
}

.quick-search-item.selected {
    background: #1abc9c !important;
    color: white !important;
}

.quick-search-item.selected .quick-search-url {
    color: white !important;
}

.quick-search-item.selected .quick-search-meta {
    color: rgba(255,255,255,0.8) !important;
}

.quick-search-item.selected .material-symbols-outlined {
    color: white !important;
}

.quick-search-item.selected .quick-search-code {
    background: rgba(255,255,255,0.3) !important;
    color: white !important;
}

.quick-search-item .material-symbols-outlined {
    color: #1abc9c;
    font-size: 20px;
    flex-shrink: 0;
}

.quick-search-url {
    flex: 1;
    font-size: 0.95rem;
    color: #2c3e50;
    white-space: nowrap;
    overflow: hidden;
}

.quick-search-meta {
    display: flex;
    gap: 0.75rem;
    font-size: 0.8rem;
    color: #657786;
    flex-shrink: 0;
}

.quick-search-code {
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    font-weight: 600;
    font-size: 0.75rem;
}

.quick-search-code.success { background: #d4edda; color: #155724; }
.quick-search-code.redirect { background: #fff3cd; color: #856404; }
.quick-search-code.error { background: #f8d7da; color: #721c24; }

.quick-search-empty {
    padding: 3rem;
    text-align: center;
    color: #657786;
}

.quick-search-empty .material-symbols-outlined {
    font-size: 48px;
    opacity: 0.3;
    margin-bottom: 0.5rem;
}

.quick-search-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
    gap: 0.75rem;
    color: #657786;
}

.quick-search-hint {
    padding: 0.75rem 1.25rem;
    background: #f8f9fa;
    border-top: 1px solid #e1e8ed;
    font-size: 0.8rem;
    color: #657786;
    display: flex;
    gap: 1.5rem;
}

.quick-search-hint kbd {
    background: #e9ecef;
    border: 1px solid #d0d0d0;
    border-radius: 3px;
    padding: 0.1rem 0.4rem;
    font-size: 0.7rem;
    margin-right: 0.25rem;
}
</style>

<script>
(function() {
    const currentProject = '<?= $projectDir ?? '' ?>';
    let quickSearchTimeout = null;
    let selectedIndex = -1;
    let searchResults = [];
    
    // Ouvrir avec Ctrl+P
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            openQuickSearch();
        }
    });
    
    function openQuickSearch() {
        const modal = document.getElementById('quickSearchModal');
        const input = document.getElementById('quickSearchInput');
        const results = document.getElementById('quickSearchResults');
        
        modal.classList.add('active');
        input.value = '';
        input.focus();
        selectedIndex = -1;
        searchResults = [];
        
        results.innerHTML = '';
    }
    
    function closeQuickSearch() {
        document.getElementById('quickSearchModal').classList.remove('active');
    }
    
    // Fermer avec Escape ou clic en dehors
    document.getElementById('quickSearchModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeQuickSearch();
        }
    });
    
    document.addEventListener('keydown', function(e) {
        const modal = document.getElementById('quickSearchModal');
        if (!modal.classList.contains('active')) return;
        
        if (e.key === 'Escape') {
            closeQuickSearch();
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            navigateResults(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            navigateResults(-1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            selectCurrentResult();
        }
    });
    
    function navigateResults(direction) {
        if (searchResults.length === 0) return;
        
        selectedIndex += direction;
        
        if (selectedIndex < 0) selectedIndex = searchResults.length - 1;
        if (selectedIndex >= searchResults.length) selectedIndex = 0;
        
        updateSelectedResult();
    }
    
    function updateSelectedResult() {
        const items = document.querySelectorAll('.quick-search-item');
        items.forEach((item, index) => {
            item.classList.toggle('selected', index === selectedIndex);
        });
        
        // Scroll vers l'élément sélectionné
        const selectedItem = items[selectedIndex];
        if (selectedItem) {
            selectedItem.scrollIntoView({ block: 'nearest' });
        }
    }
    
    function selectCurrentResult() {
        if (selectedIndex >= 0 && selectedIndex < searchResults.length) {
            const url = searchResults[selectedIndex].url;
            closeQuickSearch();
            openUrlModal(url, currentProject);
        }
    }
    
    // Recherche en temps réel
    document.getElementById('quickSearchInput')?.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        
        if (quickSearchTimeout) {
            clearTimeout(quickSearchTimeout);
        }
        
        if (query.length < 2) {
            document.getElementById('quickSearchResults').innerHTML = '';
            return;
        }
        
        // Ne pas effacer les résultats pendant le chargement (éviter le clignotement)
        
        quickSearchTimeout = setTimeout(() => {
            performQuickSearch(query);
        }, 150);
    });
    
    function performQuickSearch(query) {
        const apiUrl = window.location.pathname.includes('/pages/') 
            ? '../api/query/quick-search' 
            : 'api/query/quick-search';
        
        fetch(`${apiUrl}?project=${encodeURIComponent(currentProject)}&q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    searchResults = data.results;
                    selectedIndex = data.results.length > 0 ? 0 : -1;
                    renderResults(data.results, query);
                } else {
                    document.getElementById('quickSearchResults').innerHTML = `
                        <div class="quick-search-empty">
                            <span class="material-symbols-outlined">error</span>
                            <p>Erreur de recherche</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                document.getElementById('quickSearchResults').innerHTML = `
                    <div class="quick-search-empty">
                        <span class="material-symbols-outlined">error</span>
                        <p>Erreur de connexion</p>
                    </div>
                `;
            });
    }
    
    function renderResults(results, query) {
        const container = document.getElementById('quickSearchResults');
        
        if (results.length === 0) {
            container.innerHTML = `
                <div class="quick-search-empty">
                    <span class="material-symbols-outlined">search_off</span>
                    <p>Aucun résultat pour "${query}"</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        results.forEach((result, index) => {
            const codeClass = result.code >= 200 && result.code < 300 ? 'success' 
                            : result.code >= 300 && result.code < 400 ? 'redirect' 
                            : 'error';
            
            // Extraire seulement le path (sans le domaine)
            let displayUrl = result.url;
            try {
                const urlObj = new URL(result.url);
                displayUrl = urlObj.pathname + urlObj.search + urlObj.hash;
                if (displayUrl === '/') displayUrl = '/';
            } catch(e) {
                displayUrl = result.url;
            }
            
            // Tronquer intelligemment pour montrer le match
            let truncatedUrl = displayUrl;
            const matchIndex = displayUrl.toLowerCase().indexOf(query.toLowerCase());
            const matchEnd = matchIndex + query.length;
            
            // Si l'URL est trop longue et le match n'est pas visible au début
            if (displayUrl.length > 80) {
                if (matchIndex > 60) {
                    // Le match est loin, on coupe le début pour le montrer
                    // On garde max de contexte avant le match
                    const startCut = Math.max(0, matchEnd - 70);
                    truncatedUrl = '...' + displayUrl.substring(startCut);
                }
            }
            
            // Highlight le texte recherché
            const highlightedUrl = truncatedUrl.replace(
                new RegExp(`(${escapeRegex(query)})`, 'gi'),
                '<strong>$1</strong>'
            );
            
            html += `
                <div class="quick-search-item ${index === 0 ? 'selected' : ''}" data-index="${index}" data-url="${escapeHtml(result.url)}">
                    <span class="material-symbols-outlined">link</span>
                    <span class="quick-search-url">${highlightedUrl}</span>
                    <div class="quick-search-meta">
                        <span class="quick-search-code ${codeClass}">${result.code}</span>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
        
        // Event listeners pour le clic
        container.querySelectorAll('.quick-search-item').forEach(item => {
            item.addEventListener('click', function() {
                const url = this.dataset.url;
                closeQuickSearch();
                openUrlModal(url, currentProject);
            });
            
            item.addEventListener('mouseenter', function() {
                selectedIndex = parseInt(this.dataset.index);
                updateSelectedResult();
            });
        });
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
})();
</script>
