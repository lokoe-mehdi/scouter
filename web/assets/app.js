// Scouter Dashboard - JavaScript

// Fonction utilitaire pour formater les nombres
function formatNumber(num) {
    return new Intl.NumberFormat('fr-FR').format(num);
}

// Fonction pour copier du texte dans le presse-papier
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showNotification('Copié dans le presse-papier !', 'success');
    }).catch(err => {
        console.error('Erreur lors de la copie:', err);
    });
}

// Système de notifications
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        background: ${type === 'success' ? '#2ECC71' : type === 'error' ? '#E74C3C' : '#3498DB'};
        color: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => {
            document.body.removeChild(notification);
        }, 300);
    }, 3000);
}

// Animation CSS pour les notifications
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Export de tableau en CSV
function exportTableToCSV(tableId, filename) {
    const table = document.querySelector(`#${tableId} table`) || document.querySelector('.data-table');
    if (!table) {
        showNotification('Tableau non trouvé', 'error');
        return;
    }
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let row of rows) {
        let cols = row.querySelectorAll('td, th');
        let csvRow = [];
        for (let col of cols) {
            csvRow.push('"' + col.textContent.trim().replace(/"/g, '""') + '"');
        }
        csv.push(csvRow.join(','));
    }
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', filename || 'export.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    showNotification('Export CSV réussi !', 'success');
}

// Fonction pour trier un tableau
function sortTable(tableId, columnIndex, ascending = true) {
    const table = document.querySelector(`#${tableId} table`) || document.querySelector('.data-table');
    if (!table) return;
    
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    rows.sort((a, b) => {
        const aValue = a.cells[columnIndex].textContent.trim();
        const bValue = b.cells[columnIndex].textContent.trim();
        
        // Essayer de convertir en nombre
        const aNum = parseFloat(aValue.replace(/[^\d.-]/g, ''));
        const bNum = parseFloat(bValue.replace(/[^\d.-]/g, ''));
        
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return ascending ? aNum - bNum : bNum - aNum;
        }
        
        return ascending ? aValue.localeCompare(bValue) : bValue.localeCompare(aValue);
    });
    
    // Réinsérer les lignes triées
    rows.forEach(row => tbody.appendChild(row));
}

// Fonction pour filtrer un tableau
function filterTable(searchValue, tableId) {
    const table = document.querySelector(`#${tableId} table`) || document.querySelector('.data-table');
    if (!table) return;
    
    const tbody = table.querySelector('tbody');
    const rows = tbody.querySelectorAll('tr');
    const searchLower = searchValue.toLowerCase();
    
    let visibleCount = 0;
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(searchLower)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    return visibleCount;
}

// Initialisation au chargement de la page
document.addEventListener('DOMContentLoaded', function() {
    // Ajouter des tooltips sur les URLs tronquées (sauf les boutons cliquables)
    const truncatedElements = document.querySelectorAll('[title]');
    truncatedElements.forEach(el => {
        // Ne pas changer le cursor des éléments cliquables
        if (!el.classList.contains('copy-path-btn') && 
            !el.closest('a') && 
            !el.closest('button') &&
            !el.hasAttribute('onclick')) {
            el.style.cursor = 'help';
        }
    });
    
    // Améliorer l'accessibilité des liens externes
    const externalLinks = document.querySelectorAll('a[target="_blank"]');
    externalLinks.forEach(link => {
        link.setAttribute('rel', 'noopener noreferrer');
    });
    
    // Fermer les modals en cliquant à l'extérieur
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });
    
    // Raccourcis clavier
    document.addEventListener('keydown', function(e) {
        // ESC pour fermer les modals
        if (e.key === 'Escape') {
            const activeModal = document.querySelector('.modal.active');
            if (activeModal) {
                activeModal.classList.remove('active');
            }
        }
    });
    
    console.log('🚀 Scouter Dashboard chargé avec succès !');
});

// Fonction pour rafraîchir les graphiques (utile pour le responsive)
function refreshCharts() {
    if (typeof Highcharts !== 'undefined') {
        Highcharts.charts.forEach(chart => {
            if (chart) {
                chart.reflow();
            }
        });
    }
}

// Rafraîchir les graphiques lors du redimensionnement
window.addEventListener('resize', function() {
    clearTimeout(window.resizeTimeout);
    window.resizeTimeout = setTimeout(refreshCharts, 250);
});
