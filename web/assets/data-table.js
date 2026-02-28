/**
 * DataTable - Classe JavaScript partagée pour url-table et link-table
 * 
 * Gère la pagination AJAX, le tri, la sélection des colonnes, l'export CSV
 * et la copie du tableau.
 * 
 * @package Scouter
 * @since 2.1.0
 */

class DataTable {
    /**
     * Constructeur
     * 
     * @param {string} componentId - ID unique du composant
     * @param {Object} config - Configuration
     * @param {number} config.totalPages - Nombre total de pages
     * @param {number} config.totalResults - Nombre total de résultats
     * @param {number} config.perPage - Résultats par page
     * @param {number} config.currentPage - Page actuelle
     * @param {boolean} config.isLightMode - Mode light (pas d'AJAX)
     * @param {string} config.tableType - Type de table ('url' ou 'link')
     */
    constructor(componentId, config) {
        this.componentId = componentId;
        this.totalPages = config.totalPages || 1;
        this.totalResults = config.totalResults || 0;
        this.perPage = config.perPage || 100;
        this.currentPage = config.currentPage || 1;
        this.currentPerPage = config.perPage || 100;
        this.currentTotalPages = config.totalPages || 1;
        this.isLightMode = config.isLightMode || false;
        this.tableType = config.tableType || 'url';
        this.isLoading = false;
        this.scrollHandlers = null;
        
        this._init();
    }

    /**
     * Initialisation
     */
    _init() {
        this._initScrollbarSync();
        this._attachCopyHandlers();
        this._attachClickOutsideHandlers();
        
        // Exposer les méthodes globalement pour les onclick inline
        this._exposeGlobalMethods();
    }

    /**
     * Expose les méthodes en global pour compatibilité avec les onclick inline
     */
    _exposeGlobalMethods() {
        const self = this;
        const id = this.componentId;
        
        window['changePage_' + id] = (page) => self.changePage(page);
        window['changePerPage_' + id] = (perPage) => self.changePerPage(perPage);
        window['toggleColumnDropdown_' + id] = () => self.toggleColumnDropdown();
        window['toggleAllColumns_' + id] = (check) => self.toggleAllColumns(check);
        window['applyColumns_' + id] = () => self.applyColumns();
        window['sortByColumn_' + id] = (column) => self.sortByColumn(column);
        window['copyTableToClipboard_' + id] = (event) => self.copyToClipboard(event);
        window['exportToCSV_' + id] = () => self.exportToCSV();
        window['togglePerPageDropdown_' + id] = () => self.togglePerPageDropdown();
        window['selectPerPage_' + id] = (value) => self.changePerPage(value);
        window['showTableScope_' + id] = () => self.showScope();
        window['initScrollbarSync_' + id] = () => self._initScrollbarSync();
        window['reloadTable_' + id] = () => self.reload();
    }

    /**
     * Active/désactive les boutons de pagination pendant le chargement
     */
    _setPaginationLoading(loading) {
        this.isLoading = loading;
        const buttons = document.querySelectorAll(
            '#paginationTop_' + this.componentId + ' button, ' +
            '#paginationBottom_' + this.componentId + ' button'
        );
        buttons.forEach(btn => {
            btn.disabled = loading;
            btn.style.opacity = loading ? '0.5' : '1';
        });
    }

    /**
     * Met à jour l'état des boutons de pagination
     */
    _updatePaginationButtons(page, totalPages) {
        const selectors = ['Top', 'Bottom'];
        
        selectors.forEach(pos => {
            const prev = document.querySelector('#pagination' + pos + '_' + this.componentId + ' button:first-of-type');
            const next = document.querySelector('#pagination' + pos + '_' + this.componentId + ' button:last-of-type');
            
            if (prev) {
                prev.disabled = page <= 1;
                prev.style.opacity = page <= 1 ? '0.5' : '1';
                prev.style.cursor = page <= 1 ? 'default' : 'pointer';
            }
            
            if (next) {
                next.disabled = page >= totalPages;
                next.style.opacity = page >= totalPages ? '0.5' : '1';
                next.style.cursor = page >= totalPages ? 'default' : 'pointer';
            }
        });
    }

    /**
     * Réattache les handlers de pagination après un chargement AJAX
     */
    _attachPaginationHandlers() {
        const self = this;
        const selectors = [
            '#paginationTop_' + this.componentId + ' button:first-of-type',
            '#paginationTop_' + this.componentId + ' button:last-of-type',
            '#paginationBottom_' + this.componentId + ' button:first-of-type',
            '#paginationBottom_' + this.componentId + ' button:last-of-type'
        ];
        
        selectors.forEach((selector, index) => {
            const btn = document.querySelector(selector);
            if (btn) {
                const newBtn = btn.cloneNode(true);
                btn.parentNode.replaceChild(newBtn, btn);
                
                const isFirst = index % 2 === 0;
                newBtn.addEventListener('click', () => {
                    if (isFirst && self.currentPage > 1) {
                        self.changePage(self.currentPage - 1);
                    } else if (!isFirst && self.currentPage < self.currentTotalPages) {
                        self.changePage(self.currentPage + 1);
                    }
                });
            }
        });
        
        this._updatePaginationButtons(this.currentPage, this.currentTotalPages);
    }

    /**
     * Change de page
     */
    changePage(page) {
        if (page < 1 || page === this.currentPage || this.isLoading) return;
        
        this.currentPage = page;
        const params = new URLSearchParams(window.location.search);
        const pageParam = (this.componentId === 'main_explorer') ? 'p' : 'p_' + this.componentId;
        params.set(pageParam, page);
        
        if (this.isLightMode) {
            window.location.href = window.location.pathname + '?' + params.toString();
            return;
        }
        
        this._setPaginationLoading(true);
        
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({ page: page }, '', newUrl);
        
        this._fetchAndUpdate(newUrl, 'tableContainer');
    }

    /**
     * Change le nombre de résultats par page
     */
    changePerPage(newPerPage) {
        const params = new URLSearchParams(window.location.search);
        const perPageParam = (this.componentId === 'main_explorer') ? 'per_page' : 'per_page_' + this.componentId;
        const pageParam = (this.componentId === 'main_explorer') ? 'p' : 'p_' + this.componentId;
        
        params.set(perPageParam, newPerPage);
        params.set(pageParam, 1);
        
        if (this.isLightMode) {
            window.location.href = window.location.pathname + '?' + params.toString();
            return;
        }
        
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);
        
        this._fetchAndUpdate(newUrl, 'tableCard', () => {
            this.currentPage = 1;
            this.currentPerPage = newPerPage;
            this.currentTotalPages = Math.ceil(this.totalResults / newPerPage);
            this._attachPaginationHandlers();
        });
    }

    /**
     * Tri par colonne
     */
    sortByColumn(column) {
        const params = new URLSearchParams(window.location.search);
        const sortParam = (this.componentId === 'main_explorer') ? 'sort' : 'sort_' + this.componentId;
        const dirParam = (this.componentId === 'main_explorer') ? 'dir' : 'dir_' + this.componentId;
        const pageParam = (this.componentId === 'main_explorer') ? 'p' : 'p_' + this.componentId;
        
        const currentSort = params.get(sortParam);
        const currentDir = params.get(dirParam) || 'ASC';
        
        if (currentSort === column) {
            params.set(dirParam, currentDir === 'ASC' ? 'DESC' : 'ASC');
        } else {
            params.set(sortParam, column);
            params.set(dirParam, 'ASC');
        }
        
        params.set(pageParam, 1);
        
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);
        
        if (typeof window['reloadTable_' + this.componentId] === 'function' && 
            window['reloadTable_' + this.componentId] !== this.reload.bind(this)) {
            window['reloadTable_' + this.componentId]();
            return;
        }
        
        this._fetchAndUpdate(newUrl, 'tableCard');
    }

    /**
     * Applique les colonnes sélectionnées
     */
    applyColumns() {
        const checkboxes = document.querySelectorAll('.column-checkbox-' + this.componentId + ':checked');
        const columns = Array.from(checkboxes).map(cb => cb.value);
        
        const params = new URLSearchParams(window.location.search);
        const columnsParam = (this.componentId === 'main_explorer') ? 'columns' : 'columns_' + this.componentId;
        params.set(columnsParam, columns.join(','));
        
        const dropdown = document.getElementById('columnDropdown_' + this.componentId);
        if (dropdown) dropdown.style.display = 'none';
        
        if (this.isLightMode) {
            window.location.href = window.location.pathname + '?' + params.toString();
            return;
        }
        
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);
        
        this._fetchAndUpdate(newUrl, 'tableCard', () => {
            const newDropdown = document.getElementById('columnDropdown_' + this.componentId);
            if (newDropdown) newDropdown.style.display = 'none';
            
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus('✓ Colonnes mises à jour', 'success');
            }
        });
    }

    /**
     * Recharge le tableau
     */
    reload() {
        const url = window.location.pathname + window.location.search;
        this._fetchAndUpdate(url, 'tableCard');
    }

    /**
     * Fetch et mise à jour du DOM
     */
    _fetchAndUpdate(url, targetType, callback) {
        const self = this;
        
        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            let selector, target;
            if (targetType === 'tableCard') {
                selector = '#tableCard_' + self.componentId;
            } else {
                selector = '#tableContainer_' + self.componentId;
            }
            
            const newElement = doc.querySelector(selector);
            target = document.querySelector(selector);
            
            if (newElement && target) {
                target.innerHTML = newElement.innerHTML;
            }
            
            // Réactiver les handlers
            self.isLoading = false;
            self._updatePaginationButtons(self.currentPage, self.currentTotalPages);
            
            if (typeof refreshUrlModalHandlers === 'function') {
                refreshUrlModalHandlers();
            }
            
            self._initScrollbarSync();
            
            if (callback) callback();
        })
        .catch(error => {
            console.error('DataTable error:', error);
            self.isLoading = false;
            self._updatePaginationButtons(self.currentPage, self.currentTotalPages);
        });
    }

    /**
     * Toggle dropdown colonnes
     */
    toggleColumnDropdown() {
        const dropdown = document.getElementById('columnDropdown_' + this.componentId);
        if (dropdown.style.display === 'none' || dropdown.style.display === '') {
            dropdown.style.display = 'flex';
            dropdown.classList.add('show');
        } else {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
        }
    }

    /**
     * Tout cocher / décocher les colonnes
     */
    toggleAllColumns(check) {
        const checkboxes = document.querySelectorAll('.column-checkbox-' + this.componentId);
        checkboxes.forEach(checkbox => {
            if (!checkbox.disabled) {
                checkbox.checked = check;
            }
        });
    }

    /**
     * Toggle dropdown perPage
     */
    togglePerPageDropdown() {
        const dropdown = document.getElementById('perPageDropdown_' + this.componentId);
        const button = document.getElementById('perPageBtn_' + this.componentId);
        const icon = button?.querySelector('.material-symbols-outlined');
        
        if (dropdown.style.display === 'none' || dropdown.style.display === '') {
            dropdown.style.display = 'block';
            dropdown.classList.add('show');
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    }

    /**
     * Copie le tableau dans le presse-papiers
     */
    copyToClipboard(event) {
        const tableId = this.tableType === 'link' ? 'linkTable_' : 'urlTable_';
        const table = document.getElementById(tableId + this.componentId);
        let text = '';
        
        const getCleanText = (cell) => {
            const clone = cell.cloneNode(true);
            const icons = clone.querySelectorAll('.material-symbols-outlined');
            icons.forEach(icon => icon.remove());
            
            if (cell.querySelector('.material-symbols-outlined')) {
                const icon = cell.querySelector('.material-symbols-outlined');
                const color = icon.style.color || window.getComputedStyle(icon).color;
                if (color.includes('107, 216, 153') || color.includes('#6bd899')) {
                    return 'Oui';
                } else if (color.includes('149, 165, 166') || color.includes('#95a5a6')) {
                    return 'Non';
                }
            }
            
            let cleanText = clone.textContent.trim().replace(/\s+/g, ' ');
            return cleanText === '—' ? '' : cleanText;
        };
        
        const headers = table.querySelectorAll('thead th');
        text += Array.from(headers).map(th => getCleanText(th)).join('\t') + '\n';
        
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            text += Array.from(cells).map(td => getCleanText(td)).join('\t') + '\n';
        });
        
        navigator.clipboard.writeText(text).then(() => {
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus('✓ Tableau copié', 'success');
            }
        }).catch(err => {
            console.error('Copy error:', err);
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus('Erreur lors de la copie', 'error');
            }
        });
    }

    /**
     * Export CSV
     */
    exportToCSV() {
        const selectedCols = [];
        document.querySelectorAll('.column-checkbox-' + this.componentId + ':checked').forEach(cb => {
            selectedCols.push(cb.value);
        });
        
        const params = new URLSearchParams(window.location.search);
        const form = document.getElementById('exportForm_' + this.componentId);
        
        form.querySelector('[name="filters"]').value = params.get('filters') || '';
        form.querySelector('[name="search"]').value = params.get('search') || '';
        document.getElementById('exportColumns_' + this.componentId).value = JSON.stringify(selectedCols);
        form.submit();
    }

    /**
     * Affiche la modale de scope
     */
    showScope() {
        if (typeof openScopeModal === 'function' && this.scopeConfig) {
            openScopeModal(this.scopeConfig);
        }
    }

    /**
     * Configure le scope pour la modale
     */
    setScopeConfig(config) {
        this.scopeConfig = config;
    }

    /**
     * Initialise la synchronisation des scrollbars horizontales
     */
    _initScrollbarSync() {
        const topScrollbar = document.getElementById('topScrollbar_' + this.componentId);
        const tableContainer = document.getElementById('tableContainer_' + this.componentId);
        const topScrollbarContent = document.getElementById('topScrollbarContent_' + this.componentId);
        const tableId = this.tableType === 'link' ? 'linkTable_' : 'urlTable_';
        const table = document.getElementById(tableId + this.componentId);

        if (!topScrollbar || !tableContainer || !topScrollbarContent || !table) {
            return;
        }

        topScrollbarContent.style.width = table.offsetWidth + 'px';
        
        setTimeout(() => {
            topScrollbarContent.style.width = table.offsetWidth + 'px';
        }, 100);

        // Retirer les anciens handlers
        if (this.scrollHandlers) {
            topScrollbar.removeEventListener('scroll', this.scrollHandlers.topHandler);
            tableContainer.removeEventListener('scroll', this.scrollHandlers.tableHandler);
        }

        const self = this;
        const topHandler = function() {
            const tc = document.getElementById('tableContainer_' + self.componentId);
            if (tc) tc.scrollLeft = this.scrollLeft;
        };

        const tableHandler = function() {
            const ts = document.getElementById('topScrollbar_' + self.componentId);
            if (ts) ts.scrollLeft = this.scrollLeft;
        };

        topScrollbar.addEventListener('scroll', topHandler);
        tableContainer.addEventListener('scroll', tableHandler);

        this.scrollHandlers = { topHandler, tableHandler };
    }

    /**
     * Attache les handlers pour copier le chemin
     */
    _attachCopyHandlers() {
        const self = this;
        
        if (window['copyHandlersAttached_' + this.componentId]) return;
        
        document.addEventListener('click', function(e) {
            const copyBtn = e.target.closest('.copy-path-btn');
            if (copyBtn) {
                const tableCard = document.getElementById('tableCard_' + self.componentId);
                if (tableCard && tableCard.contains(copyBtn)) {
                    e.preventDefault();
                    e.stopPropagation();
                    const path = copyBtn.dataset.path;
                    if (path) {
                        navigator.clipboard.writeText(path).then(() => {
                            if (typeof showGlobalStatus === 'function') {
                                showGlobalStatus('Chemin copié : ' + path, 'success');
                            }
                        }).catch(err => {
                            console.error('Copy error:', err);
                        });
                    }
                }
            }
        });
        
        window['copyHandlersAttached_' + this.componentId] = true;
    }

    /**
     * Attache les handlers pour fermer les dropdowns au clic extérieur
     */
    _attachClickOutsideHandlers() {
        const self = this;
        
        document.addEventListener('click', function(e) {
            // Column dropdown
            const colDropdown = document.getElementById('columnDropdown_' + self.componentId);
            const colButton = e.target.closest('[onclick*="toggleColumnDropdown_' + self.componentId + '"]');
            
            if (!colButton && colDropdown && !colDropdown.contains(e.target)) {
                colDropdown.style.display = 'none';
                colDropdown.classList.remove('show');
            }
            
            // PerPage dropdown
            const ppDropdown = document.getElementById('perPageDropdown_' + self.componentId);
            const ppButton = document.getElementById('perPageBtn_' + self.componentId);
            
            if (ppButton && !ppButton.contains(e.target) && ppDropdown && !ppDropdown.contains(e.target)) {
                ppDropdown.style.display = 'none';
                ppDropdown.classList.remove('show');
                const icon = ppButton.querySelector('.material-symbols-outlined');
                if (icon) icon.style.transform = 'rotate(0deg)';
            }
        });
    }
}

// Export pour utilisation en module ou global
if (typeof module !== 'undefined' && module.exports) {
    module.exports = DataTable;
} else {
    window.DataTable = DataTable;
}
