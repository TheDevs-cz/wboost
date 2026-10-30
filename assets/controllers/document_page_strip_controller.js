import { Controller } from '@hotwired/stimulus';

/*
 * The editor's multi-page document strip (back to the document, sibling
 * pages). The canvas editor marks unsaved work with its "Neuložené změny"
 * badge (canvas_editor_controller markUnsaved / markSaved) but has no leave
 * guard of its own — so a strip link asks before throwing changes away.
 */
export default class extends Controller {
    navigate(event) {
        const badge = document.querySelector('[data-canvas-editor-target="unsavedChangesMessage"]');
        const dirty = badge !== null && !badge.classList.contains('d-none');

        if (dirty && !window.confirm('Máte neuložené změny. Opravdu chcete stránku opustit bez uložení?')) {
            event.preventDefault();
        }
    }
}
