/**
 * htmx-bootstrap.js — socle de la migration htmx (voir htmx.md).
 *
 * Chargé UNE fois dans le <head> de chaque page loguée, en `defer`, juste après
 * htmx. Il ne fait QUE de la plomberie de navigation ; il ne touche à aucune
 * logique métier.
 *
 * Rôles :
 *  1. Configurer htmx pour que back/forward soient des rechargements pleins
 *     (les rapports contiennent des <script> inline — Highcharts, CodeMirror —
 *     qui ne se ré-exécutent pas lors d'une restauration depuis le cache
 *     d'historique ; un reload franc est sûr plutôt que cassé).
 *  2. Offrir un registre de teardown aux pages échangées dans #main-content.
 *  3. Ré-initialiser la chrome dépendante de la nav (tooltips + item actif de la
 *     sidebar, qui n'est jamais re-rendue).
 */
(function () {
  'use strict';

  // --- 1. Configuration htmx (dès qu'il est disponible) ---------------------
  function configureHtmx() {
    if (!window.htmx) return false;
    // Toujours un "miss" d'historique → htmx fait un location.reload() propre,
    // qui ré-exécute tous les scripts inline de la page restaurée.
    window.htmx.config.historyCacheSize = 0;
    window.htmx.config.refreshOnHistoryMiss = true;
    return true;
  }
  if (!configureHtmx()) {
    document.addEventListener('DOMContentLoaded', configureHtmx);
  }

  // --- 1bis. Helper "DOM prêt" compatible swap htmx -------------------------
  // Au chargement plein, l'init d'une page est souvent gardée par
  // `DOMContentLoaded`. Mais lors d'une navigation htmx, le contenu est injecté
  // alors que DOMContentLoaded a DÉJÀ eu lieu → l'init ne se relancerait jamais.
  // htmxOnReady() exécute immédiatement si le DOM est prêt (cas du swap), sinon
  // attend DOMContentLoaded (cas du chargement plein). Remplacer dans les pages
  // `document.addEventListener('DOMContentLoaded', fn)` par `htmxOnReady(fn)`.
  window.htmxOnReady = function (fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  };

  // --- 2. Registre de teardown ---------------------------------------------
  // Les scripts inline d'une page peuvent y pousser une fonction de nettoyage,
  // exécutée juste avant que #main-content soit remplacé.
  window.__pageTeardown = window.__pageTeardown || [];

  // Enregistre un listener au niveau document/window qui appartient à la PAGE
  // courante : il est retiré automatiquement au prochain swap de #main-content.
  // → pas d'empilement (retiré à chaque swap), pas de handler fantôme (retiré
  //   avant que le DOM de la page parte), et ré-enregistré quand le script de la
  //   page se ré-exécute au swap suivant. Remplace `document.addEventListener`
  //   par `htmxPageListener(document, ...)` dans les pages échangées.
  window.htmxPageListener = function (target, type, fn, opts) {
    target.addEventListener(type, fn, opts);
    window.__pageTeardown.push(function () {
      target.removeEventListener(type, fn, opts);
    });
  };

  function isMainContent(target) {
    return !!target && target.id === 'main-content';
  }

  document.addEventListener('htmx:beforeSwap', function (e) {
    if (!isMainContent(e.detail.target)) return;

    // a) nettoyages enregistrés par la page sortante
    var fns = window.__pageTeardown;
    window.__pageTeardown = [];
    fns.forEach(function (fn) {
      try { fn(); } catch (err) { /* ne jamais bloquer un swap */ }
    });

    // b) filet de sécurité : détruire les graphes Highcharts dont le conteneur
    //    quitte le DOM (sinon ils s'accumulent dans Highcharts.charts).
    if (window.Highcharts && Array.isArray(window.Highcharts.charts)) {
      window.Highcharts.charts.forEach(function (c) {
        if (c && c.renderTo && !document.body.contains(c.renderTo)) {
          try { c.destroy(); } catch (err) { /* noop */ }
        }
      });
    }
  });

  document.addEventListener('htmx:afterSettle', function (e) {
    if (!isMainContent(e.detail.target)) return;
    refreshChrome();
  });

  function refreshChrome() {
    if (typeof window.initTooltips === 'function') {
      try { window.initTooltips(); } catch (err) { /* noop */ }
    }
    setActiveSidebarFromUrl();
  }

  // --- 3. Item actif de la sidebar (jamais re-rendue → mis à jour en JS) -----
  function pageParam(href) {
    try { return new URL(href, window.location.origin).searchParams.get('page'); }
    catch (err) { return null; }
  }

  function setActiveSidebarFromUrl() {
    var current = new URLSearchParams(window.location.search).get('page') || 'home';
    var activeItem = null;

    document.querySelectorAll('.sidebar-panel-item').forEach(function (a) {
      var on = pageParam(a.href) === current;
      a.classList.toggle('active', on);
      if (on) activeItem = a;
    });

    document.querySelectorAll('.icon-rail-link').forEach(function (a) {
      a.classList.toggle('active', pageParam(a.href) === current);
    });

    var directActive = document.querySelector('.icon-rail-link.active');

    if (directActive) {
      // Page directe (segments / config) : pas de panneau latéral.
      if (typeof window.closeSidebarPanel === 'function') {
        try { window.closeSidebarPanel(); } catch (err) { /* noop */ }
      }
      return;
    }

    // Page de rapport : activer l'icône de section + afficher/ouvrir le panneau.
    if (activeItem) {
      var section = activeItem.closest('.sidebar-panel-section');
      var name = section && section.dataset ? section.dataset.section : null;
      if (name) {
        document.querySelectorAll('.icon-rail-item[data-section]').forEach(function (i) {
          i.classList.toggle('active', i.dataset.section === name);
        });
        if (typeof window.showPanelSection === 'function') {
          try { window.showPanelSection(name); } catch (err) { /* noop */ }
        }
        if (typeof window.openSidebarPanel === 'function') {
          try { window.openSidebarPanel(); } catch (err) { /* noop */ }
        }
      }
    }
  }

  // Exposé pour réutilisation / debug.
  window.setActiveSidebarFromUrl = setActiveSidebarFromUrl;
})();
