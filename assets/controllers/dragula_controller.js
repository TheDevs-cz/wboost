import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

export default class extends Controller {
    static targets = ['container'];
    static values = {
        sortUrl: String,
        direction: { type: String, default: 'vertical' }
    };

    connect() {
        this.sortable = Sortable.create(this.containerTarget, {
            handle: '.dragula-handle',
            direction: this.directionValue,
            ghostClass: 'gu-transit',
            dragClass: 'gu-mirror',
            onEnd: this.updateOrder.bind(this)
        });
    }

    disconnect() {
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
