/**
 * Tooltip Global - Transforme tous les title="" en jolies infobulles
 * Style identique au composant chart.php
 */
(function() {
    let tooltip = null;
    let tooltipText = null;

    // Créer l'élément tooltip
    function createTooltip() {
        if (tooltip) return;
        tooltip = document.createElement('div');
        tooltip.className = 'global-tooltip';
        tooltip.innerHTML = '<span class="global-tooltip-text"></span>';
        document.body.appendChild(tooltip);
        tooltipText = tooltip.querySelector('.global-tooltip-text');
    }

    // Fonction pour afficher le tooltip
    function showTooltip(e) {
        createTooltip();
        const target = e.currentTarget;
        const text = target.getAttribute('data-tooltip');
        if (!text) return;

        tooltipText.textContent = text;
        tooltip.classList.add('visible');
        positionTooltip(target);
    }

    // Fonction pour positionner le tooltip
    function positionTooltip(target) {
        const rect = target.getBoundingClientRect();
        
        // Afficher temporairement pour mesurer
        tooltip.style.visibility = 'hidden';
        tooltip.style.display = 'block';
        const tooltipRect = tooltip.getBoundingClientRect();
        tooltip.style.visibility = '';
        tooltip.style.display = '';

        // Position en dessous
        let top = rect.bottom + 8;
        let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);

        // Ajuster si dépasse à droite
        if (left + tooltipRect.width > window.innerWidth - 10) {
            left = window.innerWidth - tooltipRect.width - 10;
        }
        // Ajuster si dépasse à gauche
        if (left < 10) {
            left = 10;
        }

        tooltip.style.top = top + 'px';
        tooltip.style.left = left + 'px';
    }

    // Fonction pour masquer le tooltip
    function hideTooltip() {
        if (tooltip) {
            tooltip.classList.remove('visible');
        }
    }

    // Initialiser les tooltips sur les éléments avec title
    function initTooltips() {
        document.querySelectorAll('[title]').forEach(el => {
            if (el.hasAttribute('data-tooltip')) return;
            
            const titleText = el.getAttribute('title');
            if (!titleText) return;

            el.setAttribute('data-tooltip', titleText);
            el.removeAttribute('title');
            el.addEventListener('mouseenter', showTooltip);
            el.addEventListener('mouseleave', hideTooltip);
        });
    }

    // Attendre que le DOM soit prêt
    function onReady() {
        initTooltips();
        
        // Observer les changements pour les éléments dynamiques
        const observer = new MutationObserver(initTooltips);
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }

    window.initTooltips = initTooltips;
})();
