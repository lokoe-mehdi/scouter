/**
 * Centre de téléchargements (icône à gauche de la cloche).
 *
 * Les exports (SQL / URL / Link / Redirect explorer) sont asynchrones : un job
 * génère le CSV et l'envoie sur le blob store. Ce widget :
 *  - poll GET /api/exports toutes les 5s (comme la cloche, mais plus réactif) ;
 *  - pastille = nombre d'exports PRÊTS pas encore vus ;
 *  - à l'ouverture : POST /api/exports/seen → éteint la pastille ;
 *  - chaque export prêt offre un bouton « Télécharger » vers
 *    /api/exports/{id}/download (redirige vers une URL S3 présignée valable 24h,
 *    ou streame le fichier en stockage local).
 *
 * Le texte est rendu via i18n (clés downloads.*).
 */
(function () {
    'use strict';

    const t = (typeof __ === 'function') ? __ : (k) => k;
    const basePath = (function () {
        return window.location.pathname.includes('/pages/') ? '../' : '';
    })();

    const POLL_MS = 5000;

    // type d'export → icône
    const TYPE_ICON = {
        urls:      'table_rows',
        links:     'link',
        redirects: 'alt_route',
        sql:       'code',
    };

    const DownloadCenter = {
        el: {},
        lastUnseen: 0,
        open: false,
        hasActive: false, // un export en attente/en cours → poll plus agressif

        init() {
            this.el.bell     = document.getElementById('dlBell');
            this.el.btn      = document.getElementById('dlBellBtn');
            this.el.dropdown = document.getElementById('dlDropdown');
            this.el.badge    = document.getElementById('dlBellBadge');
            this.el.list     = document.getElementById('dlList');
            this.el.empty    = document.getElementById('dlEmpty');
            if (!this.el.btn) return; // pas de header sur cette page

            this.el.btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggle();
            });
            document.addEventListener('click', (e) => {
                if (this.open && this.el.bell && !this.el.bell.contains(e.target)) this.close();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.open) this.close();
            });
            // Mutuellement exclusif avec la cloche : si l'autre menu s'ouvre, fermer.
            document.addEventListener('scouter-dropdown-open', (e) => {
                if (e.detail !== 'downloads' && this.open) this.close();
            });
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) this.refresh();
            });

            this.refresh();
            setInterval(() => { if (!document.hidden) this.refresh(); }, POLL_MS);

            // Permet aux explorers de rafraîchir tout de suite après avoir lancé un export.
            window.refreshDownloads = () => this.refresh();
        },

        // Petit toast quand un export est mis en file + pulse l'icône, pour pointer
        // l'utilisateur vers le centre de téléchargements.
        flash(msg) {
            this.refresh();
            if (this.el.bell) {
                this.el.bell.classList.remove('has-unread');
                void this.el.bell.offsetWidth;
                this.el.bell.classList.add('has-unread');
            }
            const toast = document.createElement('div');
            toast.className = 'dl-toast';
            toast.style.cssText = 'position:fixed;top:64px;right:18px;z-index:9999;background:#1f2a44;color:#fff;'
                + 'border:1px solid rgba(78,205,196,.4);border-radius:8px;padding:10px 14px;font-size:13px;'
                + 'box-shadow:0 6px 20px rgba(0,0,0,.3);display:flex;align-items:center;gap:8px;max-width:320px;';
            toast.innerHTML = '<span class="material-symbols-outlined" style="color:#4ECDC4;font-size:18px;">cloud_download</span>'
                + '<span>' + (msg || t('downloads.queued')) + '</span>';
            document.body.appendChild(toast);
            setTimeout(() => { toast.style.transition = 'opacity .4s'; toast.style.opacity = '0'; }, 3500);
            setTimeout(() => toast.remove(), 3900);
        },

        async refresh() {
            try {
                const res = await fetch(`${basePath}api/exports`, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                if (!data.success) return;
                this.render(data.exports || [], data.unseen || 0);
            } catch (e) { /* réseau : retry au prochain tick */ }
        },

        render(items, unseen) {
            if (unseen > 0) {
                this.el.badge.textContent = unseen > 99 ? '99+' : unseen;
                this.el.badge.hidden = false;
                if (unseen > this.lastUnseen) {
                    this.el.bell.classList.remove('has-unread');
                    void this.el.bell.offsetWidth;
                    this.el.bell.classList.add('has-unread');
                }
            } else {
                this.el.badge.hidden = true;
                this.el.bell.classList.remove('has-unread');
            }
            this.lastUnseen = unseen;

            if (!items.length) {
                this.el.list.innerHTML = '';
                this.el.list.appendChild(this.el.empty);
                this.el.empty.hidden = false;
                return;
            }
            this.el.list.innerHTML = '';
            items.forEach((x) => this.el.list.appendChild(this.buildItem(x)));
        },

        buildItem(x) {
            const item = document.createElement('div');
            item.className = 'notif-item dl-item' + (x.status === 'ready' && !x.seen_at ? ' is-unread' : '');

            const icon = document.createElement('div');
            icon.className = 'notif-item-icon ' + this.iconClass(x.status);
            icon.innerHTML = `<span class="material-symbols-outlined${x.status === 'running' || x.status === 'pending' ? ' spinning' : ''}">${this.iconName(x)}</span>`;

            const body = document.createElement('div');
            body.className = 'notif-item-body';

            const title = document.createElement('div');
            title.className = 'notif-item-title';
            title.textContent = x.label || (t('downloads.title') + ' #' + x.id);

            const meta = document.createElement('div');
            meta.className = 'notif-item-meta';
            const bits = [];
            bits.push(`<span class="dl-item-status${x.status === 'failed' ? ' is-failed' : ''}">${this.statusLabel(x)}</span>`);
            if (x.status === 'ready' && x.row_count != null) {
                bits.push(`<span>${x.row_count} ${t('downloads.rows')}</span>`);
            }
            bits.push(`<span>${this.relativeTime(x.created_at)}</span>`);
            meta.innerHTML = bits.join('');

            body.appendChild(title);
            body.appendChild(meta);

            item.appendChild(icon);
            item.appendChild(body);

            if (x.status === 'ready') {
                const actions = document.createElement('div');
                actions.className = 'dl-item-actions';
                const btn = document.createElement('a');
                btn.className = 'dl-download-btn';
                btn.href = `${basePath}api/exports/${parseInt(x.id, 10)}/download`;
                btn.setAttribute('target', '_blank');
                btn.setAttribute('rel', 'noopener');
                btn.innerHTML = `<span class="material-symbols-outlined">download</span>${t('downloads.download')}`;
                btn.addEventListener('click', (e) => e.stopPropagation());
                actions.appendChild(btn);
                item.appendChild(actions);
            }
            return item;
        },

        iconName(x) {
            if (x.status === 'failed') return 'error';
            if (x.status === 'ready')  return 'description';
            // En cours / en attente : vrai spinner de chargement (cercle qui tourne),
            // pas l'icône de type (link, table_rows…) qui tourne — c'est moche.
            if (x.status === 'running' || x.status === 'pending') return 'progress_activity';
            return TYPE_ICON[x.type] || 'hourglass_top';
        },
        iconClass(status) {
            if (status === 'failed') return 'notif-icon-failed';
            if (status === 'ready')  return 'notif-icon-export';
            return 'notif-icon-pending';
        },
        statusLabel(x) {
            switch (x.status) {
                case 'ready':   return t('downloads.status_ready');
                case 'failed':  return t('downloads.status_failed');
                case 'running': return t('downloads.status_running');
                default:        return t('downloads.status_pending');
            }
        },

        relativeTime(ts) {
            if (!ts) return '';
            const iso = ts.replace(' ', 'T') + (/[zZ]|[+\-]\d\d:?\d\d$/.test(ts) ? '' : 'Z');
            const then = new Date(iso).getTime();
            if (isNaN(then)) return '';
            const min = Math.floor(Math.max(0, Date.now() - then) / 60000);
            if (min < 1) return t('notifications.time_now');
            if (min < 60) return t('notifications.time_minutes').replace(':n', min);
            const h = Math.floor(min / 60);
            if (h < 24) return t('notifications.time_hours').replace(':n', h);
            return t('notifications.time_days').replace(':n', Math.floor(h / 24));
        },

        toggle() { this.open ? this.close() : this.openDropdown(); },
        openDropdown() {
            this.open = true;
            this.el.dropdown.classList.add('show');
            document.dispatchEvent(new CustomEvent('scouter-dropdown-open', { detail: 'downloads' }));
            this.markAllSeen();
        },
        close() {
            this.open = false;
            this.el.dropdown.classList.remove('show');
        },

        async markAllSeen() {
            if (this.lastUnseen === 0) return;
            this.el.badge.hidden = true;
            this.el.bell.classList.remove('has-unread');
            this.lastUnseen = 0;
            try {
                await fetch(`${basePath}api/exports/seen`, { method: 'POST', headers: { 'Accept': 'application/json' } });
            } catch (e) { /* re-tenté à la prochaine ouverture */ }
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => DownloadCenter.init());
    } else {
        DownloadCenter.init();
    }

    function postExport(payload) {
        const exBase = window.location.pathname.includes('/pages/') ? '../' : '';
        return fetch(`${exBase}api/exports`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload),
        })
            .then((r) => r.json());
    }

    // Helper partagé par les explorers : POST /api/exports puis toast + refresh.
    // payload = { type, project, ...params }. Affiche une erreur si l'appel échoue.
    window.queueExport = function (payload) {
        return postExport(payload)
            .then((d) => {
                if (d && d.success) DownloadCenter.flash();
                else alert((d && d.message) || t('downloads.error'));
                return d;
            })
            .catch(() => alert(t('downloads.error')));
    };

    // Helper pour les exports groupés : lance plusieurs CSV et ne montre qu'un
    // seul toast. Retourne le détail pour permettre à l'appelant de gérer l'UI.
    window.queueExports = function (payloads) {
        return Promise.all(payloads.map((payload) => postExport(payload).catch((error) => ({ success: false, error }))))
            .then((results) => {
                const failed = results.filter((d) => !d || !d.success);
                if (failed.length > 0) {
                    alert(t('downloads.error'));
                } else {
                    DownloadCenter.flash();
                }
                DownloadCenter.refresh();
                return results;
            });
    };
})();
