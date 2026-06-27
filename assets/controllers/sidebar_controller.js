import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['link', 'menu', 'overlay', 'newDropdown', 'projectDropdown']

    connect() {
        this.highlightActiveLink();
        // Clic n'importe où pour fermer les dropdowns
        this.clickOutsideHandler = this.closeAllDropdowns.bind(this);
        window.addEventListener('click', this.clickOutsideHandler);
    }

    disconnect() {
        window.removeEventListener('click', this.clickOutsideHandler);
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
            if (linkPath.includes('/literature') || linkPath.includes('/sub-projects/literature')) {
                isActive = currentPath === '/literature' || currentPath.includes('/sub-projects/literature');
            } else if (linkPath.includes('/reading') || linkPath.includes('/sub-projects/reading')) {
                isActive = currentPath === '/reading' || currentPath.includes('/reading') || currentPath.includes('/sub-projects/reading');
            } else if (linkPath.includes('/synthesis') || linkPath.includes('/sub-projects/synthesis')) {
                isActive = currentPath === '/synthesis' || currentPath.includes('/synthesis') || currentPath.includes('/sub-projects/synthesis');
            } else if (linkPath.includes('/writing') || linkPath.includes('/sub-projects/writing')) {
                isActive = currentPath === '/writing' || currentPath.includes('/writing') || currentPath.includes('/sub-projects/writing');
            } else if (linkPath.includes('/thesis') || linkPath.includes('/sub-projects/thesis')) {
                isActive = currentPath === '/thesis' || currentPath.includes('/thesis') || currentPath.includes('/sub-projects/thesis');
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
     * Toggles the "Nouveau" dropdown menu
     */
    toggleNewDropdown(event) {
        event.preventDefault();
        event.stopPropagation();
        if (this.hasNewDropdownTarget) {
            this.newDropdownTarget.classList.toggle('hidden');
        }
        if (this.hasProjectDropdownTarget) {
            this.projectDropdownTarget.classList.add('hidden');
        }
    }

    /**
     * Toggles the "Projets de recherche" dropdown menu
     */
    toggleProjectDropdown(event) {
        event.preventDefault();
        event.stopPropagation();
        if (this.hasProjectDropdownTarget) {
            this.projectDropdownTarget.classList.toggle('hidden');
        }
        if (this.hasNewDropdownTarget) {
            this.newDropdownTarget.classList.add('hidden');
        }
    }

    /**
     * Closes all dropdowns
     */
    closeAllDropdowns(event) {
        if (this.hasNewDropdownTarget && !this.newDropdownTarget.contains(event.target)) {
            this.newDropdownTarget.classList.add('hidden');
        }
        if (this.hasProjectDropdownTarget && !this.projectDropdownTarget.contains(event.target)) {
            this.projectDropdownTarget.classList.add('hidden');
        }
    }

    /**
     * Sélectionne ou désélectionne un projet de recherche comme actif
     */
    async selectResearchProject(event) {
        event.preventDefault();
        event.stopPropagation();
        
        const button = event.currentTarget;
        const rpId = button.dataset.rpId;
        const isActive = button.dataset.isActive === 'true';

        try {
            const url = isActive ? '/api/research-projects/deselect-active' : `/api/research-projects/${rpId}/select`;
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) throw new Error('Erreur lors du changement de projet actif');

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
        if (this.hasNewDropdownTarget) {
            this.newDropdownTarget.classList.add('hidden');
        }
    }
}
