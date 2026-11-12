/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { askUnfilled, attachmentName, saveBlob, UNFILLED, CONFIRM_FIELD } from '../ai-scan/export-guard.js';

/*
 * Asks before an export that would show grey stand-in pictures (ux.md T4).
 *
 * The fill pages attach this controller to their export <form> ONLY when the
 * template has an object on the project's stand-in (`ai_scan_unfilled()` > 0
 * — templates that came from a scan with places left empty); every other
 * export submits natively, exactly as before.
 *
 * On submit the same request goes out by fetch with `Accept: application/json`:
 *  - 409 `unfilled_pictures` → the dialog. "Exportovat přesto" submits the form
 *    natively with the hidden `allowUnfilled=1` (same submitter, same
 *    formaction — the page's own busy states and download run as usual);
 *    "Otevřít editor" follows `editorUrl`; Escape changes nothing.
 *  - a file (the count on the page is an upper bound — a background the export
 *    fills is not unfilled) → saved from the answer, no second render.
 *  - anything else → the native submit, so the user gets the page's own error.
 */
export default class extends Controller {
    connect() {
        this.bypass = false;
        this.busy = false;
        this.onSubmit = (event) => this.intercept(event);
        // Capture: before the page's own submit listeners show their busy state.
        this.element.addEventListener('submit', this.onSubmit, true);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit, true);
    }

    async intercept(event) {
        if (this.bypass) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (this.busy) return;

        const form = this.element;
        const submitter = event.submitter || null;
        const url = submitter && submitter.hasAttribute('formaction') ? submitter.formAction : form.action;
        this.busy = true;
        if (submitter) submitter.setAttribute('aria-busy', 'true');

        let response = null;
        try {
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                body: submitter ? new FormData(form, submitter) : new FormData(form),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
        } catch (e) {
            response = null;
        }
        this.busy = false;
        if (submitter) submitter.removeAttribute('aria-busy');

        if (response && response.status === 409) {
            const answer = await response.json().catch(() => null);
            if (answer && answer.code === UNFILLED) {
                if (await askUnfilled(answer)) this.submitNatively(submitter, true);
                return;
            }
        }

        const disposition = response ? response.headers.get('Content-Disposition') || '' : '';
        if (response && response.ok && /attachment/i.test(disposition)) {
            saveBlob(await response.blob(), attachmentName(disposition));
            return;
        }

        this.submitNatively(submitter, false);
    }

    submitNatively(submitter, confirmed) {
        const form = this.element;
        let field = null;
        if (confirmed) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = CONFIRM_FIELD;
            field.value = '1';
            form.append(field);
        }
        this.bypass = true;
        try {
            if (submitter && document.contains(submitter)) form.requestSubmit(submitter);
            else form.requestSubmit();
        } finally {
            this.bypass = false;
            // The navigation / download has its form data by now.
            if (field) window.setTimeout(() => field.remove(), 0);
        }
    }
}
