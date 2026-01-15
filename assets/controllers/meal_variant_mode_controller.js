import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['manualField', 'manualWrapper', 'referenceField', 'referenceWrapper'];

    connect() {
        // Find the mode radio buttons and set initial state
        const modeInputs = this.element.querySelectorAll('input[type="radio"][name$="[mode]"]');
        modeInputs.forEach(input => {
            input.addEventListener('change', () => this.toggle());
        });

        // Set initial visibility
        this.toggle();
    }

    toggle() {
        const modeInputs = this.element.querySelectorAll('input[type="radio"][name$="[mode]"]');
        let selectedMode = 'reference';

        modeInputs.forEach(input => {
            if (input.checked) {
                selectedMode = input.value;
            }
        });

        const isManual = selectedMode === 'manual';

        // Toggle manual wrappers visibility
        this.manualWrapperTargets.forEach(wrapper => {
            wrapper.style.display = isManual ? '' : 'none';
        });

        // Toggle reference wrappers visibility
        this.referenceWrapperTargets.forEach(wrapper => {
            wrapper.style.display = isManual ? 'none' : '';
        });
    }
}
