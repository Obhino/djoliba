import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static values = {
        type: String
    }

    async createProject(event) {
        event.preventDefault();

        // 1. Déterminer le libellé du type pour le prompt
        const labels = {
            literature_review: 'Revue de Littérature / Synthèse',
            reading: 'Lecture / Analyse PDF',
            writing: 'Rédaction / Publication',
            thesis: 'Thèse / Mémoire'
        };
        const label = labels[this.typeValue] || 'Recherche';

        // 2. Demander le nom du projet au chercheur
        const projectName = prompt(`Entrez le nom de votre nouveau projet (${label}) :`);
        if (!projectName || !projectName.trim()) {
            return;
        }

        try {
            // 3. Envoyer la requête de création du projet de ce type précis
            const response = await fetch('/api/projects', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    name: projectName.trim(),
                    type: this.typeValue
                })
            });

            if (!response.ok) {
                throw new Error('Erreur lors de la création du projet');
            }

            const result = await response.json();
            const project = result.data || result;

            // 4. Redirection intelligente vers le bon module du projet créé
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
        } catch (error) {
            console.error(error);
            alert('Impossible de créer le projet : ' + error.message);
        }
    }
}
