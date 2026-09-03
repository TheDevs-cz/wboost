import { Controller } from "@hotwired/stimulus";
import { Textbox, cache, util } from "fabric";
import { makeDraggable, isDragged, resetDrag } from "./popover_drag.js";

/**
 * Click-into-preview placeholder overlay of the fill pages — the ONE editing
 * surface of both the single-variant fill page and the group fill page.
 *
 * SURFACES. The controller draws over one or more `surface` targets, each a
 * preview of one dimension carrying its own geometry (`data-canvas-width`,
 * `data-layout` = FillTextPlaceholders::layoutData()) and its own boxes. The
 * single page has exactly one (the zoomable `.fill-stage`); the group page
 * has one per member dimension, and the SAME placeholder gets a box on every
 * surface. Boxes are positioned per surface: `scale = previewWidth /
 * canvasWidth` of that surface, frames in that dimension's canvas px.
 *
 * Every text + image placeholder is drawn at its designer frame with an
 * always-visible icon cluster. Image boxes then TRACK the picked image's
 * visible bbox as the image-fill controller reports it
 * (`variant-image-fill:frame-changed` → imageFrameChanged, single page) — a
 * contain-fitted or user-moved image can sit far from its frame's corner,
 * and the icons must stay on the artwork. Icon cluster:
 *  - pencil → text: opens the floating text popover; image: opens the gallery modal;
 *  - eye    → toggles "hide this element" (only when the slot is hidable).
 * The "Zobrazit oblasti k vyplnění" toggle shows/hides the dashed borders AND
 * the icon clusters together (via the `fill-highlight-on` CSS class), so turning
 * it off leaves a clean, undisturbed preview.
 *
 * POPOVERS are per INPUT, not per box: on the group page the pencil on any
 * dimension opens the one popover of that input, anchored to the box it was
 * clicked from (`_anchorBox`), and what the user types lands in every
 * dimension because there is only one mirror field per input.
 *
 * Editing writes through the mirrors without disturbing the overlay:
 *  - text value → the popover input mirrors into a `[data-text-mirror]` field
 *    via syncText (dispatch input → the page's re-render + the form POST value:
 *    a Live-bound hidden field on the single page, the form's own hidden
 *    field on the group page);
 *  - hide → toggleHide flips the controlled checkbox (`data-hide-mirror` for text,
 *    `data-image-hide` for image) and dispatches change so the page reacts.
 *
 * THE "VŠECHNA POLE" PANEL docks the popovers container (`panel` target) into
 * a modal listing every popover + image card as a stacked form — the SAME
 * editor instances (a WYSIWYG keeps its state), just laid out statically,
 * gated by the `fill-panel-open` class on the form. No second copy of any
 * field exists.
 *
 * The overlay + popovers + modals live in a `data-live-ignore` subtree on the
 * single page so a Live re-render never wipes open state. Progressive
 * enhancement: without `.fill-js` (added on connect) the popovers are a plain
 * stacked editable list.
 *
 * Enter inside a text field must NOT submit the form (that would download the
 * PNG / ZIP); only the Export button downloads. blockEnter handles that.
 *
 * CONTAINER REFLOW (live-tracking boxes). Text placeholders grouped into a
 * container by the designer reflow vertically at render time — the server PNG
 * moves them. The overlay mirrors that PER SURFACE: it measures each filled
 * text's wrapped height with an offscreen Fabric Textbox (same Fabric build +
 * break-word patch + fonts as the export render) and runs the shared
 * window.WBoostContainerLayout algorithm over that dimension's designed
 * frames, so the boxes/pencils track exactly where the render puts the
 * text. When a container's content can't fit its max height the export
 * would be rejected (API contract) — the overlay shows an inline error and
 * disables the Export button instead of letting the POST produce a broken PNG.
 */
export default class extends Controller {
    static targets = [
        "stage", "surface", "preview", "previewSource", "box", "popover", "modal", "spinner",
        "zoomLabel", "exportButton", "overflowAlert", "panel", "panelButton", "imageThumb",
    ];
    static values = {
        fonts: Array,
    };

    connect() {
        this._openId = null;
        this._anchorBox = null;
        this._modalTrigger = null;
        this._panelOpen = false;
        this._panelTrigger = null;
        this._zoom = 1;
        this._userZoomed = false;
        this.element.classList.add("fill-js");

        // Let the user drag each text popover by its title grip (it sometimes
        // covers the text it edits). The drag flag makes _positionPopover leave
        // the manually-placed popover alone; it's reset when the popover closes.
        this.popoverTargets.forEach((popover) => {
            const handle = popover.querySelector('[data-popover-drag-handle]');
            if (handle) makeDraggable(handle, popover);
        });

        // Wrap-parity with the export render (see class docblock).
        if (window.WBoostFabricBreakWord) {
            window.WBoostFabricBreakWord.enable(Textbox);
        }
        this._imageFrames = {};
        this._surfaces = this._collectSurfaces();
        this._loadFontsThenLayout();

        this._boundReposition = () => this.reposition();
        this._boundFit = () => this._fitToScreen();
        this._boundKeydown = (event) => this._onKeydown(event);
        this._boundOutside = (event) => this._maybeCloseOnOutside(event);
        // Remember where each press STARTED so a text-selection drag that begins
        // inside the popover but releases outside (its click lands on an outside
        // ancestor) is not mistaken for an outside click. Capture phase so we see
        // it even when openPopover stops the bubbling click.
        this._boundPointerDown = (event) => { this._pressOrigin = event.target; };

        window.addEventListener("resize", this._boundFit);
        window.addEventListener("scroll", this._boundReposition, true);
        document.addEventListener("keydown", this._boundKeydown);
        document.addEventListener("pointerdown", this._boundPointerDown, true);
        document.addEventListener("click", this._boundOutside);

        // Hide the live-preview spinner once the next server render lands. The
        // source span's data-src is updated by Live on each re-render — text-only
        // branch via previewSource, image branch via the backdrop span. (Single
        // page only; the group page's previews are swapped by group-fill.)
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

        if ("ResizeObserver" in window) {
            this._resizeObserver = new ResizeObserver(() => this._fitToScreen());
            this.previewTargets.forEach((preview) => this._resizeObserver.observe(preview));
        }
        // A preview <img> that (re)loads changes its on-screen size only on
        // the first load, but re-fitting is cheap and keeps the boxes honest
        // on the group page, where every render swaps the src.
        this._imagePreviews = this.previewTargets.filter((preview) => preview.tagName === "IMG");
        this._imagePreviews.forEach((preview) => preview.addEventListener("load", this._boundFit));

        this._fitToScreen();
    }

    disconnect() {
        window.removeEventListener("resize", this._boundFit);
        window.removeEventListener("scroll", this._boundReposition, true);
        document.removeEventListener("keydown", this._boundKeydown);
        document.removeEventListener("pointerdown", this._boundPointerDown, true);
        document.removeEventListener("click", this._boundOutside);
        (this._imagePreviews || []).forEach((preview) => preview.removeEventListener("load", this._boundFit));
        if (this._resizeObserver) this._resizeObserver.disconnect();
        if (this._previewObserver) this._previewObserver.disconnect();
        if (this._backdropObserver) this._backdropObserver.disconnect();
        if (this._spinnerTimeout) clearTimeout(this._spinnerTimeout);
        if (this._spinnerShowTimer) clearTimeout(this._spinnerShowTimer);
    }

    /**
     * One entry per `surface` target: its preview element, its boxes, its
     * canvas width + reflow payload, and the per-surface measurement state.
     * A box belongs to the surface that contains it.
     */
    _collectSurfaces() {
        const surfaces = this.surfaceTargets.map((el) => {
            let layout = null;
            if (el.dataset.layout) {
                try {
                    layout = JSON.parse(el.dataset.layout);
                } catch (err) {
                    layout = null;
                }
            }
            return {
                el,
                variantId: el.dataset.variantId || null,
                canvasWidth: parseFloat(el.dataset.canvasWidth) || 0,
                layout,
                preview: el.querySelector('[data-variant-fill-overlay-target~="preview"]'),
                boxes: [],
                computed: {},
                measureBoxes: new Map(),
            };
        });
        this.boxTargets.forEach((box) => {
            const surface = surfaces.find((candidate) => candidate.el.contains(box));
            if (surface) surface.boxes.push(box);
        });
        return surfaces;
    }

    _onKeydown(event) {
        if (event.key === "Escape") {
            // A Bootstrap modal (the group page's image picker) owns its own
            // Escape; ours must not also close the panel underneath it.
            if (document.querySelector(".modal.show")) return;
            if (this.modalTargets.some((modal) => modal.classList.contains("is-open"))) {
                this._closeAllModals();
                return;
            }
            if (this._panelOpen) {
                this.closePanel();
                return;
            }
            this.closePopover();
            return;
        }
        if (event.key === "Tab") {
            const modal = this.modalTargets.find((m) => m.classList.contains("is-open"));
            if (modal) this._trapFocus(event, modal);
        }
    }

    // --- Show-areas toggle (borders + icon clusters, gated in CSS) ----------

    toggleHighlight(event) {
        this.element.classList.toggle("fill-highlight-on", event.target.checked);
    }

    /**
     * Field-name tags, on their own switch — independent of the borders above,
     * mirroring the admin editor's pair. Named areas are useful while filling
     * and pure noise while judging the result, and that is a different decision
     * from wanting the dashed frames.
     *
     * An OVERFLOWING box keeps its tag either way: that one is a validation
     * signal (it names the container that will 400 the export), not a hint.
     */
    toggleCaptions(event) {
        this.element.classList.toggle("fill-captions-on", event.target.checked);
    }

    // --- "Všechna pole" panel ----------------------------------------------------
    // The popovers container docks into a modal: every text popover + image
    // card stacked as one form. Same DOM, same editor instances — only the
    // layout changes (CSS on `fill-panel-open`). A floating popover is
    // closed first so the panel never shows a popover twice.

    togglePanel(event) {
        if (this._panelOpen) {
            this.closePanel();
        } else {
            this.openPanel(event);
        }
    }

    openPanel(event) {
        if (!this.hasPanelTarget || this._panelOpen) return;
        this.closePopover();
        this._closeAllModals();
        this._panelOpen = true;
        this._panelTrigger = event?.currentTarget ?? null;
        this.element.classList.add("fill-panel-open");
        this.panelTarget.setAttribute("role", "dialog");
        this.panelTarget.setAttribute("aria-modal", "true");
        this.panelButtonTargets.forEach((button) => button.setAttribute("aria-expanded", "true"));

        // Every plain field grows to its value (the floating popover does this
        // on open; docked, they are all "open" at once).
        this.panelTarget.querySelectorAll("textarea").forEach((field) => this._autoGrow(field));

        // Focus the first EDITABLE field (a WYSIWYG counts); the floating
        // chrome is display:none here and would swallow the focus silently.
        const first = Array.from(this.panelTarget.querySelectorAll(
            'textarea, [contenteditable="true"], select, input[type="text"]:not(.visually-hidden), button.btn:not(.fill-popover__chrome)',
        )).find((field) => field.offsetParent !== null);
        if (first) first.focus({ preventScroll: true });
    }

    closePanel(event) {
        if (event) event.preventDefault();
        if (!this._panelOpen) return;
        this._panelOpen = false;
        this.element.classList.remove("fill-panel-open");
        if (this.hasPanelTarget) {
            this.panelTarget.removeAttribute("role");
            this.panelTarget.removeAttribute("aria-modal");
        }
        this.panelButtonTargets.forEach((button) => button.setAttribute("aria-expanded", "false"));
        if (this._panelTrigger && typeof this._panelTrigger.focus === "function") {
            this._panelTrigger.focus({ preventScroll: true });
        }
        this._panelTrigger = null;
    }

    // --- Zoom (whole preview, single page) ---------------------------------------
    // Visual CSS scale on the stage: the preview + overlay boxes scale together,
    // so they stay aligned with no re-measuring. reposition() computes the box
    // scale from the UNSCALED width (divides by this._zoom), so the boxes are
    // laid out in unscaled coords and the transform scales them. The popovers
    // live OUTSIDE the stage (position:fixed, viewport coords) so zoom/overflow
    // never clips them. No `stage` target (the group page) = no zoom, z stays 1.
    //
    // On a WIDE screen the initial zoom is auto-fit so the WHOLE canvas fits the
    // visible part of the screen — width AND height — and the viewport's
    // max-height is capped to that same visible area, so by default nothing
    // scrolls anywhere (no page scroll, no inner scrollbar). Only a manual
    // zoom-in past fit makes the viewport pan its content.
    //
    // On a NARROW screen (the stacked layout) both of those are off: the fit is
    // by width only and the viewport is uncapped, so the artwork is always fully
    // visible by scrolling the PAGE. See _isNarrow for why.
    //
    // We keep re-fitting on load/resize until the user zooms manually
    // (_userZoomed); after that we leave it alone.

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

    /** Zoom at which the WHOLE canvas fits the visible viewport box (capped at
     *  100 %) — width and height both, so the full artwork is on screen with no
     *  scrolling. Measures the preview's on-screen size so it works no matter
     *  which branch drew it (img / Fabric canvas). */
    _fitZoom() {
        if (!this.hasPreviewTarget || !this.hasStageTarget) return this._zoom || 1;
        const container = this.stageTarget.parentElement;
        const availW = container ? container.clientWidth : window.innerWidth;
        // null (narrow screens) = height is not a constraint: fit by width and
        // let the page scroll, rather than shrinking the artwork into a sliver.
        const availH = container ? this._availableHeight(container) : window.innerHeight;
        const rect = this.previewTarget.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0 || availW <= 0) return this._zoom || 1;
        if (availH !== null && availH <= 0) return this._zoom || 1;

        // rect already reflects the current zoom, so rescale it to the available
        // box. Floor (not round): erring a pixel small never creates a scrollbar.
        const fitH = availH === null ? Infinity : availH / rect.height;
        const z = (this._zoom || 1) * Math.min(availW / rect.width, fitH);
        return Math.min(1, Math.max(0.1, Math.floor(z * 100) / 100));
    }

    /** Narrow screens = the stacked layout (mirrors the `.fill-body` media
     *  query in app.css). There the page scrolls by design: everything above
     *  the preview — title, breadcrumb, the two toggles, the zoom row, publish
     *  and export — stacks into several hundred px of chrome, so "the space
     *  left below the preview's top" is a sliver of the screen and capping the
     *  viewport to it CLIPS the artwork (the bottom of the design vanishing
     *  behind the Vrstvy panel, and worse on every zoom step in). */
    _isNarrow() {
        return typeof window.matchMedia === "function"
            && window.matchMedia("(max-width: 767.98px)").matches;
    }

    /** Visible height below the viewport's top edge (document-space, so the
     *  current scroll position doesn't skew it). The fixed reserve covers the
     *  theme's content padding below the preview, so fitting into this height
     *  leaves the PAGE itself scroll-free too; the floor keeps a usable
     *  preview area on tiny windows.
     *
     *  `null` on narrow screens: there is no height to fit into — the preview
     *  is sized by WIDTH and the page scrolls (see _isNarrow). */
    _availableHeight(viewport) {
        if (this._isNarrow()) return null;
        const top = viewport.getBoundingClientRect().top + window.scrollY;
        return Math.max(220, Math.round(window.innerHeight - top - 72));
    }

    /** Cap the viewport to the visible area: at fit zoom nothing scrolls at
     *  all, and a manual zoom-in pans INSIDE this box instead of stretching
     *  the page under the user. On narrow screens the cap is REMOVED (and an
     *  earlier one cleared, so rotating a phone back to a wide layout heals):
     *  the viewport grows with the artwork and the page scrolls, which is the
     *  only way the whole canvas stays reachable once zoomed in. */
    _sizeViewport() {
        const viewport = this.hasStageTarget ? this.stageTarget.parentElement : null;
        if (!viewport) return;
        const available = this._availableHeight(viewport);
        viewport.style.maxHeight = available === null ? "" : `${available}px`;
    }

    /** Set the auto zoom so the whole canvas fits the screen (until user zooms). */
    _fitToScreen() {
        this._sizeViewport();
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
        // Expose the zoom so the icon clusters can counter-scale themselves back
        // to their default size — only the artwork/boxes scale visually.
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
        if (this._panelOpen) this.closePanel();

        // Anchor: the box the pencil sits in (any dimension's), else the
        // input's first box (a layers-panel row has no box of its own).
        const trigger = event.currentTarget;
        const box = (trigger && typeof trigger.closest === "function" && trigger.closest(".fill-box")) || this._boxFor(inputId);

        if (this._openId === inputId) {
            // Same input, another dimension's pencil: re-anchor rather than
            // toggle closed — the user is pointing at where to edit, not
            // dismissing.
            if (box && box !== this._anchorBox) {
                this._anchorBox = box;
                const open = this._popoverFor(inputId);
                if (open) {
                    resetDrag(open);
                    this._positionPopover(open, box);
                }
                return;
            }
            this.closePopover();
            return;
        }

        this.closePopover();

        const popover = this._popoverFor(inputId);
        if (!popover) return;

        this._openId = inputId;
        this._anchorBox = box;
        popover.classList.add("is-open");

        // Grow the textarea to fit its current value BEFORE positioning — the
        // popover's height feeds the above/below flip decision. Rich popovers
        // have a contenteditable editor instead of a textarea.
        const field = popover.querySelector('input[type="text"], textarea, [contenteditable="true"]');
        if (field) this._autoGrow(field);

        this._positionPopover(popover, box);

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
        if (popover) { popover.classList.remove("is-open"); resetDrag(popover); }
        this._openId = null;
        this._anchorBox = null;
    }

    /** Explicit close button inside a popover. */
    close(event) {
        if (event) event.preventDefault();
        this.closePopover();
    }

    _maybeCloseOnOutside(event) {
        if (this._openId === null) return;
        const popover = this._popoverFor(this._openId);
        const boxes = this._boxesFor(this._openId);
        const inside = (node) =>
            Boolean(node) && ((popover && popover.contains(node)) || boxes.some((box) => box.contains(node)));
        // Keep the popover open when the click's target OR the press that produced
        // it started inside the popover/box — the latter covers a text-selection
        // drag inside the WYSIWYG editor that releases beyond the popover edge.
        if (inside(event.target) || inside(this._pressOrigin)) return;
        this.closePopover();
    }

    // --- Image gallery modal ------------------------------------------------

    /**
     * The slot's picker: the page's own `.fill-modal` (single page), or a
     * Bootstrap modal carrying `data-image-modal` (the group page's pickers,
     * which the group-fill controller feeds). Either way it opens above the
     * panel, so picking from a docked image card works too.
     */
    openImageModal(event) {
        if (event) event.stopPropagation();
        const inputId = event.params?.inputid;
        if (!inputId) return;
        this.closePopover();

        const modal = this._modalFor(inputId);
        if (modal) {
            this._modalTrigger = event?.currentTarget ?? null;
            modal.classList.add("is-open");

            // Move focus into the dialog (its labelled container is tabindex=-1).
            const dialog = modal.querySelector(".fill-modal__dialog");
            const target = dialog?.querySelector("button, input, select, a[href]") || dialog;
            if (target) target.focus();
            return;
        }

        const bootstrapModal = document.querySelector(`.modal[data-image-modal="${inputId}"]`);
        if (bootstrapModal && window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(bootstrapModal).show();
        }
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

    /** The image-fill controller (single page) filled a slot: the panel's
     *  image card shows the picked picture. */
    imagePicked(event) {
        const { inputId, url } = event.detail || {};
        if (!inputId) return;
        this.imageThumbTargets
            .filter((thumb) => thumb.dataset.inputid === inputId)
            .forEach((thumb) => {
                thumb.style.backgroundImage = url ? `url('${url}')` : "none";
            });
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

    // --- Image frame tracking (live artwork position) -------------------------

    /** The image-fill controller reports where a slot's image actually sits
     *  (visible bbox = object bounds ∩ designer frame, canvas px) on every
     *  pick, drag, resize and rotation — anchor that slot's box + icons to
     *  the artwork instead of the designed frame. A null frame (hidden slot,
     *  or the image dragged fully outside its frame) reverts to the designed
     *  frame so the icons stay findable at the slot. Single page only (one
     *  surface); the group page's pictures are placed through its own ghosts. */
    imageFrameChanged(event) {
        const { inputId, frame } = event.detail || {};
        if (!inputId) return;
        if (frame) {
            this._imageFrames[inputId] = frame;
        } else {
            delete this._imageFrames[inputId];
        }
        this.reposition();
    }

    // --- Layers panel (Vrstvy) ------------------------------------------------

    /** Hovering/focusing a layers-panel row highlights the matching box(es)
     *  over the preview(s). No-op for placeholders without a box (locked / no
     *  frame). */
    highlightLayer(event) {
        this._unhighlightLayers();
        const inputId = event.params?.inputid;
        if (!inputId) return;
        this._boxesFor(inputId).forEach((box) => box.classList.add("fill-box--layer-hover"));
    }

    unhighlightLayer() {
        this._unhighlightLayers();
    }

    _unhighlightLayers() {
        this.boxTargets.forEach((box) => box.classList.remove("fill-box--layer-hover"));
    }

    // --- Text mirroring + Enter guard ----------------------------------------

    syncText(event) {
        const inputId = event.params?.inputid;
        if (!inputId) return;
        const field = event.target;

        // Hard-cap at maxlength. The attribute already blocks typing/paste, but
        // enforce it here too so the mirror (which drives the preview AND the
        // export POST) can never carry an over-length value, whatever path
        // filled the field.
        const max = parseInt(field.getAttribute("maxlength"), 10);
        if (Number.isInteger(max) && max > 0 && field.value.length > max) {
            field.value = field.value.slice(0, max);
        }

        // Grow the field to fit the new value, then re-anchor the popover (its
        // height just changed, which affects the above/below flip).
        this._autoGrow(field);
        this._reanchorOpenPopover();

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

    /** The whole-text font select of a plain input ("Uživatel může přepínat
     *  písmo"): write the pick into its mirror (the same debounced settle
     *  path as text), re-measure the reflow locally — a different face wraps
     *  differently — and show the spinner. */
    syncFont(event) {
        const inputId = event.params?.inputid;
        if (!inputId) return;
        const mirror = this.element.querySelector(`[data-font-mirror="${inputId}"]`);
        if (!mirror) return;
        mirror.value = event.target.value;
        mirror.dispatchEvent(new Event("input", { bubbles: true }));
        this._scheduleRecompute();
        this._scheduleSpinner();
    }

    /** A rich-text WYSIWYG (rich_text_editor_controller) changed its value.
     *  The editor already wrote the mirror + dispatched its `input` (the
     *  page's debounce running); this hook adds the same local echo syncText
     *  gives plain fields: instant container reflow + the render spinner.
     *  Re-anchor the open popover too — the editor auto-grows with its content. */
    richTextChanged() {
        this._reanchorOpenPopover();
        this._scheduleRecompute();
        this._scheduleSpinner();
    }

    _reanchorOpenPopover() {
        if (this._openId === null) return;
        const popover = this._popoverFor(this._openId);
        if (popover) this._positionPopover(popover, this._anchorBox);
    }

    _updateCounter(inputId, field) {
        const counter = this.element.querySelector(`[data-fill-counter="${inputId}"]`);
        if (!counter) return;
        const max = field.getAttribute("maxlength");
        if (!max) return;
        counter.textContent = `${field.value.length} / ${max} znaků`;
    }

    // --- Live-preview spinner (single page; the group page has one per frame) --

    /** Show after a short idle so fast typing never flashes the veil per key. */
    _scheduleSpinner() {
        if (!this.hasSpinnerTarget) return;
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

        (this._surfaces || []).forEach((surface) => {
            const scale = this._scaleFor(surface);
            const previewWidth = surface.preview
                ? surface.preview.getBoundingClientRect().width / z
                : 0;

            surface.boxes.forEach((box) => {
                const frame = this._frameOf(box, surface);
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
        });

        this._reanchorOpenPopover();
    }

    /** Display px per canvas px on one surface. Divides by zoom: the box
     *  positions are in the stage's UNSCALED coords; the CSS transform then
     *  scales them along with the preview. */
    _scaleFor(surface) {
        if (!surface.preview || !surface.canvasWidth) return 1;
        const rect = surface.preview.getBoundingClientRect();
        const width = rect.width / (this._zoom || 1);
        return width > 0 ? width / surface.canvasWidth : 1;
    }

    _positionPopover(popover, box) {
        // The user dragged it somewhere deliberately — keep it put (fixed, so it
        // stays in the viewport regardless of scroll/zoom) until it's closed.
        if (isDragged(popover)) return;
        if (!box) return;
        const boxRect = box.getBoundingClientRect();
        const margin = 8;

        // The popover is position:fixed OUTSIDE the zoom-scaled stage and the
        // scrolling viewport (so no ancestor can clip it): position it directly
        // in viewport coordinates, preferring below the box, flipping above it
        // when there is more room there, and ALWAYS clamping it fully on-screen.
        let top = boxRect.bottom + margin;
        let left = boxRect.left;

        popover.style.top = `${top}px`;
        popover.style.left = `${left}px`;

        const pRect = popover.getBoundingClientRect();

        // Prefer below the box; flip above when below would overflow the
        // viewport bottom and there is room above.
        if (top + pRect.height > window.innerHeight - margin) {
            const aboveTop = boxRect.top - pRect.height - margin;
            if (aboveTop >= margin) top = aboveTop;
        }

        // Whatever the anchor, the popover must stay fully on-screen — even
        // when the box itself is scrolled out of the viewport.
        top = Math.max(margin, Math.min(top, window.innerHeight - pRect.height - margin));
        left = Math.max(margin, Math.min(left, window.innerWidth - pRect.width - margin));

        popover.style.top = `${top}px`;
        popover.style.left = `${left}px`;
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
                (this._surfaces || []).forEach((surface) => surface.measureBoxes.clear());
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
     * Mirror of the export render's text pipeline, run once PER SURFACE:
     * transform each input's current value the way ResolveTextOverrides does
     * (truncate to maxLength, uppercase), measure the wrapped height with an
     * offscreen Fabric Textbox in that dimension's designed box, then run the
     * SAME two-phase shared container layout the server render runs — over
     * geometry POJOs built from that dimension's designed frames (fillable
     * texts) and decorative-member frames (icons/separators the server ships
     * in `decorations`), so nesting, uniform gaps and attachments all mirror
     * the render exactly. Results land in the surface's computed frames
     * (consumed by _frameOf → reposition) and in the overflow UI state — the
     * worst overflow of any dimension gates the export.
     */
    _recomputeLayout() {
        const layoutModule = window.WBoostContainerLayout;
        if (!layoutModule) return;

        let worstOverflow = null;

        (this._surfaces || []).forEach((surface) => {
            const data = surface.layout;
            if (!data || !data.inputs) return;
            const surfaceOverflow = this._layoutSurface(surface, data, layoutModule);
            if (surfaceOverflow !== null && (worstOverflow === null || surfaceOverflow > worstOverflow)) {
                worstOverflow = surfaceOverflow;
            }
        });

        this._setOverflowState(worstOverflow);
        this.reposition();
    }

    /** One surface's reflow pass; returns its worst container overflow in
     *  canvas px (null = everything fits). */
    _layoutSurface(surface, data, layoutModule) {
        const inputs = data.inputs;
        const computed = {};

        Object.keys(inputs).forEach((inputId) => {
            const def = inputs[inputId];
            if (!def.frame) return;
            let height = def.frame.height;
            if (!def.locked && def.style) {
                height = this._measureHeight(surface, inputId, this._currentValue(inputId, def), def);
            }
            computed[inputId] = { x: def.frame.x, y: def.frame.y, width: def.frame.width, height };
        });

        // Phase A over DESIGNED geometry (the anchor snapshot), phase B over
        // the measured heights + fill-time hides — the render's exact split.
        const textPojos = {};
        const pojos = [];
        Object.keys(inputs).forEach((inputId) => {
            const def = inputs[inputId];
            if (!def.frame) return;
            const pojo = {
                type: 'textbox',
                inputId,
                top: def.frame.y,
                left: def.frame.x,
                width: def.frame.width,
                height: def.frame.height,
                scaleX: 1,
                scaleY: 1,
                visible: true,
            };
            textPojos[inputId] = pojo;
            pojos.push(pojo);
        });
        Object.keys(data.decorations || {}).forEach((inputId) => {
            const decoration = data.decorations[inputId];
            if (!decoration || !decoration.frame) return;
            pojos.push({
                type: 'image',
                inputId,
                top: decoration.frame.y,
                left: decoration.frame.x,
                width: decoration.frame.width,
                height: decoration.frame.height,
                scaleX: 1,
                scaleY: 1,
                visible: true,
            });
        });

        const prepared = layoutModule.prepareFabricContainers(pojos, data.containers || [], {
            canvasHeight: typeof data.canvasHeight === 'number' ? data.canvasHeight : null,
        });

        Object.keys(textPojos).forEach((inputId) => {
            const pojo = textPojos[inputId];
            if (computed[inputId]) {
                pojo.height = computed[inputId].height;
            }
            if (this._isHidden(inputId)) {
                pojo.visible = false;
            }
        });

        const results = layoutModule.applyFabricLayout(prepared);

        let worstOverflow = null;
        const overflowIds = new Set();

        results.filter((result) => !result.nested).forEach((result) => {
            const flow = result.textFlow || [];
            flow.forEach((entry, i) => {
                if (!computed[entry.inputId]) return;
                if (entry.top !== null) {
                    computed[entry.inputId].y = entry.top;
                } else {
                    // Hidden member: collapse the box to a zero-height line at
                    // its would-be flow position so the eye stays reachable.
                    const nextVisible = flow.slice(i + 1).find((e) => e.top !== null);
                    computed[entry.inputId].y = nextVisible ? nextVisible.top : result.contentBottom;
                    computed[entry.inputId].height = 0;
                }
            });

            if (result.overflowPx > 0.5) {
                flow.forEach((entry) => {
                    if (computed[entry.inputId]) overflowIds.add(entry.inputId);
                });
                if (worstOverflow === null || result.overflowPx > worstOverflow) {
                    worstOverflow = result.overflowPx;
                }
            }
        });

        surface.computed = computed;
        surface.boxes.forEach((box) => {
            box.classList.toggle("fill-box--overflow", overflowIds.has(box.dataset.inputid));
        });

        return worstOverflow;
    }

    /** The value the server would render: mirror value, capped + uppercased.
     *  Returns { text, runs } — `runs` is non-null only for a rich-text input
     *  whose mirror carries the {"runs":[...]} envelope; the shared module
     *  mirrors the server's truncate-then-uppercase pipeline so the measured
     *  wrap matches the render. */
    _currentValue(inputId, def) {
        const mirror = this.element.querySelector(`[data-text-mirror="${inputId}"]`);
        const raw = mirror ? mirror.value : "";
        const module = window.WBoostRichTextRuns;
        // The whole-text font choice ("" / no mirror = the designed face).
        const fontMirror = this.element.querySelector(`[data-font-mirror="${inputId}"]`);
        const fontFamily = fontMirror && fontMirror.value !== "" ? fontMirror.value : null;

        if (def.richText && module) {
            const blocksModule = window.WBoostRichTextBlocks;
            let runs = null;
            let lines = null;
            const trimmed = raw.trim();
            if (trimmed.startsWith("{")) {
                try {
                    const decoded = JSON.parse(trimmed);
                    if (decoded && Array.isArray(decoded.runs)) {
                        runs = module.normalize(decoded.runs);
                        if (def.lists && Array.isArray(decoded.lines)) {
                            lines = decoded.lines;
                        }
                    }
                } catch (err) {
                    // Not an envelope — treat as plain text below.
                }
            }
            if (runs === null) {
                runs = raw === "" ? [] : module.normalize([{ text: raw }]);
            }
            if (Number.isInteger(def.maxLength) && def.maxLength > 0) {
                runs = module.truncate(runs, def.maxLength);
            }
            if (def.uppercase) {
                runs = module.upper(runs);
            }
            // Re-fit the line types to the (possibly truncated) text — null
            // when no list lines survive, mirroring the server's RichText.
            lines = lines && blocksModule ? blocksModule.normalizeLines(runs, lines) : null;
            return {
                text: module.plainText(runs),
                runs: module.isStyled(runs) || lines ? runs : null,
                lines,
                fontFamily,
            };
        }

        let value = raw;
        if (Number.isInteger(def.maxLength) && def.maxLength > 0 && value.length > def.maxLength) {
            value = value.slice(0, def.maxLength);
        }
        if (def.uppercase) {
            value = value.toUpperCase();
        }
        return { text: value, runs: null, fontFamily };
    }

    _isHidden(inputId) {
        const mirror = this.element.querySelector(`[data-hide-mirror="${inputId}"]`);
        return Boolean(mirror && mirror.checked);
    }

    /** Wrapped height of the value in the input's designed box on ONE surface
     *  (reused offscreen Textbox per surface + input — never added to a
     *  canvas, Fabric measures detached). `value` is { text, runs } from
     *  _currentValue: styled runs are applied as per-character styles (a bold
     *  face wraps wider!) via the shared module — and cleared again when the
     *  value flips back to plain, or the box would keep measuring with stale
     *  styling. */
    _measureHeight(surface, inputId, value, def) {
        try {
            let box = surface.measureBoxes.get(inputId);
            if (!box) {
                box = new Textbox("", {
                    width: def.frame.width,
                    fontFamily: def.style.fontFamily,
                    fontSize: def.style.fontSize,
                    lineHeight: def.style.lineHeight,
                    charSpacing: def.style.charSpacing,
                    splitByGrapheme: false,
                });
                surface.measureBoxes.set(inputId, box);
            }

            // The user's font choice re-wraps the box exactly as the render
            // applies it (before the text); no pick = the designed face.
            const fontFamily = value.fontFamily || def.style.fontFamily;
            if (box.fontFamily !== fontFamily) {
                box.set({ fontFamily });
            }

            const module = window.WBoostRichTextRuns;
            const blocksModule = window.WBoostRichTextBlocks;

            // Lists → the value renders as a BLOCK STACK, not a single wrapped
            // textbox: mirror the server's stack layout with the same shared
            // module, measuring each fragment on the cached offscreen box
            // (paragraphs at full width, items at the indented width).
            if (value.lines && value.runs && blocksModule && module && def.listStyle) {
                const blocks = blocksModule.groupBlocks(value.runs, value.lines);
                const measure = (fragmentRuns, width) => {
                    box.set({ width });
                    module.applyToTextbox(box, fragmentRuns, util.stylesFromArray);
                    return box.height;
                };
                // Same leading compensation the render template applies —
                // Fabric omits the last line's leading per Textbox, and each
                // stack element is one (see rich_text_blocks.js).
                const fontSizeMult = typeof box._fontSizeMult === "number" ? box._fontSizeMult : 1.13;
                const lineLeading = Math.max(0, def.style.fontSize * fontSizeMult * (def.style.lineHeight - 1));
                const layout = blocksModule.layoutStack(
                    blocks,
                    def.listStyle,
                    { width: def.frame.width, lineLeading },
                    measure,
                );
                box.set({ width: def.frame.width });
                return layout.height;
            }

            if (value.runs && module) {
                module.applyToTextbox(box, value.runs, util.stylesFromArray);
            } else if (module) {
                module.clearStyles(box);
                box.set({ text: value.text });
                box.initDimensions();
            } else {
                box.set({ text: value.text });
            }
            return box.height;
        } catch (err) {
            return def.frame.height;
        }
    }

    _setOverflowState(worstOverflow) {
        const overflowing = worstOverflow !== null;
        if (this.hasOverflowAlertTarget) {
            this.overflowAlertTarget.classList.toggle("d-none", !overflowing);
            if (overflowing) {
                this.overflowAlertTarget.textContent =
                    `Texty se nevejdou do vymezené oblasti (přesah ${Math.ceil(worstOverflow)} px). Zkraťte prosím zvýrazněné texty.`;
            }
        }
        this.exportButtonTargets.forEach((button) => {
            button.disabled = overflowing;
            button.title = overflowing
                ? "Zkraťte texty, které se nevejdou do vymezené oblasti"
                : "";
        });
    }

    // --- helpers -------------------------------------------------------------

    _frameOf(box, surface) {
        // Live frames win over the static designer frame baked into the data
        // attributes: image slots track their picked image's visible bbox,
        // text slots their container-reflowed / measured position.
        const image = this._imageFrames ? this._imageFrames[box.dataset.inputid] : null;
        if (image) return image;
        const computed = surface && surface.computed ? surface.computed[box.dataset.inputid] : null;
        if (computed) return computed;

        const x = parseFloat(box.dataset.frameX);
        const y = parseFloat(box.dataset.frameY);
        const w = parseFloat(box.dataset.frameWidth);
        const h = parseFloat(box.dataset.frameHeight);
        if ([x, y, w, h].some((v) => Number.isNaN(v))) return null;
        return { x, y, width: w, height: h };
    }

    /** The input's first (visible) box — the anchor when no specific one was
     *  clicked (a layers-panel row). */
    _boxFor(inputId) {
        const boxes = this._boxesFor(inputId);
        return boxes.find((box) => box.style.display !== "none") || boxes[0] || null;
    }

    /** Every box of an input — one per surface carrying it. */
    _boxesFor(inputId) {
        return this.boxTargets.filter((b) => b.dataset.inputid === inputId);
    }

    _popoverFor(inputId) {
        return this.popoverTargets.find((p) => p.dataset.inputid === inputId) || null;
    }

    _modalFor(inputId) {
        return this.modalTargets.find((m) => m.dataset.imageModal === inputId) || null;
    }
}
