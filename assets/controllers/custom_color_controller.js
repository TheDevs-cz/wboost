import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'colorDisplay'];

    connect() {
        this.updateColor();  // Initial color update on load if needed
    }

    updateColor() {
        let colorValue = this.inputTarget.value.trim();

        // Remove any leading '#' characters
        if (colorValue.startsWith('#')) {
            colorValue = colorValue.substring(1);
        }

        this.colorDisplayTarget.style.backgroundColor = `#${colorValue}`;
    }
}
