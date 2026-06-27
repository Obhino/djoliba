import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static targets = ['nameInput', 'descInput', 'card', 'submitBtn'];

    connect() {
        this.boundHandleOpen = this.handleOpen.bind(this);
        window.addEventListener('research-project-modal:open', this.boundHandleOpen);
    }

    disconnect() {
        window.removeEventListener('research-project-modal:open', this.boundHandleOpen);
    }

    handleOpen(event) {
        this.open();
    }

    open() {
        this.nameInputTarget.value = '';
        if (this.hasDescInputTarget) {
            this.descInputTarget.value = '';
        }

        this.element.classList.remove('hidden');
        setTimeout(() => {
            this.element.classList.remove('opacity-0');
            this.element.classList.add('opacity-100', 'bg-slate-950/40');
            
            if (this.hasCardTarget) {
                this.cardTarget.classList.remove('scale-95', 'opacity-0');
                this.cardTarget.classList.add('scale-100', 'opacity-100');
            }
            
            this.nameInputTarget.focus();
        }, 50);
    }

    close() {
        if (this.hasCardTarget) {
            this.cardTarget.classList.remove('scale-100', 'opacity-100');
            this.cardTarget.classList.add('scale-95', 'opacity-0');
        }
        
        this.element.classList.remove('opacity-100', 'bg-slate-950/40');
        this.element.classList.add('opacity-0');

        setTimeout(() => {
            this.element.classList.add('hidden');
        }, 300);
    }

    async submit(event) {
        event.preventDefault();
        const name = this.nameInputTarget.value.trim();
        const description = this.hasDescInputTarget ? this.descInputTarget.value.trim() : '';

        if (!name) return;

        if (this.hasSubmitBtnTarget) {
            this.submitBtnTarget.disabled = true;
            this.submitBtnTarget.textContent = 'Création...';
        }

        try {
            const response = await fetch('/api/research-projects', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    name,
                    description,
                    select: true // Make active automatically
                })
            });

            if (!response.ok) throw new Error('Erreur lors de la création');

            const result = await response.json();
            if (result.success) {
                window.location.reload();
            }
        } catch (error) {
            console.error(error);
            alert('Impossible de créer le projet de recherche : ' + error.message);
            if (this.hasSubmitBtnTarget) {
                this.submitBtnTarget.disabled = false;
                this.submitBtnTarget.textContent = 'Créer';
            }
        }
    }
}
