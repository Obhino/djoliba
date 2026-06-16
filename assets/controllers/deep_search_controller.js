import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'limitSelect', 'resultsGrid', 'loading', 'loadMoreBtn']
    static values = { projectId: Number, initialQuery: String }

    connect() {
        this.currentQuery = this.initialQueryValue || '';
        this.currentLimit = 12;
        
        if (this.currentQuery) {
            this.executeSearch(this.currentQuery, this.currentLimit);
        }
    }

    triggerSearch() {
        const query = this.inputTarget.value.trim();
        if (!query) return;
        const limit = parseInt(this.limitSelectTarget.value, 10) || 12;
        this.executeSearch(query, limit);
    }

    async executeSearch(query, limit) {
        this.currentQuery = query;
        this.currentLimit = limit;

        this.loadingTarget.classList.remove('hidden');
        this.resultsGridTarget.classList.add('hidden');
        this.resultsGridTarget.innerHTML = '';
        this.loadMoreBtnTarget.classList.add('hidden');

        try {
            const response = await fetch('/api/literature/deep-search', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query: query, limit: limit })
            });

            const result = await response.json();
            if (result.success && result.data) {
                this.renderResults(result.data);
                if (result.data.length >= limit && limit < 50) {
                    this.loadMoreBtnTarget.classList.remove('hidden');
                }
            } else {
                this.resultsGridTarget.innerHTML = '<div class="col-span-full py-12 text-center text-xs text-red-500 font-medium">Erreur lors de la recherche approfondie.</div>';
                this.loadingTarget.classList.add('hidden');
                this.resultsGridTarget.classList.remove('hidden');
            }
        } catch (error) {
            console.error('Deep search error:', error);
            this.resultsGridTarget.innerHTML = '<div class="col-span-full py-12 text-center text-xs text-red-500 font-medium">Erreur réseau lors de la recherche.</div>';
            this.loadingTarget.classList.add('hidden');
            this.resultsGridTarget.classList.remove('hidden');
        }
    }

    async loadMore() {
        const originalText = this.loadMoreBtnTarget.textContent;
        this.loadMoreBtnTarget.disabled = true;
        this.loadMoreBtnTarget.textContent = 'Chargement...';

        this.currentLimit = Math.min(this.currentLimit + 12, 50);
        this.limitSelectTarget.value = this.currentLimit.toString();

        try {
            const response = await fetch('/api/literature/deep-search', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query: this.currentQuery, limit: this.currentLimit })
            });

            const result = await response.json();
            if (result.success && result.data) {
                this.renderResults(result.data);
                if (result.data.length >= this.currentLimit && this.currentLimit < 50) {
                    this.loadMoreBtnTarget.classList.remove('hidden');
                } else {
                    this.loadMoreBtnTarget.classList.add('hidden');
                }
            } else {
                alert("Erreur lors du chargement de résultats supplémentaires.");
            }
        } catch (error) {
            console.error('Load more deep search error:', error);
            alert("Erreur réseau lors du chargement de résultats supplémentaires.");
        } finally {
            this.loadMoreBtnTarget.disabled = false;
            this.loadMoreBtnTarget.textContent = originalText;
        }
    }

    renderResults(articles) {
        this.loadingTarget.classList.add('hidden');
        this.resultsGridTarget.classList.remove('hidden');

        if (!articles || articles.length === 0) {
            this.resultsGridTarget.innerHTML = '<div class="col-span-full py-12 text-center text-xs text-slate-400 font-medium">Aucun résultat trouvé sur internet.</div>';
            return;
        }

        this.articlesList = articles;

        this.resultsGridTarget.innerHTML = articles.map((article, index) => {
            const isVerified = article.verified === true;
            const verifBadge = isVerified
                ? `<span class="cursor-help text-xs flex-shrink-0" title="Source vérifiée dans les bases de données">✅</span>`
                : `<span class="cursor-help text-xs flex-shrink-0" title="Non vérifié dans les bases de données">⚠️</span>`;

            const doiLink = article.doi
                ? `<a href="https://doi.org/${article.doi}" target="_blank" class="p-1.5 bg-slate-50 text-djoliba rounded-lg hover:bg-djoliba hover:text-white transition-colors" title="Ouvrir le DOI">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                   </a>`
                : (article.url ? `<a href="${article.url}" target="_blank" class="p-1.5 bg-slate-50 text-djoliba rounded-lg hover:bg-djoliba hover:text-white transition-colors" title="Ouvrir le lien">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                   </a>` : '');

            return `
            <div class="bg-white p-5 rounded-2xl border ${ isVerified ? 'border-emerald-100' : 'border-slate-100'} shadow-sm hover:border-djoliba/30 transition-all group flex flex-col h-[230px]">
                <div class="flex items-start justify-between mb-2 gap-2">
                    <h4 class="text-xs font-bold text-djoliba leading-snug flex-grow line-clamp-2" title="${article.title}">${article.title}</h4>
                    ${verifBadge}
                </div>
                <div class="text-[10px] text-slate-500 mb-2">
                    <p class="truncate font-medium text-djoliba/70">${article.authors || 'Auteurs inconnus'}</p>
                    <p>${article.year || 'N/A'} • <span class="italic">${article.journal || 'Journal inconnu'}</span></p>
                </div>
                <p class="text-[10px] text-slate-600 mb-4 line-clamp-3 flex-grow">${article.abstract || 'Résumé non disponible pour cet article.'}</p>
                
                <div class="mt-auto space-y-2">
                    <div class="flex justify-between items-center">
                        <span class="text-[9px] font-mono text-slate-400 truncate max-w-[180px]">${article.doi || (article.url ? 'Lien disponible' : 'Pas de DOI')}</span>
                        ${doiLink}
                    </div>
                    <button data-action="click->deep-search#addToProject" 
                            data-deep-search-index-param="${index}"
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
}
