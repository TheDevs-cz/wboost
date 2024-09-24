import { Controller } from '@hotwired/stimulus';
import dragula from 'dragula';

export default class extends Controller {
    static targets = ['container'];
    static values = { sortUrl: String }; // Define a value for the sort URL

    connect() {
        // Initialize Dragula with the container
        this.drake = dragula([this.containerTarget], {
            moves: (el, source, handle) => handle.classList.contains('dragula-handle'),
            direction: 'horizontal'
        });

        // Listen to the drop event and trigger the order update
        this.drake.on('drop', this.updateOrder.bind(this));
    }

    updateOrder() {
        // Collect just the IDs in their new order
        const orderedIds = Array.from(this.containerTarget.querySelectorAll('[data-entity-id]'))
            .map(element => element.dataset.entityId);

        // Send the reordered IDs array to the backend
        this.sendOrderToBackend(orderedIds);
    }

    sendOrderToBackend(orderedIds) {
        fetch(this.sortUrlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ orderedIds }) // Send as a JSON object with the key "orderedIds"
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
