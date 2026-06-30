import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus Controller — editor-ai
 *
 * Gère l'assistant IA interactif de l'éditeur Djoliba :
 * - Détection de sélection et positionnement de la barre d'outils flottante
 * - Raccourcis clavier (Ctrl+Shift+R, F, E, D, A, P, C, T, W)
 * - Requêtes AJAX et streaming SSE (Server-Sent Events)
 * - Insertion et remplacement dans l'éditeur TipTap
 * - Historique et acceptation/rejet des suggestions
 */
export default class extends Controller {
    static targets = [
        'floatingToolbar', 'aiModal', 'modalTitle', 'modalContent',
        'replaceBtn', 'insertAfterBtn', 'acceptBtn', 'rejectBtn',
        'historyList', 'historyContainer', 'askInput', 'askFormContainer',
        'translateContainer', 'translateSelect', 'toneContainer', 'toneSelect',
        'toggleHistoryBtn'
    ];

    connect() {
        this.projectId = this.element.getAttribute('data-writing-editor-project-id-value');
        this.currentSelection = '';
        this.currentInteractionId = null;
        
        // Liaison des événements de sélection
        this.handleSelectionBound = this.handleSelection.bind(this);
        document.addEventListener('mouseup', this.handleSelectionBound);
        document.addEventListener('keyup', this.handleSelectionBound);
        
        // Liaison des raccourcis clavier
        this.handleShortcutsBound = this.handleShortcuts.bind(this);
        document.addEventListener('keydown', this.handleShortcutsBound);

        // Charger l'historique initialement
        this.loadHistory();
    }

    disconnect() {
        document.removeEventListener('mouseup', this.handleSelectionBound);
        document.removeEventListener('keyup', this.handleSelectionBound);
        document.removeEventListener('keydown', this.handleShortcutsBound);
    }

    /**
     * Récupère l'instance TipTap de l'éditeur du contrôleur writing-editor.
     */
    getEditor() {
        const writingEditor = this.application.getControllerForElementAndIdentifier(this.element, 'writing-editor');
        return writingEditor ? writingEditor.editor : null;
    }

    /**
     * Détecte la sélection de texte et affiche la barre d'outils flottante.
     */
    handleSelection() {
        const selection = window.getSelection();
        const selectedText = selection.toString().trim();

        if (selectedText.length > 0) {
            this.currentSelection = selectedText;

            // Vérifier si la sélection provient bien de notre zone d'édition
            const anchorNode = selection.anchorNode;
            const editorContainer = this.element.querySelector('[data-writing-editor-target="editorContainer"]') 
                || this.element.querySelector('[data-writing-editor-target="input"]');

            if (editorContainer && editorContainer.contains(anchorNode)) {
                this.showFloatingToolbar();
                return;
            }
        }

        this.hideFloatingToolbar();
    }

    showFloatingToolbar() {
        const selection = window.getSelection();
        if (selection.rangeCount === 0) return;

        const range = selection.getRangeAt(0);
        const rect = range.getBoundingClientRect();
        const toolbar = this.floatingToolbarTarget;

        toolbar.classList.remove('hidden');
        
        // Positionnement au-dessus de la sélection, centré horizontalement
        const x = rect.left + window.scrollX + (rect.width / 2) - (toolbar.offsetWidth / 2);
        const y = rect.top + window.scrollY - toolbar.offsetHeight - 12;

        toolbar.style.left = `${Math.max(10, x)}px`;
        toolbar.style.top = `${y}px`;
        
        // Animation subtile
        toolbar.style.opacity = '1';
        toolbar.style.transform = 'translateY(0) scale(1)';
    }

    hideFloatingToolbar() {
        if (this.hasFloatingToolbarTarget) {
            const toolbar = this.floatingToolbarTarget;
            toolbar.style.opacity = '0';
            toolbar.style.transform = 'translateY(4px) scale(0.95)';
            setTimeout(() => {
                if (toolbar.style.opacity === '0') {
                    toolbar.classList.add('hidden');
                }
            }, 150);
        }
    }

    /**
     * Gestion des raccourcis clavier.
     */
    handleShortcuts(event) {
        if (event.ctrlKey && event.shiftKey) {
            const code = event.key.toUpperCase();
            if (['R', 'F', 'E', 'D', 'A', 'P', 'C', 'T', 'W'].includes(code)) {
                event.preventDefault();
                const selection = window.getSelection().toString().trim();
                if (selection.length > 0) {
                    this.currentSelection = selection;
                }

                if (!this.currentSelection) {
                    alert("Veuillez d'abord sélectionner du texte dans l'éditeur.");
                    return;
                }

                switch (code) {
                    case 'R': this.triggerAction('reasoning'); break;
                    case 'F': this.triggerAction('reformulate'); break;
                    case 'E': this.triggerAction('equation'); break;
                    case 'D': this.triggerAction('expand'); break;
                    case 'A': this.triggerAskPrompt(); break;
                    case 'P': this.triggerAction('redundancy'); break;
                    case 'C': this.triggerAction('code'); break;
                    case 'T': this.triggerTranslatePrompt(); break;
                    case 'W': this.triggerAction('peer_review'); break;
                }
            }
        }
    }

    /**
     * Actions directes de la barre d'outils ou des raccourcis.
     */
    runReasoning() { this.triggerAction('reasoning'); }
    runReformulate() { this.triggerAction('reformulate'); }
    runEquation() { this.triggerAction('equation'); }
    runExpand() { this.triggerAction('expand'); }
    runRedundancy() { this.triggerAction('redundancy'); }
    runPeerReview() { this.triggerAction('peer_review'); }
    runExplain() { this.triggerAction('explain'); }

    triggerAskPrompt() {
        this.openModal("Demander à l'IA", "Rédigez votre question ou instruction pour le texte sélectionné :");
        this.askFormContainerTarget.classList.remove('hidden');
        this.translateContainerTarget.classList.add('hidden');
        this.toneContainerTarget.classList.add('hidden');
        this.modalContentTarget.innerHTML = '';
        setTimeout(() => this.askInputTarget.focus(), 100);
    }

    confirmAsk() {
        const question = this.askInputTarget.value.trim();
        if (!question) return;
        this.askFormContainerTarget.classList.add('hidden');
        this.triggerAction('ask', { question });
    }

    triggerTranslatePrompt() {
        this.openModal("Traduction Académique", "Choisissez la langue cible pour la traduction de votre paragraphe scientifique :");
        this.askFormContainerTarget.classList.add('hidden');
        this.translateContainerTarget.classList.remove('hidden');
        this.toneContainerTarget.classList.add('hidden');
        this.modalContentTarget.innerHTML = '';
    }

    confirmTranslate() {
        const targetLanguage = this.translateSelectTarget.value;
        this.translateContainerTarget.classList.add('hidden');
        this.triggerAction('translate', { target_language: targetLanguage });
    }

    triggerTonePrompt() {
        this.openModal("Ajuster le Registre Académique", "Sélectionnez le registre stylistique souhaité :");
        this.askFormContainerTarget.classList.add('hidden');
        this.translateContainerTarget.classList.add('hidden');
        this.toneContainerTarget.classList.remove('hidden');
        this.modalContentTarget.innerHTML = '';
    }

    confirmTone() {
        const register = this.toneSelectTarget.value;
        this.toneContainerTarget.classList.add('hidden');
        this.triggerAction('tone', { register });
    }

    /**
     * Déclenche une action IA (soit par execute standard soit par streaming SSE).
     */
    async triggerAction(action, options = {}) {
        this.hideFloatingToolbar();
        
        let title = "Assistant IA";
        switch (action) {
            case 'reasoning': title = "Vérification du Raisonnement"; break;
            case 'reformulate': title = "Reformulations proposées"; break;
            case 'equation': title = "Correction d'Équation LaTeX"; break;
            case 'expand': title = "Développement d'Idées"; break;
            case 'ask': title = "Réponse de l'Assistant"; break;
            case 'redundancy': title = "Détection de Redondances"; break;
            case 'code': title = "Génération de Code"; break;
            case 'peer_review': title = "Rapport de Peer Review"; break;
            case 'translate': title = "Traduction Académique"; break;
            case 'tone': title = "Ajustement du Registre"; break;
            case 'explain': title = "Glossaire & Concepts"; break;
        }

        this.openModal(title, "L'assistant IA de Djoliba analyse votre texte...");
        this.modalContentTarget.innerHTML = `
            <div class="flex items-center gap-2 text-slate-400 italic">
                <svg class="animate-spin h-4 w-4 text-djoliba" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Génération de la réponse par l'IA...
            </div>
        `;

        // Actions supportant le streaming SSE
        const streamingActions = ['reformulate', 'expand', 'ask', 'code', 'peer_review', 'translate', 'tone'];

        if (streamingActions.includes(action)) {
            this.runStreamingAction(action, options);
        } else {
            this.runExecuteAction(action, options);
        }
    }

    /**
     * Exécute une action non-streaming standard via POST.
     */
    async runExecuteAction(action, options) {
        try {
            const response = await fetch(`/api/projects/${this.projectId}/editor-ai/execute`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, text: this.currentSelection, options })
            });

            const res = await response.json();
            if (response.ok && res.success) {
                this.currentInteractionId = res.data.interaction_id;
                this.renderResponse(res.data.result, action);
                this.loadHistory();
            } else {
                this.modalContentTarget.innerHTML = `<span class="text-red-500 font-semibold">Erreur : ${res.error?.message || 'Une erreur est survenue.'}</span>`;
            }
        } catch (err) {
            console.error(err);
            this.modalContentTarget.innerHTML = `<span class="text-red-500 font-semibold">Erreur de connexion avec l'IA.</span>`;
        }
    }

    /**
     * Exécute une action en streaming SSE.
     */
    runStreamingAction(action, options) {
        this.modalContentTarget.innerHTML = '';
        let fullText = '';
        
        // Pour les requêtes avec body POST en SSE, on utilise fetch et lit le body stream
        fetch(`/api/projects/${this.projectId}/editor-ai/stream`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, text: this.currentSelection, options })
        }).then(response => {
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            const read = () => {
                reader.read().then(({ done, value }) => {
                    if (done) {
                        this.loadHistory();
                        return;
                    }

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n\n');
                    buffer = lines.pop(); // Conserver le reliquat

                    for (const line of lines) {
                        if (line.trim().startsWith('data: ')) {
                            const dataStr = line.trim().substring(6);
                            if (dataStr === '[DONE]') {
                                continue;
                            }

                            try {
                                const data = JSON.parse(dataStr);
                                if (data.chunk !== undefined) {
                                    fullText += data.chunk;
                                    this.renderResponse(fullText, action);
                                }
                                if (data.interaction_id !== undefined) {
                                    this.currentInteractionId = data.interaction_id;
                                }
                                if (data.error !== undefined) {
                                    this.modalContentTarget.innerHTML = `<span class="text-red-500 font-semibold">Erreur : ${data.error}</span>`;
                                }
                            } catch (e) {
                                // Ignorer les erreurs de parsing partielles
                            }
                        }
                    }
                    read();
                });
            };
            read();
        }).catch(err => {
            console.error(err);
            this.modalContentTarget.innerHTML = `<span class="text-red-500 font-semibold">Erreur de connexion SSE.</span>`;
        });
    }

    renderResponse(text, action) {
        // Formater proprement la réponse (Markdown simple ou JSON pour références)
        if (action === 'reference') {
            try {
                const refs = JSON.parse(text);
                let html = '<div class="space-y-3 font-sans">';
                if (refs.length === 0) {
                    html += '<p class="text-slate-500 italic">Aucune référence académique trouvée.</p>';
                } else {
                    refs.forEach((ref, idx) => {
                        html += `
                            <div class="p-4 bg-slate-50 border border-slate-100 rounded-xl">
                                <h4 class="text-xs font-bold text-djoliba">${ref.title}</h4>
                                <p class="text-[10px] text-slate-500 mt-1">${ref.authors || 'Auteurs inconnus'} (${ref.year || 'Année inconnue'}) — <i>${ref.journal || 'Journal inconnu'}</i></p>
                                ${ref.doi ? `<p class="text-[9px] text-emerald-600 font-mono mt-1">DOI: ${ref.doi}</p>` : ''}
                                ${ref.url ? `<a href="${ref.url}" target="_blank" class="text-[9px] text-blue-500 hover:underline mt-1 block">Accéder à la source</a>` : ''}
                                <button type="button" class="mt-2 text-[10px] font-bold text-djoliba hover:underline" onclick="document.dispatchEvent(new CustomEvent('editor-ai:insert-text', {detail: {text: '\\\\cite{${ref.doi || ref.title.substring(0, 15)}}'}}))">
                                    Insérer la citation LaTeX
                                </button>
                            </div>
                        `;
                    });
                }
                html += '</div>';
                this.modalContentTarget.innerHTML = html;
                return;
            } catch (e) {
                // Fallback sur rendu texte
            }
        }

        // Rendu texte simple (LaTeX ou Markdown) avec remplacement des retours à la ligne
        const formatted = this.#escapeHtml(text)
            .replace(/\n/g, '<br>')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        this.modalContentTarget.innerHTML = `<div class="text-xs font-serif leading-relaxed text-slate-700">${formatted}</div>`;
    }

    #escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    /**
     * Applique la suggestion en remplaçant le texte sélectionné.
     */
    applyReplacement() {
        const editor = this.getEditor();
        const contentDiv = this.modalContentTarget.querySelector('div');
        if (!editor || !contentDiv) return;

        // Récupérer le texte propre
        const text = contentDiv.innerText || contentDiv.textContent;
        editor.chain().focus().insertContent(text).run();
        
        this.acceptInteraction();
    }

    /**
     * Applique la suggestion en l'insérant après la sélection courante.
     */
    applyInsertAfter() {
        const editor = this.getEditor();
        const contentDiv = this.modalContentTarget.querySelector('div');
        if (!editor || !contentDiv) return;

        const text = contentDiv.innerText || contentDiv.textContent;
        // Insère après la sélection
        editor.chain().focus().collapseToEnd().insertContent("\n" + text).run();
        
        this.acceptInteraction();
    }

    /**
     * Marque l'interaction comme acceptée.
     */
    async acceptInteraction() {
        if (this.currentInteractionId) {
            await this.updateInteractionStatus(this.currentInteractionId, true);
        }
        this.closeModal();
    }

    /**
     * Marque l'interaction comme rejetée.
     */
    async rejectInteraction() {
        if (this.currentInteractionId) {
            await this.updateInteractionStatus(this.currentInteractionId, false);
        }
        this.closeModal();
    }

    async updateInteractionStatus(interactionId, accepted) {
        try {
            await fetch(`/api/editor-ai/interaction/${interactionId}/status`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ accepted })
            });
            this.loadHistory();
        } catch (err) {
            console.error("Erreur de mise à jour du statut :", err);
        }
    }

    /**
     * Charge l'historique d'interactions et met à jour la liste.
     */
    async loadHistory() {
        if (!this.hasHistoryListTarget) return;

        try {
            const response = await fetch(`/api/projects/${this.projectId}/editor-ai/history`);
            const res = await response.json();

            if (response.ok && res.success) {
                const history = res.data;
                if (history.length === 0) {
                    this.historyContainerTarget.classList.add('hidden');
                    return;
                }

                this.historyContainerTarget.classList.remove('hidden');
                this.historyListTarget.innerHTML = '';

                history.forEach(item => {
                    const date = new Date(item.createdAt).toLocaleDateString('fr-FR', { hour: '2-digit', minute: '2-digit' });
                    const statusText = item.accepted === true ? 'Acceptée' : (item.accepted === false ? 'Ignorée' : 'En attente');
                    const statusClass = item.accepted === true ? 'text-emerald-600 bg-emerald-50' : (item.accepted === false ? 'text-red-500 bg-red-50' : 'text-slate-400 bg-slate-50');

                    const li = document.createElement('li');
                    li.className = 'py-3.5 px-4 bg-slate-50/50 rounded-2xl border border-slate-100 flex items-start justify-between gap-4 text-xs';
                    li.innerHTML = `
                        <div class="flex-grow min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-djoliba capitalize">${this.translateActionName(item.action)}</span>
                                <span class="text-[10px] text-slate-400">${date}</span>
                            </div>
                            <p class="text-[11px] text-slate-500 truncate mt-1">Sél : "${item.selectedText || ''}"</p>
                        </div>
                        <span class="px-2 py-0.5 rounded-lg font-bold text-[9px] ${statusClass} flex-shrink-0 align-self-start">${statusText}</span>
                    `;
                    this.historyListTarget.appendChild(li);
                });
            }
        } catch (err) {
            console.error("Erreur de chargement de l'historique :", err);
        }
    }

    translateActionName(action) {
        switch (action) {
            case 'reasoning': return "Raisonnement";
            case 'reformulate': return "Reformulation";
            case 'equation': return "Équation";
            case 'expand': return "Développement";
            case 'ask': return "Question libre";
            case 'redundancy': return "Redondances";
            case 'code': return "Code";
            case 'peer_review': return "Peer Review";
            case 'translate': return "Traduction";
            case 'tone': return "Ajustement ton";
            case 'explain': return "Glossaire";
            default: return action;
        }
    }

    toggleHistory() {
        if (!this.hasHistoryListTarget || !this.hasToggleHistoryBtnTarget) return;

        const list = this.historyListTarget;
        const btn = this.toggleHistoryBtnTarget;

        if (list.classList.contains('hidden')) {
            list.classList.remove('hidden');
            btn.textContent = 'Masquer';
        } else {
            list.classList.add('hidden');
            btn.textContent = 'Afficher';
        }
    }

    /**
     * Modales
     */
    openModal(title, defaultText = "") {
        if (!this.hasAiModalTarget) return;

        this.modalTitleTarget.textContent = title;
        this.modalContentTarget.innerHTML = defaultText;

        // Cacher les sections spécifiques par défaut
        this.askFormContainerTarget.classList.add('hidden');
        this.translateContainerTarget.classList.add('hidden');
        this.toneContainerTarget.classList.add('hidden');

        const modal = this.aiModalTarget;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');

        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.classList.add('opacity-100');
            const card = modal.querySelector('.modal-card');
            if (card) {
                card.classList.remove('scale-95', 'opacity-0');
                card.classList.add('scale-100', 'opacity-100');
            }
        }, 50);
    }

    closeModal() {
        if (!this.hasAiModalTarget) return;

        const modal = this.aiModalTarget;
        const card = modal.querySelector('.modal-card');
        
        if (card) {
            card.classList.remove('scale-100', 'opacity-100');
            card.classList.add('scale-95', 'opacity-0');
        }

        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');

        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 300);
    }
}
