/**
 * Centre de notifications (cloche du header).
 *
 * - Poll GET /api/notifications toutes les 10s (et au focus de l'onglet) pour
 *   afficher les nouveautés même sans changer de page.
 * - Pastille rouge = nombre de non-lues.
 * - À l'ouverture de la cloche : POST /api/notifications/read → tout passe en lu.
 * - Clic sur une notif : route selon `action`
 *     'logs'      → ouvre le volet de logs (openCrawlPanel) si présent,
 *                   sinon repli sur la page projet.
 *     'dashboard' → dashboard.php?crawl=<id>
 *     'explorer'  → dashboard.php?crawl=<id>&page=url-explorer&show_ai=1 (génération IA)
 *     'project'   → project.php?id=<id>
 *
 * Le texte est rendu ici via i18n (clés notifications.*) → suit la langue.
 */
(function () {
    'use strict';

    // Repli si l'i18n JS n'est pas chargée sur la page courante.
    const t = (typeof __ === 'function') ? __ : (k) => k;

    const basePath = (function () {
        return window.location.pathname.includes('/pages/') ? '../' : '';
    })();

    const POLL_MS = 10000;

    // type → présentation (icône + couleur + clés i18n + champ utilisé dans le texte)
    const TYPES = {
        crawl_started:           { icon: 'rocket_launch', cls: 'notif-icon-started',  field: 'domain'  },
        crawl_finished:          { icon: 'task_alt',      cls: 'notif-icon-finished', field: 'domain'  },
        crawl_failed:            { icon: 'error',         cls: 'notif-icon-failed',   field: 'domain'  },
        categorization_finished: { icon: 'sell',          cls: 'notif-icon-job',      field: 'project' },
        report_finished:         { icon: 'analytics',     cls: 'notif-icon-job',      field: 'project' },
        bulk_ai_finished:        { icon: 'auto_awesome',  cls: 'notif-icon-job',      field: 'project' },
        project_shared:          { icon: 'group_add',     cls: 'notif-icon-shared',   field: 'project' },
    };

    const NotifCenter = {
        el: {},
        lastUnread: 0,
        open: false,

        init() {
            // Ré-exécution sous hx-boost (navigation hub) : la cloche est
            // préservée via hx-preserve avec ses listeners et son polling ; on
            // ne ré-initialise pas (sinon listeners empilés + polls dupliqués).
            if (window.__notifCenterWired) return;
            window.__notifCenterWired = true;
            this.el.bell    = document.getElementById('notifBell');
            this.el.btn     = document.getElementById('notifBellBtn');
            this.el.dropdown = document.getElementById('notifDropdown');
            this.el.badge   = document.getElementById('notifBellBadge');
            this.el.list    = document.getElementById('notifList');
            this.el.empty   = document.getElementById('notifEmpty');
            if (!this.el.btn) return; // pas de header sur cette page

            this.el.btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggle();
            });

            document.addEventListener('click', (e) => {
                if (this.open && this.el.bell && !this.el.bell.contains(e.target)) {
                    this.close();
                }
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.open) this.close();
            });

            // Mutuellement exclusif avec le centre de téléchargements : si l'autre
            // menu s'ouvre, on ferme celui-ci (jamais les deux ouverts à la fois).
            document.addEventListener('scouter-dropdown-open', (e) => {
                if (e.detail !== 'notif' && this.open) this.close();
            });

            // Refresh quand l'utilisateur revient sur l'onglet.
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) this.refresh();
            });

            this.refresh();
            setInterval(() => { if (!document.hidden) this.refresh(); }, POLL_MS);
        },

        async refresh() {
            try {
                const res = await fetch(`${basePath}api/notifications`, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                if (!data.success) return;
                this.render(data.notifications || [], data.unread_count || 0);
            } catch (e) {
                /* réseau : on réessaiera au prochain tick */
            }
        },

        render(items, unread) {
            // Pastille.
            if (unread > 0) {
                this.el.badge.textContent = unread > 99 ? '99+' : unread;
                this.el.badge.hidden = false;
                if (unread > this.lastUnread) {
                    this.el.bell.classList.remove('has-unread');
                    void this.el.bell.offsetWidth; // reflow → rejoue l'animation
                    this.el.bell.classList.add('has-unread');
                }
            } else {
                this.el.badge.hidden = true;
                this.el.bell.classList.remove('has-unread');
            }
            this.lastUnread = unread;

            // Liste.
            if (!items.length) {
                this.el.list.innerHTML = '';
                this.el.list.appendChild(this.el.empty);
                this.el.empty.hidden = false;
                return;
            }

            this.el.list.innerHTML = '';
            items.forEach((n) => this.el.list.appendChild(this.buildItem(n)));
        },

        buildItem(n) {
            const cfg = TYPES[n.type] || { icon: 'notifications', cls: 'notif-icon-job', field: 'domain' };
            const isUnread = (n.unread === true || n.unread === 't' || n.unread === 1 || n.read_at === null);

            const item = document.createElement('div');
            item.className = 'notif-item' + (isUnread ? ' is-unread' : '');

            const icon = document.createElement('div');
            icon.className = 'notif-item-icon ' + cfg.cls;
            icon.innerHTML = `<span class="material-symbols-outlined">${cfg.icon}</span>`;

            const body = document.createElement('div');
            body.className = 'notif-item-body';

            const title = document.createElement('div');
            title.className = 'notif-item-title';
            title.textContent = t('notifications.' + n.type + '_title');

            const text = document.createElement('div');
            text.className = 'notif-item-text';
            const value = (cfg.field === 'project') ? (n.project_name || n.domain || '') : (n.domain || n.project_name || '');
            text.innerHTML = this.fillTemplate(t('notifications.' + n.type + '_body'), cfg.field, value, n.actor);

            const meta = document.createElement('div');
            meta.className = 'notif-item-meta';
            let metaHtml = '';
            if (n.crawl_id) {
                metaHtml += `<span class="notif-item-id">#${parseInt(n.crawl_id, 10)}</span>`;
            }
            metaHtml += `<span>${this.relativeTime(n.created_at)}</span>`;
            meta.innerHTML = metaHtml;

            body.appendChild(title);
            body.appendChild(text);
            body.appendChild(meta);
            item.appendChild(icon);
            item.appendChild(body);

            item.addEventListener('click', () => this.handleClick(n));
            return item;
        },

        // Remplit un gabarit i18n (":domain"/":project"/":actor") avec les valeurs
        // dynamiques échappées (anti-XSS). La valeur principale (domain/project)
        // est mise en gras ; :actor (qui a déclenché) est échappé sans gras.
        // Le gabarit lui-même est de confiance (i18n).
        fillTemplate(tpl, field, value, actor) {
            const safe = '<strong>' + this.escape(value) + '</strong>';
            return tpl
                .replace(':actor', this.escape(actor || ''))
                .replace(':domain', field === 'domain' ? safe : this.escape(value))
                .replace(':project', field === 'project' ? safe : this.escape(value));
        },

        escape(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        },

        relativeTime(ts) {
            if (!ts) return '';
            // Les timestamps PG sont en UTC sans suffixe → normaliser.
            const iso = ts.replace(' ', 'T') + (/[zZ]|[+\-]\d\d:?\d\d$/.test(ts) ? '' : 'Z');
            const then = new Date(iso).getTime();
            if (isNaN(then)) return '';
            const diff = Math.max(0, Date.now() - then);
            const min = Math.floor(diff / 60000);
            if (min < 1) return t('notifications.time_now');
            if (min < 60) return t('notifications.time_minutes').replace(':n', min);
            const h = Math.floor(min / 60);
            if (h < 24) return t('notifications.time_hours').replace(':n', h);
            const d = Math.floor(h / 24);
            return t('notifications.time_days').replace(':n', d);
        },

        handleClick(n) {
            this.close();
            if (n.action === 'explorer' && n.crawl_id) {
                // show_ai=1 → URL Explorer opens on a focused URL + generated-columns
                // view so the user immediately sees what the AI generation produced.
                window.location.href = `${basePath}dashboard.php?crawl=${parseInt(n.crawl_id, 10)}&page=url-explorer&show_ai=1`;
                return;
            }
            if (n.action === 'dashboard' && n.crawl_id) {
                window.location.href = `${basePath}dashboard.php?crawl=${parseInt(n.crawl_id, 10)}`;
                return;
            }
            if (n.action === 'project' && n.project_id) {
                window.location.href = `${basePath}project.php?id=${parseInt(n.project_id, 10)}`;
                return;
            }
            if (n.action === 'logs') {
                if (typeof openCrawlPanel === 'function' && n.crawl_id && n.project_dir) {
                    openCrawlPanel(n.project_dir, n.domain || 'Crawl', parseInt(n.crawl_id, 10));
                } else if (n.project_id) {
                    window.location.href = `${basePath}project.php?id=${parseInt(n.project_id, 10)}`;
                } else if (n.crawl_id) {
                    window.location.href = `${basePath}dashboard.php?crawl=${parseInt(n.crawl_id, 10)}`;
                }
            }
        },

        toggle() { this.open ? this.close() : this.openDropdown(); },

        openDropdown() {
            this.open = true;
            this.el.dropdown.classList.add('show');
            document.dispatchEvent(new CustomEvent('scouter-dropdown-open', { detail: 'notif' }));
            this.markAllRead();
        },

        close() {
            this.open = false;
            this.el.dropdown.classList.remove('show');
        },

        async markAllRead() {
            if (this.lastUnread === 0) return;
            // Optimiste : on éteint la pastille et on retire le surlignage tout de suite.
            this.el.badge.hidden = true;
            this.el.bell.classList.remove('has-unread');
            this.lastUnread = 0;
            this.el.list.querySelectorAll('.notif-item.is-unread').forEach((el) => el.classList.remove('is-unread'));
            try {
                await fetch(`${basePath}api/notifications/read`, { method: 'POST', headers: { 'Accept': 'application/json' } });
            } catch (e) { /* sera re-tenté implicitement au prochain ouverture */ }
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => NotifCenter.init());
    } else {
        NotifCenter.init();
    }
})();
