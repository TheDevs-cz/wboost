import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';
import { withMoveCursor } from './sort_drag_cursor.js';

/*
 * Manual mockup pages list (F13, prototype `manMockList`): view switch
 * Mřížka / Náhled stránek on ONE list of page cards (`listTarget`, also the
 * dragula grid). Náhled stacks the cards like a PDF reader; the thumbnail
 * rail (`railTarget`) highlights the page in view, jumps on click / arrow
 * keys and reorders by drag — POSTing the same `{sorted: [ids]}` payload to
 * the same endpoint the grid's dragula controller uses.
 *
 * The chosen view is remembered in localStorage['wb-manview'] ('grid'|'pdf',
 * default 'pdf'); an inline script in the template applies it before paint.
 */
const STORAGE_KEY = 'wb-manview';

export default class extends Controller {
    static targets = ['list', 'page', 'rail', 'viewButton'];
    static values = { sortUrl: String };

    connect() {
        this.visible = new Set();
        this.lockUntil = 0;
        this.applyView(this.element.getAttribute('data-view') === 'grid' ? 'grid' : 'pdf', false);

        if (this.hasRailTarget && !this.sortable) {
            this.sortable = Sortable.create(this.railTarget, withMoveCursor({
                draggable: '.man-th',
                forceFallback: true,
                // A click must stay a click: start the drag only after a few px.
                fallbackTolerance: 4,
                ghostClass: 'man-dragging',
                dragClass: 'gu-mirror',
                onEnd: this.railSorted.bind(this),
            }));
        }
    }

    disconnect() {
        this.observer?.disconnect();
        this.observer = null;
        if (this.sortable) {
            this.sortable.destroy();
            this.sortable = null;
        }
    }

    setView(event) {
        this.applyView(event.params.view === 'grid' ? 'grid' : 'pdf', true);
    }

    applyView(view, remember) {
        this.view = view;
        this.element.setAttribute('data-view', view);
        this.viewButtonTargets.forEach((button) => {
            const on = button.dataset.viewSet === view;
            button.classList.toggle('on', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (remember) {
            try { window.localStorage.setItem(STORAGE_KEY, view); } catch (e) { /* private mode */ }
        }

        if (view === 'pdf') {
            this.syncRailToList();
            this.observe();
        } else {
            this.observer?.disconnect();
            this.observer = null;
        }
    }

    /* ---- scroll-follow ------------------------------------------------ */

    observe() {
        if (this.observer || !this.hasRailTarget) return;
        this.visible.clear();
        this.observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                const id = entry.target.dataset.entityId;
                if (entry.isIntersecting) this.visible.add(id); else this.visible.delete(id);
            });
            if (Date.now() < this.lockUntil) return;
            // The first page (in list order) inside the reading band wins.
            const first = this.pageTargets.find((page) => this.visible.has(page.dataset.entityId));
            if (first) this.mark(first.dataset.entityId);
        }, { rootMargin: '-25% 0px -60% 0px' });
        this.pageTargets.forEach((page) => this.observer.observe(page));
    }

    mark(id) {
        if (!this.hasRailTarget) return;
        const rail = this.railTarget;
        rail.querySelectorAll('.man-th').forEach((li) => {
            const on = li.dataset.pageId === id;
            li.classList.toggle('on', on);
            const button = li.querySelector('button');
            if (on) {
                button.setAttribute('aria-current', 'true');
                // Keep the active thumbnail inside the rail's own scroll box
                // (never scrollIntoView — that would scroll the document too).
                if (li.offsetTop < rail.scrollTop || li.offsetTop + li.offsetHeight > rail.scrollTop + rail.clientHeight) {
                    rail.scrollTop = li.offsetTop - rail.clientHeight / 2 + li.offsetHeight / 2;
                }
            } else {
                button.removeAttribute('aria-current');
            }
        });
    }

    select(event) {
        this.go(event.params.id);
    }

    go(id) {
        this.mark(id);
        this.railTarget.querySelector(`[data-page-id="${id}"] button`)?.focus({ preventScroll: true });
        const page = this.pageTargets.find((p) => p.dataset.entityId === id);
        if (!page) return;
        // Hold the clicked highlight while the smooth scroll passes the others.
        this.lockUntil = Date.now() + 900;
        page.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    railKey(event) {
        if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const ids = this.railIds();
        const current = this.railTarget.querySelector('.man-th.on')?.dataset.pageId;
        const i = Math.max(0, ids.indexOf(current));
        const n = event.key === 'Home' ? 0
            : event.key === 'End' ? ids.length - 1
                : Math.max(0, Math.min(ids.length - 1, i + (/Up|Left/.test(event.key) ? -1 : 1)));
        if (n !== i || current === undefined) this.go(ids[n]);
    }

    /* ---- ordering ----------------------------------------------------- */

    railIds() {
        return Array.from(this.railTarget.querySelectorAll('.man-th')).map((li) => li.dataset.pageId);
    }

    listIds() {
        return this.pageTargets.map((page) => page.dataset.entityId);
    }

    // Thumbnail drag (Náhled): reorder the cards to match, then persist.
    railSorted(event) {
        if (event.oldIndex === event.newIndex) return;
        const ids = this.railIds();
        ids.forEach((id) => {
            const page = this.listTarget.querySelector(`:scope > [data-entity-id="${id}"]`);
            if (page) this.listTarget.appendChild(page);
        });
        this.renumber();
        this.persist(ids);
    }

    // Card drag (Mřížka, dragula controller persists): just renumber; the
    // rail is re-synced whenever Náhled is shown.
    listSorted(event) {
        if (event.target !== this.listTarget) return;
        this.renumber();
        if (this.view === 'pdf') this.syncRailToList();
    }

    syncRailToList() {
        if (!this.hasRailTarget) return;
        const ids = this.listIds();
        if (ids.join() === this.railIds().join()) return;
        ids.forEach((id) => {
            const li = this.railTarget.querySelector(`[data-page-id="${id}"]`);
            if (li) this.railTarget.appendChild(li);
        });
        this.renumber();
    }

    renumber() {
        this.pageTargets.forEach((page, i) => {
            const badge = page.querySelector('.man-pgn');
            if (badge) badge.textContent = String(i + 1);
            page.setAttribute('aria-label', `Stránka ${i + 1}: ${page.dataset.name}`);
        });
        if (!this.hasRailTarget) return;
        this.railTarget.querySelectorAll('.man-th').forEach((li, i) => {
            li.querySelector('.man-th-n').textContent = String(i + 1);
            li.querySelector('button').setAttribute('aria-label', `Stránka ${i + 1}: ${li.dataset.name}`);
        });
    }

    persist(sorted) {
        fetch(this.sortUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ sorted }),
        })
            .then((response) => { if (!response.ok) throw new Error(`HTTP ${response.status}`); })
            .catch((error) => console.error('Error updating mockup page order:', error));
    }
}
