import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'hiddenInput',
        'searchInput',
        'mealTypeFilter',
        'dietFilter',
        'mealCard',
        'noResults',
        'selectedPreview',
        'emptyPreview',
        'clearButton'
    ];

    connect() {
        // Set initial state based on hidden input value
        this.updateSelectionState();
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
            const dietIds = (card.dataset.dietIds || '').split(',').filter(Boolean);

            // Check search match (diacritics-insensitive)
            const matchesSearch = !searchTerm || searchText.includes(searchTerm);

            // Check meal type match
            const matchesMealType = !selectedMealType || mealType === selectedMealType;

            // Check diet match
            const matchesDiet = !selectedDiet || dietIds.includes(selectedDiet);

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
     * Handle meal card selection
     */
    selectMeal(event) {
        const card = event.currentTarget;
        const mealId = card.dataset.mealId;
        const currentValue = this.hiddenInputTarget.value;

        // If clicking the same card, deselect it
        if (currentValue === mealId) {
            this.hiddenInputTarget.value = '';
        } else {
            this.hiddenInputTarget.value = mealId;
        }

        this.updateSelectionState();
    }

    /**
     * Clear the current selection
     */
    clearSelection() {
        this.hiddenInputTarget.value = '';
        this.updateSelectionState();
    }

    /**
     * Update visual state based on current selection
     */
    updateSelectionState() {
        const selectedMealId = this.hiddenInputTarget.value;

        // Update card selection states
        this.mealCardTargets.forEach(card => {
            const isSelected = card.dataset.mealId === selectedMealId;
            card.classList.toggle('selected', isSelected);
        });

        // Update clear button visibility
        if (this.hasClearButtonTarget) {
            this.clearButtonTarget.classList.toggle('d-none', !selectedMealId);
        }

        // Update preview area
        if (this.hasSelectedPreviewTarget && this.hasEmptyPreviewTarget) {
            if (selectedMealId) {
                // Find the selected card and clone its content for preview
                const selectedCard = this.mealCardTargets.find(card => card.dataset.mealId === selectedMealId);
                if (selectedCard) {
                    // Clone the card content for preview
                    this.selectedPreviewTarget.innerHTML = selectedCard.outerHTML;
                    // Make the preview card show clear button instead of selection behavior
                    const previewCard = this.selectedPreviewTarget.querySelector('.meal-selector-item');
                    if (previewCard) {
                        previewCard.classList.add('preview-card');
                        previewCard.classList.remove('d-none');
                        previewCard.classList.remove('selected');
                        previewCard.removeAttribute('data-action');
                        previewCard.removeAttribute('data-reference-meal-selector-target');
                    }
                    this.selectedPreviewTarget.classList.remove('d-none');
                    this.emptyPreviewTarget.classList.add('d-none');
                }
            } else {
                this.selectedPreviewTarget.classList.add('d-none');
                this.selectedPreviewTarget.innerHTML = '';
                this.emptyPreviewTarget.classList.remove('d-none');
            }
        }
    }
}
