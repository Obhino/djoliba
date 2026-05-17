import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static targets = ['modal', 'card', 'input', 'typeSelect', 'title', 'submitBtn', 'typeSection'];

    connect() {
        // Listen to global open event
        this.boundHandleOpen = this.handleOpen.bind(this);
        window.addEventListener('project-modal:open', this.boundHandleOpen);
    }

    disconnect() {
        window.removeEventListener('project-modal:open', this.boundHandleOpen);
    }

    handleOpen(event) {
        const type = event.detail.type || ''; // e.g. 'reading', 'literature_review', etc.
        this.open(type);
    }

    open(type = '') {
        // Reset form
        this.inputTarget.value = '';
        
        // Show/Hide or pre-select type
        if (type) {
            // Pre-selected type, hide the selector to keep it simple
            this.typeSelectTarget.value = type;
            this.typeSectionTarget.classList.add('hidden');
            
            const labels = {
                literature_review: 'Revue de Littérature / Synthèse',
                reading: 'Lecture / Analyse PDF',
                writing: 'Rédaction / Publication',
                thesis: 'Thèse / Mémoire'
            };
            this.titleTarget.textContent = `Nouveau Projet : ${labels[type] || 'Recherche'}`;
        } else {
            // General creation, show type selector
            this.typeSectionTarget.classList.remove('hidden');
            this.typeSelectTarget.value = 'literature_review'; // default
            this.titleTarget.textContent = 'Créer un nouveau projet';
        }

        // Open animation
        this.element.classList.remove('hidden');
        setTimeout(() => {
            this.element.classList.remove('opacity-0');
            this.element.classList.add('opacity-100');
            
            this.cardTarget.classList.remove('scale-95', 'opacity-0');
            this.cardTarget.classList.add('scale-100', 'opacity-100');
            
            this.inputTarget.focus();
        }, 50);
    }

    close() {
        this.cardTarget.classList.remove('scale-100', 'opacity-100');
        this.cardTarget.classList.add('scale-95', 'opacity-0');
        
        this.element.classList.remove('opacity-100');
        this.element.classList.add('opacity-0');

        setTimeout(() => {
            this.element.classList.add('hidden');
        }, 300);
    }

    async submit(event) {
        event.preventDefault();
        const name = this.inputTarget.value.trim();
        const type = this.typeSelectTarget.value;

        if (!name) return;

        // Disable submit button and show loading state
        this.submitBtnTarget.disabled = true;
        this.submitBtnTarget.innerHTML = `
            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg> Création...
        `;

        try {
            const response = await fetch('/api/projects', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ name, type })
            });

            if (!response.ok) throw new Error('Erreur lors de la création du projet');

            const result = await response.json();
            const project = result.data || result;

            this.showToast('Projet créé avec succès !', 'success');
            
            // Redirect
            setTimeout(() => {
                if (project.type === 'literature_review') {
                    window.location.href = `/project/${project.id}/literature?autostart=1`;
                } else if (project.type === 'reading') {
                    window.location.href = `/project/${project.id}/reading`;
                } else if (project.type === 'writing') {
                    window.location.href = `/project/${project.id}/writing`;
                } else if (project.type === 'thesis') {
                    window.location.href = `/project/${project.id}/thesis`;
                } else {
                    window.location.href = `/project/${project.id}`;
                }
            }, 500);

        } catch (error) {
            console.error(error);
            this.showToast('Impossible de créer le projet : ' + error.message, 'error');
            this.submitBtnTarget.disabled = false;
            this.submitBtnTarget.textContent = 'Créer le projet';
        }
    }

    showToast(message, type = 'success') {
        window.dispatchEvent(new CustomEvent('toast:show', {
            detail: { message, type }
        }));
    }
}
