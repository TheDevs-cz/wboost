import { Controller } from '@hotwired/stimulus';
import dragula from 'dragula';
import { getComponent } from '@symfony/ux-live-component';

export default class extends Controller {
    static values = {
        mealId: String,
        variantId: String,
        type: String,
        sortUrl: String
    };

    connect() {
        this.drake = dragula([this.element], {
            moves: (el, source, handle) => {
                const dragHandle = handle.closest('.drag-handle');
                if (!dragHandle) return false;

                // Find the closest draggable element from the handle
                const closestDraggable = dragHandle.closest('[data-id]');

                // Only allow if the closest draggable is the element being considered
                return closestDraggable === el;
            },
            direction: 'vertical'
        });

        this.drake.on('drop', this.handleDrop.bind(this));
        this.initializeLiveComponent();
    }

    async initializeLiveComponent() {
        const liveElement = this.element.closest('[data-controller*="live"]');
        if (liveElement) {
            this.component = await getComponent(liveElement);
        }
    }

    async handleDrop() {
        const sorted = Array.from(this.element.querySelectorAll('[data-id]'))
            .map(element => element.dataset.id);

        await this.sendOrderToBackend(sorted);
    }

    async sendOrderToBackend(sorted) {
        try {
            const response = await fetch(this.sortUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ sorted })
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            const data = await response.json();
            console.log('Order updated successfully:', data);

            // Refresh the Live Component to reflect changes
            if (this.component) {
                await this.component.render();
            }
        } catch (error) {
            console.error('Error updating order:', error);
        }
    }

    disconnect() {
        if (this.drake) {
            this.drake.destroy();
        }
    }
}
