/**
 * Système de notification global
 * Affiche une notification en bas à droite de l'écran
 */

// Créer le conteneur de notification s'il n'existe pas
if (!document.getElementById('globalStatusMessage')) {
    const statusDiv = document.createElement('div');
    statusDiv.id = 'globalStatusMessage';
    statusDiv.className = 'global-status-message';
    document.body.appendChild(statusDiv);
}

// Fonction globale pour afficher les notifications
function showGlobalStatus(message, type) {
    const statusDiv = document.getElementById('globalStatusMessage');
    
    if(!message) {
        statusDiv.style.display = 'none';
        return;
    }
    
    statusDiv.className = 'global-status-message global-status-' + type;
    statusDiv.textContent = message;
    statusDiv.style.display = 'block';
    
    if(type === 'success') {
        setTimeout(() => {
            statusDiv.style.display = 'none';
        }, 3000);
    }
}
