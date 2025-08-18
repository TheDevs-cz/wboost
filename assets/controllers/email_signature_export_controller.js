import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['preview', 'input', 'codeInput'];

    static values = {
        sourceHtml: String,
        variantName: String,
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

    downloadHtml() {
        const htmlContent = this.codeInputTarget.value;
        const blob = new Blob([htmlContent], { type: 'text/html' });
        const url = URL.createObjectURL(blob);
        
        const filename = `${this.variantNameValue} - email podpis.html`;
        
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }
}
