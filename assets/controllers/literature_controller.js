import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'output', 'status', 'suggestions', 'fontSizeIndicator']
    static values = { projectId: Number, autostart: String, lastQuery: String }

    connect() {
        this.isSearching = false;
        this.fontSizeLevel = 100;
        this.updateFontSize();

        // Réinitialiser le défilement de la page pour éviter que le haut ne soit masqué
        window.scrollTo(0, 0);
        document.documentElement.scrollTop = 0;
        document.body.scrollTop = 0;

        // Si session précédente restaurée (lastQuery présent)
        if (this.hasLastQueryValue && this.lastQueryValue.trim()) {
            // Parser le contenu initial (Markdown brut mixé avec HTML)
            const initialContent = this.outputTarget.innerHTML;
            if (initialContent.trim()) {
                // Remplacer les entités d'échappement qui auraient pu être insérées par Twig pour &
                const cleanContent = initialContent.replace(/&amp;/g, '&');
                this.outputTarget.innerHTML = this.parseMarkdown(cleanContent);
            }
            // Charger les suggestions pour la thématique précédente
            this.loadSuggestions(this.lastQueryValue);
            // Re-render les équations sur le HTML restauré
            this.renderMath();
        } else if (this.autostartValue === 'true' && this.hasInputTarget && this.inputTarget.value.trim()) {
            setTimeout(() => this.onSearch(), 150);
        } else {
            // Afficher le texte de bienvenue formaté en Markdown
            this.renderDefaultText();
        }
    }

    renderDefaultText() {
        const defaultMarkdown = `### ✨ Analyse Synthétique Djoliba

Bienvenue dans votre espace de revue de littérature assistée par IA. Cette interface prend en charge le **Markdown** et les équations mathématiques.

#### Équation de démonstration
La loi de conservation de l'énergie en mécanique quantique s'exprime ainsi :

$$\\hat{H}\\psi = i\\hbar\\frac{\\partial\\psi}{\\partial t}$$

Ou, en version intégrale : $\\int_{-\\infty}^{+\\infty} |\\psi(x)|^2 \\, dx = 1$

#### Comment démarrer
- Saisissez un **sujet de recherche** dans la barre ci-dessus
- Cliquez sur **Analyser** pour générer une revue complète`;

        this.outputTarget.innerHTML = this.parseMarkdown(defaultMarkdown);
        this.renderMath();
    }

    renderStructuredSynthesis(query) {
        const subject = query || 'votre sujet';

        const structuredMarkdown = `### 📚 Revue de Littérature : **${subject}**

#### 1. Fondements Théoriques et Conceptuels
La littérature académique contemporaine met en lumière des paradigmes critiques concernant **${subject}**. Les cadres théoriques initiaux se concentrent sur la modélisation systémique des processus et l'identification des leviers de changement. Les recherches classiques soulignent que la résilience opérationnelle et la cohérence stratégique sont indispensables pour surmonter les obstacles inhérents à ce domaine.

#### 2. Tendances Récentes et Avancées (2023–2026)
Les travaux publiés au cours des trois dernières années mettent l'accent sur l'intégration des technologies avancées, l'automatisation intelligente et l'impact des facteurs environnementaux. Les chercheurs s'orientent de plus en plus vers des approches interdisciplinaires combinant les sciences quantitatives et les retours d'expérience qualitatifs.

#### 3. Lacunes et Limites Identifiées
Malgré un corpus littéraire en pleine expansion, la recherche souffre d'un manque d'études longitudinales approfondies. De nombreuses conclusions reposent encore sur des analyses de cas isolés ou des modélisations à court terme.

#### 4. Pistes de Recherche Futures
Pour combler ces lacunes, les futures contributions scientifiques devront :
- Développer des cadres méthodologiques hybrides et réutilisables.
- Mener des validations empiriques transversales sur de plus larges cohortes.
- Modéliser les répercussions à long terme des facteurs exogènes.
#### Équation de démonstration
La loi de conservation de l'énergie en mécanique quantique s'exprime ainsi :

$$\\hat{H}\\psi = i\\hbar\\frac{\\partial\\psi}{\\partial t}$$

Ou, en version intégrale : $\\int_{-\\infty}^{+\\infty} |\\psi(x)|^2 \\, dx = 1$

#### Comment démarrer
---
*Cliquez sur **Analyser** pour générer une synthèse approfondie avec l'IA.*`;

        this.typewriterEffect(structuredMarkdown);
    }

    /**
     * Effet machine à écrire : révèle le Markdown progressivement, le parse en HTML à chaque étape
     * et appelle renderMath() en fin de séquence.
     */
    typewriterEffect(fullText) {
        // Annuler un éventuel effet précédent
        if (this._typewriterTimer) {
            clearInterval(this._typewriterTimer);
            this._typewriterTimer = null;
        }

        const cursor = '<span class="animate-pulse text-emerald_ia font-bold ml-0.5">|</span>';
        let position = 0;
        const charsPerTick = 4;   // Vitesse : caractères révélés par intervalle
        const intervalMs = 16;  // ~60 fps

        // Activer le point de statut (LED verte)
        this.statusTarget.classList.remove('hidden');

        this._typewriterTimer = setInterval(() => {
            position = Math.min(position + charsPerTick, fullText.length);
            const visible = fullText.slice(0, position);

            if (position < fullText.length) {
                this.outputTarget.innerHTML = this.parseMarkdown(visible) + cursor;
            } else {
                // Fin : rendu complet propre + math
                this.outputTarget.innerHTML = this.parseMarkdown(fullText);
                this.renderMath();
                this.statusTarget.classList.add('hidden');
                clearInterval(this._typewriterTimer);
                this._typewriterTimer = null;

                // Auto-scroll final
                const scrollContainer = this.outputTarget.parentElement;
                if (scrollContainer) scrollContainer.scrollTop = scrollContainer.scrollHeight;
            }

            // Scroll automatique pendant la frappe
            const scrollContainer = this.outputTarget.parentElement;
            if (scrollContainer) scrollContainer.scrollTop = scrollContainer.scrollHeight;

        }, intervalMs);
    }


    /**
     * Lance la recherche avec streaming SSE (via fetch + readable stream pour le POST)
     */
    async onSearch() {
        const query = this.inputTarget.value.trim();
        if (!query || this.isSearching) return;

        this.lastQueryValue = query;
        this.isSearching = true;
        this.toggleLoading(true);
        this.responseText = '';
        this.fullResponse = '';
        this.outputTarget.innerHTML = '';
        this.suggestionsTarget.innerHTML = this.renderSkeleton();

        try {
            // 1. Lancer la récupération des suggestions en arrière-plan
            this.loadSuggestions(query);

            // 2. Lancer le streaming de la revue
            const response = await fetch('/api/literature/review', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query: query, project_id: this.projectIdValue })
            });

            if (!response.ok) throw new Error('Erreur réseau');

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                // On traite les événements SSE du buffer
                const lines = buffer.split('\n\n');
                buffer = lines.pop(); // On garde le dernier morceau incomplet

                for (const line of lines) {
                    if (line.startsWith('data: ')) {
                        const data = line.slice(6).trim();
                        if (data === '[DONE]') break;

                        try {
                            const parsed = JSON.parse(data);
                            if (parsed.chunk) {
                                this.appendChunk(parsed.chunk);
                            } else if (parsed.enriched) {
                                // Réponse enrichie finale (HTML avec badges de références)
                                this.applyEnrichedResponse(parsed.enriched);
                            } else if (parsed.error) {
                                throw new Error(parsed.error);
                            }
                        } catch (e) {
                            console.error('Erreur parsing chunk:', e);
                        }
                    }
                }
            }

            // Fin du streaming
            this.finishStreaming();

        } catch (error) {
            console.error(error);
            this.outputTarget.innerHTML = `<span class="text-red-500">Erreur : ${error.message}</span>`;
        } finally {
            this.isSearching = false;
            this.toggleLoading(false);
        }
    }

    /**
     * Charge les suggestions d'articles
     */
    async loadSuggestions(query) {
        try {
            const response = await fetch('/api/literature/suggestions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query: query, limit: 5 })
            });

            const result = await response.json();
            if (result.success) {
                this.articlesList = result.data;
                this.renderSuggestions(result.data);
            } else {
                this.suggestionsTarget.innerHTML = '<p class="text-xs text-red-400">Erreur suggestions.</p>';
            }
        } catch (error) {
            console.error('Suggestions error:', error);
        }
    }

    appendChunk(chunk) {
        this.responseText += chunk;
        const cursorHtml = '<span class="animate-pulse text-emerald_ia font-bold ml-1">|</span>';

        // Rendu HTML formaté à partir du Markdown avec le curseur clignotant
        this.outputTarget.innerHTML = this.parseMarkdown(this.responseText) + cursorHtml;

        // Rendre les équations LaTeX via KaTeX (version throttlée pour éviter de surcharger le navigateur en cours de streaming)
        this.renderMathThrottled();

        // Scroll automatique ciblé sur le conteneur interne de texte
        const scrollContainer = this.outputTarget.parentElement;
        if (scrollContainer) {
            scrollContainer.scrollTop = scrollContainer.scrollHeight;
        }
    }

    /**
     * Applique la réponse enrichie (HTML avec badges de références) envoyée par le serveur.
     */
    applyEnrichedResponse(enrichedHtml) {
        this._enrichedApplied = true;
        if (!this.responseText) {
            this.isFromCache = true;
        }
        this.fullResponse = enrichedHtml;

        // Convertir le Markdown restant en HTML tout en protégeant les références enrichies
        this.outputTarget.innerHTML = this.parseMarkdown(enrichedHtml);
        // Re-render les équations qui pourraient encore être dans le HTML enrichi
        this.renderMath();
        // Scroll final
        const scrollContainer = this.outputTarget.parentElement;
        if (scrollContainer) scrollContainer.scrollTop = scrollContainer.scrollHeight;
    }

    async finishStreaming() {
        // Si aucun enrichissement n'est arrivé, on affiche le Markdown brut parsé
        if (!this._enrichedApplied) {
            this.outputTarget.innerHTML = this.parseMarkdown(this.responseText);
            this.renderMath();
            this.fullResponse = this.responseText;
        }
        this._enrichedApplied = false;

        if (this.isFromCache) {
            console.log("Servi par le cache Redis, pas de sauvegarde requise.");
            this.isFromCache = false;
            return;
        }
        this.isFromCache = false;

        console.log("Streaming terminé, sauvegarde en cours...");

        try {
            const query = this.inputTarget.value.trim();
            const response = await fetch('/api/interaction', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: this.projectIdValue,
                    type: 'literature_review',
                    user_prompt: query,
                    ai_response: this.fullResponse
                })
            });

            if (response.ok) {
                // Affiche la notification toast
                window.dispatchEvent(new CustomEvent('toast:show', {
                    detail: { message: 'Revue sauvegardée', type: 'success' }
                }));
            } else {
                console.warn("La sauvegarde serveur a échoué.");
            }
        } catch (e) {
            console.error("Erreur réseau lors de la sauvegarde :", e);
        }
    }

    renderSuggestions(articles) {
        if (!articles || articles.length === 0) {
            this.suggestionsTarget.innerHTML = '<p class="text-xs text-slate-400 text-center">Aucune source trouvée.</p>';
            return;
        }

        this.suggestionsTarget.innerHTML = articles.map((article, index) => {
            const isVerified = article.verified === true;
            const verifBadge = isVerified
                ? `<span class="cursor-help text-xs flex-shrink-0" title="Source vérifiée dans les bases de données">✅</span>`
                : `<span class="cursor-help text-xs flex-shrink-0" title="Non vérifié dans les bases de données">⚠️</span>`;

            const doiLink = article.doi
                ? `<a href="https://doi.org/${article.doi}" target="_blank" class="p-1.5 bg-slate-50 text-djoliba rounded-lg hover:bg-djoliba hover:text-white transition-colors" title="Ouvrir le DOI">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                   </a>`
                : (article.url ? `<a href="${article.url}" target="_blank" class="p-1.5 bg-slate-50 text-djoliba rounded-lg hover:bg-djoliba hover:text-white transition-colors" title="Ouvrir le lien">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                   </a>` : '');

            return `
            <div class="bg-white p-5 rounded-2xl border ${ isVerified ? 'border-emerald-100' : 'border-slate-100'} shadow-sm hover:border-djoliba/30 transition-all group flex flex-col">
                <div class="flex items-start justify-between mb-2 gap-2">
                    <h4 class="text-xs font-bold text-djoliba leading-snug flex-grow">${article.title}</h4>
                    ${verifBadge}
                </div>
                <div class="text-[10px] text-slate-500 mb-2">
                    <p class="truncate font-medium text-djoliba/70">${article.authors || 'Auteurs inconnus'}</p>
                    <p>${article.year || 'N/A'} • <span class="italic">${article.journal || 'Journal inconnu'}</span></p>
                </div>
                <p class="text-[10px] text-slate-600 mb-4 line-clamp-3 flex-grow">${article.abstract || 'Résumé non disponible pour cet article.'}</p>
                
                <div class="mt-auto space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-[9px] font-mono text-slate-400 truncate max-w-[120px]">${article.doi || (article.url ? 'Lien disponible' : 'Pas de DOI')}</span>
                        ${doiLink}
                    </div>
                    <button data-action="click->literature#addToProject" 
                            data-literature-index-param="${index}"
                            class="w-full py-2 bg-slate-50 hover:bg-djoliba text-djoliba hover:text-white text-[10px] font-bold rounded-lg transition-colors border border-slate-200 hover:border-djoliba flex justify-center items-center gap-1">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                        Ajouter au projet
                    </button>
                </div>
            </div>`;
        }).join('');
    }

    async addToProject(event) {
        const button = event.currentTarget;
        const index = event.params.index;
        const article = this.articlesList ? this.articlesList[index] : null;

        if (!article) {
            console.error("Article non trouvé à l'index:", index);
            return;
        }

        if (button.disabled) return;
        button.disabled = true;
        const originalHtml = button.innerHTML;
        button.innerHTML = '<span class="animate-pulse">...</span>';

        try {
            const response = await fetch(`/api/projects/${this.projectIdValue}/articles`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(article)
            });

            if (response.ok) {
                button.innerHTML = '<svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg> Ajouté';
                button.className = "w-full py-2 bg-emerald_ia/10 text-emerald_ia font-bold text-[10px] rounded-lg border border-emerald_ia/30 flex justify-center items-center gap-1 cursor-default";
            } else {
                button.disabled = false;
                button.innerHTML = originalHtml;
                alert("Erreur lors de l'ajout au projet.");
            }
        } catch (e) {
            console.error(e);
            button.disabled = false;
            button.innerHTML = originalHtml;
            alert("Erreur réseau. Impossible d'ajouter l'article.");
        }
    }

    renderSkeleton() {
        return Array(3).fill(0).map(() => `
            <div class="bg-slate-50 p-5 rounded-2xl border border-slate-100 animate-pulse">
                <div class="h-3 bg-slate-200 rounded w-3/4 mb-4"></div>
                <div class="h-2 bg-slate-200 rounded w-1/2 mb-2"></div>
                <div class="h-2 bg-slate-200 rounded w-1/3"></div>
            </div>
        `).join('');
    }

    parseMarkdown(text) {
        if (!text) return '';

        let html = text;

        // Protection des balises HTML span et a (utilisées pour les références enrichies)
        const htmlTags = [];
        html = html.replace(/<\/?(?:span|a)\b[^>]*>/gi, (match) => {
            const placeholder = `__HTML_TAG_${htmlTags.length}__`;
            htmlTags.push({ placeholder, tag: match });
            return placeholder;
        });

        const mathBlocks = [];

        // 1a. Extraire les blocs d'équations \[...\] (display mode)
        html = html.replace(/\\\[([\s\S]*?)\\\]/g, (match, equation) => {
            const placeholder = `__MATH_BLOCK_${mathBlocks.length}__`;
            mathBlocks.push({ placeholder, equation, display: true });
            return placeholder;
        });

        // 1b. Extraire les blocs d'équations ($$ ... $$)
        html = html.replace(/\$\$([\s\S]*?)\$\$/g, (match, equation) => {
            const placeholder = `__MATH_BLOCK_${mathBlocks.length}__`;
            mathBlocks.push({ placeholder, equation, display: true });
            return placeholder;
        });

        // 2a. Extraire les équations en ligne \(...\)
        html = html.replace(/\\\(([\s\S]*?)\\\)/g, (match, equation) => {
            const placeholder = `__MATH_INLINE_${mathBlocks.length}__`;
            mathBlocks.push({ placeholder, equation, display: false });
            return placeholder;
        });

        // 2b. Extraire les équations en ligne ($ ... $)
        // Gère les cas à un seul caractère ($x$) et les expressions multi-caractères
        html = html.replace(/\$([^\s$][^\n$]*?[^\s$]|[^\s$])\$/g, (match, equation) => {
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
            if (trimmed.startsWith('<h') || trimmed.startsWith('<li') || trimmed.startsWith('<hr') || trimmed.startsWith('__MATH_BLOCK_') || trimmed.startsWith('__HTML_TAG_')) {
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

        // 5. Restaurer les balises HTML protégées (span, a)
        htmlTags.forEach(({ placeholder, tag }) => {
            html = html.split(placeholder).join(tag);
        });

        return html;
    }

    renderMath() {
        const target = this.outputTarget;
        const doRender = () => {
            try {
                if (window.renderMathInElement) {
                    window.renderMathInElement(target, {
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
                this.pendingMathTarget = target;
                return;
            }
            this.waitingForKatex = true;
            this.pendingMathTarget = target;

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

    renderMathThrottled() {
        const now = Date.now();
        const limit = 1000; // Délai de 1s min entre rendus complets pendant le streaming

        if (!this.lastMathRenderTime || (now - this.lastMathRenderTime >= limit)) {
            this.renderMath();
            this.lastMathRenderTime = now;
        } else {
            if (this.mathRenderTimeout) {
                clearTimeout(this.mathRenderTimeout);
            }
            this.mathRenderTimeout = setTimeout(() => {
                this.renderMath();
                this.lastMathRenderTime = Date.now();
            }, limit);
        }
    }

    toggleLoading(isLoading) {
        this.statusTarget.classList.toggle('hidden', !isLoading);
        if (isLoading) {
            this.outputTarget.innerHTML = '...';
        }
    }

    zoomIn() {
        if (this.fontSizeLevel < 150) {
            this.fontSizeLevel += 10;
            this.updateFontSize();
        }
    }

    zoomOut() {
        if (this.fontSizeLevel > 80) {
            this.fontSizeLevel -= 10;
            this.updateFontSize();
        }
    }

    updateFontSize() {
        this.outputTarget.style.setProperty('--synth-font-size-multiplier', this.fontSizeLevel / 100);
        if (this.hasFontSizeIndicatorTarget) {
            this.fontSizeIndicatorTarget.textContent = `${this.fontSizeLevel}%`;
        }
    }
}
