import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

export default class extends Controller {
    connect() {
        // One Sortable per container with no shared `group` keeps drags
        // same-container-only (replaces dragula's `accepts: target === source`).
        this.sortables = [];

        this.initGroup('coursesContainer', { handle: '.course-drag-handle' }, (container) => {
            const mealTypeId = container.dataset.mealTypeId;
            const courseIds = Array.from(container.querySelectorAll('[data-course-id]'))
                .map(element => element.dataset.courseId);
            this.callLiveAction('reorderCourses', { mealtypeid: mealTypeId, courseids: courseIds });
        });

        this.initGroup('variantsContainer', { handle: '.variant-drag-handle' }, (container) => {
            const courseId = container.dataset.courseId;
            const variantIds = Array.from(container.querySelectorAll('[data-variant-id]'))
                .map(element => element.dataset.variantId);
            this.callLiveAction('reorderVariants', { courseid: courseId, variantids: variantIds });
        });

        this.initGroup('mealsContainer', { draggable: '.meal-badge', direction: 'horizontal' }, (container) => {
            const variantId = container.dataset.variantId;
            const mealIds = Array.from(container.querySelectorAll('[data-variant-meal-id]'))
                .map(element => element.dataset.variantMealId);
            this.callLiveAction('reorderMeals', { variantid: variantId, mealids: mealIds });
        });
    }

    disconnect() {
        (this.sortables || []).forEach(sortable => sortable.destroy());
        this.sortables = [];
    }

    initGroup(targetName, options, onReorder) {
        const containers = this.element.querySelectorAll(`[data-weekly-menu-dragula-target="${targetName}"]`);

        containers.forEach(container => {
            this.sortables.push(Sortable.create(container, {
                ...options,
                ghostClass: 'gu-transit',
                dragClass: 'gu-mirror',
                onEnd: (evt) => onReorder(evt.to),
            }));
        });
    }

    callLiveAction(action, args) {
        // Find the Live Component (parent element)
        const liveComponent = this.element.closest('[data-controller*="live"]');
        if (liveComponent && liveComponent.__component) {
            liveComponent.__component.action(action, args);
        }
    }
}
