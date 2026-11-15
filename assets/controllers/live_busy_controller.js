import { Controller } from '@hotwired/stimulus';

/**
 * The busy look for Live Component action buttons (site-wide rule: a button
 * that starts work on the server shows that it works and cannot start it a
 * second time — docs/redesign/design-system.md §7).
 *
 * Live queues actions, so a double click ran a write twice. Each writing
 * button carries
 *
 *     data-loading="action(share)|addAttribute(disabled) action(share)|addClass(is-busy)"
 *
 * — while that action's request runs, every button of the action is disabled
 * and `.is-busy`. Live cannot say WHICH button was pressed (a row's „Přidat“
 * vs. all the others), so this controller, on the component root, marks the
 * pressed one with `data-live-pressed`; only that one draws the spinner
 * (buttons.css, CSS-only: Live re-renders the DOM, so no busy_button.js
 * nodes here). The mark is moved on every press; without `.is-busy` it shows
 * nothing.
 */
export default class extends Controller {
    connect() {
        this.onPress = (event) => {
            const button = event.target instanceof Element ? event.target.closest('[data-loading]') : null;
            if (!button || !this.element.contains(button)) {
                return;
            }
            this.element.querySelectorAll('[data-live-pressed]').forEach((other) => {
                if (other !== button) {
                    other.removeAttribute('data-live-pressed');
                }
            });
            button.setAttribute('data-live-pressed', '');
        };
        // Capture: before live#action (and confirm#confirm) see the click.
        this.element.addEventListener('click', this.onPress, true);
    }

    disconnect() {
        this.element.removeEventListener('click', this.onPress, true);
    }
}
