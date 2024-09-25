import { Controller } from '@hotwired/stimulus';
import dragula from 'dragula';

export default class extends Controller {
    static targets = ['container'];
    static values = {
        sortUrl: String,
        direction: { type: String, default: 'vertical' }
    };

    connect() {
        this.drake = dragula([this.containerTarget], {
            moves: (el, source, handle) => handle.classList.contains('dragula-handle'),
            direction: this.directionValue
        });

        this.drake.on('drop', this.updateOrder.bind(this));
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
