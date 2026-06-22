import { Controller } from "@hotwired/stimulus";

/**
 * Click-into-preview placeholder overlay for the user-fill / export page.
 *
 * Every text + image placeholder is drawn at its designer frame (canvas px,
 * scaled to the displayed preview: `scale = previewWidth / canvasWidth`) with an
 * always-visible icon cluster:
 *  - pencil → text: opens the floating text popover; image: opens the gallery modal;
 *  - eye    → toggles "hide this element" (only when the slot is hidable).
 * The highlight toggle controls ONLY the dashed border of the boxes — the icons
 * stay visible either way.
 *
 * Editing writes through the Live region without disturbing the overlay:
 *  - text value → the popover input mirrors into a visually-hidden Live-bound
 *    field (`data-text-mirror`) via syncText (dispatch input → debounced backdrop
 *    re-render + form POST value);
 *  - hide → toggleHide flips the controlled checkbox (`data-hide-mirror` for text,
 *    `data-image-hide` for image) and dispatches change so Live (text) or the
 *    `variant-image-fill` controller (image) reacts.
 *
 * The overlay + popovers + modals live in a `data-live-ignore` subtree so a Live
 * re-render never wipes open state. Progressive enhancement: without `.fill-js`
 * (added on connect) the popovers are a plain stacked editable list.
 *
 * Enter inside a text field must NOT submit the form (that would download the
 * PNG); only the Export button downloads. blockEnter handles that.
 */
export default class extends Controller {
    static targets = ["stage", "preview", "previewSource", "box", "popover", "modal", "spinner"];
    static values = {
        canvasWidth: Number,
    };

    connect() {
        this._openId = null;
        this.element.classList.add("fill-js");

        this._boundReposition = () => this.reposition();
        this._boundKeydown = (event) => {
            if (event.key === "Escape") {
                this.closePopover();
                this._closeAllModals();
            }
        };
        this._boundOutside = (event) => this._maybeCloseOnOutside(event);

        window.addEventListener("resize", this._boundReposition);
        window.addEventListener("scroll", this._boundReposition, true);
        document.addEventListener("keydown", this._boundKeydown);
        document.addEventListener("click", this._boundOutside);

        // Hide the live-preview spinner once the next server render lands. The
        // source span's data-src is updated by Live on each re-render — text-only
        // branch via previewSource, image branch via the backdrop span.
        if (this.hasPreviewSourceTarget) {
            this._applyPreviewSrc();
            this._previewObserver = new MutationObserver(() => {
                this._applyPreviewSrc();
                this._hideSpinner();
            });
            this._previewObserver.observe(this.previewSourceTarget, {
                attributes: true,
                attributeFilter: ["data-src"],
            });
        }

        const backdrop = document.getElementById("variant-backdrop-source");
        if (backdrop) {
            this._backdropObserver = new MutationObserver(() => this._hideSpinner());
            this._backdropObserver.observe(backdrop, {
                attributes: true,
                attributeFilter: ["data-src"],
            });
        }

        if (this.hasPreviewTarget && "ResizeObserver" in window) {
            this._resizeObserver = new ResizeObserver(() => this.reposition());
            this._resizeObserver.observe(this.previewTarget);
        }
        if (this.hasPreviewTarget && this.previewTarget.tagName === "IMG" && !this.previewTarget.complete) {
            this.previewTarget.addEventListener("load", this._boundReposition);
        }

        this.reposition();
    }

    disconnect() {
        window.removeEventListener("resize", this._boundReposition);
        window.removeEventListener("scroll", this._boundReposition, true);
        document.removeEventListener("keydown", this._boundKeydown);
        document.removeEventListener("click", this._boundOutside);
        if (this._resizeObserver) this._resizeObserver.disconnect();
        if (this._previewObserver) this._previewObserver.disconnect();
        if (this._backdropObserver) this._backdropObserver.disconnect();
        if (this._spinnerTimeout) clearTimeout(this._spinnerTimeout);
    }

    // --- Highlight toggle (borders only) ------------------------------------

    toggleHighlight(event) {
        this.element.classList.toggle("fill-highlight-on", event.target.checked);
    }

    // --- Text popover open / close ------------------------------------------

    openPopover(event) {
        if (event) event.stopPropagation();
        const inputId = event.params?.inputid;
        if (!inputId) return;

        this._closeAllModals();

        if (this._openId === inputId) {
            this.closePopover();
            return;
        }

        this.closePopover();

        const popover = this._popoverFor(inputId);
        if (!popover) return;

        this._openId = inputId;
        popover.classList.add("is-open");
        this._positionPopover(popover, this._boxFor(inputId));

        const field = popover.querySelector('input[type="text"], textarea');
        if (field) field.focus();
    }

    closePopover() {
        if (this._openId === null) return;
        const popover = this._popoverFor(this._openId);
        if (popover) popover.classList.remove("is-open");
        this._openId = null;
    }

    /** Explicit close button inside a popover. */
    close(event) {
        if (event) event.preventDefault();
        this.closePopover();
    }

    _maybeCloseOnOutside(event) {
        if (this._openId === null) return;
        const popover = this._popoverFor(this._openId);
        const box = this._boxFor(this._openId);
        if (popover && popover.contains(event.target)) return;
        if (box && box.contains(event.target)) return;
        this.closePopover();
    }

    // --- Image gallery modal ------------------------------------------------

    openImageModal(event) {
        if (event) event.stopPropagation();
        const inputId = event.params?.inputid;
        if (!inputId) return;
        this.closePopover();
        const modal = this._modalFor(inputId);
        if (modal) modal.classList.add("is-open");
    }

    /** Close button inside a modal (or a thumbnail pick that should dismiss it). */
    closeModal(event) {
        if (event) event.preventDefault();
        const modal = event?.currentTarget?.closest(".fill-modal");
        if (modal) {
            modal.classList.remove("is-open");
        } else {
            this._closeAllModals();
        }
    }

    /** Click on the modal backdrop (but not its dialog) closes it. */
    closeModalBackdrop(event) {
        if (event.target === event.currentTarget) {
            event.currentTarget.classList.remove("is-open");
        }
    }

    _closeAllModals() {
        this.modalTargets.forEach((m) => m.classList.remove("is-open"));
    }

    // --- Hide toggle (separate eye icon, text + image) -----------------------

    toggleHide(event) {
        if (event) event.stopPropagation();
        const inputId = event.params?.inputid;
        if (!inputId) return;

        // Text hide is a server re-render (show the spinner); image hide is a
        // client-side Fabric op (instant, no spinner).
        const textMirror = this.element.querySelector(`[data-hide-mirror="${inputId}"]`);
        const checkbox = textMirror || this.element.querySelector(`[data-image-hide="${inputId}"]`);
        if (!checkbox) return;

        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event("change", { bubbles: true }));
        this._reflectHide(inputId, checkbox.checked);

        if (textMirror) this._showSpinner();
    }

    _reflectHide(inputId, hidden) {
        this.element.querySelectorAll(`[data-hide-toggle="${inputId}"]`).forEach((btn) => {
            btn.classList.toggle("is-active", hidden);
            btn.setAttribute("aria-pressed", hidden ? "true" : "false");
            btn.setAttribute("title", hidden ? "Zobrazit prvek" : "Schovat prvek");
            const icon = btn.querySelector("i");
            if (icon) icon.className = hidden ? "mdi mdi-eye-off-outline" : "mdi mdi-eye-outline";
        });
    }

    // --- Text mirroring + Enter guard ----------------------------------------

    syncText(event) {
        const inputId = event.params?.inputid;
        if (!inputId) return;
        const mirror = this.element.querySelector(`[data-text-mirror="${inputId}"]`);
        if (!mirror) return;
        mirror.value = event.target.value;
        mirror.dispatchEvent(new Event("input", { bubbles: true }));
        // The preview will re-render after the debounce — signal it immediately.
        this._showSpinner();
    }

    // --- Live-preview spinner -----------------------------------------------

    _showSpinner() {
        if (!this.hasSpinnerTarget) return;
        this.spinnerTarget.classList.add("is-active");
        // Safety net: never let the spinner spin forever if a render signal is
        // missed (e.g. an unchanged data-src).
        if (this._spinnerTimeout) clearTimeout(this._spinnerTimeout);
        this._spinnerTimeout = setTimeout(() => this._hideSpinner(), 20000);
    }

    _hideSpinner() {
        if (this._spinnerTimeout) {
            clearTimeout(this._spinnerTimeout);
            this._spinnerTimeout = null;
        }
        if (this.hasSpinnerTarget) this.spinnerTarget.classList.remove("is-active");
    }

    /** Enter in a fill field must not submit the form (only Export downloads). */
    blockEnter(event) {
        if (event.key === "Enter") {
            event.preventDefault();
            this.closePopover();
        }
    }

    // --- Positioning ---------------------------------------------------------

    reposition() {
        const scale = this._scale();

        this.boxTargets.forEach((box) => {
            const frame = this._frameOf(box);
            if (!frame) {
                box.style.display = "none";
                return;
            }
            box.style.display = "";
            box.style.left = `${frame.x * scale}px`;
            box.style.top = `${frame.y * scale}px`;
            box.style.width = `${frame.width * scale}px`;
            box.style.height = `${frame.height * scale}px`;
        });

        if (this._openId !== null) {
            const popover = this._popoverFor(this._openId);
            if (popover) this._positionPopover(popover, this._boxFor(this._openId));
        }
    }

    _scale() {
        if (!this.hasPreviewTarget || !this.canvasWidthValue) return 1;
        const rect = this.previewTarget.getBoundingClientRect();
        return rect.width > 0 ? rect.width / this.canvasWidthValue : 1;
    }

    _positionPopover(popover, box) {
        if (!box) return;
        const stage = this.hasStageTarget ? this.stageTarget : this.element;
        const stageRect = stage.getBoundingClientRect();
        const boxRect = box.getBoundingClientRect();

        let top = boxRect.bottom - stageRect.top + 8;
        let left = boxRect.left - stageRect.left;

        popover.style.top = `${top}px`;
        popover.style.left = `${left}px`;

        const pRect = popover.getBoundingClientRect();
        const margin = 8;

        const overflowRight = pRect.right - (window.innerWidth - margin);
        if (overflowRight > 0) {
            left = Math.max(0, left - overflowRight);
            popover.style.left = `${left}px`;
        }

        if (pRect.bottom > window.innerHeight - margin) {
            const aboveTop = boxRect.top - stageRect.top - pRect.height - 8;
            if (stageRect.top + aboveTop > margin) {
                popover.style.top = `${aboveTop}px`;
            }
        }
    }

    _applyPreviewSrc() {
        if (!this.hasPreviewTarget || this.previewTarget.tagName !== "IMG") return;
        const src = this.previewSourceTarget.getAttribute("data-src");
        if (src && this.previewTarget.getAttribute("src") !== src) {
            this.previewTarget.addEventListener("load", this._boundReposition, { once: true });
            this.previewTarget.setAttribute("src", src);
        }
    }

    // --- helpers -------------------------------------------------------------

    _frameOf(box) {
        const x = parseFloat(box.dataset.frameX);
        const y = parseFloat(box.dataset.frameY);
        const w = parseFloat(box.dataset.frameWidth);
        const h = parseFloat(box.dataset.frameHeight);
        if ([x, y, w, h].some((v) => Number.isNaN(v))) return null;
        return { x, y, width: w, height: h };
    }

    _boxFor(inputId) {
        return this.boxTargets.find((b) => b.dataset.inputid === inputId) || null;
    }

    _popoverFor(inputId) {
        return this.popoverTargets.find((p) => p.dataset.inputid === inputId) || null;
    }

    _modalFor(inputId) {
        return this.modalTargets.find((m) => m.dataset.imageModal === inputId) || null;
    }
}
