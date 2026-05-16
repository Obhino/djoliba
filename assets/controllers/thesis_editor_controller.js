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
    static targets = ['tree', 'editor', 'titleInput', 'contentInput', 'saveBtn', 'writeBtn', 'status', 'results'];
    static values = {
        projectId: Number
    };

    connect() {
        this.currentChapterId = null;
        this.loadStructure();
        this.#log('thesis-editor connecté');
    }

    async loadStructure() {
        this.#setStatus('Chargement de la structure...');
        try {
            const response = await fetch(`/api/thesis/structure?project_id=${this.projectIdValue}`);
            const data = await response.json();
            if (data.success) {
                this.#renderTree(data.data.structure);
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
            const response = await fetch('/api/thesis/chapter', {
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

    editChapter(event) {
        const id = event.currentTarget.dataset.id;
        const title = event.currentTarget.dataset.title;
        const content = event.currentTarget.dataset.content || '';

        this.currentChapterId = id;
        this.titleInputTarget.value = title;
        this.contentInputTarget.value = content;
        
        // Afficher l'éditeur
        this.editorTarget.classList.remove('hidden');
        this.#setStatus(`Édition de : ${title}`);
    }

    async saveChapter() {
        if (!this.currentChapterId) return;

        this.#setLoading(true, 'Enregistrement...');
        try {
            const response = await fetch(`/api/thesis/chapter/${this.currentChapterId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    title: this.titleInputTarget.value,
                    content: this.contentInputTarget.value
                })
            });
            const data = await response.json();
            if (data.success) {
                this.loadStructure();
                this.#setStatus('Enregistré avec succès');
                setTimeout(() => this.#setStatus(''), 2000);
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
            }
        } catch (error) {
            alert('Erreur lors de la suppression');
        }
    }

    async checkConsistency() {
        this.#setStatus('Analyse de cohérence en cours...');
        this.resultsTarget.innerHTML = '';
        
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
                        <h4 class="font-bold text-indigo-800 mb-2">Analyse de cohérence</h4>
                        <div class="text-sm whitespace-pre-wrap text-slate-700">${data.data.response}</div>
                    </div>
                `;
                this.#setStatus('');
            }
        } catch (error) {
            this.#setStatus('Erreur d\'analyse', true);
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
                this.contentInputTarget.value = data.data.response;
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

    #renderTree(structure) {
        this.treeTarget.innerHTML = this.#buildTreeHtml(structure);
    }

    #buildTreeHtml(nodes) {
        if (!nodes || nodes.length === 0) return '';
        
        let html = '<ul class="thesis-tree-list space-y-2 pl-4 border-l-2 border-slate-100">';
        nodes.forEach(node => {
            html += `
                <li class="thesis-tree-item py-1" data-id="${node.id}">
                    <div class="flex items-center gap-2 group p-2 hover:bg-slate-50 rounded transition-colors">
                        <span class="cursor-move text-slate-400 opacity-0 group-hover:opacity-100">☰</span>
                        <span class="font-medium text-slate-700 flex-grow">${node.title}</span>
                        <div class="opacity-0 group-hover:opacity-100 flex gap-1">
                            <button type="button" class="text-xs bg-slate-200 px-2 py-1 rounded" data-action="click->thesis-editor#editChapter" data-id="${node.id}" data-title="${node.title}" data-content="${node.content || ''}">Editer</button>
                            <button type="button" class="text-xs bg-indigo-100 text-indigo-600 px-2 py-1 rounded" data-action="click->thesis-editor#addChapter" data-parent-id="${node.id}">+</button>
                            <button type="button" class="text-xs bg-red-50 text-red-500 px-2 py-1 rounded" data-action="click->thesis-editor#deleteChapter" data-id="${node.id}">×</button>
                        </div>
                    </div>
                    <div class="nested-sortable" data-parent-id="${node.id}">
                        ${this.#buildTreeHtml(node.children)}
                    </div>
                </li>
            `;
        });
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
                onEnd: () => this.#persistOrder()
            });
        });
    }

    async #persistOrder() {
        const orders = [];
        const processList = (list, parentId = null) => {
            const items = list.children;
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

        processList(this.treeTarget.querySelector('.thesis-tree-list'));

        try {
            await fetch('/api/thesis/reorder', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: this.projectIdValue,
                    orders: orders
                })
            });
        } catch (error) {
            console.error('Erreur lors de la réorganisation', error);
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
