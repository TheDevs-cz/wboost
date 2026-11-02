import { Controller } from '@hotwired/stimulus';

/*
 * Nocturne editor chrome that has no canvas behaviour of its own (both canvas
 * editors; lives on the `.editor-shell` root next to the canvas controllers):
 *
 * - toggleClean: the lavender eye in the top bar — a "clean preview" that hides
 *   every helper overlay (field frames + tags, container zones, rulers, guides)
 *   with one CSS class. Chrome only: the display switches keep their state and
 *   nothing reaches the canvas, the save or the export.
 * - toggleNavigator: the "Mini navigátor" switch in the Zobrazení menu (CSS
 *   class; canvas_navigator_controller keeps owning the panel itself).
 * - navigate: leave guard for the chrome's own links (back, format tabs) —
 *   the canvas editor has no beforeunload, it only marks unsaved work with
 *   its "Neuložené změny" badge (same check as document_page_strip).
 */
export default class extends Controller {
    static targets = ['cleanButton'];

    toggleClean() {
        const on = this.element.classList.toggle('ed-is-clean');
        if (this.hasCleanButtonTarget) {
            const button = this.cleanButtonTarget;
            const label = on ? 'Zobrazit pomocné prvky' : 'Čistý náhled — skrýt pomocné prvky';
            button.setAttribute('aria-pressed', String(on));
            button.setAttribute('aria-label', label);
            button.title = label;
            const icon = button.querySelector('.i');
            if (icon) {
                icon.classList.toggle('i-eye', !on);
                icon.classList.toggle('i-eye-off', on);
            }
        }
    }

    toggleNavigator(event) {
        this.element.classList.toggle('ed-nav-off', !event.target.checked);
    }

    navigate(event) {
        const badge = this.element.querySelector('[data-canvas-editor-target="unsavedChangesMessage"]');
        const dirty = badge !== null && !badge.classList.contains('d-none');

        if (dirty && !window.confirm('Máte neuložené změny. Opravdu chcete editor opustit bez uložení?')) {
            event.preventDefault();
        }
    }
}
