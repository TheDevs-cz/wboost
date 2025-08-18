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
        const parser = document.createElement('div');
        parser.innerHTML = this.sourceHtmlValue;

        this.inputTargets.forEach(input => {
            const id = input.dataset.textInputId;
            const val = input.value;
            const span = parser.querySelector(`#${id}[data-text-placeholder]`);
            if (span) span.innerHTML = val;
        });

        const updatedHtml = parser.firstElementChild ? parser.firstElementChild.outerHTML : parser.innerHTML;

        this.previewTarget.innerHTML = updatedHtml;
        this.codeInputTarget.value = updatedHtml;
    }
}
