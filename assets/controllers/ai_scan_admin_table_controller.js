/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * The row menus of the scans table on Admin › Statistiky › AI skeny
 * (ux.md §9.4). A menu is a `popover` element opened by its row's `⋯` button
 * (`popovertarget`): the browser gives it the top layer, light dismiss,
 * Escape and focus return — the table scrolls sideways and would clip an
 * ordinary dropdown. All this controller does is PLACE the open menu under
 * its button (above it when there is no room) and keep it there while the
 * page scrolls or resizes — a top-layer element does not move with the row —
 * closing it once the button has left the screen.
 *
 * And when a rating dialog opened from a menu closes, the focus goes back to
 * that row's `⋯` button (Bootstrap would return it to the menu item, which is
 * hidden by then).
 *
 * Without this script the menu still opens — in the middle of the screen.
 */
const GAP = 6;
const EDGE = 8;

export default class extends Controller {
    static targets = ['menu'];

    connect() {
        this.onToggle = (event) => {
            const menu = event.target;
            if (!(menu instanceof HTMLElement) || !menu.matches('.ai-menu')) return;
            if (event.newState === 'open') this.place(menu);
            else if (this.opened === menu) this.opened = null;
        };
        // A top-layer element does not move with its row: follow the button, and
        // close once the button has left the screen.
        this.onMove = () => {
            const menu = this.opened;
            if (!menu || !menu.matches(':popover-open')) return;
            const button = this.opener(menu.id);
            const at = button ? button.getBoundingClientRect() : null;
            if (!at || at.bottom < 0 || at.top > window.innerHeight || at.right < 0 || at.left > window.innerWidth) menu.hidePopover();
            else this.place(menu);
        };
        this.onDialogClosed = (event) => {
            const dialog = event.target;
            if (!(dialog instanceof HTMLElement) || !dialog.matches('.ai-rating')) return;
            const button = this.opener(dialog.id.replace('aiRating-', 'aiMenu-'));
            if (button) button.focus();
        };
        document.addEventListener('hidden.bs.modal', this.onDialogClosed);
        // `toggle` does not bubble.
        this.element.addEventListener('toggle', this.onToggle, true);
        window.addEventListener('scroll', this.onMove, { capture: true, passive: true });
        window.addEventListener('resize', this.onMove);
    }

    disconnect() {
        this.element.removeEventListener('toggle', this.onToggle, true);
        document.removeEventListener('hidden.bs.modal', this.onDialogClosed);
        window.removeEventListener('scroll', this.onMove, { capture: true });
        window.removeEventListener('resize', this.onMove);
    }

    /** The row's `⋯` button of a menu (its items may carry `popovertarget` too, to close it). */
    opener(menuId) {
        return this.element.querySelector(`[popovertarget="${CSS.escape(menuId)}"]:not(.mi)`);
    }

    place(menu) {
        const button = this.opener(menu.id);
        if (!button) return;
        const at = button.getBoundingClientRect();
        const width = menu.offsetWidth;
        const height = menu.offsetHeight;
        const left = Math.max(EDGE, Math.min(at.right - width, window.innerWidth - width - EDGE));
        const below = at.bottom + GAP;
        const top = below + height <= window.innerHeight - EDGE ? below : Math.max(EDGE, at.top - GAP - height);
        menu.style.left = `${Math.round(left)}px`;
        menu.style.top = `${Math.round(top)}px`;
        this.opened = menu;
    }
}
