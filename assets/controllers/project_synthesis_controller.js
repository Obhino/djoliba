import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['container', 'toggleBtn']
    static values = {
        content: String
    }

    async connect() {
        if (!this.hasContentValue || !this.contentValue) return;

        try {
            // Importation dynamique de Marked
            const MarkedModule = await import('https://cdn.jsdelivr.net/npm/marked@11.1.1/+esm');
            const marked = MarkedModule.marked || MarkedModule;

            // Parser le markdown en HTML
            const rawHtml = marked.parse(this.contentValue);
            
            // Injecter l'HTML dans le conteneur
            this.containerTarget.innerHTML = rawHtml;

            // Rendre le LaTeX si KaTeX est chargé
            if (window.renderMathInElement) {
                window.renderMathInElement(this.containerTarget, {
                    delimiters: [
                        { left: '$$', right: '$$', display: true },
                        { left: '$', right: '$', display: false },
                        { left: '\\(', right: '\\)', display: false },
                        { left: '\\[', right: '\\]', display: true }
                    ],
                    throwOnError: false
                });
            }
        } catch (err) {
            console.error("Error rendering synthesis markdown:", err);
            this.containerTarget.innerHTML = `<pre class="text-xs text-rose-500">${this.contentValue}</pre>`;
        }
    }

    toggle() {
        const isHidden = this.containerTarget.classList.contains('hidden');
        if (isHidden) {
            this.containerTarget.classList.remove('hidden');
            this.toggleBtnTarget.textContent = '▲';
        } else {
            this.containerTarget.classList.add('hidden');
            this.toggleBtnTarget.textContent = '▼';
        }
    }
}
