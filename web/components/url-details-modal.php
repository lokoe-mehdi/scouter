<!-- Modal de détails d'URL -->
<div id="urlDetailsModal" class="url-modal">
    <div class="url-modal-content">
        <div class="url-modal-header">
            <h2 id="modalUrlTitle" class="modal-url-title">
                <span class="material-symbols-outlined">link</span>
                <a id="modalUrlLink" href="" target="_blank" rel="noopener noreferrer" style="display: flex; align-items: center; gap: 0.5rem;"></a>
            </h2>
            <button class="url-modal-close" onclick="closeUrlModal()">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        
        <div class="url-modal-body">
            <!-- Onglets -->
            <div class="url-tabs">
                <button class="url-tab active" onclick="switchUrlTab('details')">
                    <span class="material-symbols-outlined">info</span>
                    Détails
                </button>
                <button class="url-tab" onclick="switchUrlTab('extractions')">
                    <span class="material-symbols-outlined">description</span>
                    Extractions
                </button>
                <button class="url-tab" id="headingsTabBtn" onclick="switchUrlTab('headings')" style="display: none;">
                    <span class="material-symbols-outlined">format_size</span>
                    Headings <span id="headingsErrorCount" class="tab-count" style="display: none;">0</span>
                </button>
                <button class="url-tab" onclick="switchUrlTab('inlinks')">
                    <span class="material-symbols-outlined">link</span>
                    Inlinks <span id="inlinksCount" class="tab-count">0</span>
                </button>
                <button class="url-tab" onclick="switchUrlTab('outlinks')">
                    <span class="material-symbols-outlined">open_in_new</span>
                    Outlinks <span id="outlinksCount" class="tab-count">0</span>
                </button>
                <button class="url-tab" id="htmlTabBtn" onclick="switchUrlTab('html')" style="display: none;">
                    <span class="material-symbols-outlined">code</span>
                    HTML
                </button>
                <button class="url-tab" id="previewTabBtn" onclick="switchUrlTab('preview')" style="display: none;">
                    <span class="material-symbols-outlined">visibility</span>
                    Prévisualiser
                </button>
            </div>
            
            <!-- Contenu des onglets -->
            <div id="urlTabContent" class="url-tab-content">
                <!-- Chargement -->
                <div id="urlLoading" class="url-loading">
                    <span class="material-symbols-outlined spinning">progress_activity</span>
                    Chargement...
                </div>
                
                <!-- Onglet Détails -->
                <div id="detailsTab" class="tab-pane active">
                    <div class="details-grid"></div>
                </div>
                
                <!-- Onglet Extractions -->
                <div id="extractionsTab" class="tab-pane">
                    <div class="extractions-content"></div>
                </div>
                
                <!-- Onglet Headings -->
                <div id="headingsTab" class="tab-pane">
                    <div class="headings-content"></div>
                </div>
                
                <!-- Onglet Inlinks -->
                <div id="inlinksTab" class="tab-pane">
                    <div class="links-list"></div>
                </div>
                
                <!-- Onglet Outlinks -->
                <div id="outlinksTab" class="tab-pane">
                    <div class="links-list"></div>
                </div>
                
                <!-- Onglet HTML -->
                <div id="htmlTab" class="tab-pane">
                    <div class="html-tab-content">
                        <div style="display: flex; justify-content: flex-end; margin-bottom: 0.5rem;">
                            <button class="btn-table-action btn-copy" onclick="copyHtmlSource()">
                                <span class="material-symbols-outlined">content_copy</span>
                                Copier
                            </button>
                        </div>
                        <div id="htmlLoading" class="url-loading" style="display: none;">
                            <span class="material-symbols-outlined spinning">progress_activity</span>
                            Chargement du HTML...
                        </div>
                        <div id="htmlEditorWrapper" class="html-editor-wrapper">
                            <textarea id="htmlSourceEditor"></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Onglet Prévisualiser -->
                <div id="previewTab" class="tab-pane">
                    <div class="preview-tab-content">
                        <div style="display: flex; justify-content: flex-end; margin-bottom: 0.5rem;">
                            <button id="previewFullscreenBtn" class="btn-table-action btn-copy" onclick="togglePreviewFullscreen()">
                                <span class="material-symbols-outlined">fullscreen</span>
                                Plein écran
                            </button>
                        </div>
                        <div id="previewIframeWrapper" class="preview-iframe-wrapper">
                            <iframe id="previewIframe" src="about:blank"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Overlay plein écran pour la prévisualisation -->
<div id="previewFullscreenOverlay" class="preview-fullscreen-overlay">
    <div class="preview-fullscreen-header">
        <div class="preview-fullscreen-info">
            <span class="material-symbols-outlined">visibility</span>
            <span>Prévisualisation Scouter</span>
            <span class="preview-fullscreen-url" id="previewFullscreenUrl"></span>
        </div>
        <div class="preview-fullscreen-actions">
            <span class="preview-fullscreen-date" id="previewFullscreenDate"></span>
            <button onclick="togglePreviewFullscreen()" class="preview-fullscreen-close">
                <span class="material-symbols-outlined">close</span>
                Fermer
            </button>
        </div>
    </div>
    <iframe id="previewFullscreenIframe" src="about:blank"></iframe>
</div>

<style>
.url-modal {
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

.url-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.url-modal-content {
    background-color: #2C3E50;
    margin: 2rem;
    padding: 0;
    border-radius: 12px;
    width: 90%;
    max-width: 1200px;
    height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    overflow: hidden;
}

.url-modal-header {
    padding: 1.25rem 2rem;
    border-bottom: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    background: linear-gradient(135deg, #1a252f 0%, #2C3E50 100%);
    border-radius: 12px 12px 0 0;
}

.modal-url-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: white;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    word-break: break-all;
}

.modal-url-title .material-symbols-outlined {
    color: var(--primary-color);
    font-size: 22px;
    flex-shrink: 0;
}

.modal-url-title a {
    color: white;
    text-decoration: none;
    transition: opacity 0.2s;
}

.modal-url-title a:hover {
    opacity: 0.8;
    text-decoration: underline;
}

.url-modal-close {
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

.url-modal-close:hover {
    background: rgba(231, 76, 60, 0.9);
    border-color: rgba(231, 76, 60, 0.9);
    color: white;
}

.url-modal-body {
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    background: white;
}

.url-tabs {
    display: flex;
    gap: 0.25rem;
    padding: 0 2rem;
    border-bottom: 2px solid #E1E8ED;
    background: #FAFBFC;
}

.url-tab {
    padding: 1rem 1.5rem;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 500;
    color: #657786;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    position: relative;
    bottom: -2px;
    margin-bottom: -1px;
}

.url-tab .material-symbols-outlined {
    font-size: 20px;
}

.url-tab:hover {
    color: var(--text-primary);
    background: rgba(78, 205, 196, 0.08);
}

.url-tab.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
    font-weight: 600;
    background: white;
}

.tab-count {
    background: #E1E8ED;
    color: #14171A;
    padding: 0.15rem 0.5rem;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
    margin-left: 0.5rem;
}

.url-tab.active .tab-count {
    background: #2C3E50;
    color: white;
}

.url-tab-content {
    flex: 1;
    overflow-y: auto;
    padding: 2rem;
    width: 100%;
    display: flex;
    flex-direction: column;
    min-height: 0;
}

.details-grid {
    width: 100%;
}

.links-list {
    width: 100%;
}

.tab-pane {
    display: none;
}

.tab-pane.active {
    display: block;
}

/* Pour les onglets qui doivent prendre toute la hauteur */
#htmlTab, #previewTab {
    height: 100%;
}

#htmlTab.active, #previewTab.active {
    display: flex;
    flex-direction: column;
}

.url-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 3rem;
    color: #657786;
    font-size: 1.1rem;
}

.url-loading .material-symbols-outlined {
    font-size: 32px;
    color: var(--primary);
}

.details-grid {
    width: 100%;
}

.detail-card {
    background: #FAFBFC;
    padding: 1.25rem;
    border-radius: 10px;
    border: 1px solid #E8ECF0;
    transition: all 0.2s ease;
}

.detail-card:hover {
    border-color: rgba(78, 205, 196, 0.3);
    box-shadow: 0 2px 8px rgba(78, 205, 196, 0.08);
}

.detail-label {
    font-size: 0.8rem;
    font-weight: 700;
    color: #4a5568;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.detail-label .material-symbols-outlined {
    font-size: 16px;
    color: var(--primary-color);
    opacity: 0.7;
}

.detail-value {
    font-size: 1rem;
    color: var(--text-primary);
    word-break: break-word;
}

.detail-value.success {
    color: var(--success-color);
    font-weight: 600;
}

.detail-value.danger {
    color: var(--danger-color);
    font-weight: 600;
}

.detail-value.warning {
    color: #F39C12;
    font-weight: 600;
}

/* Table des détails améliorée */
.details-table {
    border-collapse: separate;
    border-spacing: 0;
}

.details-table thead th {
    background: #f8f9fa;
    font-weight: 600;
    color: #4a5568;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    padding: 1rem;
    border-bottom: 2px solid #e2e8f0;
}

.details-table tbody tr {
    transition: background 0.15s ease;
}

.details-table tbody tr:hover {
    background: rgba(78, 205, 196, 0.04);
}

.details-table tbody td {
    padding: 0.9rem 1rem;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}

.detail-label-cell {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.links-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.link-item {
    background: white;
    border: 1px solid #E1E8ED;
    border-radius: 8px;
    padding: 1rem;
    transition: all 0.2s;
}

.link-item:hover {
    border-color: var(--primary);
    box-shadow: 0 2px 8px rgba(78, 205, 196, 0.15);
}

.link-url {
    color: var(--primary);
    font-size: 0.95rem;
    font-weight: 500;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.link-url .material-symbols-outlined {
    font-size: 18px;
}

.link-meta {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    font-size: 0.85rem;
    color: #657786;
}

.link-anchor {
    color: var(--text-primary);
    font-style: italic;
    margin-top: 0.5rem;
    padding: 0.5rem;
    background: #F7F9FA;
    border-radius: 4px;
    font-size: 0.9rem;
}

.badge-small {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-external {
    background: #E8F5E9;
    color: #2E7D32;
}

.badge-internal {
    background: #E3F2FD;
    color: #1565C0;
}

.badge-nofollow {
    background: #FFF3E0;
    color: #E65100;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #657786;
}

.empty-state .material-symbols-outlined {
    font-size: 64px;
    opacity: 0.3;
    margin-bottom: 1rem;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.spinning {
    animation: spin 1s linear infinite;
}

/* Style pour les URLs cliquables */
.url-clickable {
    color: var(--primary);
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.url-clickable:hover {
    opacity: 0.7;
    text-decoration: underline;
}

.url-clickable .material-symbols-outlined {
    font-size: 16px;
    opacity: 0.7;
}

/* HTML Tab styles */
.html-tab-content {
    height: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.html-editor-wrapper {
    flex: 1;
    min-height: 0;
    border: 1px solid #E1E8ED;
    border-radius: 8px;
    overflow: hidden;
}

.html-editor-wrapper .CodeMirror {
    height: 100%;
    font-size: 12px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
}

#htmlTab .url-loading {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
}

/* Preview Tab styles */
.preview-tab-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 0;
}

.preview-iframe-wrapper {
    flex: 1;
    min-height: 0;
    border: 1px solid #E1E8ED;
    border-radius: 8px;
    overflow: hidden;
    background: white;
}

.preview-iframe-wrapper iframe {
    width: 100%;
    height: 100%;
    border: none;
}

/* Overlay plein écran */
.preview-fullscreen-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 99999;
    background: white;
    flex-direction: column;
}

.preview-fullscreen-overlay.active {
    display: flex;
}

.preview-fullscreen-header {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    color: white;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}

.preview-fullscreen-info {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
}

.preview-fullscreen-info .material-symbols-outlined {
    color: #4ade80;
}

.preview-fullscreen-url {
    color: #94a3b8;
    max-width: 500px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.preview-fullscreen-actions {
    display: flex;
    align-items: center;
    gap: 20px;
}

.preview-fullscreen-date {
    color: #94a3b8;
    font-size: 13px;
}

.preview-fullscreen-date strong {
    color: #fbbf24;
}

.preview-fullscreen-close {
    display: flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.1);
    border: none;
    color: white;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s;
}

.preview-fullscreen-close:hover {
    background: rgba(255,255,255,0.2);
}

.preview-fullscreen-close .material-symbols-outlined {
    font-size: 18px;
}

.preview-fullscreen-overlay iframe {
    flex: 1;
    width: 100%;
    border: none;
}
</style>

<script>
let currentProject = '<?= $crawlId ?? $projectDir ?? '' ?>';
let currentUrlId = null;
let currentPageUrl = null;
let currentCrawlDate = null;
let htmlSourceLoaded = false;
let htmlSourceEditor = null;
let previewLoaded = false;
let previewFullscreen = false;
let modalAbortController = null; // Pour annuler les requêtes en cours

// Générer une couleur pastel basée sur le nom de la catégorie
function getCategoryColor(category) {
    if (!category || category === 'N/A' || category === '-') {
        return { bg: '#F0F0F0', text: '#666' };
    }
    
    // Hash simple pour générer une couleur consistante
    let hash = 0;
    for (let i = 0; i < category.length; i++) {
        hash = category.charCodeAt(i) + ((hash << 5) - hash);
    }
    
    // Palette de couleurs pastel prédéfinies
    const pastelColors = [
        { bg: '#FFE5E5', text: '#CC4444' }, // Rouge pastel
        { bg: '#E5F5FF', text: '#4488CC' }, // Bleu pastel
        { bg: '#E5FFE5', text: '#44AA44' }, // Vert pastel
        { bg: '#FFF5E5', text: '#CC8844' }, // Orange pastel
        { bg: '#F5E5FF', text: '#8844CC' }, // Violet pastel
        { bg: '#FFE5F5', text: '#CC4488' }, // Rose pastel
        { bg: '#E5FFFF', text: '#44AACC' }, // Cyan pastel
        { bg: '#FFFFE5', text: '#AAAA44' }, // Jaune pastel
    ];
    
    return pastelColors[Math.abs(hash) % pastelColors.length];
}

function getCategoryBadge(category, categoryColor) {
    if (!category || category === 'N/A' || category === '-' || category === 'Non catégorisé') {
        return '<span style="color: #999;">-</span>';
    }
    
    // Utiliser la vraie couleur de la BDD
    const bgColor = categoryColor || '#95a5a6';
    
    // Calculer la couleur du texte selon la luminosité
    const textColor = getTextColorForBg(bgColor);
    
    return `<span style="display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; background: ${bgColor}; color: ${textColor};">${category}</span>`;
}

function getTextColorForBg(hexColor) {
    const hex = hexColor.replace('#', '');
    const r = parseInt(hex.substr(0, 2), 16);
    const g = parseInt(hex.substr(2, 2), 16);
    const b = parseInt(hex.substr(4, 2), 16);
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luminance > 0.75 ? '#000000' : '#ffffff';
}

function openUrlModal(url, project = null) {
    if (project) currentProject = project;
    
    // Annuler toute requête en cours
    if (modalAbortController) {
        modalAbortController.abort();
    }
    modalAbortController = new AbortController();
    
    const modal = document.getElementById('urlDetailsModal');
    modal.classList.add('active');
    
    // Reset
    document.getElementById('urlLoading').style.display = 'flex';
    document.querySelector('#detailsTab .details-grid').innerHTML = '';
    document.querySelector('#inlinksTab .links-list').innerHTML = '';
    document.querySelector('#outlinksTab .links-list').innerHTML = '';
    document.getElementById('htmlTabBtn').style.display = 'none';
    document.getElementById('previewTabBtn').style.display = 'none';
    htmlSourceLoaded = false;
    previewLoaded = false;
    currentUrlId = null;
    currentPageUrl = null;
    currentCrawlDate = null;
    if (htmlSourceEditor) {
        htmlSourceEditor.setValue('');
    }
    document.getElementById('previewIframe').src = 'about:blank';
    switchUrlTab('details');
    
    // Charger les données - utiliser un chemin absolu depuis la racine
    const apiUrl = window.location.pathname.includes('/pages/') 
        ? '../api/query/url-details' 
        : 'api/query/url-details';
    
    fetch(`${apiUrl}?project=${encodeURIComponent(currentProject)}&url=${encodeURIComponent(url)}`, {
        signal: modalAbortController.signal
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayUrlDetails(data);
            } else {
                console.error('API Error:', data);
                alert('Erreur: ' + (data.error || 'Impossible de charger les détails'));
                closeUrlModal();
            }
        })
        .catch(error => {
            // Ignorer les erreurs d'annulation
            if (error.name === 'AbortError') {
                console.log('Requête annulée');
                return;
            }
            console.error('Fetch Error:', error);
            alert('Erreur de chargement: ' + error.message);
            closeUrlModal();
        });
}

function closeUrlModal() {
    // Annuler toute requête en cours
    if (modalAbortController) {
        modalAbortController.abort();
        modalAbortController = null;
    }
    document.getElementById('urlDetailsModal').classList.remove('active');
}

function switchUrlTab(tabName) {
    // Mettre à jour les boutons
    document.querySelectorAll('.url-tab').forEach(tab => tab.classList.remove('active'));
    
    // Trouver et activer le bon bouton par data ou id
    if (tabName === 'html') {
        document.getElementById('htmlTabBtn').classList.add('active');
    } else if (tabName === 'headings') {
        document.getElementById('headingsTabBtn').classList.add('active');
    } else if (tabName === 'preview') {
        document.getElementById('previewTabBtn').classList.add('active');
    } else {
        const tabs = {
            'details': 0,
            'extractions': 1,
            'headings': 2,
            'inlinks': 3,
            'outlinks': 4
        };
        const tabIndex = tabs[tabName];
        if (tabIndex !== undefined) {
            document.querySelectorAll('.url-tab')[tabIndex].classList.add('active');
        }
    }
    
    // Mettre à jour les contenus
    document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
    document.getElementById(tabName + 'Tab').classList.add('active');
    
    // Si c'est l'onglet HTML et qu'il n'est pas encore chargé, le charger
    if (tabName === 'html' && !htmlSourceLoaded) {
        loadHtmlSource();
    }
    
    // Si c'est l'onglet preview et qu'il n'est pas encore chargé, le charger
    if (tabName === 'preview' && !previewLoaded) {
        loadPreview();
    }
}

function displayUrlDetails(data) {
    document.getElementById('urlLoading').style.display = 'none';
    
    const url = data.url;
    
    // Titre et lien avec icône (échapper le HTML pour éviter l'injection)
    const linkElement = document.getElementById('modalUrlLink');
    linkElement.href = url.url;
    const urlSpan = document.createElement('span');
    urlSpan.style.flex = '1';
    urlSpan.textContent = url.url; // textContent échappe automatiquement le HTML
    linkElement.innerHTML = '';
    linkElement.appendChild(urlSpan);
    const iconSpan = document.createElement('span');
    iconSpan.className = 'material-symbols-outlined';
    iconSpan.style.fontSize = '20px';
    iconSpan.style.opacity = '0.7';
    iconSpan.textContent = 'open_in_new';
    linkElement.appendChild(iconSpan);
    
    // Comptes
    document.getElementById('inlinksCount').textContent = data.inlinks_count;
    document.getElementById('outlinksCount').textContent = data.outlinks.length;
    
    // Stocker l'ID, URL et date pour charger le HTML/Preview
    currentUrlId = url.id;
    currentPageUrl = url.url;
    currentCrawlDate = url.date;
    
    // Afficher les onglets HTML, Headings et Prévisualiser si code 200
    if (url.code >= 200 && url.code < 300) {
        document.getElementById('htmlTabBtn').style.display = 'flex';
        document.getElementById('headingsTabBtn').style.display = 'flex';
        document.getElementById('previewTabBtn').style.display = 'flex';
    }
    
    // Onglet Détails
    displayDetails(data);
    
    // Onglet Inlinks
    displayLinks(data.inlinks, 'inlinksTab', 'inlink');
    
    // Onglet Outlinks
    displayLinks(data.outlinks, 'outlinksTab', 'outlink');
    
    // Onglet Extractions
    displayExtractions(data);
    
    // Onglet Headings
    displayHeadings(data);
}

function getCodeBadge(code) {
    let bgColor, textColor;
    
    if (code >= 200 && code < 300) {
        bgColor = '#D4EDDA';
        textColor = '#155724';
    } else if (code >= 300 && code < 400) {
        bgColor = '#FFF3CD';
        textColor = '#856404';
    } else if (code >= 400 && code < 500) {
        bgColor = '#F8D7DA';
        textColor = '#721C24';
    } else if (code >= 500) {
        bgColor = '#F5C6CB';
        textColor = '#721C24';
    } else {
        bgColor = '#E2E3E5';
        textColor = '#383D41';
    }
    
    return `<span style="display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 0.85rem; font-weight: 600; background: ${bgColor}; color: ${textColor};">${code}</span>`;
}

function getBooleanBadge(value, trueLabel = 'Oui', falseLabel = 'Non') {
    if (value) {
        return `<span style="display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 0.85rem; font-weight: 600; background: #D4EDDA; color: #155724;">${trueLabel}</span>`;
    } else {
        return `<span style="display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 0.85rem; font-weight: 600; background: #F8D7DA; color: #721C24;">${falseLabel}</span>`;
    }
}

function displayDetails(data) {
    const url = data.url;
    const container = document.querySelector('#detailsTab .details-grid');
    
    // Icônes pour chaque type de donnée
    const iconMap = {
        'Code HTTP': 'http',
        'Indexable': 'verified',
        'Catégorie': 'folder',
        'Profondeur': 'layers',
        'Inlinks': 'link',
        'Outlinks': 'open_in_new',
        'Temps de réponse': 'speed',
        'Noindex': 'block',
        'Nofollow': 'link_off',
        'Canonique': 'content_copy',
        'Bloqué robots.txt': 'smart_toy',
        'Crawlé': 'check_circle',
        'Redirection vers': 'redo',
        'Date de crawl': 'schedule'
    };
    
    const details = [
        { label: 'Code HTTP', value: getCodeBadge(url.code), isHtml: true },
        { label: 'Indexable', value: getBooleanBadge(url.compliant, 'Oui', 'Non'), isHtml: true },
        { label: 'Catégorie', value: getCategoryBadge(data.category, url.category_color), isHtml: true },
        { label: 'Profondeur', value: url.depth },
        { label: 'Inlinks', value: data.inlinks_count },
        { label: 'Outlinks', value: url.outlinks || 0 },
        { label: 'TTFB', value: url.response_time ? url.response_time.toFixed(0) + ' ms' : 'N/A', tooltip: 'Time To First Byte' },
        { label: 'Noindex', value: getBooleanBadge(url.noindex, 'Oui', 'Non'), isHtml: true },
        { label: 'Nofollow', value: getBooleanBadge(url.nofollow, 'Oui', 'Non'), isHtml: true },
        { label: 'Canonique', value: getBooleanBadge(url.canonical, 'Oui', 'Non'), isHtml: true },
        { label: 'Bloqué robots.txt', value: getBooleanBadge(url.blocked, 'Oui', 'Non'), isHtml: true },
        { label: 'Crawlé', value: getBooleanBadge(url.crawled, 'Oui', 'Non'), isHtml: true },
    ];
    
    if (url.redirect_to) {
        details.push({ label: 'Redirection vers', value: url.redirect_to });
    }
    
    if (url.date) {
        details.push({ label: 'Date de crawl', value: new Date(url.date).toLocaleString('fr-FR') });
    }
    
    // Extractions custom
    if (data.extractions && Object.keys(data.extractions).length > 0) {
        Object.entries(data.extractions).forEach(([table, fields]) => {
            Object.entries(fields).forEach(([field, value]) => {
                details.push({ label: `${table} - ${field}`, value: value || 'N/A', icon: 'code' });
            });
        });
    }
    
    container.innerHTML = `
        <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
            <button class="btn-table-action btn-copy" onclick="copyDetailsTable()">
                <span class="material-symbols-outlined">content_copy</span>
                Copier
            </button>
        </div>
        <table class="data-table details-table" id="detailsTable" style="width: 100%;">
            <thead>
                <tr>
                    <th style="width: 30%;">Information</th>
                    <th>Valeur</th>
                </tr>
            </thead>
            <tbody>
                ${details.map(detail => {
                    const icon = detail.icon || iconMap[detail.label] || 'info';
                    const tooltipAttr = detail.tooltip ? `title="${detail.tooltip}"` : '';
                    return `
                    <tr>
                        <td class="detail-label-cell" ${tooltipAttr}>
                            <span class="material-symbols-outlined" style="font-size: 18px; color: var(--primary-color); opacity: 0.6;">${icon}</span>
                            <span style="font-weight: 600; color: #4a5568;">${detail.label}</span>
                        </td>
                        <td class="${detail.class || ''}">${detail.value}</td>
                    </tr>
                `}).join('')}
            </tbody>
        </table>
    `;
}

function displayHeadings(data) {
    const container = document.querySelector('#headingsTab .headings-content');
    const headings = data.headings || [];
    
    if (headings.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <span class="material-symbols-outlined">format_size</span>
                <p>Aucun heading trouvé</p>
            </div>
        `;
        // Pas d'erreurs si pas de headings
        document.getElementById('headingsErrorCount').style.display = 'none';
        return;
    }
    
    // Détecter les problèmes de séquençage et insérer les headings manquants
    const fixedHeadings = [];
    let lastLevel = 0;
    let h1Count = 0;
    let errorCount = 0;
    
    headings.forEach(heading => {
        const currentLevel = heading.level;
        
        // Compter les H1
        if (currentLevel === 1) {
            h1Count++;
        }
        
        // Si on saute des niveaux (ex: h1 -> h3), ajouter les niveaux manquants
        if (currentLevel > lastLevel + 1) {
            for (let missingLevel = lastLevel + 1; missingLevel < currentLevel; missingLevel++) {
                fixedHeadings.push({
                    level: missingLevel,
                    text: `H${missingLevel} manquant`,
                    missing: true,
                    multipleH1: false
                });
                errorCount++; // Compter les balises manquantes
            }
        }
        
        const isMultipleH1 = currentLevel === 1 && h1Count > 1;
        if (isMultipleH1) {
            errorCount++; // Compter les H1 multiples
        }
        
        fixedHeadings.push({
            level: currentLevel,
            text: heading.text,
            missing: false,
            multipleH1: isMultipleH1 // Marquer les H1 après le premier
        });
        
        lastLevel = currentLevel;
    });
    
    // Mettre à jour le compteur d'erreurs dans l'onglet
    const errorCountElement = document.getElementById('headingsErrorCount');
    if (errorCount > 0) {
        errorCountElement.textContent = errorCount;
        errorCountElement.style.display = 'inline-flex';
        errorCountElement.style.background = '#ffcccc'; // Rouge clair pour les erreurs
        errorCountElement.style.color = '#000'; // Texte noir
    } else {
        // Pas de pastille si pas d'erreurs
        errorCountElement.style.display = 'none';
    }
    
    // Générer le HTML
    let html = `
        <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
            <button class="btn-table-action btn-copy" onclick="copyHeadingsTable()">
                <span class="material-symbols-outlined">content_copy</span>
                Copier
            </button>
        </div>
        <div class="headings-list">
    `;
    
    fixedHeadings.forEach(heading => {
        const indent = (heading.level - 1) * 20; // Indentation selon le niveau
        const bgColor = getHeadingColor(heading.level);
        const isMissing = heading.missing;
        const isMultipleH1 = heading.multipleH1;
        const hasError = isMissing || isMultipleH1;
        
        html += `
            <div class="heading-item" style="margin-left: ${indent}px; margin-bottom: 0.75rem; display: flex; align-items: flex-start; gap: 0.75rem; ${isMissing ? 'opacity: 0.5;' : ''}">
                <span class="heading-badge" style="
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    min-width: 45px;
                    height: 32px;
                    padding: 0 0.5rem;
                    background: ${isMultipleH1 ? '#e74c3c' : bgColor};
                    color: white;
                    font-weight: 700;
                    font-size: 0.85rem;
                    border-radius: 6px;
                    flex-shrink: 0;
                    ${isMissing ? 'border: 2px dashed #e74c3c;' : ''}
                ">
                    H${heading.level}
                </span>
                <div style="
                    flex: 1;
                    padding: 0.5rem 0.75rem;
                    background: ${hasError ? '#fff5f5' : '#f8f9fa'};
                    border-radius: 6px;
                    border-left: 3px solid ${isMultipleH1 ? '#e74c3c' : bgColor};
                    ${isMissing ? 'border-style: dashed;' : ''}
                    word-break: break-word;
                    line-height: 1.5;
                ">
                    ${isMultipleH1 ? '<div style="color: #e74c3c; font-weight: 600; font-size: 0.8rem; margin-bottom: 0.25rem;">⚠️ H1 multiple</div>' : ''}
                    ${isMissing ? '<em style="color: #e74c3c;">' + heading.text + '</em>' : heading.text || '<span style="color: #95a5a6;">Vide</span>'}
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    container.innerHTML = html;
}

function getHeadingColor(level) {
    const colors = {
        1: '#3498db', // Bleu
        2: '#9b59b6', // Violet
        3: '#1abc9c', // Turquoise
        4: '#f39c12', // Orange
        5: '#e74c3c', // Rouge
        6: '#95a5a6'  // Gris
    };
    return colors[level] || '#95a5a6';
}

function copyHeadingsTable() {
    const headings = document.querySelectorAll('.heading-item');
    let text = '';
    
    headings.forEach(heading => {
        const badge = heading.querySelector('.heading-badge').textContent.trim();
        const content = heading.querySelector('div').textContent.trim();
        text += `${badge}\t${content}\n`;
    });
    
    navigator.clipboard.writeText(text).then(() => {
        showGlobalStatus('✓ Headings copiés', 'success');
    }).catch(err => {
        console.error('Erreur:', err);
        showGlobalStatus('Erreur lors de la copie', 'error');
    });
}

function displayExtractions(data) {
    const container = document.querySelector('#extractionsTab .extractions-content');
    const url = data.url;
    
    // Récupérer toutes les extractions (standard + custom)
    const extractions = [];
    
    // Extractions standard (ordre: Content-Type, URL Canonique, Données structurées, Title, H1, Meta Description)
    if (url.content_type) extractions.push({ label: 'Content-Type', value: url.content_type });
    if (url.canonical_value) extractions.push({ label: 'URL Canonique', value: url.canonical_value });
    
    // Données structurées (schemas)
    let schemasHtml = '0';
    if (url.schemas && Array.isArray(url.schemas) && url.schemas.length > 0) {
        schemasHtml = url.schemas.map(schema => 
            `<a href="https://schema.org/${schema}" target="_blank" style="display: inline-block; background: #e9ecef; color: #495057; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; margin: 2px 4px 2px 0; text-decoration: none;">${schema}</a>`
        ).join('');
    }
    extractions.push({ label: 'Données structurées', value: schemasHtml });
    
    if (data.extracts) {
        if (data.extracts.title) extractions.push({ label: 'Title', value: data.extracts.title });
        if (data.extracts.h1) extractions.push({ label: 'H1', value: data.extracts.h1 });
        if (data.extracts.metadesc) extractions.push({ label: 'Meta Description', value: data.extracts.metadesc });
    }
    
    // Analyse des headings
    extractions.push({ 
        label: 'H1 Multiples', 
        value: url.h1_multiple ? '<span style="color: #e74c3c;">Oui</span>' : '<span style="color: #27ae60;">Non</span>'
    });
    extractions.push({ 
        label: 'Mauvaise structure hn', 
        value: url.headings_missing ? '<span style="color: #e74c3c;">Oui</span>' : '<span style="color: #27ae60;">Non</span>'
    });
    
    // Nombre de mots (word_count)
    extractions.push({ 
        label: 'Nombre de mots', 
        value: url.word_count ? url.word_count.toLocaleString('fr-FR') : '0'
    });
    
    if (data.extracts) {
        // Extractions custom (cstm_*)
        Object.entries(data.extracts).forEach(([key, value]) => {
            if (key.startsWith('cstm_')) {
                const label = key.replace('cstm_', '').replace(/_/g, ' ');
                extractions.push({ label: label.charAt(0).toUpperCase() + label.slice(1), value: value || '-' });
            }
        });
    }
    
    if (extractions.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <span class="material-symbols-outlined">description</span>
                <p>Aucune extraction disponible</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = `
        <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
            <button class="btn-table-action btn-copy" onclick="copyExtractionsTable()">
                <span class="material-symbols-outlined">content_copy</span>
                Copier
            </button>
        </div>
        <table class="data-table details-table" id="extractionsTable" style="width: 100%;">
            <thead>
                <tr>
                    <th style="width: 30%;">Champ</th>
                    <th>Valeur</th>
                </tr>
            </thead>
            <tbody>
                ${extractions.map(extract => `
                    <tr>
                        <td style="font-weight: 600;">${extract.label}</td>
                        <td style="word-break: break-word;">${extract.value || '-'}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
}

function displayLinks(links, tabId, type) {
    const container = document.querySelector(`#${tabId} .links-list`);
    
    if (links.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <span class="material-symbols-outlined">link_off</span>
                <p>Aucun ${type === 'inlink' ? 'lien entrant' : 'lien sortant'}</p>
            </div>
        `;
        return;
    }
    
    const topMessage = type === 'inlink' ? '' : '';
    
    container.innerHTML = `
        ${topMessage}
        <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
            <button class="btn-table-action btn-copy" onclick="copyLinksTable('${tabId}')">
                <span class="material-symbols-outlined">content_copy</span>
                Copier
            </button>
        </div>
        <table class="data-table" id="${tabId}Table" style="width: 100%; table-layout: fixed;">
            <thead>
                <tr>
                    <th style="width: 35%;">URL</th>
                    <th style="width: 15%;">Ancre</th>
                    <th style="width: 12%;">Catégorie</th>
                    <th style="width: 10%; text-align: center;">Type</th>
                    ${type === 'outlink' ? '<th style="width: 10%; text-align: center;">Tag</th>' : ''}
                    <th style="width: 10%; text-align: center;">Follow</th>
                </tr>
            </thead>
            <tbody>
                ${links.map(link => {
                    const categoryBadge = getCategoryBadge(link.category, link.category_color);
                    const nofollowBadge = link.nofollow 
                        ? '<span class="badge-small" style="background: #F8D7DA; color: #721C24;">Nofollow</span>' 
                        : '<span class="badge-small" style="background: #D4EDDA; color: #155724;">Dofollow</span>';
                    
                    // Badge pour le type de lien (inlinks)
                    const linkTypeBadge = link.type === 'redirect' || link.type === 'r'
                        ? '<span class="badge-small" style="background: #FFF3CD; color: #856404;">Redirect</span>'
                        : '<span class="badge-small" style="background: #D1ECF1; color: #0C5460;">Ahref</span>';
                    
                    const tagBadge = type === 'outlink' 
                        ? (link.external 
                            ? '<span class="badge-small" style="background: #F8D7DA; color: #721C24;">Externe</span>' 
                            : '<span class="badge-small" style="background: #E2E3E5; color: #383D41;">Interne</span>')
                        : '';
                    
                    return `
                        <tr>
                            <td style="max-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <div class="url-clickable" data-url="${link.url}" style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer; overflow: hidden;">
                                    <span class="material-symbols-outlined" style="font-size: 16px; flex-shrink: 0;">link</span>
                                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${link.url}</span>
                                </div>
                            </td>
                            <td style="max-width: 0; font-style: italic; color: #657786; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                ${link.anchor || '-'}
                            </td>
                            <td style="max-width: 0; overflow: hidden;">
                                ${categoryBadge}
                            </td>
                            <td style="text-align: center; max-width: 0; overflow: hidden;">
                                ${linkTypeBadge}
                            </td>
                            ${type === 'outlink' ? `<td style="text-align: center; max-width: 0; overflow: hidden;">
                                ${tagBadge}
                            </td>` : ''}
                            <td style="text-align: center; max-width: 0; overflow: hidden;">
                                ${nofollowBadge}
                            </td>
                        </tr>
                    `;
                }).join('')}
            </tbody>
        </table>
    `;
    
    // Réactiver les handlers pour les URLs cliquables
    if (typeof refreshUrlModalHandlers === 'function') {
        refreshUrlModalHandlers();
    }
}

// Fonctions de copie pour chaque onglet
function copyDetailsTable() {
    const table = document.getElementById('detailsTable');
    let text = '';
    
    // En-têtes
    const headers = table.querySelectorAll('thead th');
    text += Array.from(headers).map(th => th.textContent.trim()).join('\t') + '\n';
    
    // Lignes
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const cellTexts = Array.from(cells).map(td => {
            // Cloner pour ne pas modifier l'original
            const clone = td.cloneNode(true);
            // Supprimer UNIQUEMENT les icônes Material (pas les badges)
            clone.querySelectorAll('.material-symbols-outlined').forEach(el => el.remove());
            return clone.textContent.trim();
        });
        text += cellTexts.join('\t') + '\n';
    });
    
    navigator.clipboard.writeText(text).then(() => {
        showGlobalStatus('✓ Texte copié', 'success');
    }).catch(err => {
        console.error('Erreur:', err);
        showGlobalStatus('Erreur lors de la copie', 'error');
    });
}

function copyExtractionsTable() {
    const table = document.getElementById('extractionsTable');
    let text = '';
    
    // En-têtes
    const headers = table.querySelectorAll('thead th');
    text += Array.from(headers).map(th => th.textContent.trim()).join('\t') + '\n';
    
    // Lignes
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const cellTexts = Array.from(cells).map(td => td.textContent.trim());
        text += cellTexts.join('\t') + '\n';
    });
    
    navigator.clipboard.writeText(text).then(() => {
        showGlobalStatus('✓ Texte copié', 'success');
    }).catch(err => {
        console.error('Erreur:', err);
        showGlobalStatus('Erreur lors de la copie', 'error');
    });
}

function copyLinksTable(tabId) {
    const table = document.getElementById(tabId + 'Table');
    let text = '';
    
    // En-têtes
    const headers = table.querySelectorAll('thead th');
    text += Array.from(headers).map(th => th.textContent.trim()).join('\t') + '\n';
    
    // Lignes
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const cellTexts = Array.from(cells).map(td => {
            // Cloner pour ne pas modifier l'original
            const clone = td.cloneNode(true);
            // Supprimer UNIQUEMENT les icônes Material (pas les badges)
            clone.querySelectorAll('.material-symbols-outlined').forEach(el => el.remove());
            return clone.textContent.trim();
        });
        text += cellTexts.join('\t') + '\n';
    });
    
    navigator.clipboard.writeText(text).then(() => {
        showGlobalStatus('✓ Texte copié', 'success');
    }).catch(err => {
        console.error('Erreur:', err);
        showGlobalStatus('Erreur lors de la copie', 'error');
    });
}

// Copier le code HTML dans le presse-papier
function copyHtmlSource() {
    if (!htmlSourceEditor) {
        showGlobalStatus('Aucun code HTML à copier', 'error');
        return;
    }
    
    const html = htmlSourceEditor.getValue();
    if (!html) {
        showGlobalStatus('Aucun code HTML à copier', 'error');
        return;
    }
    
    navigator.clipboard.writeText(html).then(() => {
        showGlobalStatus('✓ Code HTML copié !', 'success');
    }).catch(err => {
        console.error('Erreur lors de la copie:', err);
        showGlobalStatus('Erreur lors de la copie', 'error');
    });
}

// Charger la prévisualisation
function loadPreview() {
    if (!currentUrlId) return;
    
    const monitorUrl = window.location.pathname.includes('/pages/') 
        ? '../api/monitor/preview' 
        : 'api/monitor/preview';
    
    const iframeSrc = `${monitorUrl}?project=${encodeURIComponent(currentProject)}&id=${encodeURIComponent(currentUrlId)}&nobar=1`;
    const iframe = document.getElementById('previewIframe');
    iframe.src = iframeSrc;
    
    // Désactiver les liens dans l'iframe après chargement
    iframe.onload = function() {
        disableIframeLinks(iframe);
    };
    
    previewLoaded = true;
}

// Désactiver tous les liens dans une iframe
function disableIframeLinks(iframe) {
    try {
        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
        const links = iframeDoc.querySelectorAll('a');
        links.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                return false;
            });
            link.style.cursor = 'default';
        });
    } catch (e) {
        console.log('Impossible de désactiver les liens (CORS):', e);
    }
}

// Toggle plein écran pour la prévisualisation
function togglePreviewFullscreen() {
    const overlay = document.getElementById('previewFullscreenOverlay');
    previewFullscreen = !previewFullscreen;
    
    if (previewFullscreen) {
        // Mettre l'URL et la date dans la barre
        document.getElementById('previewFullscreenUrl').textContent = currentPageUrl || '';
        
        if (currentCrawlDate) {
            const date = new Date(currentCrawlDate);
            const formatted = date.toLocaleDateString('fr-FR') + ' à ' + date.toLocaleTimeString('fr-FR', {hour: '2-digit', minute: '2-digit'});
            document.getElementById('previewFullscreenDate').innerHTML = 'Crawlé le <strong>' + formatted + '</strong>';
        }
        
        // Charger l'iframe plein écran (sans la barre interne)
        const monitorUrl = window.location.pathname.includes('/pages/') 
            ? '../api/monitor/preview' 
            : 'api/monitor/preview';
        const iframeSrc = `${monitorUrl}?project=${encodeURIComponent(currentProject)}&id=${encodeURIComponent(currentUrlId)}&nobar=1`;
        const fullscreenIframe = document.getElementById('previewFullscreenIframe');
        fullscreenIframe.src = iframeSrc;
        
        // Désactiver les liens dans l'iframe plein écran
        fullscreenIframe.onload = function() {
            disableIframeLinks(fullscreenIframe);
        };
        
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    } else {
        overlay.classList.remove('active');
        document.body.style.overflow = '';
        document.getElementById('previewFullscreenIframe').src = 'about:blank';
    }
}

// Fermer le plein écran avec Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && previewFullscreen) {
        togglePreviewFullscreen();
    }
});

// Charger le code source HTML
function loadHtmlSource() {
    if (!currentUrlId) return;
    
    document.getElementById('htmlLoading').style.display = 'flex';
    document.getElementById('htmlEditorWrapper').style.opacity = '0.3';
    
    const apiUrl = window.location.pathname.includes('/pages/') 
        ? '../api/query/html-source' 
        : 'api/query/html-source';
    
    fetch(`${apiUrl}?project=${encodeURIComponent(currentProject)}&id=${encodeURIComponent(currentUrlId)}`, {
        signal: modalAbortController?.signal
    })
        .then(response => response.json())
        .then(data => {
            document.getElementById('htmlLoading').style.display = 'none';
            document.getElementById('htmlEditorWrapper').style.opacity = '1';
            
            if (data.success) {
                // Initialiser CodeMirror si pas encore fait
                if (!htmlSourceEditor) {
                    const textarea = document.getElementById('htmlSourceEditor');
                    htmlSourceEditor = CodeMirror.fromTextArea(textarea, {
                        mode: 'htmlmixed',
                        theme: 'eclipse',
                        lineNumbers: true,
                        lineWrapping: true,
                        readOnly: true,
                        viewportMargin: Infinity
                    });
                }
                // Formater le HTML avec js-beautify si disponible
                const rawHtml = data.html || '';
                const formattedHtml = typeof html_beautify === 'function' 
                    ? html_beautify(rawHtml, { indent_size: 2, wrap_line_length: 120 })
                    : rawHtml;
                htmlSourceEditor.setValue(formattedHtml);
                htmlSourceEditor.refresh();
                htmlSourceLoaded = true;
            } else {
                alert('Erreur: ' + (data.error || 'Impossible de charger le HTML'));
            }
        })
        .catch(error => {
            // Ignorer les erreurs d'annulation
            if (error.name === 'AbortError') return;
            document.getElementById('htmlLoading').style.display = 'none';
            document.getElementById('htmlEditorWrapper').style.opacity = '1';
            console.error('Fetch Error:', error);
            alert('Erreur de chargement: ' + error.message);
        });
}

// Fermer au clic sur le fond
document.getElementById('urlDetailsModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeUrlModal();
    }
});

// Fermer avec Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('urlDetailsModal').classList.contains('active')) {
        closeUrlModal();
    }
});
</script>
