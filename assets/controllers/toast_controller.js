import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        message: String,
        type: { type: String, default: 'success' }
    }

    connect() {
        // Si le contrôleur est instancié avec des valeurs (ex: flash messages Symfony)
        if (this.hasMessageValue && this.messageValue !== '') {
            this.show(this.messageValue, this.typeValue);
            // On supprime l'élément "témoin" du DOM car le toast est créé dynamiquement dans le container
            this.element.remove();
        }

        // Écoute des événements globaux
        this.handleShowEvent = this.handleShowEvent.bind(this);
        window.addEventListener('toast:show', this.handleShowEvent);
    }

    disconnect() {
        window.removeEventListener('toast:show', this.handleShowEvent);
    }

    handleShowEvent(event) {
        if (event.detail && event.detail.message) {
            this.show(event.detail.message, event.detail.type || 'success', event.detail.action || null);
        }
    }

    /**
     * Affiche une notification
     * @param {string}      message  Le texte à afficher
     * @param {string}      type     'success', 'error', 'info', 'warning', 'download'
     * @param {object|null} action   Optionnel : { label, url } pour un bouton cliquable
     */
    show(message, type = 'success', action = null) {
        const container = document.getElementById('toast-container');
        if (!container) {
            console.warn('Toast container not found. Make sure you have <div id="toast-container"> in your base template.');
            return;
        }

        const toast = document.createElement('div');
        toast.className = `toast-item glass shadow-xl p-4 rounded-2xl flex items-start gap-3 border-l-4 fade-in-right pointer-events-auto min-w-[320px] max-w-md`;

        const config = this.getTypeConfig(type);
        toast.classList.add(...config.classes.split(' '));

        // Bouton d'action optionnel (ex: « Télécharger »)
        const actionHtml = action && action.url
            ? `<a href="${action.url}"
                  download
                  class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg
                         bg-[#10B981] text-white text-xs font-bold hover:opacity-90
                         transition-all cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    ${action.label || 'Télécharger'}
               </a>`
            : '';

        toast.innerHTML = `
            <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center ${config.iconBg} mt-0.5">
                ${config.icon}
            </div>
            <div class="flex-grow">
                <div class="text-sm font-semibold text-slate-800">${message}</div>
                ${actionHtml}
            </div>
            <button class="flex-shrink-0 text-slate-400 hover:text-slate-600 transition-colors mt-0.5">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        `;

        // Bouton de fermeture manuelle
        toast.querySelector('button').addEventListener('click', () => this.hide(toast));

        // Clic sur le lien d'action → ferme aussi le toast
        if (action && action.url) {
            toast.querySelector('a')?.addEventListener('click', () => {
                setTimeout(() => this.hide(toast), 400);
            });
        }

        container.appendChild(toast);

        // Les toasts download restent plus longtemps (12s vs 5s)
        const lifetime = (type === 'download' || action) ? 12000 : 5000;
        setTimeout(() => this.hide(toast), lifetime);
    }

    hide(toast) {
        if (!toast.parentElement) return;
        toast.classList.add('fade-out');
        setTimeout(() => toast.remove(), 400);
    }

    getTypeConfig(type) {
        const configs = {
            success: {
                classes: 'border-emerald-500 bg-white/90',
                iconBg: 'bg-emerald-100 text-emerald-600',
                icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>'
            },
            error: {
                classes: 'border-red-500 bg-white/90',
                iconBg: 'bg-red-100 text-red-600',
                icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>'
            },
            warning: {
                classes: 'border-amber-500 bg-white/90',
                iconBg: 'bg-amber-100 text-amber-600',
                icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>'
            },
            info: {
                classes: 'border-djoliba bg-white/90',
                iconBg: 'bg-slate-100 text-djoliba',
                icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
            }
        };

        return configs[type] || configs.info;
    }
}
