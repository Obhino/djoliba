import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static values = {
        type: String
    }

    createProject(event) {
        event.preventDefault();
        
        window.dispatchEvent(new CustomEvent('project-modal:open', {
            detail: { type: this.typeValue }
        }));
    }
}
