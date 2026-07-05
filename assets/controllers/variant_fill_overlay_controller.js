import { Controller } from "@hotwired/stimulus";
import { Textbox, cache } from "fabric";

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
 *
 * CONTAINER REFLOW (live-tracking boxes). Text placeholders grouped into a
 * container by the designer reflow vertically at render time — the server PNG
 * moves them. The overlay mirrors that: it measures each filled text's wrapped
 * height with an offscreen Fabric Textbox (same Fabric build + break-word
 * patch + fonts as the export render) and runs the shared
 * window.WBoostContainerLayout algorithm over the designed frames from
 * `textLayoutValue`, so the boxes/pencils track exactly where the render puts
 * the text. When a container's content can't fit its max height the export
 * would be rejected (API contract) — the overlay shows an inline error and
 * disables the Export button instead of letting the POST produce a broken PNG.
 */
export default class extends Controller {
    static targets = ["stage", "preview", "previewSource", "box", "popover", "modal", "spinner", "zoomLabel", "exportButton", "overflowAlert"];
    static values = {
        canvasWidth: Number,
        // { inputs: { <inputId>: {frame, style, locked, uppercase, maxLength, hidable} },
        //   containers: [ {id, maxHeight, memberInputIds} ] }
        textLayout: Object,
        fonts: Array,
    };

    connect() {
        this._openId = null;
        this._modalTrigger = null;
        this._zoom = 1;
        this._userZoomed = false;
        this.element.classList.add("fill-js");

        // Wrap-parity with the export render (see class docblock).
        if (window.WBoostFabricBreakWord) {
            window.WBoostFabricBreakWord.enable(Textbox);
        }
        this._computedFrames = {};
        this._measureBoxes = new Map();
        this._loadFontsThenLayout();

        this._boundReposition = () => this.reposition();
        this._boundFit = () => this._fitToScreen();
        this._boundKeydown = (event) => this._onKeydown(event);
        this._boundOutside = (event) => this._maybeCloseOnOutside(event);

        window.addEventListener("resize", this._boundFit);
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
            this._resizeObserver = new ResizeObserver(() => this._fitToScreen());
            this._resizeObserver.observe(this.previewTarget);
        }
        if (this.hasPreviewTarget && this.previewTarget.tagName === "IMG" && !this.previewTarget.complete) {
            this.previewTarget.addEventListener("load", this._boundFit);
        }

        this._fitToScreen();
    }

    disconnect() {
        window.removeEventListener("resize", this._boundFit);
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

    // --- Zoom (whole preview) ------------------------------------------------
    // Visual CSS scale on the stage: the preview + overlay boxes + popovers all
    // scale together, so they stay aligned with no re-measuring. reposition()
    // computes the box scale from the UNSCALED width (divides by this._zoom), so
    // the boxes are laid out in unscaled coords and the transform scales them.
    //
    // The initial zoom is auto-fit so the canvas WIDTH fits the screen (crucial
    // on mobile — no horizontal scrolling); the height is free to scroll. We keep
    // re-fitting on load/resize until the user zooms manually (_userZoomed); after
    // that we leave it alone.

    zoomIn() {
        this._applyZoom((this._zoom || 1) + 0.25);
    }

    zoomOut() {
        this._applyZoom((this._zoom || 1) - 0.25);
    }

    zoomReset() {
        this._applyZoom(1);
    }

    _applyZoom(z) {
        this._userZoomed = true;
        // Low floor so a tall canvas can be zoomed out far enough to see all of it
        // (the old hard 50 % floor was the "can't go below 50 %" complaint).
        this._zoom = Math.min(3, Math.max(0.1, Math.round(z * 100) / 100));
        this._updateZoomLabel();
        this.reposition();
    }

    /** Zoom at which the canvas WIDTH fits the viewport (capped at 100 %); the
     *  height is free to scroll. Measures the preview's on-screen width so it
     *  works no matter which branch drew it (img / Fabric canvas) and regardless
     *  of any intrinsic sizing that would otherwise overflow horizontally. */
    _fitZoom() {
        if (!this.hasPreviewTarget || !this.hasStageTarget) return this._zoom || 1;
        const container = this.stageTarget.parentElement;
        const availW = container ? container.clientWidth : window.innerWidth;
        const rect = this.previewTarget.getBoundingClientRect();
        if (rect.width <= 0 || availW <= 0) return this._zoom || 1;

        // rect.width already reflects the current zoom, so rescale it to availW.
        const z = (this._zoom || 1) * (availW / rect.width);
        return Math.min(1, Math.max(0.1, Math.round(z * 100) / 100));
    }

    /** Set the auto zoom so the canvas width fits the screen (until user zooms). */
    _fitToScreen() {
        if (!this._userZoomed) {
            this._zoom = this._fitZoom();
            this._updateZoomLabel();
        }
        this.reposition();
    }

    _updateZoomLabel() {
        if (this.hasZoomLabelTarget) {
            this.zoomLabelTarget.textContent = `${Math.round((this._zoom || 1) * 100)} %`;
        }
    }

    /** Apply the CSS transform + reserve scroll space (margins) for the zoom. */
    _updateZoomBox() {
        if (!this.hasStageTarget) return;
        const z = this._zoom || 1;
        const stage = this.stageTarget;
        stage.style.transformOrigin = "top left";
        stage.style.transform = z === 1 ? "" : `scale(${z})`;
        // Expose the zoom so the chrome (icon clusters + popover) can counter-scale
        // itself back to its default size — only the artwork/boxes scale visually.
        stage.style.setProperty("--fill-zoom", z);
        // offsetWidth/Height are unaffected by transform — the true unscaled size.
        const w = stage.offsetWidth;
        const h = stage.offsetHeight;
        stage.style.marginRight = z === 1 ? "" : `${w * (z - 1)}px`;
        stage.style.marginBottom = z === 1 ? "" : `${h * (z - 1)}px`;
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

        // Grow the textarea to fit its current value BEFORE positioning — the
        // popover's height feeds the above/below flip decision.
        const field = popover.querySelector('input[type="text"], textarea');
        if (field) this._autoGrow(field);

        this._positionPopover(popover, this._boxFor(inputId));

        if (field) field.focus();
    }

    /** Resize an auto-growing textarea to fit its content (height = scrollHeight).
     *  Layout-based (scrollHeight ignores the stage's CSS zoom transform), so it
     *  stays correct at any zoom level. No-op for a plain input. */
    _autoGrow(field) {
        if (!field || field.tagName !== "TEXTAREA") return;
        field.style.height = "auto";
        field.style.height = `${field.scrollHeight}px`;
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
        // Hidden container members collapse — reflow the boxes right away.
        this._scheduleRecompute();

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
        const field = event.target;

        // Hard-cap at maxlength. The attribute already blocks typing/paste, but
        // enforce it here too so the Live-bound mirror (which drives the preview
        // AND the export POST) can never carry an over-length value, whatever
        // path filled the field.
        const max = parseInt(field.getAttribute("maxlength"), 10);
        if (Number.isInteger(max) && max > 0 && field.value.length > max) {
            field.value = field.value.slice(0, max);
        }

        // Grow the field to fit the new value, then re-anchor the popover (its
        // height just changed, which affects the above/below flip).
        this._autoGrow(field);
        if (this._openId !== null) {
            const popover = this._popoverFor(this._openId);
            if (popover) this._positionPopover(popover, this._boxFor(this._openId));
        }

        const mirror = this.element.querySelector(`[data-text-mirror="${inputId}"]`);
        if (!mirror) return;
        mirror.value = field.value;
        mirror.dispatchEvent(new Event("input", { bubbles: true }));
        this._updateCounter(inputId, field);
        // Local echo of the container reflow: the boxes move immediately, the
        // debounced server render confirms ~600 ms later.
        this._scheduleRecompute();
        // The preview re-renders after the debounce — show the spinner once the
        // user pauses (not on every keystroke, which would flash the veil).
        this._scheduleSpinner();
    }

    _updateCounter(inputId, field) {
        const counter = this.element.querySelector(`[data-fill-counter="${inputId}"]`);
        if (!counter) return;
        const max = field.getAttribute("maxlength");
        if (!max) return;
        counter.textContent = `${field.value.length} / ${max} znaků`;
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
        this._updateZoomBox();
        const z = this._zoom || 1;
        const scale = this._scale();
        const previewWidth = this.hasPreviewTarget
            ? this.previewTarget.getBoundingClientRect().width / z
            : 0;

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
        // Divide by zoom: the box positions are in the stage's UNSCALED coords; the
        // CSS transform then scales them along with the preview.
        const width = rect.width / (this._zoom || 1);
        return width > 0 ? width / this.canvasWidthValue : 1;
    }

    _positionPopover(popover, box) {
        if (!box) return;
        const stage = this.hasStageTarget ? this.stageTarget : this.element;
        const z = this._zoom || 1;
        const stageRect = stage.getBoundingClientRect();
        const boxRect = box.getBoundingClientRect();

        // The popover lives inside the transform-scaled stage, so position it in the
        // stage's LOCAL (unscaled) coordinates: divide screen deltas by the zoom.
        let top = (boxRect.bottom - stageRect.top) / z + 8;
        let left = (boxRect.left - stageRect.left) / z;

        popover.style.top = `${top}px`;
        popover.style.left = `${left}px`;

        const pRect = popover.getBoundingClientRect();
        const margin = 8;

        const overflowRight = pRect.right - (window.innerWidth - margin);
        if (overflowRight > 0) {
            left = Math.max(0, left - overflowRight / z);
            popover.style.left = `${left}px`;
        }

        // Flip above the box if it would overflow the viewport bottom and there is
        // room above (in local coords).
        if (pRect.bottom > window.innerHeight - margin) {
            const aboveTop = (boxRect.top - stageRect.top - pRect.height) / z - 8;
            if (boxRect.top - pRect.height - 8 > margin) {
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

    // --- Container reflow (live-tracking boxes) -------------------------------

    /** Load the project fonts, then (re)measure — glyph widths measured with a
     *  fallback face would put the boxes at wrong reflowed positions. An
     *  immediate first pass runs anyway so the overlay isn't frameless while
     *  fonts download. Mirrors the editor's loadFonts + font-cache flush. */
    _loadFontsThenLayout() {
        this._recomputeLayout();
        const families = this.hasFontsValue ? this.fontsValue : [];
        Promise.all(
            families.map((family) => document.fonts.load(`16px "${family}"`).catch(() => {})),
        )
            .then(() => (document.fonts && document.fonts.ready) || null)
            .then(() => {
                try {
                    cache.clearFontCache();
                } catch (err) {
                    // Non-fatal — remeasure below still improves on the fallback.
                }
                this._measureBoxes.clear();
                this._recomputeLayout();
            });
    }

    /** Coalesce bursts (every keystroke) into one recompute. setTimeout, NOT
     *  requestAnimationFrame: rAF never fires in a hidden tab (Chrome pauses
     *  it), which would freeze the boxes for anything driving the page
     *  headlessly/backgrounded. */
    _scheduleRecompute() {
        if (this._recomputeQueued) return;
        this._recomputeQueued = true;
        setTimeout(() => {
            this._recomputeQueued = false;
            this._recomputeLayout();
        }, 30);
    }

    /**
     * Mirror of the export render's text pipeline: transform each input's
     * current value the way ResolveTextOverrides does (truncate to maxLength,
     * uppercase), measure the wrapped height with an offscreen Fabric Textbox,
     * then run the shared container layout over the designed frames. Results
     * land in _computedFrames (consumed by _frameOf → reposition) and in the
     * overflow UI state.
     */
    _recomputeLayout() {
        const layoutModule = window.WBoostContainerLayout;
        const data = this.hasTextLayoutValue ? this.textLayoutValue : null;
        if (!layoutModule || !data || !data.inputs) return;

        const inputs = data.inputs;
        const computed = {};

        Object.keys(inputs).forEach((inputId) => {
            const def = inputs[inputId];
            if (!def.frame) return;
            let height = def.frame.height;
            if (!def.locked && def.style) {
                height = this._measureHeight(inputId, this._currentText(inputId, def), def);
            }
            computed[inputId] = { x: def.frame.x, y: def.frame.y, width: def.frame.width, height };
        });

        let worstOverflow = null;
        const overflowIds = new Set();

        (data.containers || []).forEach((container) => {
            const memberIds = (container.memberInputIds || []).filter((id) => inputs[id] && inputs[id].frame);
            if (memberIds.length < 2) return;

            const designed = memberIds.map((id) => ({
                designedTop: inputs[id].frame.y,
                designedHeight: inputs[id].frame.height,
            }));
            const gaps = layoutModule.computeGaps(designed);
            const members = memberIds.map((id, i) => ({
                designedTop: designed[i].designedTop,
                actualHeight: computed[id] ? computed[id].height : designed[i].designedHeight,
                hidden: this._isHidden(id),
            }));
            const result = layoutModule.computeLayout(members, container.maxHeight, gaps);

            memberIds.forEach((id, i) => {
                if (!computed[id]) return;
                if (result.tops[i] !== null) {
                    computed[id].y = result.tops[i];
                } else {
                    // Hidden member: collapse the box to a zero-height line at
                    // its would-be flow position so the eye stays reachable.
                    const nextVisibleTop = result.tops.slice(i + 1).find((t) => t !== null);
                    computed[id].y = nextVisibleTop !== undefined ? nextVisibleTop : result.contentBottom;
                    computed[id].height = 0;
                }
            });

            if (result.overflowPx > 0.5) {
                memberIds.forEach((id) => overflowIds.add(id));
                if (worstOverflow === null || result.overflowPx > worstOverflow) {
                    worstOverflow = result.overflowPx;
                }
            }
        });

        this._computedFrames = computed;
        this._setOverflowState(worstOverflow, overflowIds);
        this.reposition();
    }

    /** The value the server would render: mirror value, capped + uppercased. */
    _currentText(inputId, def) {
        const mirror = this.element.querySelector(`[data-text-mirror="${inputId}"]`);
        let value = mirror ? mirror.value : "";
        if (Number.isInteger(def.maxLength) && def.maxLength > 0 && value.length > def.maxLength) {
            value = value.slice(0, def.maxLength);
        }
        if (def.uppercase) {
            value = value.toUpperCase();
        }
        return value;
    }

    _isHidden(inputId) {
        const mirror = this.element.querySelector(`[data-hide-mirror="${inputId}"]`);
        return Boolean(mirror && mirror.checked);
    }

    /** Wrapped height of `text` in the input's designed box (reused offscreen
     *  Textbox per input — never added to a canvas, Fabric measures detached). */
    _measureHeight(inputId, text, def) {
        try {
            let box = this._measureBoxes.get(inputId);
            if (!box) {
                box = new Textbox(text, {
                    width: def.frame.width,
                    fontFamily: def.style.fontFamily,
                    fontSize: def.style.fontSize,
                    lineHeight: def.style.lineHeight,
                    charSpacing: def.style.charSpacing,
                    splitByGrapheme: false,
                });
                this._measureBoxes.set(inputId, box);
            } else {
                box.set({ text });
            }
            return box.height;
        } catch (err) {
            return def.frame.height;
        }
    }

    _setOverflowState(worstOverflow, overflowIds) {
        const overflowing = worstOverflow !== null;
        this.boxTargets.forEach((box) => {
            box.classList.toggle("fill-box--overflow", overflowIds.has(box.dataset.inputid));
        });
        if (this.hasOverflowAlertTarget) {
            this.overflowAlertTarget.classList.toggle("d-none", !overflowing);
            if (overflowing) {
                this.overflowAlertTarget.textContent =
                    `Texty se nevejdou do vymezené oblasti (přesah ${Math.ceil(worstOverflow)} px). Zkraťte prosím zvýrazněné texty.`;
            }
        }
        if (this.hasExportButtonTarget) {
            this.exportButtonTarget.disabled = overflowing;
            this.exportButtonTarget.title = overflowing
                ? "Zkraťte texty, které se nevejdou do vymezené oblasti"
                : "";
        }
    }

    // --- helpers -------------------------------------------------------------

    _frameOf(box) {
        // Live-computed frame (container reflow / measured height) wins over
        // the static designer frame baked into the data attributes.
        const computed = this._computedFrames ? this._computedFrames[box.dataset.inputid] : null;
        if (computed) return computed;

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
