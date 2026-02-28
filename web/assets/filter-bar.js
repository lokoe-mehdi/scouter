/**
 * FilterBar - Classe JavaScript partagée pour les barres de filtres
 * Utilisé par url-explorer et link-explorer
 * 
 * @package Scouter
 * @since 2.1.0
 */

class FilterBar {
    /**
     * Constructeur
     * 
     * @param {Object} config - Configuration
     * @param {Object} config.fieldConfig - Configuration des champs disponibles
     * @param {Array} config.availableCategories - Catégories disponibles
     * @param {Array} config.availableSchemas - Schemas disponibles
     * @param {Array} config.initialFilters - Filtres initiaux depuis l'URL
     * @param {Function} config.onApply - Callback lors de l'application des filtres
     * @param {boolean} config.hasTarget - Si true, affiche le sélecteur source/target (link-explorer)
     */
    constructor(config) {
        this.fieldConfig = config.fieldConfig || {};
        this.availableCategories = config.availableCategories || [];
        this.availableSchemas = config.availableSchemas || [];
        this.onApply = config.onApply || (() => {});
        this.hasTarget = config.hasTarget || false;
        
        // Labels
        this.operatorLabels = {
            'contains': 'contient', 'not_contains': 'ne contient pas',
            'regex': 'correspond à la regex', 'not_regex': 'ne correspond pas à la regex',
            '=': '=', '>': '>', '<': '<', '>=': '≥', '<=': '≤', '!=': '≠',
            'in': 'est', 'not_in': "n'est pas"
        };
        
        this.seoValueLabels = { 'unique': 'Unique', 'empty': 'Vide', 'duplicate': 'Dupliqué' };
        this.httpCodeLabels = { '1xx': '1xx (100-199)', '2xx': '2xx (200-299)', '3xx': '3xx (300-399)', '4xx': '4xx (400-499)', '5xx': '5xx (500-599)', 'other': 'Autre' };
        this.boolLabels = { 'true': 'Oui', 'false': 'Non' };
        
        // État
        this.filterGroups = [];
        this.pendingFilterConfig = null;
        this.editingChipIndex = null;
        
        // Convertir les filtres initiaux
        if (config.initialFilters && config.initialFilters.length > 0) {
            this.filterGroups = this._convertOldFiltersToNew(config.initialFilters);
        }
        
        this._init();
    }

    /**
     * Initialisation
     */
    _init() {
        this.renderChips();
        this._exposeGlobalMethods();
    }

    /**
     * Expose les méthodes en global pour les onclick inline
     */
    _exposeGlobalMethods() {
        const self = this;
        
        window.openFieldSelector = (event) => self.openFieldSelector(event);
        window.selectField = (field, target) => self.selectField(field, target);
        window.closeAllPopovers = () => self.closeAllPopovers();
        window.clearFilters = () => self.clearFilters();
        window.removeChip = (groupIndex, chipIndex) => self.removeChip(groupIndex, chipIndex);
        window.editChip = (groupIndex, chipIndex, event) => self.editChip(groupIndex, chipIndex, event);
        window.addOrToChip = (groupIndex, event) => self.addOrToChip(groupIndex, event);
        window.addOrToGroup = (groupIndex, event) => self.addOrToGroup(groupIndex, event);
        window.applyFilterConfig = () => self.applyFilterConfig();
        window.toggleStyledSelect = (btn) => self.toggleStyledSelect(btn);
        window.selectStyledOption = (item, inputId) => self.selectStyledOption(item, inputId);
        window.toggleSeoMode = (mode) => self.toggleSeoMode(mode);
        window.toggleHttpCodeMode = (mode) => self.toggleHttpCodeMode(mode);
        window.toggleSchemasMode = (mode) => self.toggleSchemasMode(mode);
        window.selectTarget = (target) => self.selectTarget(target);
        window.addSelfLinkFilter = () => self.addSelfLinkFilter();
    }

    /**
     * Convertit l'ancien format de filtres vers le nouveau
     */
    _convertOldFiltersToNew(oldFilters) {
        const groups = [];
        oldFilters.forEach(item => {
            if (item.type === 'group' && item.items) {
                if (item.logic === 'OR') {
                    const chips = item.items.filter(i => i.field).map(i => ({
                        field: i.field, 
                        operator: i.operator || '=', 
                        value: i.value,
                        target: i.target || 'source'
                    }));
                    if (chips.length > 0) groups.push(chips);
                } else {
                    item.items.filter(i => i.field).forEach(i => {
                        groups.push([{ 
                            field: i.field, 
                            operator: i.operator || '=', 
                            value: i.value,
                            target: i.target || 'source'
                        }]);
                    });
                }
            } else if (item.field) {
                groups.push([{ 
                    field: item.field, 
                    operator: item.operator || '=', 
                    value: item.value,
                    target: item.target || 'source'
                }]);
            }
        });
        return groups;
    }

    /**
     * Rend les chips de filtres
     */
    renderChips() {
        const container = document.getElementById('filterChipsContainer');
        if (!container) return;
        
        container.innerHTML = '';
        
        this.filterGroups.forEach((group, groupIndex) => {
            if (groupIndex > 0) {
                const andSep = document.createElement('span');
                andSep.className = 'chip-and-separator';
                andSep.textContent = 'et';
                container.appendChild(andSep);
            }
            
            if (group.length === 1) {
                container.appendChild(this._createChipElement(group[0], groupIndex, 0));
            } else {
                const chipGroup = document.createElement('div');
                chipGroup.className = 'chip-group';
                group.forEach((chip, chipIndex) => {
                    if (chipIndex > 0) {
                        const orConn = document.createElement('span');
                        orConn.className = 'chip-or-connector';
                        orConn.textContent = 'ou';
                        chipGroup.appendChild(orConn);
                    }
                    chipGroup.appendChild(this._createChipElement(chip, groupIndex, chipIndex));
                });
                container.appendChild(chipGroup);
            }
        });
        
        const clearBtn = document.getElementById('btnClearAll');
        if (clearBtn) {
            clearBtn.style.display = this.filterGroups.length > 0 ? 'flex' : 'none';
        }
    }

    /**
     * Crée un élément chip
     */
    _createChipElement(chip, groupIndex, chipIndex) {
        const el = document.createElement('div');
        el.className = 'filter-chip';
        el.onclick = (e) => {
            if (!e.target.classList.contains('chip-remove') && !e.target.classList.contains('chip-add-or')) {
                this.editChip(groupIndex, chipIndex, e);
            }
        };
        
        const config = this.fieldConfig[chip.field] || { label: chip.field };
        let displayValue = this._formatChipValue(chip);
        
        // Badge target pour link-explorer
        let targetBadge = '';
        if (this.hasTarget && chip.target) {
            const targetClass = chip.target === 'link' ? 'link' : chip.target;
            const targetLabel = chip.target === 'link' ? 'LIEN' : (chip.target === 'source' ? 'SRC' : 'TGT');
            targetBadge = `<span class="chip-target ${targetClass}">${targetLabel}</span>`;
        }
        
        el.innerHTML = `
            ${targetBadge}
            <span class="chip-field">${config.label}</span>
            <span class="chip-value">${displayValue}</span>
            <span class="chip-remove material-symbols-outlined" onclick="event.stopPropagation(); removeChip(${groupIndex}, ${chipIndex})">close</span>
            <span class="chip-add-or" onclick="event.stopPropagation(); addOrToChip(${groupIndex}, event)">
                <span class="material-symbols-outlined">add</span>
            </span>
        `;
        return el;
    }

    /**
     * Formate la valeur d'une chip pour l'affichage
     */
    _formatChipValue(chip) {
        const config = this.fieldConfig[chip.field];
        if (!config) return chip.value;
        
        if (config.type === 'boolean') {
            return this.boolLabels[chip.value] || chip.value;
        } else if (config.type === 'seo') {
            if (chip.operator && ['contains', 'not_contains', 'regex', 'not_regex'].includes(chip.operator)) {
                const op = this.operatorLabels[chip.operator] || '';
                return `${op} "${chip.value}"`;
            }
            if (Array.isArray(chip.value)) {
                const labels = chip.value.map(v => this.seoValueLabels[v] || v);
                return labels.length > 2 ? labels.slice(0,2).join(' / ') + '...' : labels.join(' / ');
            }
            return this.seoValueLabels[chip.value] || chip.value;
        } else if (config.type === 'http_code') {
            if (chip.operator && ['=', '>', '<', '>=', '<=', '!='].includes(chip.operator)) {
                const op = this.operatorLabels[chip.operator] || '=';
                return `${op} ${chip.value}`;
            }
            if (Array.isArray(chip.value)) {
                const labels = chip.value.map(v => v);
                return labels.length > 3 ? labels.slice(0,3).join(' / ') + '...' : labels.join(' / ');
            }
            return chip.value;
        } else if (config.type === 'category') {
            if (Array.isArray(chip.value)) {
                const names = chip.value.map(id => {
                    const cat = this.availableCategories.find(c => c.id == id);
                    return cat ? cat.cat : id;
                });
                const prefix = chip.operator === 'not_in' ? '≠ ' : '';
                return prefix + (names.length > 2 ? names.slice(0,2).join(', ') + '...' : names.join(', '));
            }
            return chip.value;
        } else if (config.type === 'text') {
            const op = this.operatorLabels[chip.operator] || '';
            return `${op} "${chip.value}"`;
        } else if (config.type === 'number') {
            const op = this.operatorLabels[chip.operator] || '=';
            return `${op} ${chip.value}`;
        }
        return chip.value;
    }

    /**
     * Ouvre le sélecteur de champ
     */
    openFieldSelector(event) {
        event.stopPropagation();
        this.closeAllPopovers();
        this.editingChipIndex = null;
        this.pendingFilterConfig = { addToGroup: null };
        
        const popover = document.getElementById('fieldSelectorPopover');
        this._positionPopover(popover, event.currentTarget);
        popover.classList.add('active');
        document.getElementById('popoverOverlay').classList.add('active');
    }

    /**
     * Sélectionne un champ pour le filtre
     */
    selectField(field, target = null) {
        this.closeAllPopovers();
        this.pendingFilterConfig = { 
            ...this.pendingFilterConfig, 
            field,
            target: target || (this.hasTarget ? 'source' : null)
        };
        this._openConfigPopover(field);
    }

    /**
     * Ajoute un filtre OU à un groupe existant
     */
    addOrToGroup(groupIndex, event) {
        event.stopPropagation();
        this.closeAllPopovers();
        this.editingChipIndex = null;
        this.pendingFilterConfig = { addToGroup: groupIndex };
        
        const popover = document.getElementById('fieldSelectorPopover');
        this._positionPopover(popover, event.currentTarget);
        popover.classList.add('active');
        document.getElementById('popoverOverlay').classList.add('active');
    }

    /**
     * Ajoute un filtre OU depuis une chip
     */
    addOrToChip(groupIndex, event) {
        event.stopPropagation();
        this.closeAllPopovers();
        this.editingChipIndex = null;
        this.pendingFilterConfig = { addToGroup: groupIndex };
        
        const popover = document.getElementById('fieldSelectorPopover');
        this._positionPopover(popover, event.currentTarget);
        popover.classList.add('active');
        document.getElementById('popoverOverlay').classList.add('active');
    }

    /**
     * Édite une chip existante
     */
    editChip(groupIndex, chipIndex, event) {
        event.stopPropagation();
        this.closeAllPopovers();
        
        const chip = this.filterGroups[groupIndex][chipIndex];
        this.editingChipIndex = { groupIndex, chipIndex };
        this.pendingFilterConfig = { 
            field: chip.field, 
            addToGroup: null,
            target: chip.target 
        };
        
        this._openConfigPopover(chip.field, chip);
    }

    /**
     * Supprime une chip
     */
    removeChip(groupIndex, chipIndex) {
        this.filterGroups[groupIndex].splice(chipIndex, 1);
        if (this.filterGroups[groupIndex].length === 0) {
            this.filterGroups.splice(groupIndex, 1);
        }
        this.renderChips();
        this.applyFilters();
    }

    /**
     * Efface tous les filtres
     */
    clearFilters() {
        this.filterGroups = [];
        this.renderChips();
        this.applyFilters();
    }

    /**
     * Ferme tous les popovers
     */
    closeAllPopovers() {
        document.querySelectorAll('.filter-popover').forEach(p => p.classList.remove('active'));
        document.getElementById('popoverOverlay')?.classList.remove('active');
        document.querySelectorAll('.styled-select-menu').forEach(m => m.classList.remove('show'));
    }

    /**
     * Positionne un popover près d'un élément
     */
    _positionPopover(popover, anchor) {
        const rect = anchor.getBoundingClientRect();
        popover.style.position = 'fixed';
        popover.style.top = (rect.bottom + 8) + 'px';
        popover.style.left = rect.left + 'px';
        
        // Ajuster si hors écran
        setTimeout(() => {
            const popoverRect = popover.getBoundingClientRect();
            if (popoverRect.right > window.innerWidth - 20) {
                popover.style.left = (window.innerWidth - popoverRect.width - 20) + 'px';
            }
            if (popoverRect.bottom > window.innerHeight - 20) {
                popover.style.top = (rect.top - popoverRect.height - 8) + 'px';
            }
        }, 0);
    }

    /**
     * Toggle styled select
     */
    toggleStyledSelect(btn) {
        const menu = btn.nextElementSibling;
        const isOpen = menu.classList.contains('show');
        
        // Fermer tous les autres
        document.querySelectorAll('.styled-select-menu').forEach(m => m.classList.remove('show'));
        
        if (!isOpen) {
            menu.classList.add('show');
        }
    }

    /**
     * Sélectionne une option dans un styled select
     */
    selectStyledOption(item, inputId) {
        const value = item.dataset.value;
        const input = document.getElementById(inputId);
        const wrapper = item.closest('.styled-select-wrapper');
        const btn = wrapper.querySelector('.styled-select-btn .select-value');
        
        input.value = value;
        btn.textContent = item.textContent;
        
        // Mettre à jour la classe active
        item.closest('.styled-select-menu').querySelectorAll('.styled-select-item').forEach(i => {
            i.classList.toggle('active', i === item);
        });
        
        item.closest('.styled-select-menu').classList.remove('show');
    }

    /**
     * Toggle mode SEO (status vs value)
     */
    toggleSeoMode(mode) {
        document.getElementById('seoStatusMode').style.display = mode === 'status' ? '' : 'none';
        document.getElementById('seoValueMode').style.display = mode === 'value' ? '' : 'none';
    }

    /**
     * Toggle mode HTTP code (group vs value)
     */
    toggleHttpCodeMode(mode) {
        document.getElementById('httpCodeGroupMode').style.display = mode === 'group' ? '' : 'none';
        document.getElementById('httpCodeValueMode').style.display = mode === 'value' ? '' : 'none';
    }

    /**
     * Toggle mode schemas (count vs contains)
     */
    toggleSchemasMode(mode) {
        document.getElementById('schemasCountMode').style.display = mode === 'count' ? '' : 'none';
        document.getElementById('schemasContainsMode').style.display = mode === 'contains' ? '' : 'none';
    }

    /**
     * Sélectionne le target (source/target) pour link-explorer
     */
    selectTarget(target) {
        this.pendingFilterConfig.target = target;
        document.querySelectorAll('.target-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.target === target);
        });
    }

    /**
     * Ajoute un filtre self-link (link-explorer)
     */
    addSelfLinkFilter() {
        this.closeAllPopovers();
        this.filterGroups.push([{ field: 'self_link', operator: '=', value: 'true', target: 'link' }]);
        this.renderChips();
        this.applyFilters();
    }

    /**
     * Applique la configuration du filtre
     */
    applyFilterConfig() {
        const field = this.pendingFilterConfig.field;
        const config = this.fieldConfig[field];
        
        let operator = null;
        let value = null;
        
        // Récupérer les valeurs selon le type
        if (config.type === 'text') {
            operator = document.getElementById('configOperator')?.value || 'contains';
            value = document.getElementById('configValue')?.value || '';
        } else if (config.type === 'number') {
            operator = document.getElementById('configOperator')?.value || '=';
            value = document.getElementById('configValue')?.value || '';
        } else if (config.type === 'boolean') {
            value = document.getElementById('configValue')?.value || 'true';
        } else if (config.type === 'http_code') {
            const mode = document.getElementById('configFilterMode')?.value || 'group';
            if (mode === 'value') {
                operator = document.getElementById('configOperator')?.value || '=';
                value = document.getElementById('configValue')?.value || '';
            } else {
                const checked = document.querySelectorAll('.httpcode-checkbox:checked');
                value = Array.from(checked).map(cb => cb.value);
                if (value.length === 0) value = ['2xx'];
            }
        } else if (config.type === 'seo') {
            const mode = document.getElementById('configFilterMode')?.value || 'status';
            if (mode === 'value') {
                operator = document.getElementById('configSeoOperator')?.value || 'contains';
                value = document.getElementById('configSeoValue')?.value || '';
            } else {
                const checked = document.querySelectorAll('.seo-checkbox:checked');
                value = Array.from(checked).map(cb => cb.value);
                if (value.length === 0) value = ['empty'];
            }
        } else if (config.type === 'category') {
            operator = document.getElementById('configOperator')?.value || 'in';
            const checked = document.querySelectorAll('.category-checkbox:checked');
            value = Array.from(checked).map(cb => cb.value);
            if (value.length === 0) {
                this.closeAllPopovers();
                return;
            }
        } else if (config.type === 'schemas') {
            const mode = document.getElementById('configSchemasMode')?.value || 'count';
            if (mode === 'count') {
                operator = document.getElementById('configSchemasOperator')?.value || '>';
                value = document.getElementById('configSchemasCount')?.value || '0';
            } else {
                operator = document.getElementById('configSchemasContainsOp')?.value || 'contains';
                const checked = document.querySelectorAll('.schema-checkbox:checked');
                value = Array.from(checked).map(cb => cb.value);
            }
        }
        
        const chip = { field, operator, value, target: this.pendingFilterConfig.target };
        
        // Édition ou ajout
        if (this.editingChipIndex !== null) {
            this.filterGroups[this.editingChipIndex.groupIndex][this.editingChipIndex.chipIndex] = chip;
        } else if (this.pendingFilterConfig.addToGroup !== null) {
            this.filterGroups[this.pendingFilterConfig.addToGroup].push(chip);
        } else {
            this.filterGroups.push([chip]);
        }
        
        this.closeAllPopovers();
        this.renderChips();
        this.applyFilters();
    }

    /**
     * Applique les filtres (mise à jour de l'URL et rechargement)
     */
    applyFilters() {
        // Convertir les groupes en format URL
        const filtersForUrl = this.filterGroups.map(group => ({
            type: 'group',
            logic: 'OR',
            items: group.map(chip => ({
                field: chip.field,
                operator: chip.operator,
                value: chip.value,
                target: chip.target
            }))
        }));
        
        const params = new URLSearchParams(window.location.search);
        
        if (filtersForUrl.length > 0) {
            params.set('filters', JSON.stringify(filtersForUrl));
        } else {
            params.delete('filters');
        }
        
        // Reset page
        params.set('p', 1);
        
        // Callback ou reload
        if (this.onApply) {
            this.onApply(params.toString());
        } else {
            window.location.href = window.location.pathname + '?' + params.toString();
        }
    }

    /**
     * Ouvre le popover de configuration
     * Cette méthode génère le HTML dynamiquement selon le type de champ
     */
    _openConfigPopover(field, existingChip = null) {
        const config = this.fieldConfig[field];
        const popover = document.getElementById('filterConfigPopover');
        const content = document.getElementById('popoverConfigContent');
        document.getElementById('configPopoverTitle').textContent = config.label;
        
        let html = this._generateConfigHtml(field, config, existingChip);
        
        // Ajouter les boutons d'action
        html += `
            <div class="popover-actions">
                <button class="popover-btn popover-btn-primary" onclick="applyFilterConfig()">Appliquer</button>
                <button class="popover-btn popover-btn-secondary" onclick="closeAllPopovers()">Annuler</button>
            </div>
        `;
        
        content.innerHTML = html;
        
        // Positionner
        const lastChip = document.querySelector('.filter-chip:last-child') || document.querySelector('.btn-add-filter');
        this._positionPopover(popover, lastChip);
        popover.classList.add('active');
        document.getElementById('popoverOverlay').classList.add('active');
    }

    /**
     * Génère le HTML de configuration selon le type
     */
    _generateConfigHtml(field, config, existingChip) {
        // Target selector pour link-explorer
        let targetHtml = '';
        if (this.hasTarget && config.type !== 'boolean' && !['anchor', 'external', 'link_nofollow', 'type', 'self_link'].includes(field)) {
            const currentTarget = existingChip?.target || this.pendingFilterConfig?.target || 'source';
            targetHtml = `
                <div class="popover-row">
                    <label class="popover-label">Appliquer sur</label>
                    <div class="target-selector">
                        <button type="button" class="target-btn source ${currentTarget === 'source' ? 'active' : ''}" data-target="source" onclick="selectTarget('source')">Page source</button>
                        <button type="button" class="target-btn target ${currentTarget === 'target' ? 'active' : ''}" data-target="target" onclick="selectTarget('target')">Page cible</button>
                    </div>
                </div>
            `;
        }
        
        let html = targetHtml;
        
        if (config.type === 'text') {
            html += this._generateTextConfigHtml(config, existingChip);
        } else if (config.type === 'number') {
            html += this._generateNumberConfigHtml(config, existingChip);
        } else if (config.type === 'boolean') {
            html += this._generateBooleanConfigHtml(config, existingChip);
        } else if (config.type === 'http_code') {
            html += this._generateHttpCodeConfigHtml(config, existingChip);
        } else if (config.type === 'seo') {
            html += this._generateSeoConfigHtml(config, existingChip);
        } else if (config.type === 'category') {
            html += this._generateCategoryConfigHtml(config, existingChip);
        } else if (config.type === 'schemas') {
            html += this._generateSchemasConfigHtml(config, existingChip);
        }
        
        return html;
    }

    _generateTextConfigHtml(config, existingChip) {
        const op = existingChip?.operator || 'contains';
        const val = existingChip?.value || '';
        const opLabels = {
            'contains': 'Contient',
            'not_contains': 'Ne contient pas',
            'regex': 'Correspond à la regex',
            'not_regex': 'Ne correspond pas à la regex'
        };
        
        return `
            <div class="popover-row">
                <label class="popover-label">Condition</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configOperator" value="${op}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${opLabels[op]}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        ${Object.entries(opLabels).map(([k, v]) => 
                            `<div class="styled-select-item ${op === k ? 'active' : ''}" data-value="${k}" onclick="selectStyledOption(this, 'configOperator')">${v}</div>`
                        ).join('')}
                    </div>
                </div>
            </div>
            <div class="popover-row">
                <label class="popover-label">Valeur</label>
                <input type="text" class="popover-input" id="configValue" placeholder="Texte ou regex..." value="${val}">
            </div>
        `;
    }

    _generateNumberConfigHtml(config, existingChip) {
        const op = existingChip?.operator || '=';
        const val = existingChip?.value || '';
        
        return `
            <div class="popover-row">
                <label class="popover-label">Opérateur</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configOperator" value="${op}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${this.operatorLabels[op]}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        ${config.operators.map(o => 
                            `<div class="styled-select-item ${op === o ? 'active' : ''}" data-value="${o}" onclick="selectStyledOption(this, 'configOperator')">${this.operatorLabels[o]}</div>`
                        ).join('')}
                    </div>
                </div>
            </div>
            <div class="popover-row">
                <label class="popover-label">Valeur</label>
                <input type="number" class="popover-input" id="configValue" placeholder="Nombre..." value="${val}">
            </div>
        `;
    }

    _generateBooleanConfigHtml(config, existingChip) {
        const val = existingChip?.value || 'true';
        
        return `
            <div class="popover-row">
                <label class="popover-label">Valeur</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configValue" value="${val}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${val === 'true' ? 'Oui' : 'Non'}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${val === 'true' ? 'active' : ''}" data-value="true" onclick="selectStyledOption(this, 'configValue')">Oui</div>
                        <div class="styled-select-item ${val === 'false' ? 'active' : ''}" data-value="false" onclick="selectStyledOption(this, 'configValue')">Non</div>
                    </div>
                </div>
            </div>
        `;
    }

    _generateHttpCodeConfigHtml(config, existingChip) {
        const isValueMode = existingChip?.operator && ['=', '>', '<', '>=', '<=', '!='].includes(existingChip.operator);
        const filterMode = isValueMode ? 'value' : 'group';
        const selectedValues = !isValueMode && Array.isArray(existingChip?.value) ? existingChip.value : ['2xx'];
        const op = existingChip?.operator || '=';
        const numVal = isValueMode ? existingChip?.value || '' : '';
        
        return `
            <div class="popover-row">
                <label class="popover-label">Filtrer par</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configFilterMode" value="${filterMode}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${filterMode === 'group' ? 'Groupe de codes' : 'Valeur exacte'}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${filterMode === 'group' ? 'active' : ''}" data-value="group" onclick="selectStyledOption(this, 'configFilterMode'); toggleHttpCodeMode('group')">Groupe de codes</div>
                        <div class="styled-select-item ${filterMode === 'value' ? 'active' : ''}" data-value="value" onclick="selectStyledOption(this, 'configFilterMode'); toggleHttpCodeMode('value')">Valeur exacte</div>
                    </div>
                </div>
            </div>
            <div id="httpCodeGroupMode" style="${filterMode === 'group' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">Groupes</label>
                    <div class="styled-checkbox-list">
                        ${config.values.map(v => `
                            <label class="styled-checkbox-item">
                                <input type="checkbox" class="httpcode-checkbox" value="${v}" ${selectedValues.includes(v) ? 'checked' : ''}>
                                <span class="checkbox-box"><span class="material-symbols-outlined">check</span></span>
                                <span class="checkbox-label">${this.httpCodeLabels[v]}</span>
                            </label>
                        `).join('')}
                    </div>
                </div>
            </div>
            <div id="httpCodeValueMode" style="${filterMode === 'value' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">Opérateur</label>
                    <div class="styled-select-wrapper">
                        <input type="hidden" id="configOperator" value="${op}">
                        <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                            <span class="select-value">${this.operatorLabels[op]}</span>
                            <span class="material-symbols-outlined">expand_more</span>
                        </div>
                        <div class="styled-select-menu">
                            ${config.operators.map(o => 
                                `<div class="styled-select-item ${op === o ? 'active' : ''}" data-value="${o}" onclick="selectStyledOption(this, 'configOperator')">${this.operatorLabels[o]}</div>`
                            ).join('')}
                        </div>
                    </div>
                </div>
                <div class="popover-row">
                    <label class="popover-label">Code</label>
                    <input type="number" class="popover-input" id="configValue" placeholder="Ex: 200, 404..." value="${numVal}">
                </div>
            </div>
        `;
    }

    _generateSeoConfigHtml(config, existingChip) {
        const isValueMode = existingChip?.operator && ['contains', 'not_contains', 'regex', 'not_regex'].includes(existingChip.operator);
        const filterMode = isValueMode ? 'value' : 'status';
        const selectedValues = !isValueMode && Array.isArray(existingChip?.value) ? existingChip.value : ['empty'];
        const op = existingChip?.operator || 'contains';
        const textVal = isValueMode ? existingChip?.value || '' : '';
        
        const opLabels = { 
            'contains': 'Contient', 
            'not_contains': 'Ne contient pas', 
            'regex': 'Correspond à la regex', 
            'not_regex': 'Ne correspond pas à la regex' 
        };
        
        return `
            <div class="popover-row">
                <label class="popover-label">Filtrer par</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configFilterMode" value="${filterMode}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${filterMode === 'status' ? 'État' : 'Valeur (texte)'}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${filterMode === 'status' ? 'active' : ''}" data-value="status" onclick="selectStyledOption(this, 'configFilterMode'); toggleSeoMode('status')">État</div>
                        <div class="styled-select-item ${filterMode === 'value' ? 'active' : ''}" data-value="value" onclick="selectStyledOption(this, 'configFilterMode'); toggleSeoMode('value')">Valeur (texte)</div>
                    </div>
                </div>
            </div>
            <div id="seoStatusMode" style="${filterMode === 'status' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">État</label>
                    <div class="styled-checkbox-list">
                        ${config.values.map(v => `
                            <label class="styled-checkbox-item">
                                <input type="checkbox" class="seo-checkbox" value="${v}" ${selectedValues.includes(v) ? 'checked' : ''}>
                                <span class="checkbox-box"><span class="material-symbols-outlined">check</span></span>
                                <span class="checkbox-label">${this.seoValueLabels[v]}</span>
                            </label>
                        `).join('')}
                    </div>
                </div>
            </div>
            <div id="seoValueMode" style="${filterMode === 'value' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">Condition</label>
                    <div class="styled-select-wrapper">
                        <input type="hidden" id="configSeoOperator" value="${op}">
                        <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                            <span class="select-value">${opLabels[op]}</span>
                            <span class="material-symbols-outlined">expand_more</span>
                        </div>
                        <div class="styled-select-menu">
                            ${Object.entries(opLabels).map(([k, v]) => 
                                `<div class="styled-select-item ${op === k ? 'active' : ''}" data-value="${k}" onclick="selectStyledOption(this, 'configSeoOperator')">${v}</div>`
                            ).join('')}
                        </div>
                    </div>
                </div>
                <div class="popover-row">
                    <label class="popover-label">Valeur</label>
                    <input type="text" class="popover-input" id="configSeoValue" placeholder="Texte ou regex..." value="${textVal}">
                </div>
            </div>
        `;
    }

    _generateCategoryConfigHtml(config, existingChip) {
        const op = existingChip?.operator || 'in';
        const selectedIds = Array.isArray(existingChip?.value) ? existingChip.value : [];
        
        return `
            <div class="popover-row">
                <label class="popover-label">Mode</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configOperator" value="${op}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${op === 'in' ? 'Est dans' : "N'est pas dans"}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${op === 'in' ? 'active' : ''}" data-value="in" onclick="selectStyledOption(this, 'configOperator')">Est dans</div>
                        <div class="styled-select-item ${op === 'not_in' ? 'active' : ''}" data-value="not_in" onclick="selectStyledOption(this, 'configOperator')">N'est pas dans</div>
                    </div>
                </div>
            </div>
            <div class="popover-row">
                <label class="popover-label">Catégories</label>
                <div class="styled-checkbox-list" style="max-height: 200px;">
                    ${this.availableCategories.map(cat => `
                        <label class="styled-checkbox-item">
                            <input type="checkbox" class="category-checkbox" value="${cat.id}" ${selectedIds.includes(String(cat.id)) ? 'checked' : ''}>
                            <span class="checkbox-box"><span class="material-symbols-outlined">check</span></span>
                            <span class="checkbox-label">${cat.cat}</span>
                        </label>
                    `).join('')}
                </div>
            </div>
        `;
    }

    _generateSchemasConfigHtml(config, existingChip) {
        const isCountMode = existingChip?.operator && ['=', '>', '<', '>=', '<='].includes(existingChip.operator);
        const mode = isCountMode ? 'count' : 'contains';
        const op = existingChip?.operator || '>';
        const countVal = isCountMode ? existingChip?.value || '0' : '0';
        const containsOp = !isCountMode ? existingChip?.operator || 'contains' : 'contains';
        const selectedSchemas = !isCountMode && Array.isArray(existingChip?.value) ? existingChip.value : [];
        
        return `
            <div class="popover-row">
                <label class="popover-label">Filtrer par</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configSchemasMode" value="${mode}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${mode === 'count' ? 'Nombre de schemas' : 'Types spécifiques'}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${mode === 'count' ? 'active' : ''}" data-value="count" onclick="selectStyledOption(this, 'configSchemasMode'); toggleSchemasMode('count')">Nombre de schemas</div>
                        <div class="styled-select-item ${mode === 'contains' ? 'active' : ''}" data-value="contains" onclick="selectStyledOption(this, 'configSchemasMode'); toggleSchemasMode('contains')">Types spécifiques</div>
                    </div>
                </div>
            </div>
            <div id="schemasCountMode" style="${mode === 'count' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">Opérateur</label>
                    <div class="styled-select-wrapper">
                        <input type="hidden" id="configSchemasOperator" value="${op}">
                        <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                            <span class="select-value">${this.operatorLabels[op]}</span>
                            <span class="material-symbols-outlined">expand_more</span>
                        </div>
                        <div class="styled-select-menu">
                            ${['=', '>', '<', '>=', '<='].map(o => 
                                `<div class="styled-select-item ${op === o ? 'active' : ''}" data-value="${o}" onclick="selectStyledOption(this, 'configSchemasOperator')">${this.operatorLabels[o]}</div>`
                            ).join('')}
                        </div>
                    </div>
                </div>
                <div class="popover-row">
                    <label class="popover-label">Nombre</label>
                    <input type="number" class="popover-input" id="configSchemasCount" placeholder="0" value="${countVal}">
                </div>
            </div>
            <div id="schemasContainsMode" style="${mode === 'contains' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">Mode</label>
                    <div class="styled-select-wrapper">
                        <input type="hidden" id="configSchemasContainsOp" value="${containsOp}">
                        <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                            <span class="select-value">${containsOp === 'contains' ? 'Contient' : 'Ne contient pas'}</span>
                            <span class="material-symbols-outlined">expand_more</span>
                        </div>
                        <div class="styled-select-menu">
                            <div class="styled-select-item ${containsOp === 'contains' ? 'active' : ''}" data-value="contains" onclick="selectStyledOption(this, 'configSchemasContainsOp')">Contient</div>
                            <div class="styled-select-item ${containsOp === 'not_contains' ? 'active' : ''}" data-value="not_contains" onclick="selectStyledOption(this, 'configSchemasContainsOp')">Ne contient pas</div>
                        </div>
                    </div>
                </div>
                <div class="popover-row">
                    <label class="popover-label">Types de schema</label>
                    <div class="styled-checkbox-list" style="max-height: 150px;">
                        ${this.availableSchemas.map(s => `
                            <label class="styled-checkbox-item">
                                <input type="checkbox" class="schema-checkbox" value="${s}" ${selectedSchemas.includes(s) ? 'checked' : ''}>
                                <span class="checkbox-box"><span class="material-symbols-outlined">check</span></span>
                                <span class="checkbox-label">${s}</span>
                            </label>
                        `).join('')}
                    </div>
                </div>
            </div>
        `;
    }
}

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = FilterBar;
} else {
    window.FilterBar = FilterBar;
}
