import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static targets = ['modal', 'modalCard', 'loader', 'loaderText'];
    static values  = { projectId: Number };

    // ── État interne du polling ────────────────────────────────────────────
    #pollTimer      = null;   // setInterval handle
    #pollInterval   = 2000;   // ms entre chaque vérification
    #maxPollTime    = 300000; // 5 minutes maximum
    #pollStart      = null;   // timestamp de début
    #currentJobId   = null;   // jobId du job en cours
    #currentFormat  = null;   // format du job en cours
    #isPolling      = false;  // garde pour éviter les doubles polls

    // Labels lisibles par format
    #labels = {
        zip:    'ZIP Standard',
        pdf:    'PDF Scientifique',
        latex:  'Archive LaTeX',
        bibtex: 'BibTeX',
        docx:   'Document Word',
    };

    connect() {}

    disconnect() {
        this.#stopPolling();
    }

    // ════════════════════════════════════════════════════════════════════════
    // Modal
    // ════════════════════════════════════════════════════════════════════════

    showModal(event) {
        if (event) event.preventDefault();

        document.body.classList.add('overflow-hidden');
        this.modalTarget.classList.remove('hidden');

        requestAnimationFrame(() => {
            this.modalTarget.classList.remove('opacity-0');
            this.modalTarget.classList.add('opacity-100');

            if (this.hasModalCardTarget) {
                this.modalCardTarget.classList.remove('scale-95', 'opacity-0');
                this.modalCardTarget.classList.add('scale-100', 'opacity-100');
            }
        });
    }

    closeModal(event) {
        if (event) event.preventDefault();

        document.body.classList.remove('overflow-hidden');

        if (this.hasModalCardTarget) {
            this.modalCardTarget.classList.remove('scale-100', 'opacity-100');
            this.modalCardTarget.classList.add('scale-95', 'opacity-0');
        }

        this.modalTarget.classList.remove('opacity-100');
        this.modalTarget.classList.add('opacity-0');

        setTimeout(() => {
            this.modalTarget.classList.add('hidden');
            this.#hideLoader();
        }, 300);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Déclenchement de l'export
    // ════════════════════════════════════════════════════════════════════════

    async exportProject(event) {
        if (event) event.preventDefault();
        if (this.#isPolling) return; // protection contre le double-clic

        const format      = event.currentTarget.dataset.format || 'zip';
        const formatLabel = this.#labels[format] ?? format.toUpperCase();

        this.#currentFormat = format;
        this.#showLoader(`Lancement de l'export ${formatLabel}…`);

        try {
            const res = await fetch(
                `/api/projects/${this.projectIdValue}/export?format=${format}`,
                {
                    method:  'GET',
                    headers: {
                        'Accept':           'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                }
            );

            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                throw new Error(err.error || `Erreur serveur (${res.status})`);
            }

            const data = await res.json();

            if (!data.success || !data.jobId) {
                throw new Error('Réponse inattendue du serveur.');
            }

            // ── Démarrer le polling ────────────────────────────────────────
            this.#currentJobId = data.jobId;
            this.#showLoader(`Génération de votre export ${formatLabel} en cours…`);
            this.#startPolling();

        } catch (error) {
            console.error('[ExportController] Erreur de lancement :', error);
            this.#hideLoader();
            this.#dispatchToast(`Impossible de lancer l'export : ${error.message}`, 'error');
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Polling
    // ════════════════════════════════════════════════════════════════════════

    #startPolling() {
        if (this.#isPolling) return;

        this.#isPolling  = true;
        this.#pollStart  = Date.now();

        // Premier appel immédiat, puis intervalle régulier
        this.#checkStatus();
        this.#pollTimer = setInterval(() => this.#checkStatus(), this.#pollInterval);
    }

    #stopPolling() {
        if (this.#pollTimer) {
            clearInterval(this.#pollTimer);
            this.#pollTimer = null;
        }
        this.#isPolling  = false;
        this.#currentJobId = null;
    }

    async #checkStatus() {
        // Sécurité : timeout global de 5 minutes
        if (Date.now() - this.#pollStart > this.#maxPollTime) {
            this.#stopPolling();
            this.#hideLoader();
            this.#dispatchToast(
                'Le délai maximum de génération a été dépassé. Réessayez.',
                'warning'
            );
            return;
        }

        if (!this.#currentJobId) return;

        try {
            const res = await fetch(
                `/api/projects/${this.projectIdValue}/export/status?jobId=${this.#currentJobId}`,
                {
                    method:  'GET',
                    headers: {
                        'Accept':           'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    // Empêche la mise en cache navigateur
                    cache: 'no-store',
                }
            );

            if (!res.ok) {
                // Erreur réseau temporaire → on continue à poller
                console.warn('[ExportController] Erreur HTTP status :', res.status);
                return;
            }

            const data = await res.json();

            this.#handleStatusData(data);

        } catch (err) {
            // Erreur réseau → on continue à poller (pas fatal)
            console.warn('[ExportController] Erreur de polling :', err.message);
        }
    }

    #handleStatusData(data) {
        const formatLabel = this.#labels[this.#currentFormat] ?? 'Fichier';

        switch (data.status) {

            case 'pending':
                // Toujours en cours → mettre à jour le texte du loader et continuer
                this.#setLoaderText(`Génération de votre export ${formatLabel} en cours…`);
                break;

            case 'done': {
                this.#stopPolling();
                this.#hideLoader();
                this.closeModal();

                // ── Téléchargement automatique ─────────────────────────────
                const filename = data.filename ?? null;
                this.#triggerDownload(data.downloadUrl, filename, formatLabel);
                break;
            }

            case 'error':
                this.#stopPolling();
                this.#hideLoader();
                this.#dispatchToast(
                    `Erreur lors de la génération : ${data.error || 'Raison inconnue.'}`,
                    'error'
                );
                break;

            case 'not_found':
                // Le fichier de statut n'existe pas encore (race condition) → continuer
                break;

            default:
                console.warn('[ExportController] Statut inconnu :', data.status);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Helpers UI
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Crée un lien <a download> invisible, déclenche un clic programmatique,
     * puis supprime l'élément du DOM. Affiche un toast de confirmation.
     *
     * @param {string}      url         URL absolue ou relative du fichier
     * @param {string|null} filename    Nom de fichier suggéré (optionnel)
     * @param {string}      formatLabel Label lisible du format (pour le toast)
     */
    #triggerDownload(url, filename, formatLabel = 'Fichier') {
        if (!url) {
            this.#dispatchToast('URL de téléchargement manquante.', 'error');
            return;
        }

        // Création du lien fantôme
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', filename ?? '');

        // Doit être dans le DOM pour fonctionner dans Firefox
        link.style.cssText = 'position:absolute;left:-9999px;opacity:0;';
        document.body.appendChild(link);

        // Clic programmatique → déclenche la boîte de dialogue de sauvegarde
        link.click();

        // Nettoyage différé (laisse le temps au navigateur d'initier le téléchargement)
        setTimeout(() => {
            document.body.removeChild(link);

            // ── Toast "Téléchargement terminé" ────────────────────────────
            this.#dispatchToast(
                `Téléchargement terminé — ${formatLabel}`,
                'success'
            );
        }, 500);
    }

    #showLoader(text) {
        if (this.hasLoaderTarget) {
            this.loaderTarget.classList.remove('hidden');
            this.loaderTarget.classList.add('flex');
        }
        this.#setLoaderText(text);
    }

    #hideLoader() {
        if (this.hasLoaderTarget) {
            this.loaderTarget.classList.add('hidden');
            this.loaderTarget.classList.remove('flex');
        }
    }

    #setLoaderText(text) {
        if (this.hasLoaderTextTarget) {
            this.loaderTextTarget.textContent = text;
        }
    }

    /**
     * Dispatch un événement global toast:show
     * @param {string}      message
     * @param {string}      type     success | error | warning | info | download
     * @param {object|null} action   { label, url }
     */
    #dispatchToast(message, type = 'success', action = null) {
        window.dispatchEvent(new CustomEvent('toast:show', {
            detail: { message, type, action },
        }));
    }
}
