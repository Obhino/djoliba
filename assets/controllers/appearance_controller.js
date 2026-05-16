import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus Controller — appearance
 *
 * Gère le thème sombre/clair et le sélecteur de langue avec persistance localStorage.
 */
export default class extends Controller {
    static targets = ['themeIcon', 'langLabel'];

    connect() {
        this.initLanguage();
    }

    // --- LANGUE ---
    initLanguage() {
        const lang = localStorage.getItem('lang') || 'fr';
        this.setLanguage(lang);
    }

    switchLanguage(event) {
        const lang = event.currentTarget.dataset.lang;
        this.setLanguage(lang);
        // Optionnel : Recharger la page ou notifier Symfony si i18n est en place
        // window.location.reload(); 
    }

    setLanguage(lang) {
        document.documentElement.setAttribute('lang', lang);
        localStorage.setItem('lang', lang);
        
        if (this.hasLangLabelTarget) {
            this.langLabelTarget.textContent = lang.toUpperCase();
        }
    }
}
