import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus Controller — reading-chat
 *
 * Gère le chat en streaming SSE avec l'API Djoliba.
 * Utilise l'API fetch + ReadableStream pour un affichage progressif des réponses IA.
 *
 * Usage HTML :
 * <div data-controller="reading-chat"
 *      data-reading-chat-document-id-value="42"
 *      data-reading-chat-url-value="/api/stream">
 *
 *   <div data-reading-chat-target="messages"></div>
 *   <div data-reading-chat-target="response" class="ai-response"></div>
 *   <div data-reading-chat-target="status"></div>
 *
 *   <form data-action="submit->reading-chat#sendQuestion">
 *     <input type="text" data-reading-chat-target="input" placeholder="Posez votre question...">
 *     <button type="submit" data-reading-chat-target="submit">Envoyer</button>
 *   </form>
 * </div>
 */
export default class extends Controller {
    #currentResponseText = '';

    static targets = [
        'input', 'messages', 'response', 'status', 'submit', 
        'progressBar', 'progressContainer', 'fileInput',
        'emptyState', 'loader', 'pointsList', 'docName', 'synthesisContainer'
    ];

    static values  = {
        documentId: Number,   // ID du document ciblé (optionnel, 0 = tout le projet)
        projectId:  Number,   // ID du projet courant
        url:        { type: String, default: '/api/stream' },  // Endpoint SSE
    };

    connect() {
        this.abortController = null;
        this.#log('reading-chat connecté');
        
        // Initialiser la taille de police depuis le localStorage ou 12px par défaut
        this.currentFontSize = parseInt(localStorage.getItem('djoliba_chat_font_size')) || 12;
        this.#updateFontSize();

        // Charger l'historique des messages existants au chargement de la page
        this.loadHistory();

        // Lancer la synthèse automatiquement si le document est pré-rempli (uploadé depuis le hub)
        if (this.documentIdValue > 0) {
            this.fetchSynthesis(this.documentIdValue);
        }
    }

    decreaseFontSize() {
        if (!this.currentFontSize) {
            this.currentFontSize = 12;
        }
        if (this.currentFontSize > 9) {
            this.currentFontSize -= 1;
            this.#updateFontSize();
        }
    }

    increaseFontSize() {
        if (!this.currentFontSize) {
            this.currentFontSize = 12;
        }
        if (this.currentFontSize < 24) {
            this.currentFontSize += 1;
            this.#updateFontSize();
        }
    }

    #updateFontSize() {
        if (this.hasMessagesTarget) {
            this.messagesTarget.style.setProperty('--chat-font-size', `${this.currentFontSize}px`);
            localStorage.setItem('djoliba_chat_font_size', this.currentFontSize);
        }
    }

    disconnect() {
        this.#cancelStream();
    }

    /**
     * Charge l'historique de chat du projet
     */
    async loadHistory() {
        if (!this.projectIdValue) return;

        try {
            const response = await fetch(`/api/reading/project/${this.projectIdValue}/history`);
            const result = await response.json();

            if (result.success && result.data.history) {
                // S'il y a un historique, on vide d'abord le message d'accueil par défaut
                if (result.data.history.length > 0 && this.hasMessagesTarget) {
                    this.messagesTarget.innerHTML = '';
                    
                    let skipNextAi = false;
                    result.data.history.forEach(msg => {
                        // Filtre les commandes internes de type [synthesize] et leurs réponses IA associées (JSON)
                        if (msg.role === 'user') {
                            if (msg.content.startsWith('[synthesize]')) {
                                skipNextAi = true;
                                return;
                            }
                            skipNextAi = false;
                        } else if (msg.role === 'ai' || msg.role === 'assistant') {
                            if (skipNextAi) {
                                skipNextAi = false;
                                return;
                            }
                        }
                        
                        this.#appendMessage(msg.role, msg.content, msg.time);
                    });
                }
            }
        } catch (error) {
            console.error('Erreur lors du chargement de l\'historique:', error);
        }
    }

    /**
     * Déclenche l'explorateur de fichiers
     */
    triggerSelect() {
        this.fileInputTarget.click();
    }

    /**
     * Gère le survol lors d'un drag over
     */
    onDragOver(event) {
        event.preventDefault();
        event.currentTarget.classList.add('border-djoliba', 'bg-djoliba/5');
    }

    /**
     * Gère la sortie du survol drag leave
     */
    onDragLeave(event) {
        event.preventDefault();
        event.currentTarget.classList.remove('border-djoliba', 'bg-djoliba/5');
    }

    /**
     * Gère le dépôt du fichier (drop)
     */
    onDrop(event) {
        event.preventDefault();
        event.currentTarget.classList.remove('border-djoliba', 'bg-djoliba/5');

        const files = event.dataTransfer.files;
        if (files.length > 0) {
            this.uploadFile(files[0]);
        }
    }

    /**
     * Gère la sélection manuelle via explorateur
     */
    onFileSelect(event) {
        const file = event.target.files[0];
        if (file) {
            this.uploadFile(file);
        }
    }

    /**
     * Valide et téléverse un fichier PDF
     * Taille max : 25 Mo (25 * 1024 * 1024 octets)
     */
    uploadFile(file) {
        const maxSize = 25 * 1024 * 1024; // 25 Mo

        // Validations robustes (type MIME ou extension .pdf)
        const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
        if (!isPdf) {
            this.showToast('Seuls les fichiers PDF sont acceptés.', 'error');
            return;
        }

        if (file.size > maxSize) {
            this.showToast('Le fichier dépasse la taille maximale autorisée de 25 Mo.', 'error');
            return;
        }

        // UI Reset pour synthèse
        if (this.hasDocNameTarget) this.docNameTarget.textContent = file.name;
        if (this.hasEmptyStateTarget) this.emptyStateTarget.classList.add('hidden');
        if (this.hasPointsListTarget) this.pointsListTarget.classList.add('hidden');
        if (this.hasLoaderTarget) this.loaderTarget.classList.add('hidden');

        // Afficher la barre de progression si elle existe
        if (this.hasProgressContainerTarget) {
            this.progressContainerTarget.classList.remove('hidden');
        }

        const formData = new FormData();
        formData.append('file', file);
        formData.append('project_id', this.projectIdValue);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/reading/upload', true);

        // Suivi de progression
        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable && this.hasProgressBarTarget) {
                const percent = (event.loaded / event.total) * 100;
                this.progressBarTarget.style.width = `${percent}%`;
            }
        };

        xhr.onload = async () => {
            if (xhr.status === 201 || xhr.status === 200) {
                const res = JSON.parse(xhr.responseText);
                
                if (res.data.redirect_url) {
                    this.showToast('Nouveau sous-projet créé. Redirection...', 'success');
                    setTimeout(() => {
                        window.location.href = res.data.redirect_url;
                    }, 800);
                } else {
                    const docId = res.data.document_id;
                    this.documentIdValue = docId;
                    this.showToast('Document uploadé. Génération de la synthèse...', 'success');
                    // Lancer la synthèse
                    await this.fetchSynthesis(docId);
                }
            } else if (xhr.status === 409) {
                this.#resetUploadProgress();
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
                let errorMsg = 'Erreur lors du téléversement.';
                try {
                    const res = JSON.parse(xhr.responseText);
                    errorMsg = res.error?.message || errorMsg;
                } catch (e) {}
                this.showToast(errorMsg, 'error');
                this.#resetUploadProgress();
            }
        };

        xhr.onerror = () => {
            this.showToast('Erreur réseau lors du téléversement.', 'error');
            this.#resetUploadProgress();
        };

        xhr.send(formData);
    }

    /**
     * Appelle l'API de synthèse et affiche les points clés
     */
    async fetchSynthesis(documentId) {
        if (this.hasLoaderTarget) this.loaderTarget.classList.remove('hidden');
        if (this.hasProgressContainerTarget) this.progressContainerTarget.classList.add('hidden');

        try {
            const response = await fetch(`/api/reading/${documentId}/synthesize`);
            const result = await response.json();

            if (result.success) {
                this.renderSynthesis(result.data.points);
            } else {
                this.showToast('Échec de la synthèse IA.', 'error');
                if (this.hasEmptyStateTarget) this.emptyStateTarget.classList.remove('hidden');
            }
        } catch (error) {
            console.error('Erreur synthèse:', error);
            this.showToast('Erreur de communication avec l\'IA.', 'error');
        } finally {
            if (this.hasLoaderTarget) this.loaderTarget.classList.add('hidden');
        }
    }

    renderSynthesis(points) {
        if (!this.hasPointsListTarget) return;

        this.pointsListTarget.innerHTML = points.map((p, index) => `
            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 hover:border-djoliba/20 transition-all group">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-5 h-5 rounded-full bg-djoliba text-white flex items-center justify-center text-[10px] font-bold">${index + 1}</span>
                    <h4 class="text-xs font-bold text-djoliba">${this.parseMarkdown(p.point)}</h4>
                </div>
                <div class="text-[11px] text-slate-600 leading-relaxed">${this.parseMarkdown(p.explication)}</div>
            </div>
        `).join('');

        this.pointsListTarget.classList.remove('hidden');
        this.renderMath(this.pointsListTarget);
    }

    #resetUploadProgress() {
        if (this.hasProgressContainerTarget) {
            this.progressContainerTarget.classList.add('hidden');
        }
        if (this.hasProgressBarTarget) {
            this.progressBarTarget.style.width = '0%';
        }
    }

    showToast(message, type = 'success') {
        window.dispatchEvent(new CustomEvent('toast:show', {
            detail: { message, type }
        }));
    }

    /**
     * Handler du formulaire de question (Chat).
     * Déclenché par : data-action="submit->reading-chat#sendMessage"
     */
    async sendMessage(event) {
        event.preventDefault();

        const question = this.inputTarget.value.trim();
        if (!question) return;

        // Afficher la question de l'utilisateur dans l'historique (alternance user)
        this.#appendMessage('user', question);
        
        // Efface le champ de saisie après envoi
        this.inputTarget.value = '';

        // Préparer la zone de réponse IA (alternance assistant/ai)
        this.#prepareResponseArea();
        this.#setStatus('En cours de génération…');
        this.#setSubmitDisabled(true);

        // Annuler un éventuel stream précédent
        this.#cancelStream();
        this.abortController = new AbortController();

        try {
            await this.#streamResponse(question);
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.#setStatus('Erreur de connexion. Veuillez réessayer.');
                console.error('[reading-chat] Erreur :', error);
            }
        } finally {
            this.#setSubmitDisabled(false);
        }
    }

    /**
     * Annule manuellement le stream en cours.
     * Déclenché par : data-action="click->reading-chat#cancelStream"
     */
    cancelStream() {
        this.#cancelStream();
        this.#setStatus('Génération annulée.');
        this.#setSubmitDisabled(false);
    }

    // ─────────────────────────────────────────────
    // Méthodes privées
    // ─────────────────────────────────────────────

    async #streamResponse(question) {
        const payload = {
            question:   question,
            project_id: this.projectIdValue,
        };

        // Si un document spécifique est ciblé, on utilise l'endpoint document-chat
        const url = (this.documentIdValue > 0)
            ? `/api/reading/${this.documentIdValue}/chat`
            : this.urlValue;

        // Pour /api/reading/{id}/chat : body JSON avec "question"
        const body = (this.documentIdValue > 0)
            ? JSON.stringify({ question })
            : JSON.stringify(payload);

        const response = await fetch(url, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json, text/event-stream',
            },
            body,
            signal: this.abortController.signal,
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data?.error?.message ?? `HTTP ${response.status}`);
        }

        const contentType = response.headers.get('content-type') || '';

        // Gestion de la réponse JSON standard (non streamée)
        if (contentType.includes('application/json')) {
            const data = await response.json();
            if (data.data?.response) {
                this.#appendChunk(data.data.response);
                this.#finalizeResponse();
            } else if (data.error) {
                this.#setStatus(`Erreur : ${data.error.message || data.error}`);
            }
            this.#setStatus('');
            return;
        }

        // Lecture du flux SSE ligne par ligne (stream)
        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop(); // conserver la ligne incomplète

            for (const line of lines) {
                this.#processSSELine(line.trim());
            }
        }

        // Traiter le reste du buffer
        if (buffer.trim()) {
            this.#processSSELine(buffer.trim());
        }

        this.renderMath(this.responseTarget);
        this.#setStatus('');
        this.#finalizeResponse();
    }

    #processSSELine(line) {
        if (!line.startsWith('data: ')) return;

        const jsonStr = line.slice(6);
        if (jsonStr === '[DONE]') {
            this.#finalizeResponse();
            return;
        }

        try {
            const data = JSON.parse(jsonStr);

            if (data.error) {
                this.#setStatus(`Erreur : ${data.error}`);
                return;
            }

            if (data.chunk) {
                this.#appendChunk(data.chunk);
            }

            // Réponse non-streamée (ex: /api/reading/{id}/chat retourne JSON complet)
            if (data.data?.response) {
                this.#appendChunk(data.data.response);
                this.#finalizeResponse();
            }
        } catch {
            // Ligne SSE non-JSON : ignorer silencieusement
        }
    }

    #appendMessage(role, text, timeStr = null) {
        if (!this.hasMessagesTarget) return;

        const bubble = document.createElement('div');
        bubble.classList.add('message', `message--${role}`);
        
        // Utiliser le parseur Markdown premium avec KaTeX
        bubble.innerHTML = this.parseMarkdown(text);

        const timestamp = document.createElement('span');
        timestamp.classList.add('message__time');
        timestamp.textContent = timeStr || new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        bubble.appendChild(timestamp);

        this.messagesTarget.appendChild(bubble);
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
        this.renderMath(bubble);
    }

    #prepareResponseArea() {
        if (!this.hasResponseTarget) return;
        this.responseTarget.innerHTML = '';
        this.responseTarget.classList.remove('hidden');
        this.responseTarget.classList.add('message', 'message--ai', 'message--streaming');
        this.#currentResponseText = '';
    }

    #appendChunk(chunk) {
        if (!this.hasResponseTarget) return;
        this.#currentResponseText = (this.#currentResponseText ?? '') + chunk;
        
        // Utiliser le parseur Markdown premium avec KaTeX
        this.responseTarget.innerHTML = this.parseMarkdown(this.#currentResponseText);
        this.messagesTarget?.scrollTo({ top: this.messagesTarget.scrollHeight, behavior: 'smooth' });
        this.renderMathThrottled(this.responseTarget);
    }

    #finalizeResponse() {
        if (!this.hasResponseTarget || !this.#currentResponseText) return;

        // Déplacer la bulle de réponse dans l'historique
        const finalBubble = this.responseTarget.cloneNode(true);
        finalBubble.classList.remove('message--streaming', 'hidden');
        this.messagesTarget?.appendChild(finalBubble);

        this.responseTarget.innerHTML = '';
        this.responseTarget.classList.remove('message--streaming');
        this.responseTarget.classList.add('hidden');
        this.#currentResponseText = '';
        this.renderMath(finalBubble);
    }

    parseMarkdown(text) {
        if (!text) return '';

        const mathBlocks = [];
        let html = text;

        // 1. Extraire les blocs d'équations ($$ ... $$ et \[ ... \])
        html = html.replace(/\$\$([\s\S]*?)\$\$/g, (match, equation) => {
            const placeholder = `__MATH_BLOCK_${mathBlocks.length}__`;
            mathBlocks.push({ placeholder, equation, display: true });
            return placeholder;
        });
        html = html.replace(/\\\[([\s\S]*?)\\\]/g, (match, equation) => {
            const placeholder = `__MATH_BLOCK_${mathBlocks.length}__`;
            mathBlocks.push({ placeholder, equation, display: true });
            return placeholder;
        });

        // 2. Extraire les équations en ligne ($ ... $ et \( ... \))
        // Gère les cas à un seul caractère ($x$) et les expressions multi-caractères
        html = html.replace(/\$([^\s$][^\n$]*?[^\s$]|[^\s$])\$/g, (match, equation) => {
            const placeholder = `__MATH_INLINE_${mathBlocks.length}__`;
            mathBlocks.push({ placeholder, equation, display: false });
            return placeholder;
        });
        html = html.replace(/\\\(([\s\S]*?)\\\)/g, (match, equation) => {
            const placeholder = `__MATH_INLINE_${mathBlocks.length}__`;
            mathBlocks.push({ placeholder, equation, display: false });
            return placeholder;
        });

        // 3. Traiter le Markdown standard
        // Échapper les balises HTML basiques pour éviter les injections XSS
        html = html
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // Remplacer les titres ###
        html = html.replace(/^###\s+(.+)$/gm, '<h3 class="text-lg font-bold text-djoliba mt-6 mb-3 flex items-center gap-2">$1</h3>');

        // Remplacer les titres ####
        html = html.replace(/^####\s+(.+)$/gm, '<h4 class="text-sm font-bold text-djoliba/80 mt-4 mb-2">$1</h4>');

        // Remplacer les listes non ordonnées (- élément)
        html = html.replace(/^-\s+(.+)$/gm, '<li class="text-xs text-slate-600 ml-4">$1</li>');

        // Remplacer les gras **texte**
        html = html.replace(/\*\*(.*?)\*\*/g, '<strong class="font-bold text-djoliba">$1</strong>');

        // Remplacer les règles horizontales ---
        html = html.replace(/^---$/gm, '<hr class="my-4 border-slate-100">');

        // Gérer les paragraphes et les retours à la ligne
        const blocks = html.split(/\n\n+/);
        const formattedBlocks = blocks.map(block => {
            const trimmed = block.trim();
            if (!trimmed) return '';

            // Si c'est déjà un titre, une liste, une règle ou un bloc math, ne pas encapsuler dans <p>
            if (trimmed.startsWith('<h') || trimmed.startsWith('<li') || trimmed.startsWith('<hr') || trimmed.startsWith('__MATH_BLOCK_')) {
                if (trimmed.startsWith('<li')) {
                    return `<ul class="list-disc pl-5 my-2 space-y-1">${trimmed}</ul>`;
                }
                return trimmed;
            }

            const withBr = trimmed.replace(/\n/g, '<br>');
            return `<p class="text-xs text-slate-600 leading-relaxed mb-4">${withBr}</p>`;
        });

        html = formattedBlocks.join('\n');

        // 4. Restaurer et compiler les équations en HTML direct via KaTeX (split/join évite les interpolations de $)
        mathBlocks.forEach(({ placeholder, equation, display }) => {
            if (window.katex && window.katex.renderToString) {
                try {
                    const rendered = window.katex.renderToString(equation.trim(), {
                        displayMode: display,
                        throwOnError: false
                    });
                    html = html.split(placeholder).join(rendered);
                } catch (e) {
                    console.warn("KaTeX renderToString error:", e);
                    const delimiter = display ? '$$' : '$';
                    html = html.split(placeholder).join(delimiter + equation + delimiter);
                }
            } else {
                const delimiter = display ? '$$' : '$';
                html = html.split(placeholder).join(delimiter + equation + delimiter);
            }
        });

        return html;
    }

    renderMath(target = null) {
        const targetElement = target || this.element;
        const doRender = () => {
            try {
                if (window.renderMathInElement) {
                    window.renderMathInElement(targetElement, {
                        delimiters: [
                            { left: "$$", right: "$$", display: true },
                            { left: "$", right: "$", display: false },
                            { left: "\\(", right: "\\)", display: false },
                            { left: "\\[", right: "\\]", display: true }
                        ],
                        throwOnError: false
                    });
                }
            } catch (e) {
                console.warn("KaTeX render error:", e);
            }
        };

        if (window.renderMathInElement) {
            doRender();
        } else {
            // Éviter de lancer plusieurs intervalles en parallèle
            if (this.waitingForKatex) {
                this.pendingMathTarget = targetElement;
                return;
            }
            this.waitingForKatex = true;
            this.pendingMathTarget = targetElement;

            const poll = setInterval(() => {
                if (window.renderMathInElement) {
                    clearInterval(poll);
                    this.waitingForKatex = false;
                    if (this.pendingMathTarget) {
                        try {
                            window.renderMathInElement(this.pendingMathTarget, {
                                delimiters: [
                                    { left: "$$", right: "$$", display: true },
                                    { left: "$", right: "$", display: false },
                                    { left: "\\(", right: "\\)", display: false },
                                    { left: "\\[", right: "\\]", display: true }
                                ],
                                throwOnError: false
                            });
                        } catch (e) {
                            console.warn("KaTeX render error on pending target:", e);
                        }
                        this.pendingMathTarget = null;
                    }
                }
            }, 50);

            // Sécurité : abandon après 5s
            setTimeout(() => {
                if (this.waitingForKatex) {
                    clearInterval(poll);
                    this.waitingForKatex = false;
                    this.pendingMathTarget = null;
                }
            }, 5000);
        }
    }

    renderMathThrottled(target = null) {
        const now = Date.now();
        const limit = 1000; // Délai de 1s min entre rendus complets pendant le streaming

        if (!this.lastMathRenderTime || (now - this.lastMathRenderTime >= limit)) {
            this.renderMath(target);
            this.lastMathRenderTime = now;
        } else {
            if (this.mathRenderTimeout) {
                clearTimeout(this.mathRenderTimeout);
            }
            this.mathRenderTimeout = setTimeout(() => {
                this.renderMath(target);
                this.lastMathRenderTime = Date.now();
            }, limit);
        }
    }

    #cancelStream() {
        if (this.abortController) {
            this.abortController.abort();
            this.abortController = null;
        }
    }

    #setStatus(text) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = text;
        }
    }

    #setSubmitDisabled(disabled) {
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = disabled;
        }
        if (this.hasInputTarget) {
            this.inputTarget.disabled = disabled;
        }
    }

    #log(msg) {
        if (import.meta.env?.DEV) {
            console.debug(`[reading-chat] ${msg}`);
        }
    }
}
