import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'filename'];

    triggerSelect() {
        if (this.hasInputTarget) {
            this.inputTarget.click();
        }
    }

    onFileSelect(event) {
        if (!this.hasFilenameTarget) return;
        const file = event.target.files[0];
        if (file) {
            this.filenameTarget.textContent = file.name;
            this.filenameTarget.classList.remove('text-slate-400', 'italic');
            this.filenameTarget.classList.add('text-djoliba', 'font-semibold');
        } else {
            this.filenameTarget.textContent = 'Aucun fichier sélectionné';
            this.filenameTarget.classList.remove('text-djoliba', 'font-semibold');
            this.filenameTarget.classList.add('text-slate-400', 'italic');
        }
    }
}
