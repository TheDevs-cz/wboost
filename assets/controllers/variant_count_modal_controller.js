import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['mealTypeId', 'variantCount'];

    connect() {
        this.element.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            const mealTypeId = button.getAttribute('data-meal-type-id');
            this.mealTypeIdTarget.value = mealTypeId;
            this.variantCountTarget.value = '2';
        });
    }

    confirm() {
        const mealTypeId = this.mealTypeIdTarget.value;
        const variantCount = parseInt(this.variantCountTarget.value, 10) || 2;

        if (!mealTypeId) {
            return;
        }

        const liveComponent = document.querySelector('[data-controller*="live"]');
        if (liveComponent && liveComponent.__component) {
            liveComponent.__component.action('addCourse', {
                mealtypeid: mealTypeId,
                singlevariantmode: false,
                variantcount: variantCount
            });
        }

        const modal = bootstrap.Modal.getInstance(this.element);
        if (modal) {
            modal.hide();
        }
    }
}
