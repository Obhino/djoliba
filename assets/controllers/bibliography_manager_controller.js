import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'addModal', 'importModal', 'associationModal',
        'doiInput', 'doiStatus', 'titleInput', 'authorsInput',
        'yearInput', 'journalInput', 'publisherInput', 'citeKeyInput'
    ];

    connect() {
        // Initialisation si nécessaire
    }

    // --- MODALES D'AJOUT & IMPORT GENERAL (DASHBOARD ET PROJET) ---
    openAddModal() {
        if (this.hasAddModalTarget) {
            this.addModalTarget.classList.remove('hidden');
            // Animer la transition d'opacité/échelle
            const container = this.addModalTarget.querySelector('.relative');
            if (container) {
                setTimeout(() => {
                    container.classList.remove('scale-95', 'opacity-0');
                    container.classList.add('scale-100', 'opacity-100');
                }, 10);
            }
        }
    }

    closeAddModal() {
        if (this.hasAddModalTarget) {
            const container = this.addModalTarget.querySelector('.relative');
            if (container) {
                container.classList.remove('scale-100', 'opacity-100');
                container.classList.add('scale-95', 'opacity-0');
            }
            setTimeout(() => {
                this.addModalTarget.classList.add('hidden');
            }, 300);
        }
    }

    openImportModal() {
        if (this.hasImportModalTarget) {
            this.importModalTarget.classList.remove('hidden');
            const container = this.importModalTarget.querySelector('.relative');
            if (container) {
                setTimeout(() => {
                    container.classList.remove('scale-95', 'opacity-0');
                    container.classList.add('scale-100', 'opacity-100');
                }, 10);
            }
        }
    }

    closeImportModal() {
        if (this.hasImportModalTarget) {
            const container = this.importModalTarget.querySelector('.relative');
            if (container) {
                container.classList.remove('scale-100', 'opacity-100');
                container.classList.add('scale-95', 'opacity-0');
            }
            setTimeout(() => {
                this.importModalTarget.classList.add('hidden');
            }, 300);
        }
    }

    // --- MODALES D'ASSOCIATION POUR PROJET ---
    openAssociationModal() {
        if (this.hasAssociationModalTarget) {
            this.associationModalTarget.classList.remove('hidden');
            const container = this.associationModalTarget.querySelector('.relative');
            if (container) {
                setTimeout(() => {
                    container.classList.remove('scale-95', 'opacity-0');
                    container.classList.add('scale-100', 'opacity-100');
                }, 10);
            }
        }
    }

    closeAssociationModal() {
        if (this.hasAssociationModalTarget) {
            const container = this.associationModalTarget.querySelector('.relative');
            if (container) {
                container.classList.remove('scale-100', 'opacity-100');
                container.classList.add('scale-95', 'opacity-0');
            }
            setTimeout(() => {
                this.associationModalTarget.classList.add('hidden');
            }, 300);
        }
    }

    // --- RESOLUTION DE DOI (AJAX) ---
    async resolveDoi(event) {
        event.preventDefault();
        
        if (!this.hasDoiInputTarget) return;
        const doi = this.doiInputTarget.value.trim();

        if (!doi) {
            this.showDoiStatus('Veuillez saisir un DOI.', 'text-rose-500');
            return;
        }

        this.showDoiStatus('Résolution du DOI via Crossref en cours...', 'text-slate-500 animate-pulse');

        try {
            const response = await fetch(`/bibliography/resolve-doi?doi=${encodeURIComponent(doi)}`);
            const result = await response.json();

            if (!response.ok || !result.success) {
                this.showDoiStatus(result.error || 'Erreur lors de la résolution du DOI.', 'text-rose-500');
                return;
            }

            const data = result.data;

            // Pré-remplir les champs du formulaire
            if (this.hasTitleInputTarget && data.title) {
                this.titleInputTarget.value = data.title;
            }
            if (this.hasAuthorsInputTarget && data.authors) {
                this.authorsInputTarget.value = data.authors;
            }
            if (this.hasYearInputTarget && data.year) {
                this.yearInputTarget.value = data.year;
            }
            if (this.hasJournalInputTarget && data.journal) {
                this.journalInputTarget.value = data.journal;
            }
            if (this.hasPublisherInputTarget && data.publisher) {
                this.publisherInputTarget.value = data.publisher;
            }
            
            // Générer un CiteKey provisoire
            if (this.hasCiteKeyInputTarget) {
                const authorWord = data.authors 
                    ? data.authors.split(',')[0].trim().replace(/[^a-zA-Z0-9]/g, '')
                    : 'Author';
                const yearWord = data.year || new Date().getFullYear();
                this.citeKeyInputTarget.value = (authorWord.charAt(0).toUpperCase() + authorWord.slice(1).toLowerCase()) + yearWord;
            }

            this.showDoiStatus('Métadonnées DOI récupérées avec succès !', 'text-emerald-600 font-bold');
        } catch (error) {
            this.showDoiStatus('Erreur de connexion lors de la résolution du DOI.', 'text-rose-500');
        }
    }

    showDoiStatus(message, classes) {
        if (this.hasDoiStatusTarget) {
            this.doiStatusTarget.className = `text-xs mt-2 ${classes}`;
            this.doiStatusTarget.textContent = message;
        }
    }
}
