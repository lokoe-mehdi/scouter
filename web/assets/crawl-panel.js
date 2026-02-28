/**
 * Crawl Panel - Gestion du panneau latéral de monitoring
 * 
 * Features:
 * - Support multi-crawls simultanés
 * - Sélecteur de crawl actif
 * - Polling Ajax pour les logs et stats
 * - Auto-scroll intelligent
 * - Notification minimisée avec compteur
 */

const CrawlPanel = {
    // État interne
    state: {
        isOpen: false,
        isMinimized: false,
        isCrawlListOpen: false,
        // Crawl actuellement affiché
        currentCrawl: null,
        // Liste de tous les crawls en cours (pour cet utilisateur)
        runningCrawls: [],
        // Crawls terminés non vus (pour notification) - persisté en localStorage
        finishedUnseenCrawls: [],
        // Crawls terminés vus dans cette session (pour le switch) - pas persisté
        sessionFinishedCrawls: [],
        // Crawls qu'on a vu en cours (pour détecter leur fin après refresh)
        trackedCrawlIds: [],
        // Interval de polling
        pollInterval: null,
        globalPollInterval: null,
        isAutoScrolling: true,
        // Compteur pour continuer le polling après fin du crawl
        finishingPollCount: 0,
        // Cache des stats maximales vues (ne peut que monter) - clé = crawl_id
        maxStatsCache: {}
    },
    
    // Base path pour les API (détecté automatiquement)
    basePath: (function() {
        const path = window.location.pathname;
        if (path.includes('/pages/')) {
            return '../';
        }
        return '';
    })(),

    // Éléments DOM
    elements: {},

    /**
     * Initialise les références DOM
     */
    init() {
        
        this.elements = {
            panel: document.getElementById('crawlPanel'),
            overlay: document.getElementById('crawlPanelOverlay'),
            minimized: document.getElementById('crawlPanelMinimized'),
            terminal: document.getElementById('crawlPanelTerminal'),
            scrollBtn: document.getElementById('crawlPanelScrollBtn'),
            projectName: document.getElementById('crawlPanelProjectName'),
            badge: document.getElementById('crawlPanelBadge'),
            statusDot: document.getElementById('crawlPanelStatusDot'),
            urlsFound: document.getElementById('crawlPanelUrlsFound'),
            urlsCrawled: document.getElementById('crawlPanelUrlsCrawled'),
            speed: document.getElementById('crawlPanelSpeed'),
            progress: document.getElementById('crawlPanelProgress'),
            progressBar: document.getElementById('crawlPanelProgressBar'),
            stopBtn: document.getElementById('crawlPanelStopBtn'),
            resumeBtn: document.getElementById('crawlPanelResumeBtn'),
            dashboardBtn: document.getElementById('crawlPanelDashboardBtn'),
            minimizedProgress: document.getElementById('crawlPanelMinimizedProgress'),
            minimizedText: document.getElementById('crawlPanelMinimizedText'),
            minimizedCount: document.getElementById('crawlPanelMinimizedCount'),
            selectorBtn: document.getElementById('crawlPanelSelectorBtn'),
            selectorBadge: document.getElementById('crawlPanelSelectorBadge'),
            crawlList: document.getElementById('crawlPanelCrawlList'),
            crawlListItems: document.getElementById('crawlPanelCrawlListItems')
        };

        // Setup auto-scroll detection
        if (this.elements.terminal) {
            this.elements.terminal.addEventListener('scroll', () => {
                const { scrollHeight, scrollTop, clientHeight } = this.elements.terminal;
                this.state.isAutoScrolling = scrollHeight - scrollTop <= clientHeight + 50;
                
                if (this.elements.scrollBtn) {
                    this.elements.scrollBtn.style.display = this.state.isAutoScrolling ? 'none' : 'flex';
                }
            });
        }

        // Charger les crawls terminés non vus depuis localStorage
        this.loadFinishedUnseenCrawls();
        
        // Charger le cache des stats max depuis sessionStorage
        this.loadMaxStatsCache();
        
        // Nettoyer les doublons éventuels
        this.cleanupDuplicates();
        
        // Charger les crawls trackés depuis localStorage
        this.loadTrackedCrawls();
        
        // Vérifier s'il y a des crawls en cours au chargement
        // et détecter les crawls trackés qui ont terminé
        this.checkRunningCrawls();
        
        // Poll global pour détecter les nouveaux crawls toutes les 5 secondes
        this.startGlobalPolling();
    },
    
    /**
     * Charge les crawls terminés non vus depuis localStorage
     */
    loadFinishedUnseenCrawls() {
        try {
            const saved = localStorage.getItem('crawlPanel_finishedUnseen');
            if (saved) {
                this.state.finishedUnseenCrawls = JSON.parse(saved);
            } else {
            }
        } catch (e) {
            console.error('loadFinishedUnseenCrawls: error:', e);
            this.state.finishedUnseenCrawls = [];
        }
    },
    
    /**
     * Sauvegarde les crawls terminés non vus dans localStorage
     */
    saveFinishedUnseenCrawls() {
        try {
            localStorage.setItem('crawlPanel_finishedUnseen', 
                JSON.stringify(this.state.finishedUnseenCrawls));
        } catch (e) {
            console.error('Error saving finished unseen crawls:', e);
        }
    },
    
    /**
     * Charge les crawls trackés depuis localStorage
     */
    loadTrackedCrawls() {
        try {
            const saved = localStorage.getItem('crawlPanel_tracked');
            if (saved) {
                this.state.trackedCrawlIds = JSON.parse(saved);
            } else {
            }
        } catch (e) {
            console.error('loadTrackedCrawls: error:', e);
            this.state.trackedCrawlIds = [];
        }
    },
    
    /**
     * Sauvegarde les crawls trackés dans localStorage
     */
    saveTrackedCrawls() {
        try {
            localStorage.setItem('crawlPanel_tracked', 
                JSON.stringify(this.state.trackedCrawlIds));
        } catch (e) {
            console.error('Error saving tracked crawls:', e);
        }
    },
    
    /**
     * Ajoute un crawl au tracking
     */
    trackCrawl(crawlId) {
        const id = parseInt(crawlId, 10);
        if (isNaN(id)) {
            return;
        }
        if (!this.state.trackedCrawlIds.includes(id)) {
            this.state.trackedCrawlIds.push(id);
            this.saveTrackedCrawls();
        }
    },
    
    /**
     * Marque un crawl comme vu (le retire de finishedUnseenCrawls)
     */
    markCrawlAsSeen(crawlId) {
        if (!crawlId) {
            return;
        }
        
        // Convertir en number pour comparaison cohérente
        const id = parseInt(crawlId, 10);
        if (isNaN(id)) {
            return;
        }
        
        
        const index = this.state.finishedUnseenCrawls.findIndex(c => {
            const cId = parseInt(c.crawl_id, 10);
            return cId === id;
        });
        
        
        if (index !== -1) {
            this.state.finishedUnseenCrawls.splice(index, 1);
            this.saveFinishedUnseenCrawls();
            this.updateMinimizedBadge();
            this.updateCrawlList();
        } else {
        }
        
        // Retirer aussi du tracking
        const trackIndex = this.state.trackedCrawlIds.indexOf(id);
        if (trackIndex !== -1) {
            this.state.trackedCrawlIds.splice(trackIndex, 1);
            this.saveTrackedCrawls();
        }
    },

    /**
     * Vérifie s'il y a des crawls en cours au chargement de la page
     */
    async checkRunningCrawls() {
        try {
            const response = await fetch(`${this.basePath}api/crawls/running`);
            const data = await response.json();
            
            if (data.success && data.crawls && data.crawls.length > 0) {
                this.state.runningCrawls = data.crawls;
                
                // Tracker tous les crawls en cours
                data.crawls.forEach(c => this.trackCrawl(c.crawl_id));
            } else {
                this.state.runningCrawls = [];
            }
            
            // Détecter les crawls trackés qui ont terminé pendant l'absence
            // (ils sont dans trackedCrawlIds mais plus dans runningCrawls et pas encore dans finishedUnseen)
            const runningIds = this.state.runningCrawls.map(c => parseInt(c.crawl_id, 10));
            const finishedIds = await this.checkFinishedTrackedCrawls(runningIds);
            
            this.updateCrawlList();
            this.updateMinimizedBadge();
            
            // Afficher la notification si crawls en cours OU terminés non vus
            if (this.state.runningCrawls.length > 0 || this.state.finishedUnseenCrawls.length > 0) {
                this.showMinimized();
            }
        } catch (error) {
            console.error('Error checking running crawls:', error);
        }
    },
    
    /**
     * Vérifie les crawls trackés qui ont terminé pendant l'absence
     */
    async checkFinishedTrackedCrawls(runningIds) {
        
        const finishedIds = [];
        
        for (const trackedId of this.state.trackedCrawlIds) {
            // Si le crawl tracké n'est plus en cours
            if (!runningIds.includes(trackedId)) {
                
                // Vérifier s'il n'est pas déjà dans les non vus
                const alreadyUnseen = this.state.finishedUnseenCrawls.find(
                    c => parseInt(c.crawl_id, 10) === trackedId
                );
                
                if (!alreadyUnseen) {
                    // Récupérer les infos du crawl via l'API
                    try {
                        const resp = await fetch(`${this.basePath}api/crawls/info?project=${trackedId}`);
                        
                        // Vérifier que la réponse est OK
                        if (!resp.ok) {
                            // Retirer ce crawl du tracking car il n'existe plus
                            const idx = this.state.trackedCrawlIds.indexOf(trackedId);
                            if (idx !== -1) {
                                this.state.trackedCrawlIds.splice(idx, 1);
                            }
                            continue;
                        }
                        
                        const crawlData = await resp.json();
                        
                        if (crawlData.success && crawlData.crawl) {
                            const finishedCrawl = {
                                crawl_id: trackedId,
                                domain: crawlData.crawl.domain || 'Crawl terminé',
                                project_dir: crawlData.crawl.project_dir || '',
                                urls: parseInt(crawlData.crawl.urls, 10) || 0,
                                crawled: parseInt(crawlData.crawl.crawled, 10) || 0,
                                status: 'completed',
                                finishedAt: Date.now()
                            };
                            this.state.finishedUnseenCrawls.push(finishedCrawl);
                            finishedIds.push(trackedId);
                            
                            // IMPORTANT: Retirer du tracking pour éviter de le re-détecter
                            const idx = this.state.trackedCrawlIds.indexOf(trackedId);
                            if (idx !== -1) {
                                this.state.trackedCrawlIds.splice(idx, 1);
                            }
                        } else {
                            // Retirer ce crawl du tracking car il n'existe plus
                            const idx = this.state.trackedCrawlIds.indexOf(trackedId);
                            if (idx !== -1) {
                                this.state.trackedCrawlIds.splice(idx, 1);
                            }
                        }
                    } catch (e) {
                        console.error('checkFinishedTrackedCrawls: Error fetching crawl info:', e);
                        // En cas d'erreur, retirer du tracking pour éviter les boucles
                        const idx = this.state.trackedCrawlIds.indexOf(trackedId);
                        if (idx !== -1) {
                            this.state.trackedCrawlIds.splice(idx, 1);
                        }
                    }
                } else {
                }
            }
        }
        
        if (finishedIds.length > 0) {
            this.saveFinishedUnseenCrawls();
        }
        
        // Sauvegarder les tracked après nettoyage des IDs invalides
        this.saveTrackedCrawls();
        
        return finishedIds;
    },

    /**
     * Démarre le polling global (pour détecter nouveaux crawls)
     */
    startGlobalPolling() {
        if (this.state.globalPollInterval) {
            clearInterval(this.state.globalPollInterval);
        }
        
        this.state.globalPollInterval = setInterval(() => {
            this.refreshRunningCrawlsList();
        }, 2000);
    },

    /**
     * Rafraîchit la liste des crawls en cours (NE change PAS le crawl sélectionné)
     */
    async refreshRunningCrawlsList() {
        try {
            const response = await fetch(`${this.basePath}api/crawls/running`);
            const data = await response.json();
            
            if (data.success) {
                const newRunningCrawls = data.crawls || [];
                const oldRunningCrawls = this.state.runningCrawls;
                
                // Détecter les crawls qui ont disparu (= terminés)
                // et les ajouter aux non vus s'ils n'y sont pas déjà
                oldRunningCrawls.forEach(oldCrawl => {
                    const oldId = parseInt(oldCrawl.crawl_id, 10);
                    const stillRunning = newRunningCrawls.find(c => parseInt(c.crawl_id, 10) === oldId);
                    if (!stillRunning) {
                        // Ce crawl a terminé !
                        // Préserver le statut existant s'il est déjà terminal (stopped, failed, error)
                        const terminalStatuses = ['stopped', 'failed', 'error', 'completed'];
                        const existingStatus = oldCrawl.status;
                        const finalStatus = terminalStatuses.includes(existingStatus) ? existingStatus : 'completed';
                        
                        const finishedCrawl = {
                            ...oldCrawl,
                            crawl_id: oldId,
                            status: finalStatus,
                            finishedAt: Date.now()
                        };
                        
                        // Si panel ouvert → considéré comme VU (retirer du tracking)
                        if (this.state.isOpen) {
                            const alreadyInSession = this.state.sessionFinishedCrawls.find(
                                c => parseInt(c.crawl_id, 10) === oldId
                            );
                            if (!alreadyInSession) {
                                this.state.sessionFinishedCrawls.push(finishedCrawl);
                            }
                            // Retirer du tracking pour ne pas le re-détecter comme unseen
                            this.state.trackedCrawlIds = this.state.trackedCrawlIds.filter(id => id !== oldId);
                            this.saveTrackedCrawls();
                        } else {
                            const alreadyInUnseen = this.state.finishedUnseenCrawls.find(
                                c => parseInt(c.crawl_id, 10) === oldId
                            );
                            if (!alreadyInUnseen) {
                                this.state.finishedUnseenCrawls.push(finishedCrawl);
                                this.saveFinishedUnseenCrawls();
                            }
                        }
                    }
                });
                
                // Fusionner les nouvelles données avec le cache des stats max
                this.state.runningCrawls = newRunningCrawls.map(newCrawl => {
                    const crawlId = parseInt(newCrawl.crawl_id, 10);
                    const urls = parseInt(newCrawl.urls, 10) || 0;
                    const crawled = parseInt(newCrawl.crawled, 10) || 0;
                    
                    // Mettre à jour le cache avec les valeurs max
                    this.updateMaxStats(crawlId, urls, crawled);
                    const maxStats = this.getMaxStats(crawlId);
                    
                    return {
                        ...newCrawl,
                        urls: maxStats.urls,
                        crawled: maxStats.crawled
                    };
                });
                
                // Remove crawls from finished lists if they're now running again (resumed)
                const runningIds = newRunningCrawls.map(c => parseInt(c.crawl_id, 10));
                this.state.sessionFinishedCrawls = this.state.sessionFinishedCrawls.filter(
                    c => !runningIds.includes(parseInt(c.crawl_id, 10))
                );
                this.state.finishedUnseenCrawls = this.state.finishedUnseenCrawls.filter(
                    c => !runningIds.includes(parseInt(c.crawl_id, 10))
                );
                this.saveFinishedUnseenCrawls();
                
                // Mettre à jour la liste et le badge
                this.updateCrawlList();
                this.updateMinimizedBadge();
                
                // Gérer l'affichage de la notification
                const hasRunning = this.state.runningCrawls.length > 0;
                const hasUnseen = this.state.finishedUnseenCrawls.length > 0;
                
                if (hasRunning || hasUnseen) {
                    // Toujours afficher/mettre à jour la notification si panel fermé
                    if (!this.state.isOpen) {
                        this.showMinimized();
                    }
                } else {
                    // Plus rien à afficher
                    this.elements.minimized.classList.remove('is-visible');
                }
                
                // Vérifier si le crawl actuellement affiché est toujours en cours
                if (this.state.currentCrawl) {
                    const stillRunning = this.state.runningCrawls.find(
                        c => c.crawl_id === this.state.currentCrawl.crawl_id
                    );
                    if (!stillRunning) {
                        // Ne marquer comme terminé que si le crawl était vraiment en cours (running/processing)
                        // Les crawls queued/stopped/failed/error/completed ne doivent pas être changés
                        const currentStatus = this.state.currentCrawl.status;
                        const wasActuallyRunning = ['running', 'processing'].includes(currentStatus);
                        if (wasActuallyRunning) {
                            this.updateStatus('completed');
                        }
                    }
                }
            }
        } catch (error) {
            console.error('Error refreshing crawls list:', error);
        }
    },

    /**
     * Ajoute/démarre un nouveau crawl
     */
    start(projectDir, projectName, crawlId) {
        
        const newCrawl = {
            crawl_id: crawlId,
            project_dir: projectDir,
            domain: projectName,
            status: 'running',
            urls: 0,
            crawled: 0
        };
        
        // Tracker ce crawl pour détecter sa fin même après refresh
        this.trackCrawl(crawlId);
        
        // Ajouter à la liste s'il n'existe pas
        const exists = this.state.runningCrawls.find(c => c.crawl_id === crawlId);
        if (!exists) {
            this.state.runningCrawls.unshift(newCrawl);
        }
        
        // Sélectionner ce crawl
        this.selectCrawl(newCrawl);
        
        // Mettre à jour l'UI
        this.updateCrawlList();
        this.updateMinimizedBadge();
        
        // Ouvrir le panel
        this.open();
        
        // Démarrer le polling
        this.startPolling();
    },

    /**
     * Sélectionne un crawl à afficher
     */
    selectCrawl(crawl) {
        
        this.state.currentCrawl = crawl;
        
        // Fermer la liste des crawls
        this.hideCrawlList();
        
        // Mettre à jour l'affichage
        if (this.elements.projectName) {
            this.elements.projectName.textContent = crawl.domain || crawl.project_dir || 'Crawl';
        }
        if (this.elements.dashboardBtn) {
            this.elements.dashboardBtn.href = `${this.basePath}dashboard.php?crawl=${crawl.crawl_id}`;
        }
        
        // Reset terminal
        this.clearTerminal();
        this.resetKPIs();
        
        // Mettre à jour le statut
        this.updateStatus(crawl.status || 'running');
        
        // Mettre à jour la sélection dans la liste
        this.updateCrawlListSelection();
        
        // Le marquage comme "vu" se fait dans open() pour tous les crawls d'un coup
        // Donc pas besoin de markCrawlAsSeen ici
        
        // Redémarrer le polling pour ce crawl (s'il est en cours)
        const isRunning = ['running', 'pending', 'processing', 'queued', 'stopping'].includes(crawl.status);
        if (isRunning) {
            this.startPolling();
        } else {
            // Crawl terminé - faire un seul poll pour récupérer les logs
            this.stopPolling();
            this.poll();
        }
    },

    /**
     * Met à jour la liste des crawls dans le dropdown
     */
    updateCrawlList() {
        if (!this.elements.crawlListItems) return;
        
        const runningCount = this.state.runningCrawls.length;
        const unseenCount = this.state.finishedUnseenCrawls.length;
        const sessionCount = this.state.sessionFinishedCrawls.length;
        const totalCount = runningCount + unseenCount + sessionCount;
        
        // Afficher le sélecteur si plus d'un crawl
        const showSelector = totalCount > 1;
        
        if (this.elements.selectorBtn) {
            this.elements.selectorBtn.style.display = showSelector ? 'flex' : 'none';
        }
        if (this.elements.selectorBadge) {
            this.elements.selectorBadge.textContent = totalCount;
        }
        
        // Générer les items (crawls en cours)
        // Utiliser le cache des stats max pour éviter les oscillations
        const currentId = this.state.currentCrawl ? parseInt(this.state.currentCrawl.crawl_id, 10) : null;
        let itemsHtml = this.state.runningCrawls.map(crawl => {
            const crawlId = parseInt(crawl.crawl_id, 10);
            const isActive = currentId !== null && crawlId === currentId;
            const maxStats = this.getMaxStats(crawlId);
            const urls = maxStats.urls;
            const crawled = maxStats.crawled;
            const progress = urls > 0 
                ? Math.round((crawled / urls) * 100) 
                : 0;
            
            return `
                <div class="crawl-panel-crawl-list-item ${isActive ? 'active' : ''}" 
                     onclick="CrawlPanel.selectCrawlById(${crawl.crawl_id})">
                    <div class="crawl-panel-crawl-list-item-dot"></div>
                    <div class="crawl-panel-crawl-list-item-info">
                        <div class="crawl-panel-crawl-list-item-name">${this.escapeHtml(crawl.domain || 'Crawl')}</div>
                        <div class="crawl-panel-crawl-list-item-stats">
                            ${crawled.toLocaleString()} / ${urls.toLocaleString()} URLs
                        </div>
                    </div>
                    <div class="crawl-panel-crawl-list-item-progress">${progress}%</div>
                </div>
            `;
        }).join('');
        
        // Ajouter les crawls terminés non vus (avec badge "non vu")
        if (unseenCount > 0) {
            const unseenHtml = this.state.finishedUnseenCrawls.map(crawl => {
                const isActive = currentId !== null && parseInt(crawl.crawl_id, 10) === currentId;
                return `
                    <div class="crawl-panel-crawl-list-item finished ${isActive ? 'active' : ''}" 
                         onclick="CrawlPanel.selectCrawlById(${crawl.crawl_id})">
                        <div class="crawl-panel-crawl-list-item-dot" style="background: var(--success); animation: none;"></div>
                        <div class="crawl-panel-crawl-list-item-info">
                            <div class="crawl-panel-crawl-list-item-name">${this.escapeHtml(crawl.domain)}</div>
                            <div class="crawl-panel-crawl-list-item-stats" style="color: var(--success);">Terminé (non vu)</div>
                        </div>
                        <div class="crawl-panel-crawl-list-item-progress" style="color: var(--success);">✓</div>
                    </div>
                `;
            }).join('');
            itemsHtml = itemsHtml + unseenHtml;
        }
        
        // Ajouter les crawls terminés de session (déjà vus, pour le switch)
        if (sessionCount > 0) {
            const sessionHtml = this.state.sessionFinishedCrawls.map(crawl => {
                const isActive = currentId !== null && parseInt(crawl.crawl_id, 10) === currentId;
                return `
                    <div class="crawl-panel-crawl-list-item finished ${isActive ? 'active' : ''}" 
                         onclick="CrawlPanel.selectCrawlById(${crawl.crawl_id})">
                        <div class="crawl-panel-crawl-list-item-dot" style="background: var(--success); animation: none;"></div>
                        <div class="crawl-panel-crawl-list-item-info">
                            <div class="crawl-panel-crawl-list-item-name">${this.escapeHtml(crawl.domain)}</div>
                            <div class="crawl-panel-crawl-list-item-stats" style="color: #94a3b8;">Terminé</div>
                        </div>
                        <div class="crawl-panel-crawl-list-item-progress" style="color: var(--success);">✓</div>
                    </div>
                `;
            }).join('');
            itemsHtml = itemsHtml + sessionHtml;
        }
        
        this.elements.crawlListItems.innerHTML = itemsHtml;
    },

    /**
     * Met à jour la sélection visuelle dans la liste
     */
    updateCrawlListSelection() {
        if (!this.elements.crawlListItems) return;
        
        const currentId = this.state.currentCrawl ? parseInt(this.state.currentCrawl.crawl_id, 10) : null;
        const items = this.elements.crawlListItems.querySelectorAll('.crawl-panel-crawl-list-item');
        items.forEach(item => {
            const crawlId = parseInt(item.getAttribute('onclick').match(/\d+/)[0], 10);
            item.classList.toggle('active', currentId !== null && crawlId === currentId);
        });
    },

    /**
     * Sélectionne un crawl par ID
     */
    selectCrawlById(crawlId) {
        const id = parseInt(crawlId, 10);
        
        // Chercher dans les crawls en cours
        let crawl = this.state.runningCrawls.find(c => parseInt(c.crawl_id, 10) === id);
        
        // Si pas trouvé, chercher dans les terminés non vus
        if (!crawl) {
            crawl = this.state.finishedUnseenCrawls.find(c => parseInt(c.crawl_id, 10) === id);
        }
        
        // Si pas trouvé, chercher dans les terminés de session
        if (!crawl) {
            crawl = this.state.sessionFinishedCrawls.find(c => parseInt(c.crawl_id, 10) === id);
        }
        
        // Si pas trouvé, vérifier si c'est le crawl actuel
        if (!crawl && this.state.currentCrawl && parseInt(this.state.currentCrawl.crawl_id, 10) === id) {
            crawl = this.state.currentCrawl;
        }
        
        if (crawl) {
            this.selectCrawl(crawl);
        }
    },

    /**
     * Toggle la liste des crawls
     */
    toggleCrawlList() {
        if (this.state.isCrawlListOpen) {
            this.hideCrawlList();
        } else {
            this.showCrawlList();
        }
    },

    showCrawlList() {
        this.state.isCrawlListOpen = true;
        if (this.elements.crawlList) {
            this.elements.crawlList.style.display = 'block';
        }
    },

    hideCrawlList() {
        this.state.isCrawlListOpen = false;
        if (this.elements.crawlList) {
            this.elements.crawlList.style.display = 'none';
        }
    },

    /**
     * Met à jour le badge minimisé
     */
    updateMinimizedBadge() {
        const runningCount = this.state.runningCrawls.length;
        const unseenCount = this.state.finishedUnseenCrawls.length;
        const totalCount = runningCount + unseenCount;
        
        // Cas 1: Des crawls en cours
        if (runningCount > 0) {
            if (this.elements.minimizedCount) {
                if (totalCount > 1) {
                    this.elements.minimizedCount.style.display = 'flex';
                    this.elements.minimizedCount.textContent = totalCount;
                } else {
                    this.elements.minimizedCount.style.display = 'none';
                }
            }
            
            if (this.elements.minimizedText) {
                this.elements.minimizedText.textContent = runningCount > 1 
                    ? 'Crawls en cours' 
                    : 'Crawl en cours';
            }
            
            // Calculer la progression globale (pondérée par le nombre d'URLs)
            // Utiliser le cache des stats max pour éviter les oscillations
            if (this.elements.minimizedProgress) {
                let totalCrawled = 0;
                let totalUrls = 0;
                this.state.runningCrawls.forEach(crawl => {
                    const crawlId = parseInt(crawl.crawl_id, 10);
                    const maxStats = this.getMaxStats(crawlId);
                    totalCrawled += maxStats.crawled;
                    totalUrls += maxStats.urls;
                });
                const globalProgress = totalUrls > 0 ? Math.round((totalCrawled / totalUrls) * 100) : 0;
                this.elements.minimizedProgress.textContent = globalProgress + '%';
            }
            
            // Changer la couleur si des crawls terminés non vus
            if (this.elements.minimized && unseenCount > 0) {
                this.elements.minimized.classList.add('has-finished');
            } else if (this.elements.minimized) {
                this.elements.minimized.classList.remove('has-finished');
            }
        }
        // Cas 2: Pas de crawls en cours, mais des terminés non vus
        else if (unseenCount > 0) {
            if (this.elements.minimizedCount) {
                if (unseenCount > 1) {
                    this.elements.minimizedCount.style.display = 'flex';
                    this.elements.minimizedCount.textContent = unseenCount;
                } else {
                    this.elements.minimizedCount.style.display = 'none';
                }
            }
            
            if (this.elements.minimizedText) {
                this.elements.minimizedText.textContent = unseenCount > 1 
                    ? 'Crawls terminés' 
                    : 'Crawl terminé';
            }
            
            if (this.elements.minimizedProgress) {
                this.elements.minimizedProgress.textContent = '✓';
            }
            
            if (this.elements.minimized) {
                this.elements.minimized.classList.add('is-finished');
                this.elements.minimized.classList.remove('has-finished');
            }
        }
        // Cas 3: Rien
        else {
            if (this.elements.minimized) {
                this.elements.minimized.classList.remove('has-finished', 'is-finished');
            }
        }
    },

    /**
     * Ouvre le panneau
     */
    open() {
        if (!this.elements.panel) this.init();
        
        // Réactiver la notification (l'user a ouvert le panel = il veut voir les crawls)
        this.enableNotification();
        
        this.state.isOpen = true;
        this.state.isMinimized = false;
        
        this.elements.panel.classList.add('is-open');
        this.elements.overlay.classList.add('is-visible');
        this.elements.minimized.classList.remove('is-visible');
        
        // Marquer TOUS les crawls terminés non vus comme vus d'un coup
        // Mais les garder dans sessionFinishedCrawls pour le switch
        if (this.state.finishedUnseenCrawls.length > 0) {
            // Ajouter aux crawls de session (pour le switch)
            this.state.finishedUnseenCrawls.forEach(crawl => {
                const alreadyInSession = this.state.sessionFinishedCrawls.find(
                    c => parseInt(c.crawl_id, 10) === parseInt(crawl.crawl_id, 10)
                );
                if (!alreadyInSession) {
                    this.state.sessionFinishedCrawls.push(crawl);
                }
            });
            // Vider les unseen et sauvegarder
            this.state.finishedUnseenCrawls = [];
            this.saveFinishedUnseenCrawls();
            // Retirer du tracking aussi
            this.state.sessionFinishedCrawls.forEach(c => {
                const idx = this.state.trackedCrawlIds.indexOf(parseInt(c.crawl_id, 10));
                if (idx !== -1) this.state.trackedCrawlIds.splice(idx, 1);
            });
            this.saveTrackedCrawls();
            // Mettre à jour l'UI
            this.updateMinimizedBadge();
            this.updateCrawlList();
        }
        
        // Sélectionner un crawl à afficher
        // Priorité : crawl en cours > crawl terminé de session > crawl actuel
        if (this.state.runningCrawls.length > 0) {
            this.selectCrawl(this.state.runningCrawls[0]);
        } else if (this.state.sessionFinishedCrawls.length > 0) {
            this.selectCrawl(this.state.sessionFinishedCrawls[0]);
        } else if (this.state.currentCrawl) {
            this.poll();
        }
        
        // Démarrer le polling si un crawl en cours est sélectionné
        if (this.state.currentCrawl && !this.state.pollInterval) {
            const isRunning = ['running', 'pending', 'processing', 'queued', 'stopping'].includes(this.state.currentCrawl.status);
            if (isRunning) {
                this.startPolling();
            }
        }
        
        this.elements.panel.focus();
    },

    /**
     * Minimise le panneau
     */
    minimize() {
        
        // Vider les crawls de session (ils ont été vus)
        this.state.sessionFinishedCrawls = [];
        
        this.state.isOpen = false;
        this.state.isMinimized = true;
        
        this.elements.panel.classList.remove('is-open');
        this.elements.overlay.classList.remove('is-visible');
        this.hideCrawlList();
        
        // Afficher la notification si des crawls en cours OU terminés non vus
        if (this.state.runningCrawls.length > 0 || this.state.finishedUnseenCrawls.length > 0) {
            this.showMinimized();
        }
    },

    /**
     * Ferme complètement le panneau
     */
    close() {
        
        // Vider les crawls de session (ils ont été vus)
        this.state.sessionFinishedCrawls = [];
        
        this.state.isOpen = false;
        this.state.isMinimized = false;
        
        this.elements.panel.classList.remove('is-open');
        this.elements.overlay.classList.remove('is-visible');
        this.hideCrawlList();
        
        // Garder la notification si des crawls en cours OU terminés non vus
        if (this.state.runningCrawls.length > 0 || this.state.finishedUnseenCrawls.length > 0) {
            this.showMinimized();
        } else {
            this.elements.minimized.classList.remove('is-visible');
        }
    },

    /**
     * Affiche la notification minimisée (sauf si masquée par l'utilisateur)
     */
    showMinimized() {
        // Ne pas afficher si l'utilisateur a masqué la notification
        if (this.isNotificationHidden()) {
            return;
        }
        this.updateMinimizedBadge();
        this.elements.minimized.classList.add('is-visible');
    },

    /**
     * Masque la notification pour la session (sans annuler les crawls)
     */
    hideNotification(event) {
        event.stopPropagation();
        
        // Marquer comme masqué pour cette session
        sessionStorage.setItem('crawlPanel_hidden', 'true');
        
        // Cacher la notification
        this.elements.minimized.classList.remove('is-visible');
    },
    
    /**
     * Vérifie si la notification doit être masquée (session)
     */
    isNotificationHidden() {
        return sessionStorage.getItem('crawlPanel_hidden') === 'true';
    },
    
    /**
     * Réactive la notification (appelé quand on ouvre le panel)
     */
    enableNotification() {
        sessionStorage.removeItem('crawlPanel_hidden');
    },
    
    /**
     * Met à jour le cache des stats maximales pour un crawl
     */
    updateMaxStats(crawlId, urls, crawled) {
        const id = parseInt(crawlId, 10);
        if (!this.state.maxStatsCache[id]) {
            this.state.maxStatsCache[id] = { urls: 0, crawled: 0 };
        }
        this.state.maxStatsCache[id].urls = Math.max(this.state.maxStatsCache[id].urls, urls || 0);
        this.state.maxStatsCache[id].crawled = Math.max(this.state.maxStatsCache[id].crawled, crawled || 0);
        // Persister dans sessionStorage
        this.saveMaxStatsCache();
    },
    
    /**
     * Récupère les stats maximales pour un crawl
     */
    getMaxStats(crawlId) {
        const id = parseInt(crawlId, 10);
        return this.state.maxStatsCache[id] || { urls: 0, crawled: 0 };
    },
    
    /**
     * Réinitialise le cache des stats pour un crawl (quand il est terminé)
     */
    clearMaxStats(crawlId) {
        const id = parseInt(crawlId, 10);
        delete this.state.maxStatsCache[id];
        this.saveMaxStatsCache();
    },
    
    /**
     * Sauvegarde le cache dans sessionStorage
     */
    saveMaxStatsCache() {
        try {
            sessionStorage.setItem('crawlPanel_maxStats', JSON.stringify(this.state.maxStatsCache));
        } catch (e) {
            // Ignore storage errors
        }
    },
    
    /**
     * Charge le cache depuis sessionStorage
     */
    loadMaxStatsCache() {
        try {
            const saved = sessionStorage.getItem('crawlPanel_maxStats');
            if (saved) {
                this.state.maxStatsCache = JSON.parse(saved);
            }
        } catch (e) {
            this.state.maxStatsCache = {};
        }
    },

    /**
     * Démarre le polling des données
     */
    startPolling() {
        this.stopPolling();
        this.poll();
        this.state.pollInterval = setInterval(() => this.poll(), 500);
    },

    /**
     * Arrête le polling
     */
    stopPolling() {
        if (this.state.pollInterval) {
            clearInterval(this.state.pollInterval);
            this.state.pollInterval = null;
        }
    },

    /**
     * Effectue un poll des données du crawl actuel
     */
    async poll() {
        if (!this.state.currentCrawl) return;

        const projectDir = this.state.currentCrawl.project_dir;
        
        try {
            const [statusRes, logsRes, statsRes] = await Promise.all([
                fetch(`${this.basePath}api/jobs/status?project_dir=${encodeURIComponent(projectDir)}`),
                fetch(`${this.basePath}api/jobs/logs?project_dir=${encodeURIComponent(projectDir)}&_t=${Date.now()}`),
                fetch(`${this.basePath}api/crawls/info?project=${encodeURIComponent(projectDir)}`)
            ]);

            const [statusData, logsData, statsData] = await Promise.all([
                statusRes.json(),
                logsRes.json(),
                statsRes.json()
            ]);

            // Mettre à jour le domain si disponible dans stats (source de vérité)
            if (statsData.domain && this.state.currentCrawl) {
                this.state.currentCrawl.domain = statsData.domain;
                if (this.elements.projectName) {
                    this.elements.projectName.textContent = statsData.domain;
                }
            }

            // Mettre à jour le cache des stats max et le crawl actuel dans la liste
            const crawlId = parseInt(this.state.currentCrawl.crawl_id, 10);
            const urls = parseInt(statsData.urls, 10) || 0;
            const crawled = parseInt(statsData.crawled, 10) || 0;
            
            // Toujours mettre à jour le cache avec les valeurs max
            this.updateMaxStats(crawlId, urls, crawled);
            const maxStats = this.getMaxStats(crawlId);
            
            const crawlIndex = this.state.runningCrawls.findIndex(
                c => parseInt(c.crawl_id, 10) === crawlId
            );
            if (crawlIndex !== -1) {
                this.state.runningCrawls[crawlIndex].urls = maxStats.urls;
                this.state.runningCrawls[crawlIndex].crawled = maxStats.crawled;
                this.state.runningCrawls[crawlIndex].status = statusData.status;
                if (statsData.domain) {
                    this.state.runningCrawls[crawlIndex].domain = statsData.domain;
                }
            }
            
            // Mettre à jour aussi le currentCrawl
            this.state.currentCrawl.urls = maxStats.urls;
            this.state.currentCrawl.crawled = maxStats.crawled;

            // Utiliser le status du CRAWL (statsData.status) comme source de vérité
            // Mapper les status crawl vers les status UI
            const crawlToUiStatus = {
                'queued': 'queued',
                'running': 'running',
                'stopping': 'stopping',
                'stopped': 'stopped',
                'finished': 'completed',
                'error': 'failed'
            };
            const finalStatus = statsData.status ? (crawlToUiStatus[statsData.status] || statusData.status) : statusData.status;
            
            // Mettre à jour le status
            if (finalStatus) {
                this.updateStatus(finalStatus);
            }

            // Mettre à jour les KPIs
            this.updateKPIs(statsData, logsData);

            // Mettre à jour les logs
            this.updateLogs(logsData);

            // Mettre à jour le badge minimisé
            this.updateMinimizedBadge();

            // Gérer la fin du crawl (avec délai pour récupérer tous les logs)
            if (['completed', 'stopped', 'failed'].includes(statusData.status)) {
                // Ne pas arrêter immédiatement - attendre quelques polls pour avoir tous les logs
                if (!this.state.finishingPollCount) {
                    this.state.finishingPollCount = 1;
                } else {
                    this.state.finishingPollCount++;
                }
                
                // Continuer à poller pendant 5 cycles (2.5 secondes) après la fin
                if (this.state.finishingPollCount >= 5) {
                    this.state.finishingPollCount = 0;
                    this.handleCrawlEnd(statusData.status);
                }
            } else {
                // Réinitialiser le compteur si pas terminé
                this.state.finishingPollCount = 0;
            }

        } catch (error) {
            console.error('CrawlPanel poll error:', error);
        }
    },

    /**
     * Met à jour le statut affiché
     */
    updateStatus(status) {
        if (this.state.currentCrawl) {
            this.state.currentCrawl.status = status;
        }
        
        const statusLabels = {
            pending: 'En attente',
            queued: 'En file d\'attente',
            running: 'En cours...',
            stopping: 'Arrêt en cours...',
            processing: 'Post-traitements...',
            completed: 'Terminé',
            finished: 'Terminé',
            stopped: 'Arrêté',
            failed: 'Échoué',
            error: 'Erreur'
        };

        if (this.elements.badge) {
            this.elements.badge.textContent = statusLabels[status] || status;
            this.elements.badge.className = 'crawl-panel-badge ' + status;
        }

        if (this.elements.statusDot) {
            this.elements.statusDot.className = 'crawl-panel-status-dot ' + status;
        }

        // Boutons - montrer Stop si en cours, Resume si stoppé/échoué, Dashboard si terminé
        const isRunning = ['running', 'processing', 'pending', 'queued'].includes(status);
        const isStopping = status === 'stopping';
        const isResumable = ['stopped', 'failed', 'error'].includes(status);
        const isFinished = ['completed', 'stopped', 'finished', 'failed', 'error'].includes(status);
        
        if (this.elements.stopBtn) {
            // Afficher le bouton si en cours OU en arrêt
            this.elements.stopBtn.style.display = (isRunning || isStopping) ? 'flex' : 'none';
            // Désactiver si déjà en cours d'arrêt
            this.elements.stopBtn.disabled = isStopping;
            if (isStopping) {
                this.elements.stopBtn.innerHTML = '<span class="material-symbols-outlined spinning">progress_activity</span> Arrêt...';
            } else {
                this.elements.stopBtn.innerHTML = '<span class="material-symbols-outlined">stop_circle</span> Arrêter';
            }
        }
        if (this.elements.resumeBtn) {
            this.elements.resumeBtn.style.display = isResumable ? 'flex' : 'none';
        }
        if (this.elements.dashboardBtn) {
            this.elements.dashboardBtn.style.display = isFinished ? 'flex' : 'none';
        }
    },

    /**
     * Met à jour les KPIs
     */
    updateKPIs(stats, logs) {
        // Utiliser le cache des stats max pour éviter les oscillations
        const crawlId = this.state.currentCrawl ? parseInt(this.state.currentCrawl.crawl_id, 10) : null;
        let urlsFound = parseInt(stats.urls, 10) || 0;
        let urlsCrawled = parseInt(stats.crawled, 10) || 0;
        
        if (crawlId) {
            this.updateMaxStats(crawlId, urlsFound, urlsCrawled);
            const maxStats = this.getMaxStats(crawlId);
            urlsFound = maxStats.urls;
            urlsCrawled = maxStats.crawled;
        }
        
        // Calculer la progression
        // Si le crawl est terminé (completed/stopped), c'est 100%
        let progress = 0;
        const currentStatus = this.state.currentCrawl?.status;
        if (currentStatus === 'completed' || currentStatus === 'stopped') {
            progress = 100;
        } else if (urlsFound > 0) {
            progress = Math.round((urlsCrawled / urlsFound) * 100);
        }

        // Extraire la vitesse des logs
        let speed = 0;
        if (logs.file_logs) {
            const depthLogs = logs.file_logs.filter(l => l.message.match(/Depth \d+ : ([\d.]+) URLs\/sec/));
            if (depthLogs.length > 0) {
                const lastDepth = depthLogs[depthLogs.length - 1];
                const match = lastDepth.message.match(/([\d.]+) URLs\/sec/);
                if (match) {
                    speed = parseFloat(match[1]);
                }
            }
        }

        if (this.elements.urlsFound) {
            this.elements.urlsFound.textContent = urlsFound.toLocaleString();
        }
        if (this.elements.urlsCrawled) {
            this.elements.urlsCrawled.textContent = urlsCrawled.toLocaleString();
        }
        if (this.elements.speed) {
            this.elements.speed.textContent = speed.toFixed(1);
        }
        if (this.elements.progress) {
            this.elements.progress.textContent = progress + '%';
        }
        if (this.elements.progressBar) {
            this.elements.progressBar.style.width = progress + '%';
        }
    },

    /**
     * Met à jour les logs dans le terminal
     */
    updateLogs(logsData) {
        if (!logsData.file_logs || !this.elements.terminal) return;

        const logs = logsData.file_logs;
        
        // Filtrer et organiser les logs
        const headerLogs = [];
        const creditLogs = [];
        const workerLogs = [];  // WORKER STARTED, stopped, resumed
        const depthLogs = {};
        let crawlFinish = null;
        const postLogs = {};
        let postFinish = null;
        const otherLogs = [];

        logs.forEach(log => {
            const msg = log.message;
            
            // Détecter le header ASCII (caractères Unicode blocks ███ ou box drawing ═══)
            if (msg.includes('███') || msg.includes('═══') || msg.includes('╔') || msg.includes('╗') || msg.includes('╚') || msg.includes('╝')) {
                headerLogs.push(log);
            } else if (msg.includes('Crédit:') || msg.includes('Credit:') || msg.includes('lokoe.fr')) {
                creditLogs.push(log);
            } else if (msg.includes('WORKER STARTED') || msg.includes('stopped by user') || msg.includes('Reprise du crawl')) {
                workerLogs.push(log);
            } else if (msg.match(/Depth (\d+)/)) {
                const match = msg.match(/Depth (\d+)/);
                depthLogs['Depth ' + match[1]] = log;
            } else if (msg.includes('Crawl finish')) {
                crawlFinish = log;
            } else if (msg.includes('Inlinks calcul')) {
                postLogs['Inlinks'] = log;
            } else if (msg.includes('Pagerank calcul')) {
                postLogs['Pagerank'] = log;
            } else if (msg.includes('Semantic analysis')) {
                postLogs['Semantic'] = log;
            } else if (msg.includes('Categorisation')) {
                postLogs['Categorisation'] = log;
            } else if (msg.includes('Duplicate analysis')) {
                postLogs['Duplicate'] = log;
            } else if (msg.includes('Post-traitement terminé') || msg.includes('POST-ANALYSIS COMPLETED')) {
                postFinish = log;
            } else if (msg.trim()) {
                otherLogs.push(log);
            }
        });

        const filteredLogs = [
            ...headerLogs,
            ...creditLogs,
            ...workerLogs,  // Worker status messages after credits
            ...Object.keys(depthLogs)
                .sort((a, b) => parseInt(a.split(' ')[1]) - parseInt(b.split(' ')[1]))
                .map(k => depthLogs[k]),
            ...(crawlFinish ? [crawlFinish] : []),
            ...(postLogs['Inlinks'] ? [postLogs['Inlinks']] : []),
            ...(postLogs['Pagerank'] ? [postLogs['Pagerank']] : []),
            ...(postLogs['Semantic'] ? [postLogs['Semantic']] : []),
            ...(postLogs['Categorisation'] ? [postLogs['Categorisation']] : []),
            ...(postLogs['Duplicate'] ? [postLogs['Duplicate']] : []),
            ...(postFinish ? [postFinish] : []),
            ...otherLogs
        ];

        this.elements.terminal.innerHTML = '';
        
        filteredLogs.forEach(log => {
            const line = document.createElement('div');
            line.className = 'crawl-panel-log-line';
            const msg = log.message;

            if (msg.includes('███') || msg.includes('═══') || msg.includes('╔') || msg.includes('╗') || msg.includes('╚') || msg.includes('╝')) {
                line.classList.add('crawl-panel-log-header');
                line.textContent = msg;
            } else if (msg.includes('Crédit:') || msg.includes('Credit:') || msg.includes('lokoe.fr')) {
                line.classList.add('crawl-panel-log-credit');
                line.textContent = msg;
            } else if (msg.match(/Depth \d+ :/)) {
                line.classList.add('crawl-panel-log-progress');
                const match = msg.match(/Depth (\d+) : ([\d.]+) URLs\/sec \((\d+)\/(\d+)\)/);
                if (match) {
                    const [_, depth, speed, current, total] = match;
                    const percent = Math.round((current / total) * 100);
                    line.innerHTML = `<span class="crawl-panel-log-depth">Depth ${depth}</span> : <span class="crawl-panel-log-speed">${speed} URLs/sec</span> <span class="crawl-panel-log-count">(${current}/${total})</span> <span class="crawl-panel-log-bar"><span class="crawl-panel-log-bar-fill" style="width: ${percent}%"></span></span>`;
                } else {
                    line.textContent = msg;
                }
            } else if (msg.includes('Crawl finish') || msg.includes('Post-traitement terminé')) {
                line.classList.add('crawl-panel-log-success');
                const text = msg.replace(/[✓]/g, '').trim();
                line.innerHTML = `<span class="material-symbols-outlined">check_circle</span>${text}`;
            } else if (msg.match(/(Inlinks calcul|Pagerank calcul|Semantic analysis|Categorisation|Duplicate analysis)\s*:\s*(.+)/)) {
                line.classList.add('crawl-panel-log-progress');
                const match = msg.match(/(Inlinks calcul|Pagerank calcul|Semantic analysis|Categorisation|Duplicate analysis)\s*:\s*(.+)/);
                if (match) {
                    line.innerHTML = `<span class="crawl-panel-log-depth">${match[1]}</span> : <span class="crawl-panel-log-count">${match[2].trim()}</span>`;
                } else {
                    line.textContent = msg;
                }
            } else {
                line.textContent = msg;
            }

            this.elements.terminal.appendChild(line);
        });

        if (this.state.isAutoScrolling) {
            this.scrollToBottom();
        }
    },

    /**
     * Scroll le terminal vers le bas
     */
    scrollToBottom() {
        if (this.elements.terminal) {
            this.elements.terminal.scrollTop = this.elements.terminal.scrollHeight;
        }
        if (this.elements.scrollBtn) {
            this.elements.scrollBtn.style.display = 'none';
        }
        this.state.isAutoScrolling = true;
    },

    /**
     * Réinitialise les KPIs
     */
    resetKPIs() {
        if (this.elements.urlsFound) this.elements.urlsFound.textContent = '0';
        if (this.elements.urlsCrawled) this.elements.urlsCrawled.textContent = '0';
        if (this.elements.speed) this.elements.speed.textContent = '0';
        if (this.elements.progress) this.elements.progress.textContent = '0%';
        if (this.elements.progressBar) this.elements.progressBar.style.width = '0%';
    },

    /**
     * Efface le terminal
     */
    clearTerminal() {
        if (this.elements.terminal) {
            this.elements.terminal.innerHTML = '<div class="crawl-panel-log-line crawl-panel-log-system crawl-panel-loading">Chargement des logs...</div>';
        }
    },

    /**
     * Gère la fin du crawl actuel (NE sélectionne PAS automatiquement un autre)
     */
    handleCrawlEnd(status) {
        if (this.state.currentCrawl) {
            const currentId = parseInt(this.state.currentCrawl.crawl_id, 10);
            
            // Copier le crawl avec son status final
            const finishedCrawl = { 
                ...this.state.currentCrawl,
                crawl_id: currentId,
                status: status,
                finishedAt: Date.now()
            };
            
            // Si le panel est OUVERT, ajouter à session (déjà vu)
            // Sinon ajouter à unseen (notification)
            if (this.state.isOpen) {
                const alreadyInSession = this.state.sessionFinishedCrawls.find(
                    c => parseInt(c.crawl_id, 10) === currentId
                );
                if (!alreadyInSession) {
                    this.state.sessionFinishedCrawls.push(finishedCrawl);
                }
            } else {
                const alreadyInUnseen = this.state.finishedUnseenCrawls.find(
                    c => parseInt(c.crawl_id, 10) === currentId
                );
                if (!alreadyInUnseen) {
                    this.state.finishedUnseenCrawls.push(finishedCrawl);
                    this.saveFinishedUnseenCrawls();
                }
            }
        }
        
        // Retirer de la liste des crawls en cours
        const currentId = this.state.currentCrawl ? parseInt(this.state.currentCrawl.crawl_id, 10) : null;
        this.state.runningCrawls = this.state.runningCrawls.filter(
            c => parseInt(c.crawl_id, 10) !== currentId
        );
        
        this.updateCrawlList();
        this.updateMinimizedBadge();
        
        // Arrêter le polling pour ce crawl
        this.stopPolling();
        
        // IMPORTANT: Garder le badge visible si crawls non vus
        if (!this.state.isOpen && this.state.finishedUnseenCrawls.length > 0) {
            this.showMinimized();
        }
    },

    /**
     * Arrête le crawl en cours
     */
    async stopCrawl() {
        if (!this.state.currentCrawl) return;

        const confirmed = await customConfirm(
            'Êtes-vous sûr de vouloir arrêter le crawl ?\n\nLes données collectées seront analysées.',
            'Arrêter le crawl',
            'Arrêter',
            'danger'
        );

        if (!confirmed) return;

        if (this.elements.stopBtn) {
            this.elements.stopBtn.disabled = true;
            this.elements.stopBtn.innerHTML = '<span class="material-symbols-outlined spinning">progress_activity</span> Arrêt...';
        }

        try {
            const response = await fetch(`${this.basePath}api/crawls/stop`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ project_dir: this.state.currentCrawl.project_dir })
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.error || 'Erreur lors de l\'arrêt');
            }

            this.updateStatus('processing');

        } catch (error) {
            alert(`Erreur: ${error.message}`);
            if (this.elements.stopBtn) {
                this.elements.stopBtn.disabled = false;
                this.elements.stopBtn.innerHTML = '<span class="material-symbols-outlined">stop_circle</span> Arrêter';
            }
        }
    },

    /**
     * Reprend un crawl arrêté/échoué
     */
    async resumeCrawl() {
        if (!this.state.currentCrawl) return;

        const confirmed = await customConfirm(
            'Reprendre le crawl là où il s\'est arrêté ?',
            'Reprendre le crawl',
            'Reprendre',
            'success'
        );

        if (!confirmed) return;

        if (this.elements.resumeBtn) {
            this.elements.resumeBtn.disabled = true;
            this.elements.resumeBtn.innerHTML = '<span class="material-symbols-outlined spinning">progress_activity</span> Reprise...';
        }

        try {
            const response = await fetch(`${this.basePath}api/crawls/resume`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ project_dir: this.state.currentCrawl.project_dir })
            });

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.error || 'Erreur lors de la reprise');
            }

            // Update status to queued
            this.updateStatus('queued');
            
            // Update crawl object
            if (this.state.currentCrawl) {
                this.state.currentCrawl.status = 'queued';
                this.state.currentCrawl.job_id = result.job_id;
                
                // Remove from finished lists (it's running again)
                const crawlId = parseInt(this.state.currentCrawl.crawl_id, 10);
                this.state.sessionFinishedCrawls = this.state.sessionFinishedCrawls.filter(
                    c => parseInt(c.crawl_id, 10) !== crawlId
                );
                this.state.finishedUnseenCrawls = this.state.finishedUnseenCrawls.filter(
                    c => parseInt(c.crawl_id, 10) !== crawlId
                );
                this.saveFinishedUnseenCrawls();
            }
            
            // Start polling to track progress
            this.startPolling();
            
            // Refresh running crawls list
            this.refreshRunningCrawlsList();

            // Reset button
            if (this.elements.resumeBtn) {
                this.elements.resumeBtn.disabled = false;
                this.elements.resumeBtn.innerHTML = '<span class="material-symbols-outlined">play_arrow</span> Reprendre';
            }

        } catch (error) {
            alert(`Erreur: ${error.message}`);
            if (this.elements.resumeBtn) {
                this.elements.resumeBtn.disabled = false;
                this.elements.resumeBtn.innerHTML = '<span class="material-symbols-outlined">play_arrow</span> Reprendre';
            }
        }
    },

    /**
     * Échappe le HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },
    
    /**
     * Debug: Affiche l'état complet dans la console
     * Appeler avec: CrawlPanel.debug()
     */
    debug() {
    },
    
    /**
     * Efface le localStorage (utile pour debug)
     */
    clearStorage() {
        localStorage.removeItem('crawlPanel_finishedUnseen');
        localStorage.removeItem('crawlPanel_tracked');
        this.state.finishedUnseenCrawls = [];
        this.state.trackedCrawlIds = [];
        this.state.sessionFinishedCrawls = [];
        this.updateMinimizedBadge();
        this.elements.minimized.classList.remove('is-visible', 'is-finished', 'has-finished');
        console.log('CrawlPanel: Storage cleared');
    },
    
    /**
     * Nettoie les doublons dans finishedUnseenCrawls
     */
    cleanupDuplicates() {
        const seen = new Set();
        this.state.finishedUnseenCrawls = this.state.finishedUnseenCrawls.filter(crawl => {
            const id = parseInt(crawl.crawl_id, 10);
            if (seen.has(id)) {
                return false;
            }
            seen.add(id);
            return true;
        });
        this.saveFinishedUnseenCrawls();
    }
};

// Initialiser au chargement du DOM
document.addEventListener('DOMContentLoaded', () => {
    CrawlPanel.init();
});

/**
 * Fonction globale pour ouvrir le panel sur un crawl existant
 */
function openCrawlPanel(projectDir, projectName, crawlId) {
    const id = parseInt(crawlId, 10);
    const crawl = {
        crawl_id: id,
        project_dir: projectDir,
        domain: projectName,
        status: 'running',
        urls: 0,
        crawled: 0
    };
    
    // Ajouter à la liste s'il n'existe pas déjà
    const exists = CrawlPanel.state.runningCrawls.find(c => parseInt(c.crawl_id, 10) === id);
    if (!exists) {
        CrawlPanel.state.runningCrawls.unshift(crawl);
    }
    
    CrawlPanel.selectCrawl(crawl);
    CrawlPanel.updateCrawlList();
    CrawlPanel.open();
    CrawlPanel.startPolling();
}

/**
 * Fonction globale pour démarrer un crawl avec le panel
 */
async function startCrawlWithPanel(projectDir, projectName) {
    try {
        const response = await fetch(`${CrawlPanel.basePath}api/crawls/start`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ project_dir: projectDir })
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.error || 'Erreur lors du démarrage du crawl');
        }

        CrawlPanel.start(projectDir, projectName, result.crawl_id);
        return result;

    } catch (error) {
        alert(`Erreur: ${error.message}`);
        throw error;
    }
}
