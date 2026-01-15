import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['variantId', 'searchInput', 'mealTypeFilter', 'dietFilter', 'mealCard', 'noResults'];

    connect() {
        // Store the variant ID when the modal is opened
        const modal = this.element;
        modal.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            const variantId = button.getAttribute('data-variant-id');
            this.variantIdTarget.value = variantId;
        });
    }

    /**
     * Remove diacritics from a string for accent-insensitive search
     */
    removeDiacritics(str) {
        return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    /**
     * Filter meals based on search input, meal type, and diet
     */
    filter() {
        const searchTerm = this.hasSearchInputTarget
            ? this.removeDiacritics(this.searchInputTarget.value.toLowerCase().trim())
            : '';
        const selectedMealType = this.hasMealTypeFilterTarget
            ? this.mealTypeFilterTarget.value
            : '';
        const selectedDiet = this.hasDietFilterTarget
            ? this.dietFilterTarget.value
            : '';

        let visibleCount = 0;

        this.mealCardTargets.forEach(card => {
            const searchText = this.removeDiacritics(card.dataset.searchText || '');
            const mealType = card.dataset.mealType || '';
            const dietId = card.dataset.dietId || '';

            // Check search match (diacritics-insensitive)
            const matchesSearch = !searchTerm || searchText.includes(searchTerm);

            // Check meal type match
            const matchesMealType = !selectedMealType || mealType === selectedMealType;

            // Check diet match
            const matchesDiet = !selectedDiet || dietId === selectedDiet;

            // Show/hide card based on all filters
            const isVisible = matchesSearch && matchesMealType && matchesDiet;
            card.classList.toggle('d-none', !isVisible);

            if (isVisible) {
                visibleCount++;
            }
        });

        // Show/hide "no results" message
        if (this.hasNoResultsTarget) {
            this.noResultsTarget.classList.toggle('d-none', visibleCount > 0);
        }
    }

    /**
     * Reset all filters to their default state
     */
    resetFilters() {
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.value = '';
        }
        if (this.hasMealTypeFilterTarget) {
            this.mealTypeFilterTarget.value = '';
        }
        if (this.hasDietFilterTarget) {
            this.dietFilterTarget.value = '';
        }

        // Show all meal cards
        this.mealCardTargets.forEach(card => {
            card.classList.remove('d-none');
        });

        // Hide "no results" message
        if (this.hasNoResultsTarget) {
            this.noResultsTarget.classList.add('d-none');
        }
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
            // Use Symfony UX Live Component's action system
            const component = liveComponent.__component;
            if (component) {
                component.action('addMeal', { variantid: variantId, mealid: mealId });
            }
        }

        // Reset filters before closing
        this.resetFilters();

        // Close the modal
        const modalElement = this.element;
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    }
}
