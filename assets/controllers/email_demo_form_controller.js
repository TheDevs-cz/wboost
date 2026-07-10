import { Controller } from '@hotwired/stimulus';

/**
 * Drives the "send demo signature email" modal form: lets the user add up to
 * maxValue recipient rows, remove extra rows, and disables the submit button
 * while the form posts.
 */
export default class extends Controller {
    static targets = ['rows', 'row', 'addButton', 'submitButton'];

    static values = {
        max: { type: Number, default: 5 },
    };

    addRow() {
        if (this.rowTargets.length >= this.maxValue) return;

        const template = this.rowTargets[0];
        const row = template.cloneNode(true);
        const input = row.querySelector('input');

        input.value = '';
        input.required = false;
        row.querySelector('[data-role="remove"]').classList.remove('invisible');

        this.rowsTarget.appendChild(row);
        input.focus();
        this.refresh();
    }

    removeRow(event) {
        if (this.rowTargets.length <= 1) return;

        event.currentTarget.closest('[data-email-demo-form-target="row"]').remove();
        this.refresh();
    }

    refresh() {
        this.addButtonTarget.classList.toggle('d-none', this.rowTargets.length >= this.maxValue);
    }

    submitting() {
        this.submitButtonTarget.disabled = true;
        this.submitButtonTarget.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Odesílám…';
    }
}
