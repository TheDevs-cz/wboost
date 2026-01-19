import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['content'];

    connect() {
        this.element.addEventListener('click', this.handleClick.bind(this));

        // Set initial state - if closed, add animating class to hide overflow
        if (!this.element.open) {
            this.element.classList.add('animating');
        }
    }

    disconnect() {
        this.element.removeEventListener('click', this.handleClick.bind(this));
    }

    handleClick(event) {
        // Only handle clicks on the summary element
        const summary = this.element.querySelector('summary');
        if (!summary.contains(event.target)) return;

        // Don't interfere with buttons/dropdowns inside summary
        if (event.target.closest('button') || event.target.closest('.dropdown-menu')) return;

        if (this.element.open) {
            // Closing
            event.preventDefault();
            this.close();
        } else {
            // Opening - add animating class before it opens
            this.element.classList.add('animating');
            // Remove animating class after animation completes
            this.contentTarget.addEventListener('transitionend', () => {
                this.element.classList.remove('animating');
                // Auto-open single course when opening meal type
                this.autoOpenSingleCourse();
            }, { once: true });
        }
    }

    close() {
        const content = this.contentTarget;

        // Add classes to trigger close animation
        this.element.classList.add('closing');
        this.element.classList.add('animating');

        // Wait for animation to complete
        content.addEventListener('transitionend', () => {
            this.element.classList.remove('closing');
            this.element.open = false;
            // Keep animating class when closed to maintain overflow:hidden
        }, { once: true });
    }

    autoOpenSingleCourse() {
        // Only for meal-type collapsibles
        if (!this.element.classList.contains('collapsible-meal-type')) return;

        // Find all course collapsibles inside
        const courses = this.element.querySelectorAll('details.collapsible-course');

        // If exactly one course, open it
        if (courses.length === 1 && !courses[0].open) {
            courses[0].classList.remove('animating');
            courses[0].open = true;
        }
    }
}
