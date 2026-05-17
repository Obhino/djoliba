import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static targets = ['modal', 'card', 'input', 'typeInput', 'typeCard', 'title', 'submitBtn', 'typeSection'];

    connect() {
        // Listen to global open event
        this.boundHandleOpen = this.handleOpen.bind(this);
        window.addEventListener('project-modal:open', this.boundHandleOpen);
    }

    disconnect() {
        window.removeEventListener('project-modal:open', this.boundHandleOpen);
    }

    handleOpen(event) {
        const type = event.detail.type || '';
        this.open(type);
    }

    open(type = '') {
        this.inputTarget.value = '';
        
        if (type) {
            // Pre-selected type, hide selection section
            this.typeInputTarget.value = type;
            this.typeSectionTarget.classList.add('hidden');
            
            const labels = {
                literature_review: 'Revue de Littérature / Synthèse',
                reading: 'Lecture / Analyse PDF',
                writing: 'Rédaction / Publication',
                thesis: 'Thèse / Mémoire'
            };
            this.titleTarget.textContent = `Nouveau Projet : ${labels[type] || 'Recherche'}`;
        } else {
            // General creation, show selector and set default to 'literature_review'
            this.typeSectionTarget.classList.remove('hidden');
            this.typeInputTarget.value = 'literature_review';
            this.titleTarget.textContent = 'Créer un nouveau projet';
            
            // Set first card visual state to active
            this.typeCardTargets.forEach(card => {
                const cardType = card.dataset.type;
                if (cardType === 'literature_review') {
                    card.classList.add('active');
                } else {
                    card.classList.remove('active');
                }
            });
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

    selectType(event) {
        event.preventDefault();
        
        const name = this.inputTarget.value.trim();
        if (!name) {
            this.inputTarget.focus();
            // Flash the input border in red to prompt the user
            this.inputTarget.classList.add('border-red-500', 'ring-red-100');
            setTimeout(() => {
                this.inputTarget.classList.remove('border-red-500', 'ring-red-100');
            }, 1000);
            this.showToast('Veuillez saisir un nom pour votre projet.', 'error');
            return;
        }

        const selectedType = event.currentTarget.dataset.type;
        this.typeInputTarget.value = selectedType;

        // Visual toggle active classes
        this.typeCardTargets.forEach(card => {
            if (card === event.currentTarget) {
                card.classList.add('active');
            } else {
                card.classList.remove('active');
            }
        });

        // Trigger instant submission!
        this.submitForm();
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
        if (event) event.preventDefault();
        await this.submitForm();
    }

    async submitForm() {
        const name = this.inputTarget.value.trim();
        const type = this.typeInputTarget.value;

        if (!name) {
            this.inputTarget.focus();
            return;
        }

        // Disable all cards and submit button, and show loading states
        this.typeCardTargets.forEach(card => card.style.pointerEvents = 'none');
        if (this.hasSubmitBtnTarget) {
            this.submitBtnTarget.disabled = true;
            this.submitBtnTarget.innerHTML = `
                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg> Création...
            `;
        }

        // Show a loading text or indicator inside the clicked card!
        const activeCard = this.typeCardTargets.find(card => card.classList.contains('active'));
        if (activeCard) {
            activeCard.innerHTML = `
                <svg class="animate-spin h-6 w-6 text-djoliba mx-auto mb-1" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-[11px] font-bold text-djoliba block leading-tight">Création...</span>
            `;
        }

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
            
            // Restore visual state
            this.typeCardTargets.forEach(card => card.style.pointerEvents = 'auto');
            if (activeCard) {
                const labels = {
                    literature_review: { icon: '📚', text: 'Synthèse / Revue' },
                    reading: { icon: '📖', text: 'Lecture / PDF' },
                    writing: { icon: '✍️', text: 'Rédaction / Pub' },
                    thesis: { icon: '🎓', text: 'Thèse / Mémoire' }
                };
                const info = labels[type] || { icon: '🔍', text: 'Projet' };
                activeCard.innerHTML = `
                    <span class="text-2xl mb-1">${info.icon}</span>
                    <span class="text-[11px] font-bold text-djoliba block leading-tight">${info.text}</span>
                `;
            }
            if (this.hasSubmitBtnTarget) {
                this.submitBtnTarget.disabled = false;
                this.submitBtnTarget.textContent = 'Créer le projet';
            }
        }
    }

    showToast(message, type = 'success') {
        window.dispatchEvent(new CustomEvent('toast:show', {
            detail: { message, type }
        }));
    }
}
