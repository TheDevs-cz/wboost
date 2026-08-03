import { Controller } from "@hotwired/stimulus";

/**
 * Visual zoom for the canvas wrapper. Just CSS-transforms the wrapper —
 * the underlying Fabric canvas dimensions don't change. The wrapper's
 * layout box is shrunk with negative margins to match the visual size,
 * so the page never reserves scroll space for the unscaled canvas.
 *
 * On connect the scale auto-fits the canvas into the visible area (width
 * AND height, capped at 100 %) and keeps re-fitting on window resize
 * until the user zooms manually — a print-sized canvas (A4 = 2480×3508)
 * starts fully on screen instead of at an unusable 100 %.
 */
export default class extends Controller {
    static targets = ["zoomInButton", "zoomOutButton", "scaleDisplay", "canvasContainer"];

    static values = {
        min: { type: Number, default: 0.1 },
        max: { type: Number, default: 1.0 },
        step: { type: Number, default: 0.1 },
    };

    connect() {
        // Compute the real button states immediately — the markup renders
        // both buttons with the `.disabled` class (which also sets
        // `pointer-events: none` in Bootstrap), so without this the zoom-out
        // button could never be clicked to bootstrap itself out of the
        // disabled state.
        this.currentScale = this.maxValue;
        this.userZoomed = false;
        this.updateButtonStates();

        this.onResize = () => this.fitToScreen();
        window.addEventListener('resize', this.onResize);
        this.fitToScreen();
    }

    disconnect() {
        window.removeEventListener('resize', this.onResize);
    }

    zoomIn() {
        this.userZoomed = true;
        if (this.currentScale < this.maxValue) {
            this.currentScale += this.stepValue;
            this.applyScale();
        }
    }

    zoomOut() {
        this.userZoomed = true;
        if (this.currentScale > this.minValue) {
            this.currentScale -= this.stepValue;
            this.applyScale();
        }
    }

    /** Auto-fit the whole canvas into the visible area (until the user
     *  zooms manually). Also runs on canvas:loaded — the group editor swaps
     *  canvas dimensions in place on variant switch, so the layout-box
     *  margins must track the live size even when the scale is kept. */
    fitToScreen() {
        this.compensateLayoutBox();

        if (this.userZoomed) {
            return;
        }

        const fit = this.fitScale();

        if (fit !== null && fit !== this.currentScale) {
            this.currentScale = fit;
            this.applyScale();
        }
    }

    /** Scale at which the whole canvas fits the visible area — width and
     *  height both, capped at 100 %. Floor (not round): erring a pixel
     *  small never creates a scrollbar. */
    fitScale() {
        const { width, height } = this.canvasLayoutSize();
        const stage = this.canvasContainerTarget.parentElement;

        if (!stage || width <= 0 || height <= 0) {
            return null;
        }

        // The ruler gutter (.has-rulers padding) may not be applied yet at
        // connect time, so reserve it unconditionally.
        const availableWidth = stage.clientWidth - 24;
        // Visible height below the sticky toolbar — the content above the
        // stage scrolls away, the toolbar stays.
        const header = document.querySelector('[data-editor-header]');
        const availableHeight = Math.max(220, window.innerHeight - (header ? header.offsetHeight : 0) - 40);

        if (availableWidth <= 0) {
            return null;
        }

        const fit = Math.min(availableWidth / width, availableHeight / height);

        return Math.max(this.minValue, Math.min(this.maxValue, Math.floor(fit * 100) / 100));
    }

    applyScale() {
        // Ensure scale is within bounds
        this.currentScale = Math.max(this.minValue, Math.min(this.maxValue, this.currentScale));

        // Apply the scale to the canvas container
        this.canvasContainerTarget.style.transform = `scale(${this.currentScale})`;
        this.compensateLayoutBox();

        const scalePercentage = Math.round(this.currentScale * 100);
        this.scaleDisplayTarget.textContent = `${scalePercentage}%`;

        // Let the floating toolbar re-anchor its chrome to the new zoom scale.
        this.dispatch('changed', { detail: { scale: this.currentScale } });

        this.updateButtonStates();
    }

    /** transform: scale() is visual only — pull the layout box in to match
     *  so the page doesn't keep scrollbars for the unscaled canvas size. */
    compensateLayoutBox() {
        const { width, height } = this.canvasLayoutSize();

        if (width <= 0 || height <= 0) {
            return;
        }

        const factor = this.currentScale - 1;
        this.canvasContainerTarget.style.marginRight = `${width * factor}px`;
        this.canvasContainerTarget.style.marginBottom = `${height * factor}px`;
    }

    /** The canvas design size in layout px. Measured off the wrapper
     *  (offsetWidth ignores the transform), NOT the canvas element's
     *  width/height attributes — Fabric's retina scaling multiplies those
     *  by devicePixelRatio. */
    canvasLayoutSize() {
        return {
            width: this.canvasContainerTarget.offsetWidth,
            height: this.canvasContainerTarget.offsetHeight,
        };
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
