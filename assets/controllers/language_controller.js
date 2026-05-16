import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    connect() {
        // Optionnel : vérifier si la langue actuelle correspond au localStorage
        const savedLang = localStorage.getItem('lang');
        const currentLang = new URLSearchParams(window.location.search).get('_locale');

        if (savedLang && !currentLang) {
            // Si une langue est sauvegardée mais pas présente dans l'URL, on pourrait rediriger
            // Mais attention aux boucles de redirection
        }
    }

    switch(event) {
        event.preventDefault();
        const lang = event.currentTarget.dataset.lang;
        
        if (!lang) return;

        // Sauvegarder la préférence
        localStorage.setItem('lang', lang);

        // Recharger la page avec le paramètre de langue
        const url = new URL(window.location.href);
        url.searchParams.set('_locale', lang);

        window.location.href = url.toString();
    }
}
