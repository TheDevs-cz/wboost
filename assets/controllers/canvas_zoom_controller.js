import { Controller } from "@hotwired/stimulus";

/** Below this the shell would be uselessly cramped — the page scrolls instead. */
const MIN_SHELL_HEIGHT = 320;

/**
 * Visual zoom for the canvas wrapper. Just CSS-transforms the wrapper —
 * the underlying Fabric canvas dimensions don't change. The wrapper's
 * layout box is shrunk with negative margins to match the visual size,
 * so the viewport never reserves scroll space for the unscaled canvas.
 *
 * Also owns the editor's APP-SHELL height (lg+): the controller root is
 * pinned to the bottom of the window, so the page itself does not scroll and
 * the left panel + `.canvas-viewport` scroll independently (see the
 * `.editor-shell` rules in app.css). One shell height is measured rather than
 * computed as calc(100vh - Npx) because the title/breadcrumb block above wraps
 * to arbitrary heights and the theme adds its own padding below — a corrective
 * pass folds whatever is left over in, so no second (page) scrollbar survives.
 *
 * On connect the scale auto-fits the canvas into the visible area (width
 * AND height, capped at 100 %) and keeps re-fitting on window resize
 * until the user zooms manually — a print-sized canvas (A4 = 2480×3508)
 * starts fully on screen instead of at an unusable 100 %.
 */
export default class extends Controller {
    static targets = ["zoomInButton", "zoomOutButton", "scaleDisplay", "canvasContainer", "viewport"];

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
        this.onPageScroll = () => this.resetStrayPageScroll();
        window.addEventListener('scroll', this.onPageScroll, { passive: true });
        this.fitToScreen();

    }

    disconnect() {
        window.removeEventListener('resize', this.onResize);
        window.removeEventListener('scroll', this.onPageScroll);
        document.body.classList.remove('editor-shell-page');
    }

    /** While the shell is pinned the page has nothing to scroll — so any
     *  document scroll offset is a stray one, and `overflow: hidden` means the
     *  user cannot scroll back out of it. (Programmatic scrolls still work on
     *  an overflow-hidden body: focusing an element positioned below the fold
     *  — Fabric's hidden textarea used to be exactly that — strands the editor
     *  on a blank band until reload.) Snap it back. */
    resetStrayPageScroll() {
        if (!document.body.classList.contains('editor-shell-page')) {
            return;
        }

        const scroller = document.scrollingElement || document.documentElement;

        if (scroller.scrollTop !== 0) {
            scroller.scrollTop = 0;
        }
        if (scroller.scrollLeft !== 0) {
            scroller.scrollLeft = 0;
        }
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
        this.sizeShell();
        this.resetStrayPageScroll();
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
        const available = this.availableSize();

        if (!available || width <= 0 || height <= 0) {
            return null;
        }

        // The ruler gutter (.has-rulers padding) may not be applied yet at
        // connect time, so reserve it unconditionally.
        const availableWidth = available.width - 24;
        const availableHeight = available.height - 24;

        if (availableWidth <= 0 || availableHeight <= 0) {
            return null;
        }

        const fit = Math.min(availableWidth / width, availableHeight / height);

        return Math.max(this.minValue, Math.min(this.maxValue, Math.floor(fit * 100) / 100));
    }

    /** Room the canvas may occupy. In shell mode that is the viewport's own
     *  client box (clientWidth/Height exclude its scrollbars, so a fit never
     *  leaves one behind); stacked below lg it is the stage width and the
     *  window band under the sticky header. */
    availableSize() {
        if (this.shellMode() && this.hasViewportTarget) {
            const vp = this.viewportTarget;
            return vp.clientWidth > 0 ? { width: vp.clientWidth, height: vp.clientHeight } : null;
        }

        const stage = this.canvasContainerTarget.parentElement;
        if (!stage || stage.clientWidth <= 0) {
            return null;
        }
        const header = document.querySelector('[data-editor-header]');

        return {
            width: stage.clientWidth,
            height: Math.max(244, window.innerHeight - (header ? header.offsetHeight : 0) - 40),
        };
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

    /** The app-shell layout only applies where the panel and stage columns sit
     *  side by side (Bootstrap's lg breakpoint); stacked below that, the page
     *  scrolls as usual. */
    shellMode() {
        return this.element.classList.contains('editor-shell')
            && window.matchMedia('(min-width: 992px)').matches;
    }

    /** Pin the editor root to the bottom of the window so the PAGE has nothing
     *  to scroll — panning then happens inside .canvas-viewport and the left
     *  panel + toolbar can't be dragged along. */
    sizeShell() {
        const shell = this.element;

        if (!this.shellMode()) {
            shell.style.height = '';
            document.body.classList.remove('editor-shell-page');
            return;
        }

        // Document-relative, so the measurement doesn't depend on where the
        // page happens to be scrolled when this runs.
        const top = shell.getBoundingClientRect().top + window.scrollY;
        const available = window.innerHeight - top - 8;

        shell.style.height = `${Math.max(MIN_SHELL_HEIGHT, available)}px`;

        // The page is pinned rather than measured: whatever a theme or the dev
        // debug toolbar leaves below the shell can't produce a second scrollbar
        // (deriving the height from document.scrollHeight is unusable — the
        // toolbar appends a whole exception page down there when it errors).
        // Released on a window too short for the shell, so a cramped screen
        // scrolls instead of hiding the bottom of the editor for good.
        document.body.classList.toggle('editor-shell-page', available >= MIN_SHELL_HEIGHT);
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
