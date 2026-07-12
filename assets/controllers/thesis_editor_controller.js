import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * Stimulus Controller — thesis-editor
 *
 * Gère l'édition de la structure de thèse avec drag-and-drop (SortableJS).
 * Permet l'ajout, la modification, la suppression et la réorganisation des chapitres.
 * Supporte également la génération assistée par IA (write) et le contrôle de cohérence.
 */
export default class extends Controller {
    static targets = ['tree', 'editor', 'titleInput', 'saveBtn', 'writeBtn', 'status', 'results', 'blankState'];
    static values = {
        projectId: Number
    };

    connect() {
        this.currentChapterId = null;
        this.autosaveInterval = null;
        this.loadStructure();
        this.#log('thesis-editor connecté');
    }

    disconnect() {
        if (this.autosaveInterval) {
            clearInterval(this.autosaveInterval);
        }
    }

    async loadStructure() {
        this.#setStatus('Chargement de la structure...');
        try {
            const response = await fetch(`/api/thesis/structure?project_id=${this.projectIdValue}`);
            const data = await response.json();
            if (data.success) {
                this.structureData = data.data.structure;
                this.#renderTree(this.structureData);
                this.#initDragAndDrop();
                this.#setStatus('');
            }
        } catch (error) {
            this.#setStatus('Erreur de chargement', true);
        }
    }

    async addChapter(event) {
        const parentId = event.currentTarget.dataset.parentId || null;
        const title = prompt('Titre du nouveau chapitre :');
        if (!title) return;

        try {
            const response = await fetch('/api/thesis/structure', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: this.projectIdValue,
                    title: title,
                    parent_id: parentId
                })
            });
            const data = await response.json();
            if (data.success) {
                this.loadStructure();
            }
        } catch (error) {
            alert('Erreur lors de l\'ajout');
        }
    }

    async addSubChapter() {
        if (!this.currentChapterId) {
            alert("Veuillez d'abord sélectionner un chapitre dans la liste pour y ajouter un sous-chapitre.");
            return;
        }
        const title = prompt('Titre du nouveau sous-chapitre :');
        if (!title) return;

        try {
            const response = await fetch('/api/thesis/structure', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: this.projectIdValue,
                    title: title,
                    parent_id: parseInt(this.currentChapterId)
                })
            });
            const data = await response.json();
            if (data.success) {
                this.loadStructure();
            }
        } catch (error) {
            alert('Erreur lors de l\'ajout du sous-chapitre');
        }
    }

    async editChapter(event) {
        const id = event.currentTarget.dataset.id;
        this.currentChapterId = id;
        
        // Mettre à jour immédiatement la surbrillance dans l'arborescence
        if (this.structureData) {
            this.#renderTree(this.structureData);
        }

        this.#setStatus('Chargement du chapitre...');
        
        try {
            const response = await fetch(`/api/thesis/chapter/${id}`);
            const data = await response.json();
            
            if (data.success) {
                const chapter = data.data.chapter;
                this.titleInputTarget.value = chapter.title;
                const content = chapter.content || '';
                
                // Trouver l'instance du contrôleur writing-editor et lui injecter le contenu
                const editorEl = this.element.querySelector('[data-controller~="writing-editor"]');
                if (editorEl) {
                    const writingEditor = this.application.getControllerForElementAndIdentifier(editorEl, 'writing-editor');
                    if (writingEditor) {
                        writingEditor.setEditorContent(content, content, writingEditor.currentMode || 'wysiwyg');
                    }

                    // Mettre à jour l'ID du sous-projet pour la bibliographie
                    // (le chapitre correspond à un SubProject — id = chapter.subProjectId || id)
                    const subProjectId = chapter.subProjectId || chapter.sub_project_id || id;
                    editorEl.dataset.writingEditorSubProjectIdValue = subProjectId;
                }
                
                // Afficher l'éditeur et masquer l'état vide
                this.editorTarget.classList.remove('hidden');
                if (this.hasBlankStateTarget) {
                    this.blankStateTarget.classList.add('hidden');
                }
                this.#setStatus('');
                
                // Configurer la sauvegarde automatique toutes les 30 secondes
                if (this.autosaveInterval) {
                    clearInterval(this.autosaveInterval);
                }
                this.autosaveInterval = setInterval(() => {
                    this.autosave();
                }, 30000);
            } else {
                this.#setStatus('Erreur lors du chargement du chapitre', true);
            }
        } catch (error) {
            console.error('Erreur de chargement du chapitre :', error);
            this.#setStatus('Erreur de connexion', true);
        }
    }

    async renameChapter(event) {
        event.stopPropagation();
        const id = event.currentTarget.dataset.id;
        const currentTitle = event.currentTarget.dataset.title;
        const newTitle = prompt('Nouveau titre pour cette section :', currentTitle);
        if (!newTitle || newTitle === currentTitle) return;

        this.#setLoading(true, 'Modification du titre...');
        try {
            const response = await fetch(`/api/thesis/chapter/${id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    title: newTitle,
                    content: null
                })
            });
            const data = await response.json();
            if (data.success) {
                this.loadStructure();
                
                // Si c'est le chapitre actif, mettre à jour le input caché
                if (this.currentChapterId == id) {
                    this.titleInputTarget.value = newTitle;
                }
                
                window.dispatchEvent(new CustomEvent('toast:show', {
                    detail: { message: "Titre mis à jour", type: "success" }
                }));
            }
        } catch (error) {
            console.error('Erreur lors de renameChapter :', error);
            alert('Erreur lors de la modification du titre');
        } finally {
            this.#setLoading(false);
        }
    }

    async autosave() {
        if (!this.currentChapterId) return;

        let content = '';
        const editorEl = this.element.querySelector('[data-controller~="writing-editor"]');
        if (editorEl) {
            const writingEditor = this.application.getControllerForElementAndIdentifier(editorEl, 'writing-editor');
            if (writingEditor) {
                content = writingEditor.getMarkdownContent();
            }
        }

        this.#setStatus('Sauvegarde automatique...');
        try {
            const response = await fetch(`/api/thesis/chapter/${this.currentChapterId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    title: this.titleInputTarget.value,
                    content: content
                })
            });
            const data = await response.json();
            if (data.success) {
                this.#setStatus('Sauvegardé');
                setTimeout(() => {
                    if (this.statusTarget.textContent === 'Sauvegardé') {
                        this.#setStatus('');
                    }
                }, 3000);
            }
        } catch (error) {
            console.warn('Échec d\'autosave chapitre :', error);
            this.#setStatus('Échec de la sauvegarde automatique', true);
        }
    }

    async saveChapter() {
        if (!this.currentChapterId) return;

        let content = '';
        const editorEl = this.element.querySelector('[data-controller~="writing-editor"]');
        if (editorEl) {
            const writingEditor = this.application.getControllerForElementAndIdentifier(editorEl, 'writing-editor');
            if (writingEditor) {
                content = writingEditor.getMarkdownContent();
            }
        }

        this.#setLoading(true, 'Enregistrement...');
        try {
            const response = await fetch(`/api/thesis/chapter/${this.currentChapterId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    title: this.titleInputTarget.value,
                    content: content
                })
            });
            const data = await response.json();
            if (data.success) {
                this.loadStructure();
                this.#setStatus('Sauvegardé');
                setTimeout(() => {
                    if (this.statusTarget.textContent === 'Sauvegardé') {
                        this.#setStatus('');
                    }
                }, 3000);

                // Réinitialiser le timer de sauvegarde automatique
                if (this.autosaveInterval) {
                    clearInterval(this.autosaveInterval);
                }
                this.autosaveInterval = setInterval(() => {
                    this.autosave();
                }, 30000);
            }
        } catch (error) {
            this.#setStatus('Erreur d\'enregistrement', true);
        } finally {
            this.#setLoading(false);
        }
    }

    async deleteChapter(event) {
        const id = event.currentTarget.dataset.id;
        if (!confirm('Voulez-vous vraiment supprimer ce chapitre et tous ses sous-chapitres ?')) return;

        try {
            await fetch(`/api/thesis/chapter/${id}`, { method: 'DELETE' });
            this.loadStructure();
            if (this.currentChapterId == id) {
                this.editorTarget.classList.add('hidden');
                if (this.hasBlankStateTarget) {
                    this.blankStateTarget.classList.remove('hidden');
                }
                if (this.autosaveInterval) {
                    clearInterval(this.autosaveInterval);
                    this.autosaveInterval = null;
                }
                this.currentChapterId = null;
            }
        } catch (error) {
            alert('Erreur lors de la suppression');
        }
    }

    async checkConsistency() {
        if (!this.currentChapterId) {
            alert("Veuillez d'abord sélectionner un chapitre.");
            return;
        }

        this.#setStatus('Analyse de cohérence en cours...');
        this.resultsTarget.innerHTML = `
            <div class="text-center py-6 text-slate-400 italic">
                <svg class="w-6 h-6 mx-auto mb-2 animate-spin text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.21 7.89H18" /></svg>
                Génération des suggestions d'amélioration de la cohérence...
            </div>
        `;

        const promptText = `Tu es un réviseur académique expert. Analyse la cohérence scientifique du texte de ce chapitre en relation avec le reste de ma thèse (les autres chapitres).
Identifie 3 incohérences, manques logiques ou opportunités d'amélioration concrètes.
Renvoie STRICTEMENT un tableau JSON de suggestions sans aucun texte explicatif avant ou après.
Le format attendu est :
[
  {
    "id": 1,
    "text": "Description claire et constructive de la suggestion (ex: Insérer une phrase de transition liant cette section avec l'étude des matériaux au Chapitre 2)",
    "prompt": "Consigne précise pour l'IA pour récrire le texte du chapitre afin d'appliquer cette suggestion"
  }
]`;

        try {
            const response = await fetch('/api/thesis/write', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    chapter_id: this.currentChapterId,
                    prompt: promptText
                })
            });
            const data = await response.json();
            if (data.success) {
                const suggestions = this.#extractJson(data.data.response);
                if (suggestions && suggestions.length > 0) {
                    this.#renderSuggestions(suggestions);
                    this.#setStatus('');
                } else {
                    // Fallback si le format JSON n'est pas respecté
                    this.resultsTarget.innerHTML = `
                        <div class="bg-indigo-50 p-4 rounded border border-indigo-200 mt-4">
                            <h4 class="font-bold text-indigo-800 mb-2">Suggestions de Cohérence</h4>
                            <div class="text-sm whitespace-pre-wrap text-slate-700">${data.data.response}</div>
                        </div>
                    `;
                    this.#setStatus('Analyse terminée (format non structuré)');
                }
            } else {
                this.#setStatus('Erreur lors de l\'analyse', true);
            }
        } catch (error) {
            console.error('Erreur lors de checkConsistency :', error);
            this.#setStatus('Erreur de connexion', true);
        }
    }

    #extractJson(text) {
        const match = text.match(/\[\s*\{[\s\S]*\}\s*\]/);
        if (match) {
            try {
                return JSON.parse(match[0]);
            } catch (e) {
                console.error("Erreur de parsing JSON extrait :", e);
            }
        }
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error("Erreur de parsing direct :", e);
        }
        return null;
    }

    #renderSuggestions(suggestions) {
        let html = `
            <div class="bg-slate-50 rounded-2xl border border-slate-200 p-6 space-y-4 shadow-inner mt-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                    <h4 class="font-bold text-djoliba text-xs">Suggestions de Cohérence (IA)</h4>
                    <span class="text-[10px] text-slate-400 italic">${suggestions.length} suggestions trouvées</span>
                </div>
                <div class="space-y-3">
        `;

        suggestions.forEach(s => {
            html += `
                <div class="bg-white rounded-xl border border-slate-100 p-4 shadow-sm flex items-center justify-between gap-4 transition-all hover:shadow-md">
                    <div class="flex-grow text-[11px] text-slate-700 font-semibold leading-relaxed">
                        ${s.text}
                    </div>
                    <button type="button" 
                            data-action="click->thesis-editor#applySuggestion" 
                            data-prompt="${encodeURIComponent(s.prompt)}" 
                            class="px-3.5 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-600 text-[10px] font-bold rounded-lg transition-all flex items-center gap-1 whitespace-nowrap">
                        Appliquer
                    </button>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;
        this.resultsTarget.innerHTML = html;
    }

    async applySuggestion(event) {
        if (!this.currentChapterId) return;
        const suggestionPrompt = decodeURIComponent(event.currentTarget.dataset.prompt);

        this.#setLoading(true, 'Application de l\'amélioration par l\'IA...');
        
        let currentContent = '';
        const editorEl = this.element.querySelector('[data-controller~="writing-editor"]');
        if (editorEl) {
            const writingEditor = this.application.getControllerForElementAndIdentifier(editorEl, 'writing-editor');
            if (writingEditor) {
                currentContent = writingEditor.getMarkdownContent();
            }
        }

        const promptText = `Voici mon texte scientifique actuel du chapitre :
"${currentContent}"

Applique l'amélioration suivante de manière fluide et professionnelle en conservant le style académique : "${suggestionPrompt}".
Renvoie UNIQUEMENT le texte complet du chapitre mis à jour (aucun commentaire d'introduction ou de conclusion).`;

        try {
            const response = await fetch('/api/thesis/write', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    chapter_id: this.currentChapterId,
                    prompt: promptText
                })
            });
            const data = await response.json();
            if (data.success) {
                const updatedContent = data.data.response;
                
                // Injecter le contenu mis à jour dans le writing-editor
                if (editorEl) {
                    const writingEditor = this.application.getControllerForElementAndIdentifier(editorEl, 'writing-editor');
                    if (writingEditor) {
                        writingEditor.setEditorContent(updatedContent, updatedContent, writingEditor.currentMode || 'wysiwyg');
                    }
                }
                
                this.#setStatus('Amélioration appliquée');
                // Nettoyer la liste des suggestions appliquée
                this.resultsTarget.innerHTML = '';
                
                // Sauvegarder automatiquement
                this.autosave();
            }
        } catch (error) {
            console.error('Erreur lors de applySuggestion :', error);
            this.#setStatus('Erreur lors de l\'application de la suggestion', true);
        } finally {
            this.#setLoading(false);
        }
    }

    async writeWithAI() {
        if (!this.currentChapterId) return;
        const promptText = prompt('Instructions pour la rédaction (ex: "Rédige une introduction sur les enjeux...") :');
        if (!promptText) return;

        this.#setLoading(true, 'L\'IA rédige le contenu...');
        try {
            const response = await fetch('/api/thesis/write', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    chapter_id: this.currentChapterId,
                    prompt: promptText
                })
            });
            const data = await response.json();
            if (data.success) {
                const generatedContent = data.data.response;
                
                // Injecter le contenu généré dans le writing-editor
                const editorEl = this.element.querySelector('[data-controller~="writing-editor"]');
                if (editorEl) {
                    const writingEditor = this.application.getControllerForElementAndIdentifier(editorEl, 'writing-editor');
                    if (writingEditor) {
                        writingEditor.setEditorContent(generatedContent, generatedContent, writingEditor.currentMode || 'wysiwyg');
                    }
                }
                
                this.#setStatus('Contenu généré par l\'IA');
            }
        } catch (error) {
            this.#setStatus('Erreur de génération', true);
        } finally {
            this.#setLoading(false);
        }
    }

    // ─────────────────────────────────────────────
    // Rendu et Drag & Drop
    // ─────────────────────────────────────────────

    async checkPlanConsistency() {
        this.#setStatus('Analyse de cohérence du plan en cours...');
        this.resultsTarget.innerHTML = `
            <div class="text-center py-6 text-slate-400 italic">
                <svg class="w-6 h-6 mx-auto mb-2 animate-spin text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.21 7.89H18" /></svg>
                Analyse de la cohérence de la structure du plan...
            </div>
        `;
        
        try {
            const response = await fetch('/api/thesis/consistency', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ project_id: this.projectIdValue })
            });
            const data = await response.json();
            if (data.success) {
                this.resultsTarget.innerHTML = `
                    <div class="bg-indigo-50 p-4 rounded border border-indigo-200 mt-4">
                        <h4 class="font-bold text-indigo-800 mb-2">Analyse de cohérence globale</h4>
                        <div class="text-sm whitespace-pre-wrap text-slate-700">${data.data.response}</div>
                    </div>
                `;
                this.#setStatus('');
            }
        } catch (error) {
            console.error('Erreur lors de checkPlanConsistency :', error);
            this.#setStatus('Erreur d\'analyse', true);
        }
    }

    // ─────────────────────────────────────────────
    // Export PDF du document complet
    // ─────────────────────────────────────────────

    /**
     * Génère un PDF contenant tous les chapitres et sous-chapitres du document.
     * La structure est récupérée depuis l'API, assemblée en HTML cohérent,
     * puis envoyée à la logique de génération PDF partagée (generatePdfFromHtml).
     * N'affecte pas l'export de la section courante dans l'éditeur.
     */
    async exportFullPdf() {
        const exportBtn = document.getElementById('btn-export-full-pdf');
        if (exportBtn) exportBtn.disabled = true;
        this.#setStatus('Préparation du document...');

        try {
            // 1. Récupérer la structure complète avec le contenu (déjà inclus dans la réponse)
            const response = await fetch(`/api/thesis/structure?project_id=${this.projectIdValue}`);
            const data = await response.json();

            if (!data.success || !data.data.structure || data.data.structure.length === 0) {
                this.#setStatus('Aucun chapitre à exporter.', true);
                return;
            }

            const structure = data.data.structure;

            // 2. Récupérer l'instance du writing-editor enfant pour accéder à ses méthodes
            const editorEl = this.element.querySelector('[data-controller~="writing-editor"]');
            if (!editorEl) {
                this.#setStatus('Éditeur introuvable.', true);
                return;
            }
            const writingEditor = this.application.getControllerForElementAndIdentifier(editorEl, 'writing-editor');
            if (!writingEditor) {
                this.#setStatus('Contrôleur éditeur introuvable.', true);
                return;
            }

            // 3. Assembler le HTML du document complet depuis la structure
            const documentHtml = this.#buildDocumentHtml(structure, writingEditor);

            if (!documentHtml.trim()) {
                this.#setStatus('Le document est vide.', true);
                return;
            }

            // 4. Déléguer la génération PDF à la méthode partagée de l'éditeur
            const projectName = document.title.replace(' – Djoliba Search', '').trim() || 'these';
            const filename = `djoliba_these_${this.projectIdValue}.pdf`;

            await writingEditor.generatePdfFromHtml(documentHtml, filename);

        } catch (err) {
            console.error('Erreur exportFullPdf:', err);
            this.#setStatus('Erreur lors de la génération du PDF', true);
        } finally {
            if (exportBtn) exportBtn.disabled = false;
        }
    }

    /**
     * Assemble un HTML structuré depuis l'arbre des chapitres.
     * Chaque chapitre (et sous-chapitre) est converti de Markdown → HTML
     * via les méthodes internes de l'éditeur.
     * @param {Array} structure  - L'arbre de chapitres (avec children)
     * @param {Object} writingEditor - L'instance du writing-editor pour la conversion
     * @returns {string} - HTML complet du document
     */
    #buildDocumentHtml(structure, writingEditor) {
        let html = '';

        structure.forEach((chapter, chapterIdx) => {
            const chapterNum = chapterIdx + 1;

            // Titre de chapitre (h1)
            html += `<h1>${chapterNum}. ${chapter.title}</h1>`;

            // Contenu du chapitre (Markdown → HTML)
            if (chapter.content && chapter.content.trim()) {
                const transcribed = writingEditor.transcribeLatex ? writingEditor.transcribeLatex(chapter.content) : chapter.content;
                const chapterHtml = writingEditor.marked.parse(transcribed);
                html += `<div class="chapter-content">${chapterHtml}</div>`;
            }

            // Sous-chapitres (children)
            if (chapter.children && chapter.children.length > 0) {
                chapter.children.forEach((sub, subIdx) => {
                    const subNum = subIdx + 1;

                    // Titre de sous-chapitre (h2)
                    html += `<h2>${chapterNum}.${subNum} ${sub.title}</h2>`;

                    // Contenu du sous-chapitre (Markdown → HTML)
                    if (sub.content && sub.content.trim()) {
                        const transcribedSub = writingEditor.transcribeLatex ? writingEditor.transcribeLatex(sub.content) : sub.content;
                        const subHtml = writingEditor.marked.parse(transcribedSub);
                        html += `<div class="chapter-content">${subHtml}</div>`;
                    }

                    // Sous-sous-chapitres (niveau 3, si nécessaire)
                    if (sub.children && sub.children.length > 0) {
                        sub.children.forEach((subsub, subsubIdx) => {
                            html += `<h3>${chapterNum}.${subNum}.${subsubIdx + 1} ${subsub.title}</h3>`;
                            if (subsub.content && subsub.content.trim()) {
                                const transcribedSubSub = writingEditor.transcribeLatex ? writingEditor.transcribeLatex(subsub.content) : subsub.content;
                                const subsubHtml = writingEditor.marked.parse(transcribedSubSub);
                                html += `<div class="chapter-content">${subsubHtml}</div>`;
                            }
                        });
                    }
                });
            }
        });

        return html;
    }

    // ─────────────────────────────────────────────
    // Rendu et Drag & Drop
    // ─────────────────────────────────────────────

    #renderTree(structure) {
        this.treeTarget.innerHTML = this.#buildTreeHtml(structure);
    }

    #buildTreeHtml(nodes) {
        let html = '<ul class="thesis-tree-list space-y-2 pl-4 border-l-2 border-slate-100 min-h-[8px] pb-2">';
        if (nodes && nodes.length > 0) {
            nodes.forEach(node => {
                const isActive = node.id == this.currentChapterId;
                html += `
                    <li class="thesis-tree-item py-0.5" data-id="${node.id}">
                        <div class="flex items-center gap-2 group p-2 hover:bg-slate-50 rounded-xl transition-all ${isActive ? 'thesis-tree-item-active' : ''}">
                            <span class="cursor-move text-slate-400 opacity-0 group-hover:opacity-100 mr-1 select-none flex-shrink-0">☰</span>
                            <span class="flex-grow cursor-pointer py-0.5 transition-colors truncate ${isActive ? 'text-djoliba font-bold' : 'text-slate-700 hover:text-djoliba'}" data-action="click->thesis-editor#editChapter" data-id="${node.id}">${node.title}</span>
                            <div class="opacity-0 group-hover:opacity-100 flex gap-1 items-center z-10 flex-shrink-0">
                                <button type="button" class="text-[10px] bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded-lg hover:bg-djoliba hover:text-white transition-all" data-action="click->thesis-editor#renameChapter" data-id="${node.id}" data-title="${node.title}" title="Renommer">✎</button>
                                <button type="button" class="text-[10px] bg-slate-100 border border-slate-200 text-slate-600 px-1.5 py-0.5 rounded-lg hover:bg-djoliba hover:text-white transition-all font-bold" data-action="click->thesis-editor#addChapter" data-parent-id="${node.id}" title="Ajouter une sous-partie">+</button>
                                <button type="button" class="text-[10px] bg-red-50 border border-red-100 text-red-500 px-1.5 py-0.5 rounded-lg hover:bg-red-500 hover:text-white transition-all font-bold" data-action="click->thesis-editor#deleteChapter" data-id="${node.id}" title="Supprimer">×</button>
                            </div>
                        </div>
                        <div class="nested-sortable" data-parent-id="${node.id}">
                            ${this.#buildTreeHtml(node.children)}
                        </div>
                    </li>
                `;
            });
        }
        html += '</ul>';
        return html;
    }

    #initDragAndDrop() {
        const lists = this.treeTarget.querySelectorAll('.thesis-tree-list, .nested-sortable > ul');
        lists.forEach(list => {
            Sortable.create(list, {
                group: 'nested',
                animation: 150,
                fallbackOnBody: true,
                swapThreshold: 0.65,
                handle: '.cursor-move',
                onEnd: () => {
                    const orders = [];
                    const processList = (listEl, parentId = null) => {
                        const items = listEl.children;
                        Array.from(items).forEach((item, index) => {
                            const id = item.dataset.id;
                            if (!id) return;
                            orders.push({ id: parseInt(id), parent_id: parentId, order: index + 1 });
                            
                            const nestedList = item.querySelector('.nested-sortable > ul');
                            if (nestedList) {
                                processList(nestedList, parseInt(id));
                            }
                        });
                    };

                    const treeRoot = this.treeTarget.querySelector('.thesis-tree-list');
                    if (treeRoot) {
                        processList(treeRoot);
                        this.onReorder(orders);
                    }
                }
            });
        });
    }

    async onReorder(chapters) {
        try {
            const response = await fetch('/api/thesis/structure', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: this.projectIdValue,
                    orders: chapters
                })
            });
            const data = await response.json();
            if (data.success) {
                this.loadStructure();

                // Notification Toast "Ordre mis à jour"
                window.dispatchEvent(new CustomEvent('toast:show', {
                    detail: { message: "Ordre mis à jour", type: "success" }
                }));
            }
        } catch (error) {
            console.error('Erreur lors de la réorganisation', error);
            window.dispatchEvent(new CustomEvent('toast:show', {
                detail: { message: "Erreur lors de la réorganisation", type: "error" }
            }));
        }
    }

    checkOriginality() {
        const editorEl = this.element.querySelector('[data-controller~="writing-editor"]');
        if (editorEl) {
            const writingEditor = this.application.getControllerForElementAndIdentifier(editorEl, 'writing-editor');
            if (writingEditor) {
                writingEditor.checkOriginality();
            }
        }
    }

    #setLoading(isLoading, message = '') {
        if (this.hasSaveBtnTarget) this.saveBtnTarget.disabled = isLoading;
        if (this.hasWriteBtnTarget) this.writeBtnTarget.disabled = isLoading;
        this.#setStatus(isLoading ? message : '');
    }

    #setStatus(text, isError = false) {
        if (!this.hasStatusTarget) return;
        this.statusTarget.textContent = text;
        this.statusTarget.className = isError ? 'text-sm text-red-600 mt-2 font-medium' : 'text-sm text-slate-500 mt-2 italic';
    }

    #log(msg) {
        if (import.meta.env?.DEV) {
            console.debug(`[thesis-editor] ${msg}`);
        }
    }
}
