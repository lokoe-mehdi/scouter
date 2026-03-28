/**
 * Gestionnaire pour ouvrir la modal de détails d'URL
 * Utilise la délégation d'événements pour capturer tous les clics sur .url-clickable
 * y compris ceux ajoutés dynamiquement (AJAX, pagination, etc.)
 */
document.addEventListener('click', function(e) {
    const el = e.target.closest('.url-clickable');
    if (!el) return;

    e.preventDefault();
    e.stopPropagation();

    const url = el.getAttribute('data-url');
    if (!url) return;

    const project = el.getAttribute('data-project') || null;
    openUrlModal(url, project);
});
