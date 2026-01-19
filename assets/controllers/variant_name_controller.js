import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    save(event) {
        const variantId = this.element.getAttribute('data-variant-id');
        const name = this.element.value;

        if (!variantId) {
            return;
        }

        // Find the Live Component and trigger the action
        const liveComponent = document.querySelector('[data-controller*="live"]');
        if (liveComponent) {
            const component = liveComponent.__component;
            if (component) {
                component.action('editVariant', { variantid: variantId, name: name });
            }
        }
    }
}
