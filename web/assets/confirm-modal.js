/**
 * Custom Confirm Modal - Remplace les confirm() natifs par des modals UX friendly
 */

/**
 * Fonction customConfirm pour remplacer window.confirm()
 * Définie AVANT DOMContentLoaded pour être disponible immédiatement
 */
window.customConfirm = function(message, title = 'Confirmation', confirmText = 'Confirmer', confirmType = 'primary') {
    return new Promise((resolve) => {
        // Attendre que le DOM soit prêt si nécessaire
        const executeConfirm = () => {
            const modal = document.getElementById('customConfirmModal');
            if (!modal) {
                console.error('Modal de confirmation non trouvée. Assurez-vous que le DOM est chargé.');
                resolve(false);
                return;
            }
            
            const titleEl = document.getElementById('customConfirmTitle');
            const messageEl = document.getElementById('customConfirmMessage');
            const cancelBtn = document.getElementById('customConfirmCancel');
            const okBtn = document.getElementById('customConfirmOk');
            
            // Définir le contenu
            titleEl.textContent = title;
            messageEl.textContent = message;
            const okBtnText = document.getElementById('customConfirmOkText');
            if (okBtnText) okBtnText.textContent = confirmText;
            
            // Changer le type de bouton
            okBtn.className = 'btn btn-' + confirmType;
            
            // Afficher la modal
            modal.classList.add('active');
            
            // Variable pour stocker le handler clavier
            let handleKeyboard;
            
            // Fonction pour fermer la modal
            const closeModal = (result) => {
                modal.classList.remove('active');
                cancelBtn.removeEventListener('click', handleCancel);
                okBtn.removeEventListener('click', handleOk);
                if (handleKeyboard) {
                    document.removeEventListener('keydown', handleKeyboard);
                }
                resolve(result);
            };
            
            // Handlers
            const handleCancel = () => closeModal(false);
            const handleOk = () => closeModal(true);
            
            // Ajouter les event listeners
            cancelBtn.addEventListener('click', handleCancel);
            okBtn.addEventListener('click', handleOk);
            
            // Raccourcis clavier : Escape = annuler, Ctrl+Enter = confirmer
            handleKeyboard = (e) => {
                if (e.key === 'Escape') {
                    closeModal(false);
                } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    closeModal(true);
                }
            };
            document.addEventListener('keydown', handleKeyboard);
            
            // Focus sur le bouton OK pour feedback visuel
            okBtn.focus();
        };
        
        // Si le DOM n'est pas encore prêt, attendre
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', executeConfirm);
        } else {
            executeConfirm();
        }
    });
};

// Créer la modal de confirmation au chargement de la page
document.addEventListener('DOMContentLoaded', function() {
    // Créer le HTML de la modal
    const modalHTML = `
        <div id="customConfirmModal" class="custom-confirm-modal">
            <div class="custom-confirm-overlay"></div>
            <div class="custom-confirm-content">
                <div class="custom-confirm-header">
                    <span class="material-symbols-outlined custom-confirm-icon">help_outline</span>
                    <h3 id="customConfirmTitle" class="custom-confirm-title">Confirmation</h3>
                </div>
                <div id="customConfirmMessage" class="custom-confirm-message"></div>
                <div class="custom-confirm-actions">
                    <button id="customConfirmCancel" class="btn btn-secondary">
                        Annuler
                        <span class="shortcut-hint">Échap</span>
                    </button>
                    <button id="customConfirmOk" class="btn btn-primary">
                        <span id="customConfirmOkText">Confirmer</span>
                        <span class="shortcut-hint">Ctrl+↵</span>
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Ajouter au body
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Ajouter les styles CSS
    const styles = `
        <style>
            .custom-confirm-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 99999;
                align-items: center;
                justify-content: center;
            }
            
            .custom-confirm-modal.active {
                display: flex;
            }
            
            .custom-confirm-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.6);
                backdrop-filter: blur(4px);
            }
            
            .custom-confirm-content {
                position: relative;
                background: white;
                border-radius: 12px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                max-width: 500px;
                width: 90%;
                padding: 2rem;
                animation: confirmSlideIn 0.3s ease-out;
            }
            
            @keyframes confirmSlideIn {
                from {
                    opacity: 0;
                    transform: translateY(-20px) scale(0.95);
                }
                to {
                    opacity: 1;
                    transform: translateY(0) scale(1);
                }
            }
            
            .custom-confirm-header {
                display: flex;
                align-items: center;
                gap: 1rem;
                margin-bottom: 1.5rem;
            }
            
            .custom-confirm-icon {
                font-size: 32px;
                color: var(--primary-color, #4ECDC4);
            }
            
            .custom-confirm-title {
                margin: 0;
                font-size: 1.5rem;
                color: var(--text-primary, #2C3E50);
                font-weight: 600;
            }
            
            .custom-confirm-message {
                color: var(--text-secondary, #7F8C8D);
                font-size: 1rem;
                line-height: 1.6;
                margin-bottom: 2rem;
            }
            
            .custom-confirm-actions {
                display: flex;
                gap: 1rem;
                justify-content: flex-end;
            }
            
            .custom-confirm-actions .btn {
                padding: 0.75rem 1.5rem;
                border: none;
                border-radius: 8px;
                font-size: 1rem;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }
            
            .custom-confirm-actions .btn-secondary {
                background: #95a5a6;
                color: white;
            }
            
            .custom-confirm-actions .btn-secondary:hover {
                background: #7f8c8d;
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(149, 165, 166, 0.3);
            }
            
            .custom-confirm-actions .btn-primary {
                background: var(--primary-color, #4ECDC4);
                color: white;
            }
            
            .custom-confirm-actions .btn-primary:hover {
                background: var(--primary-dark, #3DB8AF);
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(78, 205, 196, 0.3);
            }
            
            .custom-confirm-actions .btn-danger {
                background: var(--danger, #E74C3C);
                color: white;
            }
            
            .custom-confirm-actions .btn-danger:hover {
                background: #c0392b;
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3);
            }
            
            .shortcut-hint {
                font-size: 0.7rem;
                opacity: 0.6;
                margin-left: 0.5rem;
                padding: 2px 6px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 4px;
                font-weight: 400;
            }
        </style>
    `;
    
    document.head.insertAdjacentHTML('beforeend', styles);
});
