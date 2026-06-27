import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    connect() {
        // Connected
    }

    openModal() {
        window.dispatchEvent(new CustomEvent('research-project-modal:open'));
    }

    async selectProject(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const rpId = button.dataset.rpId;
        const isActive = button.dataset.active === 'true';

        try {
            const url = isActive ? '/api/research-projects/deselect-active' : `/api/research-projects/${rpId}/select`;
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) throw new Error('Erreur lors de la sélection');

            const result = await response.json();
            if (result.success) {
                window.location.reload();
            }
        } catch (error) {
            console.error(error);
            alert('Erreur : ' + error.message);
        }
    }

    async deleteProject(event) {
        event.preventDefault();
        if (!confirm('Voulez-vous vraiment supprimer ce projet de recherche ? Les sous-projets associés ne seront pas supprimés, mais détachés.')) {
            return;
        }

        const button = event.currentTarget;
        const rpId = button.dataset.rpId;

        try {
            const response = await fetch(`/api/research-projects/${rpId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            });

            if (response.status === 204 || response.ok) {
                window.location.reload();
            } else {
                throw new Error('Erreur de suppression');
            }
        } catch (error) {
            console.error(error);
            alert('Erreur : ' + error.message);
        }
    }
}
