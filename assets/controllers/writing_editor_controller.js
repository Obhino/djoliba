import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus Controller — writing-editor
 *
 * Gère l'éditeur de texte pour l'écriture avec vérification d'originalité
 * et suggestions de revues.
 *
 * Usage HTML :
 * <div data-controller="writing-editor" data-writing-editor-project-id-value="42">
 *   <textarea data-writing-editor-target="input"></textarea>
 *   
 *   <button data-action="click->writing-editor#checkOriginality" data-writing-editor-target="checkBtn">
 *     Vérifier l'originalité
 *   </button>
 *   <button data-action="click->writing-editor#suggestJournal" data-writing-editor-target="suggestBtn">
 *     Suggérer des revues
 *   </button>
 *
 *   <div data-writing-editor-target="status"></div>
 *   <div data-writing-editor-target="results"></div>
 * </div>
 */
export default class extends Controller {
    static targets = ['input', 'checkBtn', 'suggestBtn', 'status', 'results'];
    static values = {
        projectId: Number
    };

    connect() {
        this.#log('writing-editor connecté');
    }

    async checkOriginality() {
        const text = this.inputTarget.value.trim();
        if (text.length < 100) {
            this.#setStatus('Le texte doit contenir au moins 100 caractères.', true);
            return;
        }

        this.#setLoading(true, 'Vérification en cours...');

        try {
            const response = await fetch('/api/writing/check', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    text: text,
                    project_id: this.projectIdValue
                })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data?.error?.message || 'Erreur lors de la vérification');
            }

            this.#displayOriginalityResults(data.data);
            this.#setStatus('');
        } catch (error) {
            this.#setStatus(error.message, true);
        } finally {
            this.#setLoading(false);
        }
    }

    async suggestJournal() {
        const text = this.inputTarget.value.trim();
        if (text.length < 100) {
            this.#setStatus('Le texte doit contenir au moins 100 caractères.', true);
            return;
        }

        this.#setLoading(true, 'Recherche de revues en cours...');

        try {
            const response = await fetch('/api/writing/suggest-journal', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    text: text,
                    project_id: this.projectIdValue
                })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data?.error?.message || 'Erreur lors de la suggestion');
            }

            this.#displayJournalResults(data.data);
            this.#setStatus('');
        } catch (error) {
            this.#setStatus(error.message, true);
        } finally {
            this.#setLoading(false);
        }
    }

    // ─────────────────────────────────────────────
    // Méthodes privées d'affichage
    // ─────────────────────────────────────────────

    #displayOriginalityResults(data) {
        if (!this.hasResultsTarget) return;

        let html = `
            <div class="originality-results bg-white p-4 rounded shadow mt-4">
                <h3 class="text-lg font-bold mb-2">Résultats de l'originalité</h3>
                <div class="flex items-center gap-4 mb-4">
                    <div class="text-3xl font-bold ${this.#getScoreColor(data.originality_score)}">
                        ${data.originality_score}%
                    </div>
                    <div class="text-sm text-slate-500">Niveau : ${data.level}</div>
                </div>
        `;

        if (data.similar_passages && data.similar_passages.length > 0) {
            html += `<h4 class="font-bold mt-4 mb-2">Passages similaires détectés</h4><ul class="space-y-2">`;
            data.similar_passages.forEach(p => {
                html += `
                    <li class="bg-amber-50 p-3 rounded border border-amber-200">
                        <div class="text-sm text-slate-700 italic">"${p.passage}"</div>
                        <div class="text-xs font-semibold text-red-600 mt-1">Risque : ${p.risk}</div>
                        <div class="text-xs text-emerald-600 mt-1">Suggestion : ${p.suggestion}</div>
                    </li>
                `;
            });
            html += `</ul>`;
        } else {
             html += `<p class="text-emerald-600 font-medium">Aucun passage problématique détecté.</p>`;
        }

        if (data.recommendations && data.recommendations.length > 0) {
            html += `<h4 class="font-bold mt-4 mb-2">Recommandations</h4><ul class="list-disc pl-5 text-sm">`;
            data.recommendations.forEach(r => {
                html += `<li>${r}</li>`;
            });
            html += `</ul>`;
        }

        html += `</div>`;
        this.resultsTarget.innerHTML = html;
    }

    #displayJournalResults(data) {
        if (!this.hasResultsTarget) return;

        let html = `
            <div class="journal-results bg-white p-4 rounded shadow mt-4">
                <h3 class="text-lg font-bold mb-4">Revues suggérées</h3>
                <div class="space-y-4">
        `;

        if (data.journals && data.journals.length > 0) {
            data.journals.forEach(j => {
                html += `
                    <div class="border p-3 rounded hover:bg-slate-50 transition-colors">
                        <div class="font-bold text-primary-600">${j.name}</div>
                        <div class="text-xs text-slate-500 mb-2">Éditeur : ${j.publisher} | Impact Factor : ${j.impact_factor}</div>
                        <div class="text-sm text-slate-700 mb-2">${j.scope}</div>
                        <div class="text-sm bg-indigo-50 p-2 rounded italic">${j.match_reason}</div>
                        ${j.url !== 'N/A' ? `<a href="${j.url}" target="_blank" class="text-xs text-blue-500 hover:underline mt-2 inline-block">Voir le site</a>` : ''}
                    </div>
                `;
            });
        } else {
            html += `<p class="text-slate-500">Aucune revue trouvée.</p>`;
        }

        html += `</div></div>`;
        this.resultsTarget.innerHTML = html;
    }

    #getScoreColor(score) {
        if (score >= 80) return 'text-emerald-600';
        if (score >= 50) return 'text-amber-500';
        return 'text-red-600';
    }

    #setLoading(isLoading, message = '') {
        if (this.hasCheckBtnTarget) this.checkBtnTarget.disabled = isLoading;
        if (this.hasSuggestBtnTarget) this.suggestBtnTarget.disabled = isLoading;
        if (this.hasInputTarget) this.inputTarget.disabled = isLoading;
        
        if (isLoading) {
            this.#setStatus(message);
        } else {
            this.#setStatus('');
        }
    }

    #setStatus(text, isError = false) {
        if (!this.hasStatusTarget) return;
        this.statusTarget.textContent = text;
        this.statusTarget.className = isError ? 'text-sm text-red-600 mt-2 font-medium' : 'text-sm text-slate-500 mt-2 italic';
    }

    #log(msg) {
        if (import.meta.env?.DEV) {
            console.debug(`[writing-editor] ${msg}`);
        }
    }
}
