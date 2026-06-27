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

    /**
     * Sélectionne ou désélectionne un projet de recherche comme actif
     */
    async selectResearchProject(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const rpId = button.dataset.rpId;
        const isActive = button.textContent.includes('Projet actif') || button.textContent.includes('✅');

        try {
            const url = isActive ? '/api/research-projects/deselect-active' : `/api/research-projects/${rpId}/select`;
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) throw new Error('Erreur lors de la sélection du projet de recherche');

            const result = await response.json();
            if (result.success) {
                window.location.reload();
            }
        } catch (error) {
            console.error(error);
            alert('Erreur : ' + error.message);
        }
    }

    /**
     * Ouvre la modale de création de projet de recherche
     */
    openResearchProjectModal(event) {
        event.preventDefault();
        window.dispatchEvent(new CustomEvent('research-project-modal:open'));
    }
}
