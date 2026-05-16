import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'dropzone', 'fileInput', 'progressBar', 
        'progressContainer', 'statusText', 'docName', 
        'emptyState', 'loader', 'pointsList'
    ];
    
    static values = {
        projectId: Number
    };

    connect() {
        this.isUploading = false;
    }

    triggerSelect() {
        if (this.isUploading) return;
        this.fileInputTarget.click();
    }

    onDragOver(event) {
        event.preventDefault();
        if (this.isUploading) return;
        this.dropzoneTarget.classList.add('border-djoliba', 'bg-djoliba/5');
    }

    onDragLeave(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.remove('border-djoliba', 'bg-djoliba/5');
    }

    onDrop(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.remove('border-djoliba', 'bg-djoliba/5');
        if (this.isUploading) return;

        const files = event.dataTransfer.files;
        if (files.length > 0 && files[0].type === 'application/pdf') {
            this.uploadFile(files[0]);
        } else {
            this.showToast('Veuillez sélectionner un fichier PDF valide.', 'error');
        }
    }

    onFileSelect(event) {
        const file = event.target.files[0];
        if (file) {
            this.uploadFile(file);
        }
    }

    async uploadFile(file) {
        this.isUploading = true;
        
        // UI Reset
        this.progressContainerTarget.classList.remove('hidden');
        this.statusTextTarget.classList.remove('hidden');
        this.statusTextTarget.textContent = "Upload du fichier...";
        this.docNameTarget.textContent = file.name;
        this.emptyStateTarget.classList.add('hidden');
        this.pointsListTarget.classList.add('hidden');
        this.loaderTarget.classList.add('hidden');

        const formData = new FormData();
        formData.append('file', file);
        formData.append('project_id', this.projectIdValue);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/reading/upload', true);

        // Suivi de la progression de l'upload
        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) {
                const percentComplete = (e.loaded / e.total) * 100;
                this.progressBarTarget.style.width = percentComplete + '%';
            }
        };

        xhr.onload = async () => {
            if (xhr.status === 201) {
                const response = JSON.parse(xhr.responseText);
                if (response.success && response.data.document_id) {
                    this.statusTextTarget.textContent = "Génération de la synthèse...";
                    
                    // Liaison avec le Chat
                    const chatEl = document.querySelector('[data-controller="reading-chat"]');
                    if (chatEl) {
                        chatEl.setAttribute('data-reading-chat-document-id-value', response.data.document_id);
                    }

                    // Lancer la synthèse
                    await this.generateSynthesis(response.data.document_id);
                } else {
                    this.resetUI("Erreur d'upload.");
                }
            } else {
                this.resetUI("Erreur serveur lors de l'upload.");
                this.showToast("Erreur lors de l'upload du document.", "error");
            }
        };

        xhr.onerror = () => {
            this.resetUI("Erreur de connexion.");
            this.showToast("Erreur de connexion réseau.", "error");
        };

        xhr.send(formData);
    }

    async generateSynthesis(documentId) {
        this.loaderTarget.classList.remove('hidden');

        try {
            const response = await fetch('/api/reading/synthesize', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    document_id: documentId,
                    project_id: this.projectIdValue
                })
            });

            const result = await response.json();

            if (response.ok && result.success) {
                this.renderSynthesis(result.data.points);
                this.showToast("Document synthétisé avec succès", "success");
            } else {
                this.resetUI("Impossible de générer la synthèse.");
                this.showToast("Erreur de synthèse IA.", "error");
            }
        } catch (e) {
            console.error(e);
            this.resetUI("Erreur réseau.");
            this.showToast("Erreur lors de la génération de la synthèse.", "error");
        } finally {
            this.isUploading = false;
            this.progressContainerTarget.classList.add('hidden');
            this.statusTextTarget.classList.add('hidden');
            this.progressBarTarget.style.width = '0%';
        }
    }

    renderSynthesis(points) {
        this.loaderTarget.classList.add('hidden');
        this.pointsListTarget.classList.remove('hidden');

        if (!points || points.length === 0) {
            this.pointsListTarget.innerHTML = '<p class="text-xs text-slate-400 text-center">Aucun point clé disponible.</p>';
            return;
        }

        this.pointsListTarget.innerHTML = points.map((p, index) => `
            <div class="flex gap-4 items-start p-4 rounded-2xl bg-slate-50 border border-slate-100 hover:border-djoliba/10 transition-colors">
                <span class="flex-shrink-0 w-6 h-6 rounded-full bg-djoliba/10 text-djoliba font-bold text-xs flex items-center justify-center">${index + 1}</span>
                <div class="space-y-1">
                    <h4 class="text-xs font-bold text-djoliba">${p.point}</h4>
                    <p class="text-xs text-slate-600 leading-relaxed">${p.explication}</p>
                </div>
            </div>
        `).join('');
    }

    resetUI(message = "") {
        this.isUploading = false;
        this.progressContainerTarget.classList.add('hidden');
        this.statusTextTarget.classList.add('hidden');
        this.progressBarTarget.style.width = '0%';
        this.emptyStateTarget.classList.remove('hidden');
        this.pointsListTarget.classList.add('hidden');
        this.loaderTarget.classList.add('hidden');
        this.docNameTarget.textContent = "Aucun document chargé";
    }

    showToast(message, type = 'success') {
        window.dispatchEvent(new CustomEvent('toast:show', {
            detail: { message, type }
        }));
    }
}
