import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        ids: Array
    };

    connect() {
        this.openCollapsibles();

        // Listen for Live Component render events
        this.element.addEventListener('live:update', () => {
            this.openCollapsibles();
        });
    }

    idsValueChanged() {
        this.openCollapsibles();
    }

    openCollapsibles() {
        // Read fresh value from DOM attribute
        const idsAttr = this.element.dataset.plannerCollapsibleOpenerIdsValue;
        const ids = idsAttr ? JSON.parse(idsAttr) : [];

        if (!ids || ids.length === 0) return;

        // Small delay to ensure DOM is ready after Live Component render
        setTimeout(() => {
            ids.forEach(id => {
                // Try to find by ID first (more reliable)
                const mealTypeDetails = document.getElementById(`meal-type-${id}`);
                if (mealTypeDetails) {
                    this.openDetails(mealTypeDetails);
                    // Also open the parent day
                    const dayDetails = mealTypeDetails.closest('details.collapsible-day');
                    if (dayDetails) {
                        this.openDetails(dayDetails);
                    }
                }

                const courseDetails = document.getElementById(`course-${id}`);
                if (courseDetails) {
                    this.openDetails(courseDetails);
                    // Also open parent meal type and day
                    const parentMealType = courseDetails.closest('details.collapsible-meal-type');
                    if (parentMealType) {
                        this.openDetails(parentMealType);
                        const dayDetails = parentMealType.closest('details.collapsible-day');
                        if (dayDetails) {
                            this.openDetails(dayDetails);
                        }
                    }
                }
            });
        }, 100);
    }

    openDetails(detailsElement) {
        if (detailsElement.open) return;

        // Remove animating class if present (from initial closed state)
        detailsElement.classList.remove('animating');
        detailsElement.open = true;
    }
}
