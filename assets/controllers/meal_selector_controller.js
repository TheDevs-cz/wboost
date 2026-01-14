import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['variantId'];

    connect() {
        // Store the variant ID when the modal is opened
        const modal = this.element;
        modal.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            const variantId = button.getAttribute('data-variant-id');
            this.variantIdTarget.value = variantId;
        });
    }

    selectMeal(event) {
        const mealId = event.currentTarget.getAttribute('data-meal-id');
        const variantId = this.variantIdTarget.value;

        if (!variantId || !mealId) {
            return;
        }

        // Find the Live Component and trigger the action
        const liveComponent = document.querySelector('[data-controller="live"]');
        if (liveComponent) {
            // Dispatch a custom event that the Live Component can handle
            const actionEvent = new CustomEvent('live:action', {
                bubbles: true,
                detail: {
                    action: 'addMeal',
                    args: {
                        variantid: variantId,
                        mealid: mealId
                    }
                }
            });

            // Use Symfony UX Live Component's action system
            // We need to click a hidden button or use the component's action method
            const component = liveComponent.__component;
            if (component) {
                component.action('addMeal', { variantid: variantId, mealid: mealId });
            }
        }

        // Close the modal
        const modalElement = this.element;
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    }
}
