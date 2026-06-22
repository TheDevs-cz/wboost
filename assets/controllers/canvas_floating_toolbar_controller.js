import { Controller } from "@hotwired/stimulus";

/**
 * Floating, element-anchored editing chrome for the admin canvas editor.
 *
 * Replaces the old left-panel selection UI with a Google-Slides / Canva style
 * floating layer that sits NEXT TO the selected object:
 *   - a per-element mini-toolbar (pencil + duplicate / lock-or-placeholder /
 *     z-order / delete),
 *   - a "pencil" popover holding the FULL property form (the relocated text /
 *     image controls — those fields are still driven by canvas-text-toolbar,
 *     canvas-input-properties and canvas-image-properties; this controller only
 *     decides WHEN and WHERE they are shown),
 *   - a multi-select bar (6-way align + z-order + delete) for activeSelection,
 *   - an optional "highlight all editable elements" overlay.
 *
 * Coordinate model: zoom is a CSS transform: scale() on .canvas-wrapper
 * (canvas_zoom_controller), so the chrome is mounted in an UNSCALED layer
 * (.canvas-stage) and positions are derived from the live, already-scaled
 * canvas DOM rect:
 *     scale = fabricContainerRect.width / canvasEl.width
 * The highlight outlines are plain DOM (never drawn on the canvas bitmap), so
 * they can never leak into the saved preview thumbnail or the server PNG export.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = [
        "layer", "miniToolbar", "multiBar",
        "lockButton", "placeholderButton",
        "textPopover", "imagePopover",
    ];

    connect() {
        this._outlines = [];
        this._highlight = false;
        this._editing = false;
        this._boundReposition = () => this.reposition();
        window.addEventListener('resize', this._boundReposition);
    }

    disconnect() {
        window.removeEventListener('resize', this._boundReposition);
        this._clearOutlines();
    }

    canvasEditorOutletConnected(outlet) {
        const canvas = outlet.canvas;

        // Keep the chrome glued to the object while it is transformed.
        this._onObjTransform = () => this.reposition();
        canvas.on('object:moving', this._onObjTransform);
        canvas.on('object:scaling', this._onObjTransform);
        canvas.on('object:rotating', this._onObjTransform);
        canvas.on('object:modified', this._onObjTransform);

        // Hide the chrome while the user types inline into a textbox.
        this._onEnterEdit = () => this._setEditing(true);
        this._onExitEdit = () => this._setEditing(false);
        canvas.on('text:editing:entered', this._onEnterEdit);
        canvas.on('text:editing:exited', this._onExitEdit);

        // Keep the highlight overlay aligned on every repaint, and rebuild it
        // when the object set changes.
        this._onAfterRender = () => { if (this._highlight) this._positionOutlines(); };
        canvas.on('after:render', this._onAfterRender);
        this._onObjAdded = () => { if (this._highlight) this._renderOutlines(); };
        this._onObjRemoved = () => {
            if (this._highlight) this._renderOutlines();
            if (!canvas.getActiveObject()) this._hideChrome();
        };
        canvas.on('object:added', this._onObjAdded);
        canvas.on('object:removed', this._onObjRemoved);

        this._hideChrome();
    }

    canvasEditorOutletDisconnected(outlet) {
        const canvas = outlet.canvas;
        if (!canvas) return;
        canvas.off('object:moving', this._onObjTransform);
        canvas.off('object:scaling', this._onObjTransform);
        canvas.off('object:rotating', this._onObjTransform);
        canvas.off('object:modified', this._onObjTransform);
        canvas.off('text:editing:entered', this._onEnterEdit);
        canvas.off('text:editing:exited', this._onExitEdit);
        canvas.off('after:render', this._onAfterRender);
        canvas.off('object:added', this._onObjAdded);
        canvas.off('object:removed', this._onObjRemoved);
    }

    // --- selection -> which chrome to show -------------------------------

    onSelectionChanged(event) {
        this._showFor(event.detail ? event.detail.activeObject : null);
    }

    _showFor(obj) {
        this.closePopovers();

        if (!obj || this._editing) {
            this._hideBars();
            return;
        }

        const type = (obj.type || '').toLowerCase();

        if (type === 'activeselection') {
            if (this.hasMiniToolbarTarget) this.miniToolbarTarget.classList.add('d-none');
            if (this.hasMultiBarTarget) this.multiBarTarget.classList.remove('d-none');
        } else {
            if (this.hasMultiBarTarget) this.multiBarTarget.classList.add('d-none');
            if (this.hasMiniToolbarTarget) this.miniToolbarTarget.classList.remove('d-none');

            const isText = type === 'textbox';
            const isImage = type === 'image';
            if (this.hasLockButtonTarget) this.lockButtonTarget.classList.toggle('d-none', !isText);
            if (this.hasPlaceholderButtonTarget) this.placeholderButtonTarget.classList.toggle('d-none', !isImage);
            this.refreshContextToggle();
        }

        this.reposition();
    }

    // --- pencil -> full-options popover ----------------------------------

    openPopover() {
        if (!this.hasCanvasEditorOutlet) return;
        const obj = this.canvasEditorOutlet.canvas.getActiveObject();
        if (!obj) return;

        const type = (obj.type || '').toLowerCase();
        let target = null;
        if (type === 'textbox' && this.hasTextPopoverTarget) target = this.textPopoverTarget;
        else if (type === 'image' && this.hasImagePopoverTarget) target = this.imagePopoverTarget;
        if (!target) return;

        const isOpen = !target.classList.contains('d-none');
        this.closePopovers();
        if (!isOpen) {
            target.classList.remove('d-none');
            this.reposition();
        }
    }

    closePopovers() {
        if (this.hasTextPopoverTarget) this.textPopoverTarget.classList.add('d-none');
        if (this.hasImagePopoverTarget) this.imagePopoverTarget.classList.add('d-none');
    }

    /**
     * Reflect the active object's lock / placeholder state onto the inline
     * mini-toolbar toggle. Bound as the SECOND action after the controller that
     * actually mutates the object (canvas-input-properties#toggleLocked /
     * canvas-image-properties#togglePlaceholder), so it reads the new value.
     */
    refreshContextToggle() {
        if (!this.hasCanvasEditorOutlet) return;
        const obj = this.canvasEditorOutlet.canvas.getActiveObject();
        if (!obj) return;
        const type = (obj.type || '').toLowerCase();

        if (type === 'textbox' && this.hasLockButtonTarget) {
            const locked = !!obj.locked;
            this.lockButtonTarget.classList.toggle('active', locked);
            this.lockButtonTarget.title = locked ? 'Odemknout text' : 'Uzamknout text';
            const icon = this.lockButtonTarget.querySelector('i');
            if (icon) icon.className = locked ? 'mdi mdi-lock' : 'mdi mdi-lock-open-variant-outline';
        }

        if (type === 'image' && this.hasPlaceholderButtonTarget) {
            const placeholder = !!obj.imagePlaceholder;
            this.placeholderButtonTarget.classList.toggle('active', placeholder);
            this.placeholderButtonTarget.title = placeholder ? 'Zrušit placeholder' : 'Označit jako placeholder';
            const icon = this.placeholderButtonTarget.querySelector('i');
            if (icon) icon.className = placeholder ? 'mdi mdi-image-edit' : 'mdi mdi-image-edit-outline';
        }
    }

    // --- highlight editable elements -------------------------------------

    toggleHighlight(event) {
        this._highlight = event.target.checked;
        if (this._highlight) this._renderOutlines();
        else this._clearOutlines();
    }

    _renderOutlines() {
        this._clearOutlines();
        if (!this.hasCanvasEditorOutlet || !this.hasLayerTarget) return;
        const objects = this.canvasEditorOutlet.canvas.getObjects().filter((o) => o.selectable !== false);
        this._outlines = objects.map((obj) => {
            const el = document.createElement('div');
            el.className = 'editable-outline';
            this.layerTarget.appendChild(el);
            return { obj, el };
        });
        this._positionOutlines();
    }

    _positionOutlines(g) {
        if (!this._outlines.length) return;
        g = g || this._geometry();
        if (!g) return;
        const offX = g.contRect.left - g.layerRect.left;
        const offY = g.contRect.top - g.layerRect.top;
        this._outlines.forEach(({ obj, el }) => {
            const r = obj.getBoundingRect();
            el.style.left = `${offX + r.left * g.scale}px`;
            el.style.top = `${offY + r.top * g.scale}px`;
            el.style.width = `${r.width * g.scale}px`;
            el.style.height = `${r.height * g.scale}px`;
        });
    }

    _clearOutlines() {
        (this._outlines || []).forEach(({ el }) => el.remove());
        this._outlines = [];
    }

    // --- text editing collision ------------------------------------------

    _setEditing(editing) {
        this._editing = editing;
        if (editing) {
            this._hideChrome();
        } else {
            this._showFor(this.hasCanvasEditorOutlet ? this.canvasEditorOutlet.canvas.getActiveObject() : null);
        }
    }

    // --- positioning ------------------------------------------------------

    reposition() {
        if (!this.hasCanvasEditorOutlet || !this.hasLayerTarget) return;
        const g = this._geometry();
        if (!g) return;

        if (this._highlight) this._positionOutlines(g);

        const obj = this.canvasEditorOutlet.canvas.getActiveObject();
        if (!obj || this._editing) return;

        const r = obj.getBoundingRect();
        // The element's box in screen coordinates.
        const box = {
            left: g.contRect.left + r.left * g.scale,
            top: g.contRect.top + r.top * g.scale,
            width: r.width * g.scale,
            height: r.height * g.scale,
        };

        const type = (obj.type || '').toLowerCase();
        const bar = type === 'activeselection' ? this.multiBarTarget : this.miniToolbarTarget;
        const barShown = bar && !bar.classList.contains('d-none');
        if (barShown) this._placeBar(bar, box, g);

        const popover = this._openPopoverEl();
        if (popover) {
            // TEXT popover: place it BESIDE the element so font/size/colour changes
            // stay visible live (it must never cover the text it edits). IMAGE
            // popover: drop it straight down from the toolbar (placeholder settings
            // don't change the picture, so overlapping the image is fine).
            const isText = this.hasTextPopoverTarget && popover === this.textPopoverTarget;
            if (isText || !barShown) this._placePopover(popover, box, g);
            else this._placePopoverUnder(popover, bar, g);
        }
    }

    _openPopoverEl() {
        if (this.hasTextPopoverTarget && !this.textPopoverTarget.classList.contains('d-none')) return this.textPopoverTarget;
        if (this.hasImagePopoverTarget && !this.imagePopoverTarget.classList.contains('d-none')) return this.imagePopoverTarget;
        return null;
    }

    /** Popover dropped directly below the mini-toolbar, left-aligned to it. */
    _placePopoverUnder(el, anchorEl, g) {
        const GAP = 6;
        const MARGIN = 8;
        const eh = el.offsetHeight;
        const ew = el.offsetWidth;
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const safeTop = this._safeTop();
        const a = anchorEl.getBoundingClientRect();

        let left = a.left;
        let top = a.bottom + GAP;
        left = Math.min(Math.max(left, MARGIN), vw - ew - MARGIN);
        top = Math.min(Math.max(top, safeTop), Math.max(safeTop, vh - eh - MARGIN));

        this._setPos(el, left, top, g);
    }

    /** Mini-toolbar / multi-bar: centered just above the element, flipped below
     *  when there's no room above (near the top / sticky header). */
    _placeBar(el, box, g) {
        const GAP = 8;
        const MARGIN = 8;
        const eh = el.offsetHeight;
        const ew = el.offsetWidth;
        const safeTop = this._safeTop();

        let top = box.top - GAP - eh;
        if (top < safeTop) top = box.top + box.height + GAP;

        let left = box.left + (box.width - ew) / 2;
        left = Math.min(Math.max(left, MARGIN), window.innerWidth - ew - MARGIN);

        this._setPos(el, left, top, g);
    }

    /** Popover: BESIDE the element — right, else left, else below — so it never
     *  covers the element or the mini-toolbar. Top aligns with the element,
     *  clamped to the viewport (below the sticky header). */
    _placePopover(el, box, g) {
        const GAP = 10;
        const MARGIN = 8;
        const eh = el.offsetHeight;
        const ew = el.offsetWidth;
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const safeTop = this._safeTop();

        let left;
        let top = box.top;
        if (box.left + box.width + GAP + ew <= vw - MARGIN) {
            left = box.left + box.width + GAP;          // to the right
        } else if (box.left - GAP - ew >= MARGIN) {
            left = box.left - GAP - ew;                 // to the left
        } else {
            left = box.left;                            // below
            top = box.top + box.height + GAP;
        }

        left = Math.min(Math.max(left, MARGIN), vw - ew - MARGIN);
        top = Math.min(Math.max(top, safeTop), Math.max(safeTop, vh - eh - MARGIN));

        this._setPos(el, left, top, g);
    }

    _setPos(el, screenLeft, screenTop, g) {
        el.style.left = `${screenLeft - g.layerRect.left}px`;
        el.style.top = `${screenTop - g.layerRect.top}px`;
    }

    _geometry() {
        if (!this.hasCanvasEditorOutlet || !this.hasLayerTarget) return null;
        const canvas = this.canvasEditorOutlet.canvas;
        const canvasEl = canvas.getElement();
        if (!canvasEl) return null;
        const container = canvasEl.parentElement || canvasEl;
        const contRect = container.getBoundingClientRect();
        const layerRect = this.layerTarget.getBoundingClientRect();
        // Use Fabric's LOGICAL width, not canvasEl.width: with retina scaling the
        // DOM attribute is the device-pixel buffer width (logical × devicePixelRatio),
        // which would halve the scale on a 2x display and offset all chrom/outlines.
        const logicalWidth = (typeof canvas.getWidth === 'function' ? canvas.getWidth() : canvas.width) || canvasEl.width;
        const scale = logicalWidth ? contRect.width / logicalWidth : 1;
        return { contRect, layerRect, scale };
    }

    /** Don't let chrome slide under the sticky editor header. */
    _safeTop() {
        const header = this.element.querySelector('[data-editor-header]');
        if (header) return header.getBoundingClientRect().bottom + 8;
        return 8;
    }

    // --- show / hide helpers ---------------------------------------------

    _hideBars() {
        if (this.hasMiniToolbarTarget) this.miniToolbarTarget.classList.add('d-none');
        if (this.hasMultiBarTarget) this.multiBarTarget.classList.add('d-none');
    }

    _hideChrome() {
        this.closePopovers();
        this._hideBars();
    }
}
