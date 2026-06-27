document.addEventListener('DOMContentLoaded', () => {
    const flashMessagesContainer = document.querySelector('#flash-messages');
    if (!flashMessagesContainer) return;

    // Injecter les animations CSS pour le toast
    const style = document.createElement('style');
    style.innerHTML = `
        #admin-toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .toast-message {
            margin: 0;
            min-width: 320px;
            max-width: 450px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border-radius: 12px;
            border: 1px solid rgba(0, 0, 0, 0.05);
            padding: 16px;
            animation: toastSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            position: relative;
            background-color: white;
            color: #1e293b;
        }
        .toast-message.alert-success {
            border-left: 5px solid #10b981;
            background-color: #f0fdf4;
        }
        .toast-message.alert-danger {
            border-left: 5px solid #ef4444;
            background-color: #fef2f2;
        }
        .toast-message.alert-warning {
            border-left: 5px solid #f59e0b;
            background-color: #fffbeb;
        }
        .toast-message.alert-info {
            border-left: 5px solid #3b82f6;
            background-color: #eff6ff;
        }
        @keyframes toastSlideIn {
            from { transform: translateX(120%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes toastSlideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(120%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);

    // Créer le conteneur de toast s'il n'existe pas
    let toastContainer = document.querySelector('#admin-toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'admin-toast-container';
        document.body.appendChild(toastContainer);
    }

    const alerts = flashMessagesContainer.querySelectorAll('.alert');
    alerts.forEach((alert) => {
        // Cacher l'alerte d'origine
        alert.style.display = 'none';

        // Créer l'élément toast
        const toast = document.createElement('div');
        toast.className = `toast-message alert ${alert.className}`;
        toast.innerHTML = alert.innerHTML;

        // Ajouter le bouton de fermeture
        const closeBtn = alert.querySelector('.btn-close');
        if (closeBtn) {
            // S'assurer qu'il a du style
            const myCloseBtn = toast.querySelector('.btn-close');
            if (myCloseBtn) {
                myCloseBtn.addEventListener('click', () => {
                    toast.remove();
                });
            }
        }

        toastContainer.appendChild(toast);

        // Retrait automatique après 5 secondes
        setTimeout(() => {
            toast.style.animation = 'toastSlideOut 0.3s ease-in forwards';
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 5000);
    });
});
