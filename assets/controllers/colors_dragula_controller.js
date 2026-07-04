import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

export default class extends Controller {
    static targets = ['container'];

    connect() {
        this.sortable = Sortable.create(this.containerTarget, {
            handle: '.dragula-handle',
            // See dragula_controller.js: fallback mode keeps .gu-mirror on a
            // detached clone (correct) instead of the in-flow item (breaks layout).
            forceFallback: true,
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
        // Loop through each draggable item in the container and update its order field
        this.containerTarget.querySelectorAll('[data-order-field-target]').forEach((element, index) => {
            const orderField = element.querySelector('input[name$="[order]"]');

            if (orderField) {
                orderField.value = index; // Set the index as the order
            }
        });
    }
}
