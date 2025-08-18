import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['preview', 'input', 'codeInput'];

    static values = {
        sourceHtml: String,
    };

    connect() {
        this.update();
    }

    update() {
        // Use DOMParser to preserve the original HTML structure including root elements
        const parser = new DOMParser();
        const doc = parser.parseFromString(this.sourceHtmlValue, 'text/html');

        this.inputTargets.forEach(input => {
            const id = input.dataset.textInputId;
            const val = input.value;
            const span = doc.querySelector(`#${id}[data-text-placeholder]`);
            if (span) span.innerHTML = val;
        });

        // Get the complete HTML including the root elements
        const updatedHtml = doc.documentElement.outerHTML;

        this.previewTarget.innerHTML = updatedHtml;
        this.codeInputTarget.value = updatedHtml;
    }
}
