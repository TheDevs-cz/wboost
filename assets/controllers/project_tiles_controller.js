import { Controller } from '@hotwired/stimulus';

/*
 * Project overview (templates/project_dashboard.html.twig):
 *
 *  - personal module-tile order (prototype "wb-tiles"): drag a tile onto
 *    another to move it there; the order is per viewer (localStorage), the
 *    same in every project. Tiles a project doesn't have are kept in the
 *    stored order so switching projects never loses them.
 *  - the Uživatelé box reloads after the sharing modal (ManageProjectSharing
 *    Live component) actually changed something, so the list stays truthful.
 */
const KEY = 'wb-tiles';

const readOrder = () => {
    try { return JSON.parse(window.localStorage.getItem(KEY) || '[]'); } catch (e) { return []; }
};

export default class extends Controller {
    static targets = ['grid', 'tile'];

    connect() {
        this.shared = false;
        this.dragKey = null;
        if (!this.hasGridTarget) return;
        this.applyOrder();
        this.onDragStart = (e) => this.dragStart(e);
        this.onDragOver = (e) => this.dragOver(e);
        this.onDrop = (e) => this.drop(e);
        this.onDragEnd = () => this.cleanup();
        this.gridTarget.addEventListener('dragstart', this.onDragStart);
        this.gridTarget.addEventListener('dragover', this.onDragOver);
        this.gridTarget.addEventListener('drop', this.onDrop);
        this.gridTarget.addEventListener('dragend', this.onDragEnd);
    }

    disconnect() {
        if (!this.hasGridTarget || !this.onDragStart) return;
        this.gridTarget.removeEventListener('dragstart', this.onDragStart);
        this.gridTarget.removeEventListener('dragover', this.onDragOver);
        this.gridTarget.removeEventListener('drop', this.onDrop);
        this.gridTarget.removeEventListener('dragend', this.onDragEnd);
    }

    applyOrder() {
        const order = readOrder();
        if (!order.length) return;
        const rank = (k) => { const i = order.indexOf(k); return i < 0 ? 99 : i; };
        this.tileTargets
            .map((tile, i) => ({ tile, i }))
            .sort((a, b) => (rank(a.tile.dataset.pdk) - rank(b.tile.dataset.pdk)) || (a.i - b.i))
            .forEach(({ tile }) => this.gridTarget.appendChild(tile));
    }

    dragStart(e) {
        const tile = e.target.closest && e.target.closest('[data-pdk]');
        if (!tile) return;
        this.dragKey = tile.dataset.pdk;
        tile.classList.add('pd-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', this.dragKey);
    }

    dragOver(e) {
        if (!this.dragKey) return;
        const tile = e.target.closest('[data-pdk]');
        if (!tile) return;
        e.preventDefault();
        this.tileTargets.forEach((t) => { if (t !== tile) t.classList.remove('pd-over'); });
        if (tile.dataset.pdk !== this.dragKey) tile.classList.add('pd-over');
    }

    drop(e) {
        if (!this.dragKey) return;
        e.preventDefault();
        const target = e.target.closest('[data-pdk]');
        const dragged = this.tileTargets.find((t) => t.dataset.pdk === this.dragKey);
        if (target && dragged && target !== dragged) {
            const all = [...this.gridTarget.children];
            const from = all.indexOf(dragged);
            const to = all.indexOf(target);
            this.gridTarget.insertBefore(dragged, from < to ? target.nextSibling : target);
            const keys = [...this.gridTarget.children].map((t) => t.dataset.pdk).filter(Boolean);
            const rest = readOrder().filter((k) => !keys.includes(k));
            try { window.localStorage.setItem(KEY, JSON.stringify(keys.concat(rest))); } catch (err) { /* private mode */ }
        }
        this.cleanup();
    }

    cleanup() {
        this.dragKey = null;
        this.tileTargets.forEach((t) => t.classList.remove('pd-dragging', 'pd-over'));
    }

    // ---- sharing modal -----------------------------------------------------

    markShared(e) {
        if (e.target.closest('[data-live-action-param]')) this.shared = true;
    }

    reloadIfShared() {
        if (this.shared) window.location.reload();
    }
}
