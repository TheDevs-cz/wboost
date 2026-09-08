import { Controller } from '@hotwired/stimulus';

/**
 * One row of the "Historie exportů" dropdown (`_export_history_menu_body`):
 * the pencil flips the label into the inline rename field, Escape / × flip
 * it back. Saving is NOT this controller's business — Enter / ✓ submit the
 * row's out-of-line rename form (the field's `form` owner) through Turbo,
 * and the Turbo Stream answer re-renders the whole menu body, this row
 * included, back in its view state.
 */
export default class extends Controller {
    static targets = ['view', 'editor', 'input', 'pencil'];

    edit(event) {
        event.preventDefault();
        this.viewTargets.forEach((element) => { element.hidden = true; });
        this.editorTarget.hidden = false;
        this.inputTarget.focus();
        this.inputTarget.select();
    }

    /**
     * The × button, and Escape — which is bound on WINDOW in the CAPTURE
     * phase (`keydown.esc@window->…:capture`), not on the field: Bootstrap's
     * dropdown handles Escape on the document in the capture phase too, and
     * it stops propagation before closing the menu, so a listener on the
     * field never hears it (browser-verified 2026-09-08). Window-capture
     * runs first; while this row's field holds the edit, Escape cancels it
     * and stops there (the menu stays open), otherwise it falls through and
     * closes the menu as usual.
     */
    cancel(event) {
        if (this.editorTarget.hidden || !this.element.contains(event.target)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        this.inputTarget.value = this.inputTarget.defaultValue;
        this.editorTarget.hidden = true;
        this.viewTargets.forEach((element) => { element.hidden = false; });
        // Hiding the focused field drops focus on <body>; hand it back to the
        // pencil so a second Escape closes the menu the way Bootstrap does it.
        this.pencilTarget.focus();
    }
}
