/**
 * Gestionnaire pour ouvrir la modal de détails d'URL
 * Détecte les éléments avec la classe .url-clickable
 */

// Fonction pour activer la modal sur les éléments avec .url-clickable
function enableUrlModal() {
    const urlElements = document.querySelectorAll('.url-clickable');
    
    urlElements.forEach(element => {
        const url = element.getAttribute('data-url');
        
        if (url) {
            // Ajouter le cursor pointer
            element.style.cursor = 'pointer';
            
            // Ajouter l'événement click
            element.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                openUrlModal(url);
            });
            
            // Tooltip
            element.title = 'Cliquer pour voir les détails de cette URL';
        }
    });
}

// Auto-activer au chargement de la page
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        enableUrlModal();
    }, 100);
});

// Fonction à appeler après un chargement AJAX ou dynamique
function refreshUrlModalHandlers() {
    enableUrlModal();
}
