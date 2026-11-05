import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';
import { withMoveCursor } from './sort_drag_cursor.js';

export default class extends Controller {
    static targets = ['container'];
    static values = {
        sortUrl: String,
        direction: { type: String, default: 'vertical' }
    };

    connect() {
        // Still alive from a disconnect skipped mid-drag (see disconnect()).
        if (this.sortable) return;

        this.sortable = Sortable.create(this.containerTarget, withMoveCursor({
            handle: '.dragula-handle',
            direction: this.directionValue,
            // Use the JS-managed fallback drag instead of native HTML5 DnD: in
            // fallback mode `dragClass` (.gu-mirror, position:fixed) is applied to
            // a detached clone that follows the cursor — the way dragula's mirror
            // worked — while the real item keeps only `ghostClass` and stays in
            // flow. Native mode instead slaps .gu-mirror on the in-flow element,
            // ripping it out of layout and breaking drop targeting (cards stack).
            forceFallback: true,
            ghostClass: 'gu-transit',
            dragClass: 'gu-mirror',
            onEnd: this.updateOrder.bind(this)
        }));
    }

    disconnect() {
        // An outer Sortable that moves this list's ancestor (the Šablony
        // category reorder drags whole sections holding these grids) makes
        // Stimulus disconnect + reconnect us on every swap. Sortable#destroy()
        // calls _onDrop() on the shared global drag state and would kill that
        // outer drag — so keep the instance while someone else is dragging;
        // connect() reuses it.
        if (Sortable.active && Sortable.active !== this.sortable) return;

        if (this.sortable) {
            this.sortable.destroy();
            this.sortable = null;
        }
    }

    updateOrder() {
        const sorted = Array.from(this.containerTarget.querySelectorAll('[data-entity-id]'))
            .map(element => element.dataset.entityId);

        this.sendOrderToBackend(sorted);
    }

    sendOrderToBackend(sorted) {
        fetch(this.sortUrlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ sorted })
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                console.log('Order updated successfully:', data);
            })
            .catch(error => {
                console.error('Error updating order:', error);
            });
    }
}
