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
        this._modalTrigger = null;
        this.element.classList.add("fill-js");

        this._boundReposition = () => this.reposition();
        this._boundKeydown = (event) => this._onKeydown(event);
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
        if (this._spinnerShowTimer) clearTimeout(this._spinnerShowTimer);
    }

    _onKeydown(event) {
        if (event.key === "Escape") {
            this.closePopover();
            this._closeAllModals();
            return;
        }
        if (event.key === "Tab") {
            const modal = this.modalTargets.find((m) => m.classList.contains("is-open"));
            if (modal) this._trapFocus(event, modal);
        }
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
        if (!modal) return;

        this._modalTrigger = event?.currentTarget ?? null;
        modal.classList.add("is-open");

        // Move focus into the dialog (its labelled container is tabindex=-1).
        const dialog = modal.querySelector(".fill-modal__dialog");
        const target = dialog?.querySelector("button, input, select, a[href]") || dialog;
        if (target) target.focus();
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
        this._restoreModalFocus();
    }

    /** Click on the modal backdrop (but not its dialog) closes it. */
    closeModalBackdrop(event) {
        if (event.target === event.currentTarget) {
            event.currentTarget.classList.remove("is-open");
            this._restoreModalFocus();
        }
    }

    _closeAllModals() {
        let any = false;
        this.modalTargets.forEach((m) => {
            if (m.classList.contains("is-open")) any = true;
            m.classList.remove("is-open");
        });
        if (any) this._restoreModalFocus();
    }

    _restoreModalFocus() {
        if (this._modalTrigger && typeof this._modalTrigger.focus === "function") {
            this._modalTrigger.focus();
        }
        this._modalTrigger = null;
    }

    /** Keep Tab focus inside the open modal dialog. */
    _trapFocus(event, modal) {
        const dialog = modal.querySelector(".fill-modal__dialog") || modal;
        const focusable = Array.from(
            dialog.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ),
        ).filter((el) => el.offsetParent !== null);
        if (focusable.length === 0) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        const active = document.activeElement;

        if (event.shiftKey && (active === first || !dialog.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
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
        const label = hidden ? "Zobrazit prvek" : "Schovat prvek";
        this.element.querySelectorAll(`[data-hide-toggle="${inputId}"]`).forEach((btn) => {
            btn.classList.toggle("is-active", hidden);
            btn.setAttribute("aria-pressed", hidden ? "true" : "false");
            btn.setAttribute("title", label);
            btn.setAttribute("aria-label", label);
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
        this._updateCounter(inputId, event.target);
        // The preview re-renders after the debounce — show the spinner once the
        // user pauses (not on every keystroke, which would flash the veil).
        this._scheduleSpinner();
    }

    _updateCounter(inputId, field) {
        const counter = this.element.querySelector(`[data-fill-counter="${inputId}"]`);
        if (!counter) return;
        const max = field.getAttribute("maxlength");
        if (!max) return;
        counter.textContent = `${field.value.length}/${max}`;
    }

    // --- Live-preview spinner -----------------------------------------------

    /** Show after a short idle so fast typing never flashes the veil per key. */
    _scheduleSpinner() {
        if (this._spinnerShowTimer) clearTimeout(this._spinnerShowTimer);
        this._spinnerShowTimer = setTimeout(() => this._showSpinner(), 300);
    }

    _showSpinner() {
        if (this._spinnerShowTimer) {
            clearTimeout(this._spinnerShowTimer);
            this._spinnerShowTimer = null;
        }
        if (!this.hasSpinnerTarget) return;
        this.spinnerTarget.classList.add("is-active");
        // Safety net: never let the spinner spin forever if a render signal is
        // missed (e.g. an unchanged data-src).
        if (this._spinnerTimeout) clearTimeout(this._spinnerTimeout);
        this._spinnerTimeout = setTimeout(() => this._hideSpinner(), 20000);
    }

    _hideSpinner() {
        if (this._spinnerShowTimer) {
            clearTimeout(this._spinnerShowTimer);
            this._spinnerShowTimer = null;
        }
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
        const previewWidth = this.hasPreviewTarget ? this.previewTarget.getBoundingClientRect().width : 0;

        this.boxTargets.forEach((box) => {
            const frame = this._frameOf(box);
            if (!frame) {
                box.style.display = "none";
                return;
            }
            box.style.display = "";
            const left = frame.x * scale;
            const top = frame.y * scale;
            const right = (frame.x + frame.width) * scale;
            box.style.left = `${left}px`;
            box.style.top = `${top}px`;
            box.style.width = `${frame.width * scale}px`;
            box.style.height = `${frame.height * scale}px`;

            // Edge-aware icon cluster: keep it from hanging off the top or right
            // of the preview (where it would detach from the artwork / clip).
            box.classList.toggle("fill-box--tools-inside", top < 30);
            box.classList.toggle("fill-box--tools-left", previewWidth > 0 && previewWidth - right < 60);
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

        // Flip above the box if it would overflow the viewport bottom and there
        // is room above; otherwise clamp it within the visible area.
        if (pRect.bottom > window.innerHeight - margin) {
            const aboveTop = boxRect.top - stageRect.top - pRect.height - 8;
            if (stageRect.top + aboveTop > margin) {
                popover.style.top = `${aboveTop}px`;
            } else {
                const maxTop = window.innerHeight - margin - pRect.height - stageRect.top;
                popover.style.top = `${Math.max(margin - stageRect.top, maxTop)}px`;
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
