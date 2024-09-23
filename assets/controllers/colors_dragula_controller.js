import { Controller } from '@hotwired/stimulus';
import dragula from 'dragula';

export default class extends Controller {
    static targets = ['container'];

    connect() {
        // Initialize Dragula for the containers
        this.drake = dragula([this.containerTarget], {
            moves: (el, source, handle) => handle.classList.contains('dragula-handle')
        });

        // Listen to the drop event and update the order fields accordingly
        this.drake.on('drop', this.updateOrder.bind(this));
    }

    updateOrder() {
        // Loop through each draggable item in the container and update its order field
        this.containerTarget.querySelectorAll('[data-order-field-target]').forEach((element, index) => {
            const orderField = element.querySelector('input[name$="[order]"]');

            if (orderField) {
                orderField.value = index; // Set the index as the order
            }
        });
    }
}
