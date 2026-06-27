import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['createModal', 'editModal']

    openCreateModal() {
        if (!this.hasCreateModalTarget) return;

        this.createModalTarget.classList.remove('hidden');
        setTimeout(() => {
            this.createModalTarget.classList.add('opacity-100');
            const inner = this.createModalTarget.querySelector('.transform');
            if (inner) {
                inner.classList.add('scale-100', 'opacity-100');
                inner.classList.remove('scale-95', 'opacity-0');
            }
        }, 10);
    }

    closeCreateModal() {
        if (!this.hasCreateModalTarget) return;

        const inner = this.createModalTarget.querySelector('.transform');
        if (inner) {
            inner.classList.remove('scale-100', 'opacity-100');
            inner.classList.add('scale-95', 'opacity-0');
        }
        this.createModalTarget.classList.remove('opacity-100');
        setTimeout(() => {
            this.createModalTarget.classList.add('hidden');
        }, 300);
    }

    openEditModal(event) {
        if (!this.hasEditModalTarget) return;

        const btn = event.currentTarget;
        const spId = btn.dataset.spId;
        const spName = btn.dataset.spName;

        // Configuration de la soumission de formulaire
        const form = this.editModalTarget.querySelector('#edit-sp-form');
        if (form) {
            form.setAttribute('action', `/sub-project/${spId}/edit`);
        }

        const input = this.editModalTarget.querySelector('#edit-sp-name');
        if (input) {
            input.value = spName;
        }

        this.editModalTarget.classList.remove('hidden');
        setTimeout(() => {
            this.editModalTarget.classList.add('opacity-100');
            const inner = this.editModalTarget.querySelector('.transform');
            if (inner) {
                inner.classList.add('scale-100', 'opacity-100');
                inner.classList.remove('scale-95', 'opacity-0');
            }
        }, 10);
    }

    closeEditModal() {
        if (!this.hasEditModalTarget) return;

        const inner = this.editModalTarget.querySelector('.transform');
        if (inner) {
            inner.classList.remove('scale-100', 'opacity-100');
            inner.classList.add('scale-95', 'opacity-0');
        }
        this.editModalTarget.classList.remove('opacity-100');
        setTimeout(() => {
            this.editModalTarget.classList.add('hidden');
        }, 300);
    }
}
