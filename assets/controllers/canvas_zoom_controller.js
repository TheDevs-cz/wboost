import { Controller } from "@hotwired/stimulus";

/**
 * Visual zoom for the canvas wrapper. Just CSS-transforms the wrapper —
 * the underlying Fabric canvas dimensions don't change.
 */
export default class extends Controller {
    static targets = ["zoomInButton", "zoomOutButton", "scaleDisplay", "canvasContainer"];

    static values = {
        min: { type: Number, default: 0.5 },
        max: { type: Number, default: 1.0 },
        step: { type: Number, default: 0.1 },
    };

    connect() {
        // Scale starts at max (canvas shown at 100%). Compute the real button
        // states immediately — the markup renders both buttons with the
        // `.disabled` class (which also sets `pointer-events: none` in
        // Bootstrap), so without this the zoom-out button could never be
        // clicked to bootstrap itself out of the disabled state.
        this.currentScale = this.maxValue;
        this.updateButtonStates();
    }

    zoomIn() {
        if (this.currentScale < this.maxValue) {
            this.currentScale += this.stepValue;
            this.applyScale();
        }
    }

    zoomOut() {
        if (this.currentScale > this.minValue) {
            this.currentScale -= this.stepValue;
            this.applyScale();
        }
    }

    applyScale() {
        // Ensure scale is within bounds
        this.currentScale = Math.max(this.minValue, Math.min(this.maxValue, this.currentScale));

        // Apply the scale to the canvas container
        this.canvasContainerTarget.style.transform = `scale(${this.currentScale})`;

        const scalePercentage = Math.round(this.currentScale * 100);
        this.scaleDisplayTarget.textContent = `${scalePercentage}%`;

        // Let the floating toolbar re-anchor its chrome to the new zoom scale.
        this.dispatch('changed', { detail: { scale: this.currentScale } });

        this.updateButtonStates();
    }

    updateButtonStates() {
        this._toggleDisabled(this.zoomOutButtonTarget, (this.currentScale - 0.01) <= this.minValue);
        this._toggleDisabled(this.zoomInButtonTarget, (this.currentScale + 0.01) >= this.maxValue);
    }

    _toggleDisabled(button, disabled) {
        button.classList.toggle('disabled', disabled);
        if (disabled) {
            button.setAttribute('disabled', 'disabled');
        } else {
            button.removeAttribute('disabled');
        }
    }
}
