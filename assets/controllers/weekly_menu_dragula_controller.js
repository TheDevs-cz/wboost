import { Controller } from '@hotwired/stimulus';
import dragula from 'dragula';

export default class extends Controller {
    connect() {
        this.initCourseDragula();
        this.initVariantDragula();
        this.initMealDragula();
    }

    disconnect() {
        if (this.courseDrake) {
            this.courseDrake.destroy();
        }
        if (this.variantDrake) {
            this.variantDrake.destroy();
        }
        if (this.mealDrake) {
            this.mealDrake.destroy();
        }
    }

    initCourseDragula() {
        const containers = this.element.querySelectorAll('[data-weekly-menu-dragula-target="coursesContainer"]');
        if (containers.length === 0) return;

        this.courseDrake = dragula(Array.from(containers), {
            moves: (el, source, handle) => handle.classList.contains('course-drag-handle'),
            accepts: (el, target, source) => target === source
        });

        this.courseDrake.on('drop', (el, target) => {
            if (!target) return;
            const mealTypeId = target.dataset.mealTypeId;
            const courseIds = Array.from(target.querySelectorAll('[data-course-id]'))
                .map(element => element.dataset.courseId);
            this.callLiveAction('reorderCourses', { mealtypeid: mealTypeId, courseids: courseIds });
        });
    }

    initVariantDragula() {
        const containers = this.element.querySelectorAll('[data-weekly-menu-dragula-target="variantsContainer"]');
        if (containers.length === 0) return;

        this.variantDrake = dragula(Array.from(containers), {
            moves: (el, source, handle) => handle.classList.contains('variant-drag-handle'),
            accepts: (el, target, source) => target === source
        });

        this.variantDrake.on('drop', (el, target) => {
            if (!target) return;
            const courseId = target.dataset.courseId;
            const variantIds = Array.from(target.querySelectorAll('[data-variant-id]'))
                .map(element => element.dataset.variantId);
            this.callLiveAction('reorderVariants', { courseid: courseId, variantids: variantIds });
        });
    }

    initMealDragula() {
        const containers = this.element.querySelectorAll('[data-weekly-menu-dragula-target="mealsContainer"]');
        if (containers.length === 0) return;

        this.mealDrake = dragula(Array.from(containers), {
            moves: (el, source, handle) => el.classList.contains('meal-badge'),
            accepts: (el, target, source) => target === source,
            direction: 'horizontal'
        });

        this.mealDrake.on('drop', (el, target) => {
            if (!target) return;
            const variantId = target.dataset.variantId;
            const mealIds = Array.from(target.querySelectorAll('[data-variant-meal-id]'))
                .map(element => element.dataset.variantMealId);
            this.callLiveAction('reorderMeals', { variantid: variantId, mealids: mealIds });
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
