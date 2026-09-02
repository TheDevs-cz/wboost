import { Controller } from "@hotwired/stimulus";
import { Canvas, FabricImage, Rect } from "fabric";

/**
 * Interactive image-fill canvas for the user-fill page (Stage 5 hybrid).
 *
 * The background is the SERVER backdrop (the design BELOW the lowest image
 * placeholder, placeholders hidden), exposed by the Live Component in an
 * element this controller reads by id and re-reads on change (text edits). On
 * top, each fillable image slot is a live Fabric object the user can move /
 * resize / rotate within the designer's limits, clipped to the designer's
 * frame. Design content the admin stacked ABOVE a placeholder (a locked frame
 * image, a title over a photo) arrives as transparent overlay slices
 * (`variant-overlay-sources`) painted directly over that placeholder's live
 * object — _restack() keeps everything in the designed z-order (layerIndex),
 * so a picked picture can never cover design that belongs above it. Every
 * change is mirrored into the hidden `images[<uuid>][...]` fields so the plain
 * form POST drives the same server render the API uses — the produced PNG is
 * always authoritative.
 *
 * The placement math is a 1:1 port of the server-side ImagePlacement so the live
 * preview and the export agree pixel-for-pixel: an image is fitted object-contain
 * into the frame (base scale s0 = min(fw/iw, fh/ih)); the user's `scale` multiplies
 * s0, `offsetX/Y` pan from the frame centre (canvas px), `rotation` is degrees.
 *
 * Every placement change also broadcasts `variant-image-fill:frame-changed`
 * (bubbling Stimulus event from the shared form root) carrying the slot's
 * VISIBLE bbox — object bounds ∩ designer frame, canvas px — so the overlay
 * controller anchors its dashed box + pencil/eye cluster to the artwork
 * instead of the designed frame (a contain-fitted or user-moved image can sit
 * far from the frame's corner).
 */
export default class extends Controller {
    static targets = ["canvas", "wrapper"];
    static values = {
        placeholders: Array,
        width: Number,
        height: Number,
        backdropId: String,
        overlaysId: String,
    };

    connect() {
        this.objects = {};
        this.overlayObjects = {};
        this.placeholdersById = {};
        (this.placeholdersValue || []).forEach((ph) => { this.placeholdersById[ph.inputId] = ph; });

        // Echo mode (see variant_text_echo_controller): while the user types,
        // the backdrop + overlays swap to their `data-base-src` variants (the
        // echo-capable texts transparent) so the client-drawn text layer above
        // is the only place those texts appear. FabricImages are cached per
        // src, so flipping modes is a repaint, not a reload.
        this._echoMode = false;
        this._backdropImages = new Map();

        this.canvas = new Canvas(this.canvasTarget, {
            width: this.widthValue,
            height: this.heightValue,
            selection: false,
            preserveObjectStacking: true,
        });

        this._fitToWrapper();
        this._boundFit = () => this._fitToWrapper();
        window.addEventListener('resize', this._boundFit);

        this._applyBackdrop();
        this._observeBackdrop();
        this._applyOverlays();
        this._observeOverlays();

        // A loaded export version ships a per-slot `seed` (picked image +
        // transform, or an explicit hide) — restore it instead of the designed
        // stand-in. The hidden form fields are already server-rendered from
        // the same seed, so this only redraws; a slot whose seed fails to
        // load still EXPORTS the seeded image (the server render is the truth).
        (this.placeholdersValue || []).forEach((ph) => {
            const seed = ph.seed || null;
            if (seed && seed.hide) {
                this._broadcastFrame(ph.inputId, null, null);
                return;
            }
            if (seed && seed.imageId && seed.url) {
                this._restoreSeed(ph, seed);
                return;
            }
            this._addStandIn(ph);
        });

        this.canvas.on('object:modified', (event) => this._onObjectModified(event));

        // The overlay's box must FOLLOW the artwork while it is being dragged /
        // scaled / rotated — not jump only on mouse-up (object:modified).
        const track = (event) => this._broadcastObjectFrame(event.target);
        this.canvas.on('object:moving', track);
        this.canvas.on('object:scaling', track);
        this.canvas.on('object:rotating', track);

        // Arrow keys nudge the selected picture by 1 canvas px, as in the admin
        // editor. Document-level like the editor's handler: Fabric's upper
        // canvas is not focusable, so there is no element to hang it on.
        this._boundKeydown = (event) => this._onKeydown(event);
        document.addEventListener('keydown', this._boundKeydown);
    }

    disconnect() {
        if (this._boundKeydown) document.removeEventListener('keydown', this._boundKeydown);
        if (this._boundFit) window.removeEventListener('resize', this._boundFit);
        if (this._backdropObserver) this._backdropObserver.disconnect();
        if (this._overlaysObserver) this._overlaysObserver.disconnect();
        if (this.canvas) this.canvas.dispose();
    }

    // --- Rendering / layout -------------------------------------------------

    _fitToWrapper() {
        // Measure the scroll viewport, NOT the wrapper: in JS mode the stage
        // shrink-wraps the canvas, so the wrapper's width IS the canvas width
        // (circular). The viewport is the real on-screen budget for the raster;
        // the overlay's fit zoom handles the visual fitting on top.
        const wrapper = this.hasWrapperTarget ? this.wrapperTarget : this.canvasTarget.parentElement;
        const viewport = this.element.querySelector('.fill-viewport') || wrapper;
        const available = viewport ? viewport.clientWidth : this.widthValue;
        const scale = available > 0 ? Math.min(1, available / this.widthValue) : 1;
        this.canvas.setDimensions({ width: this.widthValue * scale, height: this.heightValue * scale });
        this.canvas.setZoom(scale);
        this.canvas.requestRenderAll();
    }

    _backdropElement() {
        return document.getElementById(this.backdropIdValue);
    }

    /** The mode-appropriate src of a source span: the settle render, or its
     *  text-transparent base in echo mode (equal strings when the range holds
     *  no echoed text — the server reuses the bytes). */
    _sourceSrcFor(element) {
        if (!element) return '';
        const settle = element.getAttribute('data-src') || '';
        if (!this._echoMode) return settle;
        return element.getAttribute('data-base-src') || settle;
    }

    _applyBackdrop() {
        const src = this._sourceSrcFor(this._backdropElement());
        if (!src) return;

        const cached = this._backdropImages.get(src);
        if (cached) {
            this.canvas.backgroundImage = cached;
            this.canvas.requestRenderAll();
            return;
        }

        FabricImage.fromURL(src, { crossOrigin: 'anonymous' }).then((img) => {
            img.set({ left: 0, top: 0, originX: 'left', originY: 'top', selectable: false, evented: false });
            // Bound the cache: only the freshest settle + base pair matters.
            if (this._backdropImages.size > 4) this._backdropImages.clear();
            this._backdropImages.set(src, img);
            // The mode (or the sources) may have moved on while decoding.
            if (this._sourceSrcFor(this._backdropElement()) !== src) return;
            this.canvas.backgroundImage = img;
            this.canvas.requestRenderAll();
        }).catch(() => {});
    }

    _observeBackdrop() {
        const element = this._backdropElement();
        if (!element) return;
        this._backdropObserver = new MutationObserver(() => this._applyBackdrop());
        this._backdropObserver.observe(element, { attributes: true, attributeFilter: ['data-src', 'data-base-src'] });
    }

    /** variant-text-echo:mode (wired via data-action on the shared root). */
    echoModeChanged(event) {
        const echo = Boolean(event.detail && event.detail.echo);
        if (echo === this._echoMode) return;
        this._echoMode = echo;
        this._applyBackdrop();
        this._refreshOverlayVisibility();
    }

    // --- Overlay slices (design content ABOVE a placeholder) -----------------

    _overlaysElement() {
        return this.hasOverlaysIdValue ? document.getElementById(this.overlaysIdValue) : null;
    }

    /** (Re)load each overlay slice — a transparent full-canvas PNG of the
     *  design content directly above one placeholder. Slices carrying text are
     *  re-rendered by Live on settle (new data-src), so loads are keyed by src
     *  and stale in-flight loads are dropped. Each slice keeps BOTH variants
     *  decoded — the settle bytes and the text-transparent base — and shows
     *  the one the current echo mode calls for. */
    _applyOverlays() {
        const wrapper = this._overlaysElement();
        if (!wrapper) return;
        wrapper.querySelectorAll('[data-overlay-above]').forEach((span) => {
            const aboveId = span.getAttribute('data-overlay-above');
            const settleSrc = span.getAttribute('data-src') || '';
            if (!aboveId || !settleSrc) return;
            const baseAttr = span.getAttribute('data-base-src') || '';
            // Equal strings = the slice holds no echoed text; one object serves
            // both modes.
            const baseSrc = baseAttr !== '' && baseAttr !== settleSrc ? baseAttr : null;

            const entry = (this.overlayObjects[aboveId] ??= {
                settleSrc: null, settleObject: null,
                baseSrc: null, baseObject: null,
                shown: null,
            });

            this._loadOverlayVariant(entry, 'settle', settleSrc, aboveId);
            if (baseSrc !== null) {
                this._loadOverlayVariant(entry, 'base', baseSrc, aboveId);
            } else if (entry.baseSrc !== null) {
                if (entry.shown === entry.baseObject && entry.baseObject) this.canvas.remove(entry.baseObject);
                if (entry.shown === entry.baseObject) entry.shown = null;
                entry.baseSrc = null;
                entry.baseObject = null;
            }
            this._showOverlay(aboveId);
        });
    }

    _loadOverlayVariant(entry, kind, src, aboveId) {
        const srcKey = `${kind}Src`;
        const objKey = `${kind}Object`;
        if (entry[srcKey] === src) return;
        entry[srcKey] = src;

        FabricImage.fromURL(src, { crossOrigin: 'anonymous' }).then((img) => {
            const current = this.overlayObjects[aboveId];
            if (!current || current[srcKey] !== src) return;
            img.set({ left: 0, top: 0, originX: 'left', originY: 'top', selectable: false, evented: false });
            if (current.shown === current[objKey] && current[objKey]) {
                this.canvas.remove(current[objKey]);
                current.shown = null;
            }
            current[objKey] = img;
            this._showOverlay(aboveId);
        }).catch(() => {});
    }

    /** Put the mode-appropriate variant of one slice on the canvas. */
    _showOverlay(aboveId) {
        const entry = this.overlayObjects[aboveId];
        if (!entry) return;
        const desired = (this._echoMode && entry.baseObject) ? entry.baseObject : entry.settleObject;
        if (entry.shown === desired) return;
        if (entry.shown) this.canvas.remove(entry.shown);
        entry.shown = desired;
        if (desired) this.canvas.add(desired);
        this._restack();
    }

    _refreshOverlayVisibility() {
        Object.keys(this.overlayObjects).forEach((aboveId) => this._showOverlay(aboveId));
    }

    _observeOverlays() {
        const wrapper = this._overlaysElement();
        if (!wrapper) return;
        this._overlaysObserver = new MutationObserver(() => this._applyOverlays());
        // childList too: a Live morph may swap the source spans instead of
        // mutating data-src in place.
        this._overlaysObserver.observe(wrapper, {
            attributes: true,
            attributeFilter: ['data-src', 'data-base-src'],
            childList: true,
            subtree: true,
        });
    }

    /**
     * Repaint everything in the designed z-order: placeholders ascending by
     * their canvas stack index (layerIndex), each immediately followed by the
     * overlay slice of design content sitting above it. The backdrop (design
     * below the lowest placeholder) is the canvas backgroundImage, underneath
     * by construction. Overlays stay in place even while their placeholder's
     * object is absent (hidden slot).
     */
    _restack() {
        const ranked = (this.placeholdersValue || [])
            .slice()
            .sort((a, b) => (a.layerIndex || 0) - (b.layerIndex || 0));
        let index = 0;
        ranked.forEach((ph) => {
            const slot = this.objects[ph.inputId];
            if (slot && slot.object) this.canvas.moveObjectTo(slot.object, index++);
            const overlay = this.overlayObjects[ph.inputId];
            if (overlay && overlay.shown) this.canvas.moveObjectTo(overlay.shown, index++);
        });
        this.canvas.requestRenderAll();
    }

    // --- Placeholder objects ------------------------------------------------

    async _addStandIn(placeholder) {
        if (!placeholder.frame || !placeholder.defaultImageUrl) return;
        try {
            const img = await FabricImage.fromURL(placeholder.defaultImageUrl, { crossOrigin: 'anonymous' });
            const frame = placeholder.frame;
            const naturalWidth = img.width || 1;
            const naturalHeight = img.height || 1;
            if (placeholder.isBackground) {
                // Background slot: uniform cover anchored top-left (the frame
                // is the whole canvas) — a frame-stretch would distort it.
                const cover = Math.max(frame.width / naturalWidth, frame.height / naturalHeight) || 1;
                img.set({
                    originX: 'left', originY: 'top',
                    left: frame.x, top: frame.y,
                    scaleX: cover, scaleY: cover,
                    angle: 0,
                    selectable: false, evented: false,
                    clipPath: this._frameClip(frame),
                });
            } else {
                img.set({
                    originX: 'left', originY: 'top',
                    left: frame.x, top: frame.y,
                    scaleX: frame.width / naturalWidth,
                    scaleY: frame.height / naturalHeight,
                    angle: 0,
                    selectable: false, evented: false,
                });
            }
            img._placeholderId = placeholder.inputId;
            this._replaceObject(placeholder.inputId, img);
            this._broadcastFrame(placeholder.inputId, img, frame);
        } catch (error) { /* a missing stand-in just leaves the slot empty */ }
    }

    _replaceObject(inputId, fabricObject) {
        const existing = this.objects[inputId];
        if (existing && existing.object) {
            this.canvas.remove(existing.object);
        }
        this.objects[inputId] = { object: fabricObject };
        if (fabricObject) {
            this.canvas.add(fabricObject);
        }
        // Every slot change repaints in the designed z-order (this also puts
        // background slots at the bottom — their layerIndex is the lowest).
        this._restack();
    }

    async pickImage(event) {
        const { inputid, imageid, url } = event.params;
        await this._fillPlaceholder(inputid, imageid, url);
    }

    /**
     * Redraw one slot from a loaded version's seed: the picked picture with
     * its stored transform applied on top of the contain fit — the exact
     * inverse of _writeTransform, so an untouched slot re-posts the version's
     * values verbatim (the server-rendered fields are left alone). No
     * activation: restoring must not steal focus slot by slot.
     */
    async _restoreSeed(placeholder, seed) {
        const filled = await this._fillPlaceholder(placeholder.inputId, seed.imageId, seed.url, { activate: false, writeFields: false });
        if (!filled || placeholder.isBackground) return;

        const img = this.objects[placeholder.inputId] && this.objects[placeholder.inputId].object;
        const frame = placeholder.frame;
        if (!img || !frame) return;

        const containScale = img._containScale || 1;
        const scale = containScale * (Number(seed.scale) || 1);
        img.set({
            scaleX: scale,
            scaleY: scale,
            left: frame.x + frame.width / 2 + (Number(seed.offsetX) || 0),
            top: frame.y + frame.height / 2 + (Number(seed.offsetY) || 0),
            angle: Number(seed.rotation) || 0,
        });
        img.setCoords();
        this.canvas.requestRenderAll();
        this._broadcastFrame(placeholder.inputId, img, frame);
    }

    async _fillPlaceholder(inputId, imageId, url, { activate = true, writeFields = true } = {}) {
        const placeholder = this.placeholdersById[inputId];
        if (!placeholder || !placeholder.frame) return false;

        let img;
        try {
            img = await FabricImage.fromURL(url, { crossOrigin: 'anonymous' });
        } catch (error) {
            return false;
        }

        const frame = placeholder.frame;
        const naturalWidth = img.width || 1;
        const naturalHeight = img.height || 1;

        if (placeholder.isBackground) {
            // Background slot: deterministic cover anchored top-left over the
            // whole canvas — no user transform, mirrors ImagePlacement::computeCover.
            const cover = Math.max(frame.width / naturalWidth, frame.height / naturalHeight) || 1;
            img.set({
                originX: 'left', originY: 'top',
                left: frame.x, top: frame.y,
                scaleX: cover, scaleY: cover,
                angle: 0,
                selectable: false, evented: false,
                clipPath: this._frameClip(frame),
            });
            img._placeholderId = inputId;
            this._replaceObject(inputId, img);
            this._broadcastFrame(inputId, img, frame);

            if (writeFields) {
                this._setField(inputId, 'hide', '');
                this._setField(inputId, 'imageId', imageId);
            }
            return true;
        }

        const containScale = Math.min(frame.width / naturalWidth, frame.height / naturalHeight) || 1;
        const adjustable = placeholder.allowMove || placeholder.allowResize || placeholder.allowRotate;

        img.set({
            originX: 'center', originY: 'center',
            left: frame.x + frame.width / 2,
            top: frame.y + frame.height / 2,
            scaleX: containScale, scaleY: containScale,
            angle: 0,
            lockMovementX: !placeholder.allowMove,
            lockMovementY: !placeholder.allowMove,
            lockScalingX: !placeholder.allowResize,
            lockScalingY: !placeholder.allowResize,
            lockRotation: !placeholder.allowRotate,
            hasControls: placeholder.allowResize || placeholder.allowRotate,
            selectable: adjustable,
            evented: adjustable,
            clipPath: this._frameClip(frame),
        });
        img._placeholderId = inputId;
        img._containScale = containScale;

        // Scaling must stay UNIFORM: the placement contract (and the hidden
        // form fields) carry a single `scale`, so a distorted preview could
        // never match the server render. `lockUniScaling` was removed in
        // Fabric v6 — hide the middle handles instead (corner drags are
        // uniform by default via the canvas's uniformScaling).
        img.setControlsVisibility({ ml: false, mt: false, mr: false, mb: false });

        this._replaceObject(inputId, img);
        if (adjustable && activate) {
            this.canvas.setActiveObject(img);
        }
        this.canvas.requestRenderAll();
        this._broadcastFrame(inputId, img, frame);

        if (writeFields) {
            this._setField(inputId, 'hide', '');
            this._setField(inputId, 'imageId', imageId);
            this._writeTransform(inputId, img, frame);
        }
        return true;
    }

    _frameClip(frame) {
        return new Rect({
            originX: 'center', originY: 'center',
            left: frame.x + frame.width / 2,
            top: frame.y + frame.height / 2,
            width: frame.width, height: frame.height,
            absolutePositioned: true,
        });
    }

    // Uploading into a picker modal is owned by the shared fill-gallery
    // controller (folder navigation + dropzone); its freshly inserted thumbs
    // carry this controller's regular pickImage wiring, so a single-file
    // upload auto-picks by clicking its own thumb.

    toggleHide(event) {
        const inputId = event.params.inputid;
        if (event.target.checked) {
            this._replaceObject(inputId, null);
            this._broadcastFrame(inputId, null, null);
            this._setField(inputId, 'hide', '1');
            this._setField(inputId, 'imageId', '');
        } else {
            this._setField(inputId, 'hide', '');
            const placeholder = this.placeholdersById[inputId];
            if (placeholder) {
                this._addStandIn(placeholder);
            }
        }
    }

    // --- Placement <-> form-field mirroring ---------------------------------

    _onObjectModified(event) {
        const object = event.target;
        if (!object || !object._placeholderId || !object._containScale) return;
        const placeholder = this.placeholdersById[object._placeholderId];
        if (!placeholder || !placeholder.frame) return;
        this._writeTransform(object._placeholderId, object, placeholder.frame);
        this._broadcastFrame(object._placeholderId, object, placeholder.frame);
    }

    // --- Keyboard nudge ------------------------------------------------------

    _onKeydown(event) {
        const step = {
            ArrowLeft: [-1, 0],
            ArrowRight: [1, 0],
            ArrowUp: [0, -1],
            ArrowDown: [0, 1],
        }[event.key];
        if (!step || event.altKey || event.ctrlKey || event.metaKey) return;

        // Typing in a fill field (popover input, WYSIWYG, checklist row), or
        // moving through an open picker modal, must keep its own arrow keys.
        const active = document.activeElement;
        const typing = active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT' || active.isContentEditable);
        if (typing || this.element.querySelector('.fill-modal.is-open')) return;

        const object = this.canvas.getActiveObject();
        if (!object || !object._placeholderId || !object._containScale) return;

        // Same per-axis locks the drag respects — a slot without allowMove sets
        // both, so all four arrows are inert on it (mirrors the editor).
        if (step[0] !== 0 && object.lockMovementX) return;
        if (step[1] !== 0 && object.lockMovementY) return;

        event.preventDefault();
        object.set({ left: object.left + step[0], top: object.top + step[1] });
        object.setCoords();
        this.canvas.requestRenderAll();
        // set() fires no Fabric events — announce the move so the hidden
        // placement fields and the overlay's box follow (_onObjectModified).
        this.canvas.fire('object:modified', { target: object });
    }

    // --- Overlay frame broadcast ---------------------------------------------

    _broadcastObjectFrame(object) {
        if (!object || !object._placeholderId) return;
        const placeholder = this.placeholdersById[object._placeholderId];
        if (!placeholder || !placeholder.frame) return;
        this._broadcastFrame(object._placeholderId, object, placeholder.frame);
    }

    /**
     * Tell the overlay where the slot's image actually IS: the axis-aligned
     * bbox of the live object intersected with the designer frame (the render
     * clips there, so that intersection is the visible artwork). A null frame
     * (no object / image dragged fully outside its frame) makes the overlay
     * fall back to the designed frame, keeping the icons findable at the slot.
     */
    _broadcastFrame(inputId, object, frame) {
        let visible = null;
        if (object && frame) {
            // AABB of the (possibly rotated) rectangle, computed from first
            // principles — no dependence on Fabric's viewport-transform rules.
            const w = object.getScaledWidth();
            const h = object.getScaledHeight();
            const center = object.getCenterPoint();
            const rad = ((object.angle || 0) * Math.PI) / 180;
            const halfW = (Math.abs(w * Math.cos(rad)) + Math.abs(h * Math.sin(rad))) / 2;
            const halfH = (Math.abs(w * Math.sin(rad)) + Math.abs(h * Math.cos(rad))) / 2;
            const x1 = Math.max(center.x - halfW, frame.x);
            const y1 = Math.max(center.y - halfH, frame.y);
            const x2 = Math.min(center.x + halfW, frame.x + frame.width);
            const y2 = Math.min(center.y + halfH, frame.y + frame.height);
            if (x2 > x1 && y2 > y1) {
                visible = { x: x1, y: y1, width: x2 - x1, height: y2 - y1 };
            }
        }
        this.dispatch('frame-changed', { detail: { inputId, frame: visible } });
    }

    _writeTransform(inputId, object, frame) {
        const containScale = object._containScale || 1;
        const center = object.getCenterPoint();
        this._setField(inputId, 'scale', String((object.scaleX || containScale) / containScale));
        this._setField(inputId, 'offsetX', String(center.x - (frame.x + frame.width / 2)));
        this._setField(inputId, 'offsetY', String(center.y - (frame.y + frame.height / 2)));
        this._setField(inputId, 'rotation', String(object.angle || 0));
    }

    _setField(inputId, field, value) {
        const element = this.element.querySelector(`input[data-placeholder="${inputId}"][data-field="${field}"]`);
        if (element) {
            element.value = value;
        }
    }
}
