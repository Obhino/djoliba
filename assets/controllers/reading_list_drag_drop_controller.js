import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['dropzone', 'fileInput', 'progressBar', 'progressContainer', 'statusText'];

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
        this.dropzoneTarget.classList.add('border-djoliba', 'bg-djoliba/5', 'ring-4', 'ring-djoliba/10');
    }

    onDragLeave(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.remove('border-djoliba', 'bg-djoliba/5', 'ring-4', 'ring-djoliba/10');
    }

    onDrop(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.remove('border-djoliba', 'bg-djoliba/5', 'ring-4', 'ring-djoliba/10');
        if (this.isUploading) return;

        const files = event.dataTransfer.files;
        if (files.length > 0 && files[0].type === 'application/pdf') {
            this.uploadFile(files[0]);
        } else {
            alert('Veuillez sélectionner un fichier PDF valide.');
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
        this.progressContainerTarget.classList.remove('hidden');
        this.statusTextTarget.textContent = "Création de l'activité de lecture...";
        
        try {
            // 1. Créer le projet de lecture
            const name = file.name.replace(/\.[^/.]+$/, "");
            const projectResponse = await fetch('/api/projects', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ name: name, type: 'reading' })
            });

            if (!projectResponse.ok) throw new Error("Erreur lors de la création du projet");

            const projectResult = await projectResponse.json();
            const projectData = projectResult.data || projectResult;
            const projectId = projectData.id;

            this.statusTextTarget.textContent = "Téléversement de l'article...";

            // 2. Téléverser le PDF lié à ce projet
            const formData = new FormData();
            formData.append('file', file);
            formData.append('project_id', projectId);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/api/reading/upload', true);

            // Suivi de progression
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    this.progressBarTarget.style.width = percentComplete + '%';
                }
            };

            xhr.onload = () => {
                if (xhr.status === 201 || xhr.status === 200) {
                    const res = JSON.parse(xhr.responseText);
                    this.statusTextTarget.textContent = "Redirection...";
                    if (res.data && res.data.redirect_url) {
                        window.location.href = res.data.redirect_url;
                    } else {
                        window.location.href = `/project/${projectId}/reading`;
                    }
                } else if (xhr.status === 409) {
                    this.resetUI("Doublon détecté.");
                    let redirectUrl = null;
                    let errorMsg = 'Un document avec le même nom existe déjà.';
                    try {
                        const res = JSON.parse(xhr.responseText);
                        redirectUrl = res.error?.redirect_url;
                        errorMsg = res.error?.message || errorMsg;
                    } catch (e) {}

                    if (redirectUrl && confirm(`${errorMsg}\nSouhaitez-vous être redirigé vers sa synthèse ?`)) {
                        window.location.href = redirectUrl;
                    }
                } else {
                    let errorMsg = "Erreur lors du téléversement de l'article.";
                    try {
                        const res = JSON.parse(xhr.responseText);
                        errorMsg = res.error?.message || errorMsg;
                    } catch (e) {}
                    this.resetUI("Erreur d'upload.");
                    alert(errorMsg);
                }
            };

            xhr.onerror = () => {
                this.resetUI("Erreur de connexion.");
                alert("Erreur réseau.");
            };

            xhr.send(formData);

        } catch (error) {
            console.error(error);
            this.resetUI("Erreur.");
            alert("Erreur: " + error.message);
        }
    }

    resetUI(message = "") {
        this.isUploading = false;
        this.progressContainerTarget.classList.add('hidden');
        this.progressBarTarget.style.width = '0%';
    }
}
