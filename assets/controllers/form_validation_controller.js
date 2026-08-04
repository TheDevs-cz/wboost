import { Controller } from "@hotwired/stimulus";

/**
 * Bootstrap-styled client-side validation for a <form>, replacing the
 * browser-native bubble (which draws its own chrome, ignores the app's
 * styling and anchors badly under the sticky header).
 *
 * Attach with `data-controller="form-validation"` on the form. connect()
 * turns native validation OFF (novalidate) — progressive enhancement:
 * without JS the browser bubble still guards required fields, and the
 * Symfony/bootstrap_5_layout server-side render remains the backstop.
 *
 * On an invalid submit every offending field gets Bootstrap's `.is-invalid`
 * plus an injected sibling `.invalid-feedback` carrying the browser's own
 * LOCALIZED `validationMessage` ("Vyplňte toto pole") — native messages,
 * Bootstrap look. The first invalid field is scrolled to view and focused.
 * Fields self-heal while typing: the class drops as soon as the value
 * becomes valid. Deliberately per-field `is-invalid` (not the form-level
 * `was-validated` pattern): that one also paints every valid optional
 * field green, which is just noise on long forms.
 */
export default class extends Controller {
    connect() {
        this.element.setAttribute('novalidate', '');

        this._onSubmit = (event) => this.validate(event);
        this.element.addEventListener('submit', this._onSubmit);

        // Self-heal: an invalid field clears its error state the moment the
        // user fixes it (input covers typing, change covers selects/files).
        this._onInput = (event) => {
            const field = event.target;
            if (field instanceof Element && field.classList.contains('is-invalid') && field.checkValidity()) {
                field.classList.remove('is-invalid');
            }
        };
        this.element.addEventListener('input', this._onInput);
        this.element.addEventListener('change', this._onInput);
    }

    disconnect() {
        this.element.removeEventListener('submit', this._onSubmit);
        this.element.removeEventListener('input', this._onInput);
        this.element.removeEventListener('change', this._onInput);
    }

    validate(event) {
        if (this.element.checkValidity()) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        let firstInvalid = null;

        this.element.querySelectorAll('input, select, textarea').forEach((field) => {
            if (field.disabled || field.type === 'hidden') {
                return;
            }

            const valid = field.checkValidity();
            field.classList.toggle('is-invalid', !valid);

            if (!valid) {
                firstInvalid = firstInvalid || field;
                this._feedbackFor(field).textContent = field.validationMessage;
            }
        });

        if (firstInvalid) {
            firstInvalid.scrollIntoView({ block: 'center', behavior: 'smooth' });
            firstInvalid.focus({ preventScroll: true });
        }
    }

    /** The field's sibling `.invalid-feedback`, created on first use —
     *  Bootstrap's `.is-invalid ~ .invalid-feedback` rule shows/hides it. */
    _feedbackFor(field) {
        const next = field.nextElementSibling;
        if (next && next.classList.contains('invalid-feedback')) {
            return next;
        }

        const feedback = document.createElement('div');
        feedback.className = 'invalid-feedback';
        field.insertAdjacentElement('afterend', feedback);

        return feedback;
    }
}
