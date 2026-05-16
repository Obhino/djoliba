import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static targets = ['input', 'fileInput']

    /**
     * Gère la recherche et la création d'un projet de revue de littérature
     */
    async onSearch(event) {
        if (event.type === 'keydown' && event.key !== 'Enter') return;
        
        const query = this.inputTarget.value.trim();
        if (!query) return;

        try {
            const response = await fetch('/api/projects', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    name: query,
                    type: 'literature_review'
                })
            });

            if (!response.ok) throw new Error('Erreur lors de la création du projet');

            const result = await response.json();
            const project = result.data || result;
            
            // Redirection intelligente selon le type de projet
            if (project.type === 'literature_review') {
                window.location.href = `/project/${project.id}/literature?autostart=1`;
            } else if (project.type === 'reading') {
                window.location.href = `/project/${project.id}/reading`;
            } else {
                window.location.href = `/project/${project.id}`;
            }
        } catch (error) {
            console.error(error);
            // On pourrait utiliser le toast_controller ici
            alert('Impossible de créer le projet : ' + error.message);
        }
    }

    /**
     * Déclenche l'explorateur de fichiers
     */
    triggerUpload() {
        this.fileInputTarget.click();
    }

    /**
     * Gère l'upload du PDF et la création d'un projet de lecture
     */
    async onUpload(event) {
        const file = event.target.files[0];
        if (!file) return;

        try {
            // 1. Créer le projet de type lecture ('reading')
            const projectResponse = await fetch('/api/projects', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    name: file.name.replace(/\.[^/.]+$/, ""), // Enlever l'extension
                    type: 'reading'
                })
            });

            if (!projectResponse.ok) throw new Error("Erreur lors de la création du projet");

            const projectResult = await projectResponse.json();
            const projectData = projectResult.data || projectResult;
            const projectId = projectData.id;

            // 2. Téléverser le PDF lié à ce projet
            const uploadFormData = new FormData();
            uploadFormData.append('file', file);
            uploadFormData.append('project_id', projectId);

            const uploadResponse = await fetch('/api/reading/upload', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: uploadFormData
            });

            if (!uploadResponse.ok) throw new Error("Erreur lors du téléversement du document");

            // 3. Rediriger l'utilisateur vers l'interface de lecture dédiée du projet
            window.location.href = `/project/${projectId}/reading`;
        } catch (error) {
            console.error(error);
            alert("Erreur lors de l'intégration : " + error.message);
        }
    }
}
