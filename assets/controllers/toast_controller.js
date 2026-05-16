import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus Controller — toast
 *
 * Gère l'affichage des notifications toasts (succès, erreur, info).
 */
export default class extends Controller {
    static targets = ['container'];
    static values = { message: String, type: String };

    connect() {
        if (this.hasMessageValue) {
            this.show(this.messageValue, this.typeValue || 'info');
        }

        // Écoute les événements personnalisés pour afficher des toasts depuis d'autres contrôleurs
        window.addEventListener('toast:show', (event) => {
            this.show(event.detail.message, event.detail.type || 'info');
        });
    }

    showFromFlash() {
        this.show(this.messageValue, this.typeValue || 'info');
    }

    show(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast-item fade-in-right ${this.getThemeClasses(type)}`;
        
        const icon = this.getIcon(type);
        
        toast.innerHTML = `
            <div class="flex items-center gap-3">
                <div class="flex-shrink-0">${icon}</div>
                <div class="text-sm font-semibold">${message}</div>
                <button class="ml-auto text-current opacity-50 hover:opacity-100 transition-opacity" onclick="this.parentElement.parentElement.remove()">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        `;

        this.element.appendChild(toast);

        // Auto-suppression après 5 secondes
        setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 500);
        }, 5000);
    }

    getThemeClasses(type) {
        switch (type) {
            case 'success': return 'bg-emerald-500 text-white shadow-lg shadow-emerald-500/20';
            case 'error': return 'bg-red-500 text-white shadow-lg shadow-red-500/20';
            case 'warning': return 'bg-amber-500 text-white shadow-lg shadow-amber-500/20';
            default: return 'bg-djoliba text-white shadow-lg shadow-djoliba/20';
        }
    }

    getIcon(type) {
        switch (type) {
            case 'success': return '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
            case 'error': return '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
            default: return '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
        }
    }
}
