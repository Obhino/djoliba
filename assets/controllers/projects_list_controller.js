import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['container']

    connect() {
        this.loadProjects();
        // Rafraîchissement toutes les 30 secondes
        this.refreshTimer = setInterval(() => this.loadProjects(), 30000);
    }

    disconnect() {
        clearInterval(this.refreshTimer);
    }

    async loadProjects() {
        try {
            const response = await fetch('/api/projects?limit=5');
            if (!response.ok) throw new Error('Erreur de chargement');
            
            const result = await response.json();
            this.renderProjects(result.data);
        } catch (error) {
            console.error(error);
        }
    }

    renderProjects(projects) {
        if (projects.length === 0) {
            this.containerTarget.innerHTML = `
                <div class="bg-white rounded-2xl border-2 border-dashed border-slate-100 p-12 text-center col-span-full">
                    <p class="text-slate-400">Aucun projet récent. Commencez par soumettre un PDF !</p>
                </div>
            `;
            return;
        }

        this.containerTarget.innerHTML = projects.map(project => this.projectTemplate(project)).join('');
    }

    projectTemplate(project) {
        const date = new Date(project.updatedAt || project.createdAt).toLocaleDateString('fr-FR');
        const icon = this.getTypeIcon(project.type);
        
        return `
            <a href="/project/${project.id}" class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm hover:shadow-md transition-all group">
                <div class="flex justify-between items-start mb-4">
                    <div class="w-10 h-10 bg-slate-50 rounded-lg flex items-center justify-center text-djoliba group-hover:bg-djoliba group-hover:text-white transition-colors">
                        ${icon}
                    </div>
                    <span class="text-[10px] font-bold text-slate-400 uppercase">${date}</span>
                </div>
                <h3 class="font-bold text-djoliba mb-1 group-hover:text-emerald_ia transition-colors">${project.name}</h3>
                <p class="text-xs text-slate-500 line-clamp-1">Type: ${this.getTypeLabel(project.type)}</p>
            </a>
        `;
    }

    getTypeIcon(type) {
        const icons = {
            literature_review: '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>',
            reading: '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253" /></svg>',
            writing: '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>',
            thesis: '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z" /></svg>'
        };
        return icons[type] || icons.literature_review;
    }

    getTypeLabel(type) {
        const labels = {
            literature_review: 'Revue de littérature',
            reading: 'Lecture / PDF',
            writing: 'Écriture',
            thesis: 'Thèse / Mémoire'
        };
        return labels[type] || 'Projet';
    }
}
