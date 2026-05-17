import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'dropzone', 'fileInput', 'progressBar', 
        'progressContainer', 'statusText'
    ];

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
            this.processUpload(files[0]);
        } else {
            this.showToast('Veuillez sélectionner un fichier PDF valide.', 'error');
        }
    }

    onFileSelect(event) {
        const file = event.target.files[0];
        if (file) {
            this.processUpload(file);
        }
    }

    async processUpload(file) {
        this.isUploading = true;

        // UI Reset & Show Progress
        this.progressContainerTarget.classList.remove('hidden');
        this.statusTextTarget.classList.remove('hidden');
        this.statusTextTarget.textContent = "Création du projet de synthèse...";
        this.progressBarTarget.style.width = '10%';

        try {
            // 1. Créer le projet 'literature_review'
            const projectResponse = await fetch('/api/projects', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    name: file.name.replace(/\.[^/.]+$/, ""), // Enlever l'extension
                    type: 'literature_review'
                })
            });

            if (!projectResponse.ok) throw new Error("Erreur lors de la création du projet");

            const projectResult = await projectResponse.json();
            const projectData = projectResult.data || projectResult;
            const projectId = projectData.id;

            this.statusTextTarget.textContent = "Importation du document PDF...";
            this.progressBarTarget.style.width = '30%';

            // 2. Téléverser le PDF lié à ce projet via XMLHttpRequest pour suivre précisément l'upload
            const formData = new FormData();
            formData.append('file', file);
            formData.append('project_id', projectId);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/api/reading/upload', true);
            xhr.setRequestHeader('Accept', 'application/json');

            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                    // Progression entre 30% et 90%
                    const percentComplete = 30 + ((e.loaded / e.total) * 60);
                    this.progressBarTarget.style.width = percentComplete + '%';
                }
            };

            xhr.onload = () => {
                if (xhr.status === 201) {
                    this.progressBarTarget.style.width = '100%';
                    this.statusTextTarget.textContent = "Succès ! Redirection...";
                    this.showToast("Projet de synthèse créé avec succès", "success");
                    
                    // 3. Rediriger l'utilisateur vers la page du projet
                    setTimeout(() => {
                        window.location.href = `/project/${projectId}/literature?autostart=1`;
                    }, 500);
                } else {
                    this.resetUI("Erreur d'importation.");
                    this.showToast("Erreur lors du téléversement du document.", "error");
                }
            };

            xhr.onerror = () => {
                this.resetUI("Erreur de connexion.");
                this.showToast("Erreur de connexion réseau.", "error");
            };

            xhr.send(formData);

        } catch (error) {
            console.error(error);
            this.resetUI("Erreur d'intégration.");
            this.showToast("Erreur : " + error.message, "error");
        }
    }

    resetUI(message = "") {
        this.isUploading = false;
        this.progressContainerTarget.classList.add('hidden');
        this.statusTextTarget.classList.add('hidden');
        this.progressBarTarget.style.width = '0%';
        if (this.hasFileInputTarget) {
            this.fileInputTarget.value = '';
        }
    }

    showToast(message, type = 'success') {
        window.dispatchEvent(new CustomEvent('toast:show', {
            detail: { message, type }
        }));
    }
}
