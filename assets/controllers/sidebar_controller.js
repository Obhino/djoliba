import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['link', 'menu', 'overlay']

    connect() {
        this.highlightActiveLink();
    }

    /**
     * Gère la mise en surbrillance du lien actif
     */
    highlightActiveLink() {
        const currentPath = window.location.pathname;

        this.linkTargets.forEach(link => {
            const linkPath = link.getAttribute('href');
            if (!linkPath || linkPath === '#') return;
            
            let isActive = false;
            if (linkPath.includes('/literature')) {
                isActive = currentPath === '/literature';
            } else if (linkPath.includes('/reading')) {
                isActive = currentPath === '/reading' || currentPath.includes('/reading');
            } else if (linkPath.includes('/synthesis')) {
                isActive = currentPath === '/synthesis' || currentPath.includes('/synthesis') || (currentPath.includes('/literature') && currentPath !== '/literature');
            } else if (linkPath.includes('/writing')) {
                isActive = currentPath === '/writing' || currentPath.includes('/writing');
            } else if (linkPath.includes('/thesis')) {
                isActive = currentPath === '/thesis' || currentPath.includes('/thesis');
            }

            if (isActive) {
                link.classList.add('bg-djoliba/10', 'text-djoliba', 'border-djoliba');
                link.classList.remove('text-slate-500', 'border-transparent');
            } else {
                link.classList.remove('bg-djoliba/10', 'text-djoliba', 'border-djoliba');
                link.classList.add('text-slate-500', 'border-transparent');
            }
        });
    }

    /**
     * Ouvre le menu mobile
     */
    toggle() {
        if (this.hasMenuTarget) {
            this.menuTarget.classList.toggle('-translate-x-full');
        }
        if (this.hasOverlayTarget) {
            this.overlayTarget.classList.toggle('hidden');
        }
    }

    /**
     * Ferme le menu mobile
     */
    close() {
        if (this.hasMenuTarget) {
            this.menuTarget.classList.add('-translate-x-full');
        }
        if (this.hasOverlayTarget) {
            this.overlayTarget.classList.add('hidden');
        }
    }
}
