import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus Controller — sidebar
 *
 * Gère l'état actif des liens de la barre latérale en fonction de l'URL actuelle.
 */
export default class extends Controller {
    static targets = ['link'];

    connect() {
        this.highlightActiveLink();
    }

    highlightActiveLink() {
        const currentPath = window.location.pathname;

        this.linkTargets.forEach(link => {
            const linkPath = link.getAttribute('href');
            
            // On vérifie si l'URL commence par le lien (pour gérer les sous-routes)
            // Ou si c'est une correspondance exacte pour le Hub
            if (currentPath === linkPath || (linkPath !== '/hub' && currentPath.startsWith(linkPath))) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    }
}
