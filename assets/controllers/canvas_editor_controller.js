import { Controller } from "@hotwired/stimulus";
import { Canvas, IText, Textbox, FabricImage, cache } from "fabric";

import { patchHiddenTextarea } from './canvas_hidden_textarea.js';
import { PREVIEW_MAX_WIDTH, buildVariantPayload, containForDimensions, coverForDimensions, restoreCustomProperties } from './canvas_payload.js';
import { applyEditorLock, applyBackdropState, isBackdropCovering } from './canvas_custom_properties.js';
import { createShapeObject, isShapeObject } from './canvas_shapes.js';
import { applyChecklistPreview, sweepChecklistPreviews } from './canvas_checklist_preview.js';
import { syncChecklistSample, syncTextSample, sweepChecklistSamples } from './canvas_checklist_sample.js';
import { collectAllowedFonts } from './canvas_font_choice.js';
import { DEFAULT_LINE_HEIGHT } from './canvas_text_toolbar_controller.js';

/**
 * Where a freshly added image lands when it comfortably fits — offset from the
 * canvas origin so it doesn't hide whatever already sits in the corner.
 */
const IMAGE_DROP_OFFSET = 100;

/**
 * Orchestrator controller for the social-network template variant editor.
 *
 * Owns the Fabric `Canvas` instance and is the single point that talks to
 * the form (save) and to Fabric's lifecycle. Sibling controllers (history,
 * clipboard, zoom, text-toolbar, input-properties, alignment) reach in via
 * Stimulus 3 outlets to read `this.canvas`, and react to selection changes
 * via the `canvas-editor:selection:changed` window event we dispatch here.
 */
export default class extends Controller {
    static targets = [
        "canvas", "textInputs", "imageInputs", "previewImage", "unsavedChangesMessage",
    ];

    static values = {
        backgroundImage: String,
        // 'canvas' (legacy: background = canvas-level backgroundImage, mirrored
        // from the entity) or 'layer' (background = regular `isBackground`
        // object inside the canvas document, optional).
        backgroundMode: { type: String, default: 'canvas' },
        customFonts: Array,
        editVariantUrl: String,
        // The project's manual colours (same list the text popover and the fill
        // WYSIWYG offer). A new shape is painted with the first one, so it
        // lands on-brand instead of in a placeholder grey the designer then has
        // to correct on every single add.
        brandColors: Array,
    };

    connect() {
        // Retina scaling multiplies the backing store by devicePixelRatio² —
        // for a print-size canvas (A4 @300dpi = 2480×3508 ≈ 8.7M logical px)
        // that is ~35M pixels repainted on EVERY render frame, which is what
        // made the editor crawl on layer-heavy print templates. The editor is
        // almost always zoomed OUT on those (auto-fit), so the extra density
        // is invisible anyway. Social presets (≤ 1080×1920 ≈ 2.1M px) keep
        // retina for crispness at 100%+ zoom. The export is unaffected — it
        // renders headless through Gotenberg, and the preview thumbnail uses
        // its own offscreen canvas.
        const canvasEl = document.getElementById('c');
        const logicalPixels = canvasEl
            ? (parseInt(canvasEl.getAttribute('width') || '0', 10)
                * parseInt(canvasEl.getAttribute('height') || '0', 10))
            : 0;
        this.canvas = new Canvas('c', {
            enableRetinaScaling: logicalPixels > 0 && logicalPixels <= 4_000_000,
        });

        // Word-wrap parity with the export: the headless render template
        // patches Textbox wrapping (break-word for over-long words) — apply
        // the identical shared patch here so the editor measures/wraps text
        // exactly like the exported PNG (container reflow depends on it).
        if (window.WBoostFabricBreakWord) {
            window.WBoostFabricBreakWord.enable(Textbox);
        }

        // Entering text editing focuses Fabric's hidden textarea, which it
        // positions in UNSCALED canvas coordinates on the body — on a print
        // canvas that lands ~2000 px down the document and the focus scroll
        // took the whole (overflow-hidden) app shell with it, unrecoverably.
        // Patch it onto IText so Textbox inherits it; the group editor drives
        // this same canvas, so both editors are covered.
        patchHiddenTextarea(IText);

        // Kick off font loading FIRST and keep the promise. The project fonts
        // are declared as @font-face served over HTTP from Minio, so on a cold
        // browser cache they are NOT resident when connect() runs. The canvas
        // load below awaits this.fontsReady before its first text measurement,
        // so glyphs are measured/painted with the real webfont instead of a
        // serif fallback — that race was the intermittent "wrong font until
        // refresh" bug.
        this.fontsReady = this.loadFonts();
        this.populateFontSelect();

        const canvasJson = this.element.dataset.canvasEditorCanvasJson;
        if (canvasJson && canvasJson.trim() !== '') {
            // loadCanvasWithoutHistory is async in v7 (Promise-based loadFromJSON);
            // Stimulus connect() can't be async, so we fire-and-forget. It
            // awaits this.fontsReady internally before measuring/painting text.
            this.loadCanvasWithoutHistory(canvasJson);
        }

        // Always override background when loaded — canvas mode only. In layer
        // mode the background is an ordinary object already inside the canvas
        // JSON; assigning a canvas-level background on top of it would shadow
        // the layer (and in the group editor, re-add it on every tab switch).
        if (this.backgroundModeValue !== 'layer' && this.backgroundImageValue) {
            this.setBackgroundImage(this.backgroundImageValue);
        }

        this._syncTransparencyHint();

        // Safety net: once the browser reports every face ready, drop Fabric's
        // glyph-measurement cache and repaint. Catches any face that settles
        // after the initial render (or a family not in customFonts) so the
        // editor never gets stuck showing a fallback font.
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(() => this.refreshAfterFontsLoaded());
        }

        this._boundHandleKeydown = this.handleKeydown.bind(this);
        window.addEventListener('keydown', this._boundHandleKeydown);

        // Selection lifecycle → broadcast a single semantic event for siblings.
        this._boundDispatchSelection = () => this.dispatchSelectionChanged();
        this.canvas.on('selection:created', this._boundDispatchSelection);
        this.canvas.on('selection:updated', this._boundDispatchSelection);
        this.canvas.on('selection:cleared', this._boundDispatchSelection);

        // Backdrop targeting (see applyBackdropState): an unlocked image that
        // covers the canvas is click-through while unselected, so dragging
        // over it rubber-bands instead of moving the picture. Re-evaluated on
        // every mutation/selection change — coverage shifts when an image is
        // scaled/moved, and the active object is exempt (that's what makes it
        // movable once deliberately selected). A plain CLICK (mousedown+up
        // without movement, nothing hit) selects the topmost backdrop under
        // the pointer, Canva-style; Esc releases it back to click-through.
        this._boundRefreshBackdrops = () => this.refreshBackdropStates();
        ['object:added', 'object:modified', 'selection:created', 'selection:updated', 'selection:cleared']
            .forEach((ev) => this.canvas.on(ev, this._boundRefreshBackdrops));
        // Checklist components paint their own checkboxes in the editor (see
        // canvas_checklist_preview) — every freshly added / re-loaded object
        // needs the instance patch, so sweep on add (load re-runs it too).
        this.canvas.on('object:added', (opt) => applyChecklistPreview(opt.target));
        // ...and their sample envelope IS the exported value, so an inline
        // text edit has to be written into it (canvas_checklist_sample) —
        // otherwise the canvas shows the new items and the PNG keeps the old.
        // syncTextSample generalizes the same lockstep to every SAMPLED text
        // input: the canvas text the designer just typed becomes the sample's
        // text (lead-run styling kept), so the export can never keep quietly
        // rendering the pre-edit sample.
        this.canvas.on('text:changed', (opt) => {
            syncChecklistSample(opt.target);
            syncTextSample(opt.target);
        });
        // Pointer modifiers (applyPointerModifiers): Alt/⌥+drag always
        // rubber-bands, Ctrl/⌘+drag always grabs the object under the cursor
        // (backdrops included). Must run BEFORE Fabric's target search.
        this.canvas.on('mouse:down:before', (opt) => this.applyPointerModifiers(opt));
        this.canvas.on('mouse:down', (opt) => {
            this._backdropPress = opt.target ? null : this._scenePoint(opt);
        });
        this.canvas.on('mouse:up', (opt) => {
            this.releasePointerModifiers();
            this.maybeSelectBackdrop(opt);
        });

        // Mark form dirty whenever the canvas changes. The "unsaved changes"
        // indicator was the only meaningful piece of the old autosave UI;
        // keep it driven directly off Fabric events.
        const markDirty = () => this.markUnsaved();
        this.canvas.on('text:changed', markDirty);
        this.canvas.on('object:added', () => {
            if (!this.loadingCanvas) {
                markDirty();
            }
        });
        this.canvas.on('object:modified', markDirty);
        this.canvas.on('object:removed', markDirty);
    }

    disconnect() {
        if (this._boundHandleKeydown) {
            window.removeEventListener('keydown', this._boundHandleKeydown);
        }
    }

    dispatchSelectionChanged() {
        const activeObject = this.canvas.getActiveObject();
        this.dispatch('selection:changed', { detail: { activeObject } });
    }

    /**
     * Sweep every unlocked image and SHAPE and set its backdrop click-through
     * state from current coverage + selection (see applyBackdropState).
     * Idempotent and cheap — pure arithmetic per object, no DOM, no Fabric
     * events fired — so it can run on every mutation/selection event without
     * contributing to the layer-heavy-canvas lag this editor already had to
     * shed.
     *
     * Shapes are swept for exactly the reason images are: a full-bleed colour
     * rectangle (a tint over a photo, a solid brand panel) is the same pointer
     * trap as a full-canvas picture — evented, it would swallow every mousedown
     * and there would be no empty spot left to start a rubber-band selection
     * from anywhere on the canvas.
     */
    refreshBackdropStates() {
        if (!this.canvas) return;
        const width = this.canvas.getWidth();
        const height = this.canvas.getHeight();
        const active = new Set(this.canvas.getActiveObjects());
        this.canvas.getObjects().forEach((obj) => {
            if ((obj.type || '').toLowerCase() !== 'image' && !isShapeObject(obj)) return;
            applyBackdropState(obj, isBackdropCovering(obj, width, height), active.has(obj));
        });
    }

    /**
     * Canva-style backdrop selection: a plain click on "empty" canvas that is
     * actually a click-through backdrop selects that backdrop. Fires on
     * mouse:up, AFTER Fabric finalized its own targeting/rubber-band — so it
     * only acts when nothing was hit (no target on down OR up), nothing ended
     * up selected (a marquee that caught objects leaves an ActiveSelection),
     * and the pointer didn't travel (a drag was a marquee attempt, not a
     * click). selection:created from setActiveObject then runs the sweep,
     * which exempts the now-active backdrop → it's immediately draggable.
     */
    maybeSelectBackdrop(opt) {
        const press = this._backdropPress;
        this._backdropPress = null;
        if (!press || opt.target || this.canvas.getActiveObject()) return;
        // Alt = area-select modifier: an Alt-click deliberately ignores
        // whatever is under the pointer, so it never selects the backdrop.
        if (opt.e && opt.e.altKey) return;

        const up = this._scenePoint(opt);
        if (!up || Math.abs(up.x - press.x) > 3 || Math.abs(up.y - press.y) > 3) return;

        const width = this.canvas.getWidth();
        const height = this.canvas.getHeight();
        const objects = this.canvas.getObjects();
        for (let i = objects.length - 1; i >= 0; i--) {
            const obj = objects[i];
            if (!this._backdropCandidate(obj, width, height)) continue;
            if (typeof obj.containsPoint === 'function' && !obj.containsPoint(up)) continue;
            this.canvas.setActiveObject(obj);
            this.canvas.requestRenderAll();
            return;
        }
    }

    /**
     * Could this object be acting as a click-through backdrop right now?
     * Shared by the click-to-select pass and the ⌘-drag promotion so the two
     * can never disagree about what counts. Images AND shapes: a full-bleed
     * rectangle behaves exactly like a full-bleed photo.
     */
    _backdropCandidate(obj, width, height) {
        if ((obj.type || '').toLowerCase() !== 'image' && !isShapeObject(obj)) return false;
        if (obj.editorLocked === true) return false;
        if (obj.visible === false) return false;
        return isBackdropCovering(obj, width, height);
    }

    _scenePoint(opt) {
        if (opt && opt.scenePoint) return opt.scenePoint;
        if (opt && opt.e && typeof this.canvas.getScenePoint === 'function') {
            return this.canvas.getScenePoint(opt.e);
        }
        return null;
    }

    /**
     * Pointer-modifier conventions, applied on mouse:down:before — i.e.
     * ahead of Fabric's own target search for this press:
     *
     * Alt/⌥ + drag — ALWAYS area-select: skipTargetFind for the duration of
     * the press, so the drag draws the rubber-band even when it starts on an
     * object (diagram-editor convention — Photoshop-family tools solve this
     * with a separate marquee tool, which a single-tool canvas doesn't have).
     * Exception: a press on a transform HANDLE of the active object keeps
     * its native Photoshop meaning (Alt = centered scaling).
     *
     * Ctrl/⌘ + drag — ALWAYS grab: the topmost click-through backdrop under
     * the pointer is promoted to targetable for this press, so Fabric picks
     * it up and the drag moves it immediately — Photoshop's Move-tool
     * auto-select convention (Ctrl/⌘-drag grabs the layer under the cursor).
     * Normal objects need no promotion (they target natively, and one on top
     * of the backdrop still wins, like Photoshop's topmost layer); the
     * backdrop sweep re-demotes the image once it's deselected. Composes
     * with the existing ⌘-while-dragging snap bypass: ⌘ consistently means
     * "direct, precise manipulation".
     */
    applyPointerModifiers(opt) {
        const e = opt && opt.e;
        if (!e || !this.canvas) return;

        if (e.altKey) {
            const active = this.canvas.getActiveObject();
            let onControl = false;
            if (active && typeof active.findControl === 'function' && typeof this.canvas.getViewportPoint === 'function') {
                onControl = !!active.findControl(this.canvas.getViewportPoint(e));
            }
            if (!onControl) {
                this.canvas.skipTargetFind = true;
                this._modifierSkipTargetFind = true;
            }
            return;
        }

        if (!e.metaKey && !e.ctrlKey) return;
        const point = this._scenePoint(opt);
        if (!point) return;
        const width = this.canvas.getWidth();
        const height = this.canvas.getHeight();
        const objects = this.canvas.getObjects();
        for (let i = objects.length - 1; i >= 0; i--) {
            const obj = objects[i];
            if (obj.evented !== false) continue; // only passthrough backdrops need promoting
            if (!this._backdropCandidate(obj, width, height)) continue;
            if (typeof obj.containsPoint === 'function' && !obj.containsPoint(point)) continue;
            obj.evented = true;
            obj.selectable = true;
            return;
        }
    }

    releasePointerModifiers() {
        if (this._modifierSkipTargetFind && this.canvas) {
            this.canvas.skipTargetFind = false;
            this._modifierSkipTargetFind = false;
        }
    }

    markUnsaved() {
        this.unsavedChangesMessageTarget.classList.remove('d-none');
        // Universal "something mutated the canvas" signal: every toolbar /
        // properties / container mutation funnels through markUnsaved, so the
        // group editor hooks its propagation engine on this single event.
        this.dispatch('dirty');
    }

    markSaved() {
        this.unsavedChangesMessageTarget.classList.add('d-none');
    }

    /**
     * Single source of truth for "the canvas has edits not yet persisted":
     * the visibility of the "Neuložené změny" indicator, which markUnsaved/
     * markSaved toggle off Fabric's mutation events and the save response.
     */
    isDirty() {
        return this.hasUnsavedChangesMessageTarget
            && !this.unsavedChangesMessageTarget.classList.contains('d-none');
    }

    /**
     * Intercept the Export link. The export is rendered server-side from the
     * LAST SAVED variant, so following the link with unsaved edits silently
     * produces a PNG that doesn't reflect what's on screen. When dirty, stop
     * the navigation and ask the user; otherwise let the link behave normally.
     */
    confirmExport(event) {
        if (!this.isDirty()) {
            return; // nothing unsaved — follow the link as usual
        }

        event.preventDefault();
        this.pendingExportUrl = event.currentTarget.href;
        bootstrap.Modal.getOrCreateInstance('#exportUnsavedModal').show();
    }

    exportWithoutSaving() {
        this.hideExportModal();
        if (this.pendingExportUrl) {
            window.location.href = this.pendingExportUrl;
        }
    }

    saveAndExport() {
        const url = this.pendingExportUrl;
        this.hideExportModal();
        this.submitForm().then((saved) => {
            if (saved && url) {
                window.location.href = url;
            }
        });
    }

    hideExportModal() {
        const modal = bootstrap.Modal.getInstance('#exportUnsavedModal');
        if (modal) {
            modal.hide();
        }
    }

    async loadCanvasWithoutHistory(canvasJson) {
        this.loadingCanvas = true;
        try {
            // Parse the source JSON ourselves so we keep a reference to the
            // original (with our custom properties intact) for the post-load
            // restore pass below. canvasJson can arrive as either a string
            // (from the data attribute) or an already-decoded object.
            let sourceCanvas;
            if (typeof canvasJson === 'string') {
                try {
                    sourceCanvas = canvasJson.length > 0 ? JSON.parse(canvasJson) : {};
                } catch (err) {
                    console.error('Invalid canvas JSON', err);
                    sourceCanvas = {};
                }
            } else {
                sourceCanvas = canvasJson || {};
            }

            // Wait for the project webfonts to be resident BEFORE loadFromJSON.
            // loadFromJSON triggers Fabric's text measurement (initDimensions);
            // if the font is not yet loaded that measurement — and the first
            // paint — fall back to a serif, which is exactly the cold-cache bug.
            // connect() assigns this.fontsReady synchronously before calling us,
            // so it is always set here; awaiting an already-resolved promise on
            // later calls (undo/redo restore) is a no-op.
            if (this.fontsReady) {
                try {
                    await this.fontsReady;
                } catch (err) {
                    // Best effort: a failed/slow face must not block the canvas.
                }
            }

            // Fabric v7 loadFromJSON returns a Promise (no callback form).
            await this.canvas.loadFromJSON(sourceCanvas);

            // CRITICAL: Fabric v7 strips our custom annotation properties
            // (inputId / name / locked / …) during loadFromJSON — without the
            // restore pass the toolbar shows empty metadata after reload and
            // the export renderer cannot match overrides by inputId. The pass
            // lives in canvas_payload.js so the group editor's shadow-canvas
            // hydration runs the identical code.
            restoreCustomProperties(this.canvas, sourceCanvas);

            // A background restored from saved JSON may predate the cover fix
            // (center origin, no scale → cropped to a quadrant under Fabric v7).
            // Re-apply cover/center from the image's natural size so the editor
            // matches the export. Idempotent for backgrounds already covered.
            if (this.backgroundModeValue === 'layer') {
                // Layer mode never has a canvas-level background. Clear any
                // leftover (e.g. a group-editor tab switch from a canvas-mode
                // sibling) instead of re-covering it.
                this.canvas.backgroundImage = undefined;
            } else if (this.canvas.backgroundImage) {
                this.coverBackgroundImage(this.canvas.backgroundImage);
            }

            // Container definitions ride the canvas document (top-level
            // `containers` key) — restore them onto the canvas instance, the
            // shared state the container controller and submitForm read.
            this.canvas.wboostContainers = Array.isArray(sourceCanvas.containers)
                ? sourceCanvas.containers.map((c) => ({
                    ...c,
                    memberInputIds: Array.isArray(c.memberInputIds) ? c.memberInputIds.slice() : [],
                    memberContainerIds: Array.isArray(c.memberContainerIds) ? c.memberContainerIds.slice() : [],
                }))
                : [];

            // Ruler guides too (top-level `guides` key) — but ONLY when the
            // key is present: history snapshots don't carry guides (guide
            // edits are deliberately outside undo), so an undo/redo restore
            // must keep the current guides instead of wiping them.
            if (Array.isArray(sourceCanvas.guides)) {
                this.canvas.wboostGuides = sourceCanvas.guides.map((g) => ({ ...g }));
            } else if (!Array.isArray(this.canvas.wboostGuides)) {
                this.canvas.wboostGuides = [];
            }

            // restoreCustomProperties → applyEditorLock re-enabled every
            // unlocked image; demote canvas-covering ones back to backdrop
            // click-through before first interaction.
            this.refreshBackdropStates();
            // Hydrated objects are fresh instances — re-apply the checklist
            // render patch (undo/redo restores land here too).
            sweepChecklistPreviews(this.canvas);
            // Heal checklists saved before the text↔sample sync existed: their
            // stored envelope may still hold the pre-edit items. Idempotent on
            // consistent objects, and deliberately does NOT mark the form dirty
            // — the reconciliation rides along with the designer's next save.
            sweepChecklistSamples(this.canvas);

            this.canvas.renderAll();
        } finally {
            this.loadingCanvas = false;
        }

        // After BOTH initial load and undo/redo restores — the container
        // controller re-derives its design snapshots + zone overlay from this.
        this.dispatch('canvas:loaded');
    }

    handleKeydown(event) {
        // Check if the focus is on an input, textarea, or contenteditable element
        const activeElement = document.activeElement;
        const isInputFocused = activeElement.tagName === 'INPUT' ||
            activeElement.tagName === 'TEXTAREA' ||
            activeElement.isContentEditable;

        const activeObject = this.canvas.getActiveObject();
        const isEditingText = activeElement && activeElement.isEditing;

        if (isInputFocused || isEditingText) {
            // Allow default behavior (do not prevent default)
            return;
        }

        // Esc drops the selection — the only way OFF a selected backdrop
        // image (its pixels cover the whole canvas, so there is no empty spot
        // to click; see maybeSelectBackdrop), and standard editor muscle
        // memory anyway.
        if (event.key === 'Escape') {
            if (activeObject) {
                this.canvas.discardActiveObject();
                this.canvas.requestRenderAll();
            }
            return;
        }

        // Handle Delete or Backspace for object deletion
        if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            this.deleteActiveObject();
            return;
        }

        // Handle arrow keys for moving the selected object only if an object is selected
        if (activeObject && ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
            event.preventDefault();
            this.moveSelectedObject(event.key);
        }

        if ((event.ctrlKey || event.metaKey) && event.key === 'c') {
            event.preventDefault();
            // Clipboard controller listens for this on window.
            this.dispatch('copy');
        } else if ((event.ctrlKey || event.metaKey) && event.key === 'v') {
            event.preventDefault();
            this.dispatch('paste');
        }
    }

    deleteActiveObject() {
        // getActiveObjects() resolves a multi-selection to its members — the
        // ActiveSelection wrapper itself is not in the objects array, so
        // remove(wrapper) would silently do nothing.
        const objects = this.canvas.getActiveObjects();
        if (objects.length) {
            // Discard FIRST so selection:cleared fires and the floating chrome
            // hides; then remove.
            this.canvas.discardActiveObject();
            this.canvas.remove(...objects);
            this.canvas.renderAll();
        }
    }

    moveSelectedObject(key) {
        const activeObject = this.canvas.getActiveObject();
        if (!activeObject) return;

        // Respect Fabric's movement locks per axis so keyboard nudging can't
        // bypass what dragging is prevented from doing — an editor-locked image
        // sets both flags, so all four arrows are inert on it.
        const horizontal = key === 'ArrowLeft' || key === 'ArrowRight';
        if (horizontal && activeObject.lockMovementX) return;
        if (!horizontal && activeObject.lockMovementY) return;

        switch (key) {
            case 'ArrowLeft':
                activeObject.set('left', activeObject.left - 1);
                break;
            case 'ArrowRight':
                activeObject.set('left', activeObject.left + 1);
                break;
            case 'ArrowUp':
                activeObject.set('top', activeObject.top - 1);
                break;
            case 'ArrowDown':
                activeObject.set('top', activeObject.top + 1);
                break;
        }

        activeObject.setCoords();
        this.canvas.renderAll();
        this.markUnsaved();
        // set() fires no Fabric events, so announce the move explicitly —
        // history snapshots the step and container members re-derive their
        // design geometry (otherwise the next reflow would snap the arrow-moved
        // box back to its stale snapshot position).
        this.canvas.fire('object:modified', { target: activeObject });
    }

    /**
     * A layer-mode variant with no background layer renders transparent —
     * show the classic checkerboard behind the canvas element so that state
     * is visible. Re-evaluated when the group editor switches the active
     * variant (backgroundModeValue changes).
     */
    _syncTransparencyHint() {
        const element = this.canvas && typeof this.canvas.getElement === 'function'
            ? this.canvas.getElement()
            : null;
        const wrapper = element ? element.closest('.canvas-wrapper') : null;
        if (!wrapper) return;
        wrapper.classList.toggle('canvas-wrapper--transparent', this.backgroundModeValue === 'layer');
    }

    backgroundModeValueChanged() {
        // The initial callback fires before connect() creates the canvas —
        // connect() calls _syncTransparencyHint() itself once ready.
        this._syncTransparencyHint();
    }

    async setBackgroundImage(imageUrl) {
        // Fabric v7: FabricImage.fromURL is Promise-based;
        // backgroundImage is now a property assignment, not a setter method.
        const img = await FabricImage.fromURL(imageUrl, { crossOrigin: 'anonymous' });
        this.coverBackgroundImage(img);
        this.canvas.backgroundImage = img;
        this.canvas.renderAll();
    }

    /**
     * Layer-mode counterpart of setBackgroundImage: the background is a
     * regular image object marked `isBackground`, cover-fitted TOP-LEFT
     * (overflow crops bottom-right; must match the server's
     * `ImagePlacement::computeCover` / `BackgroundLayer::buildObject`).
     *
     * Replaces the existing background layer IN PLACE — same stack index,
     * same inputId/name/placeholder metadata — so picking a new picture never
     * duplicates the layer or rebinds its fill slot. Without an existing
     * layer it lands at the bottom of the stack. Unlike the legacy flow this
     * is an ordinary canvas edit: dirty until saved, undoable, no
     * persistBackgroundPath side channel (the entity column is synced
     * server-side from the saved canvas).
     */
    async setBackgroundLayer(imageUrl, assetPath = null, assetId = null) {
        const existing = this.canvas.getObjects().find((o) => o.isBackground === true);

        const img = await FabricImage.fromURL(imageUrl, { crossOrigin: 'anonymous' });
        coverForDimensions(img, this.canvas.width, this.canvas.height, 'top-left');

        img.isBackground = true;
        img.inputId = existing?.inputId || crypto.randomUUID();
        img.imagePlaceholder = existing?.imagePlaceholder === true;
        if (existing?.name !== undefined) img.name = existing.name;
        if (existing?.description !== undefined) img.description = existing.description;
        if (existing?.hidable !== undefined) img.hidable = existing.hidable;
        if (existing?.allowedDirectoryIds !== undefined) img.allowedDirectoryIds = existing.allowedDirectoryIds;
        img.assetPath = assetPath || undefined;
        img.assetId = assetId || undefined;
        // A FRESH background is seeded locked — a designer picking a backdrop
        // almost never wants to nudge it next, and a locked layer is
        // click-through so it can't swallow the rubber band. Unlocking it (the
        // mini-toolbar padlock / the popover) makes it move and resize like any
        // other picture; a REPLACEMENT keeps whatever lock state was set.
        img.editorLocked = existing?.editorLocked !== undefined ? existing.editorLocked : true;
        applyEditorLock(img);

        let index = 0;
        if (existing) {
            index = this.canvas.getObjects().indexOf(existing);
            this.canvas.remove(existing);
        }
        this.canvas.add(img);
        this.canvas.moveObjectTo(img, index);
        this.canvas.renderAll();
        this.markUnsaved();
    }

    /**
     * Scale + center a background image so it COVERS the whole canvas
     * (CSS `object-fit: cover`, `background-position: center center`) — never
     * cropped to a quadrant, never letterboxed.
     *
     * Fabric v7 changed the default object origin to center/center, so a
     * background assigned without an explicit origin lands its CENTRE at canvas
     * (0,0) and only the bottom-right quadrant is visible — that was the
     * "background is cropped" bug. We pin the centre to the canvas centre and
     * scale by the LARGER axis ratio so the image bleeds to every edge
     * regardless of the source image's size or aspect ratio. The identical math
     * runs server-side in templates/api/template_variant_render.html.twig, so
     * the editor preview and the exported PNG always match.
     */
    coverBackgroundImage(img) {
        coverForDimensions(img, this.canvas.width, this.canvas.height);
    }

    /**
     * Force every project font face to actually download, and resolve only
     * once they are usable for canvas text. Uses the native CSS Font Loading
     * API: `document.fonts.load()` triggers the matching @font-face declared
     * in the page <style> (family names are emitted identically server-side —
     * `"<font> (<face>)"`) and its promise settles when the glyphs are ready.
     *
     * This replaces FontFaceObserver, whose fixed ~3s timeout could fire
     * before a large face finished downloading on a cold cache. Per-face
     * failures are swallowed so one broken font never blocks the rest (or the
     * canvas render that awaits this).
     */
    async loadFonts() {
        const families = this.customFontsValue || [];

        await Promise.all(families.map(async (family) => {
            try {
                await document.fonts.load(`16px "${family}"`);
            } catch (err) {
                console.error(`Font ${family} failed to load:`, err);
            }
        }));
    }

    populateFontSelect() {
        // The text popover's font select AND the "Přidat text" modal's — the
        // modal lets the designer pick the new text's face up front.
        ['font-family-control', 'addTextFont'].forEach((id) => {
            const fontFamilySelect = document.getElementById(id);
            if (!fontFamilySelect) {
                return;
            }
            fontFamilySelect.innerHTML = '';

            (this.customFontsValue || []).forEach((font) => this.addFontOption(fontFamilySelect, font));
        });
    }

    addFontOption(selectElement, font) {
        const option = document.createElement('option');
        option.value = font;
        option.textContent = font;
        selectElement.appendChild(option);
    }

    /**
     * Repaint the canvas with correct font metrics after the browser reports
     * all faces ready. Glyph widths measured while a face was still a fallback
     * get cached under the same fontFamily key, so we clear Fabric's font
     * cache and re-run text layout before requesting a render. This is the
     * safety net behind the await in loadCanvasWithoutHistory — it covers any
     * face that settles after the first paint.
     */
    refreshAfterFontsLoaded() {
        if (!this.canvas) {
            return;
        }

        try {
            cache.clearFontCache();
        } catch (err) {
            // Non-fatal: the repaint below still corrects the painted glyphs.
        }

        this.canvas.getObjects().forEach((obj) => {
            if (typeof obj.initDimensions === 'function') {
                obj.initDimensions();
                obj.setCoords();
            }
        });

        this.canvas.requestRenderAll();
    }

    showAddTextModal() {
        const modal = new bootstrap.Modal('#addTextModal');
        modal.show();
    }

    showAddChecklistModal() {
        const modal = new bootstrap.Modal('#addChecklistModal');
        modal.show();
    }

    /**
     * Stage 7: open the unified project image gallery in "background" mode.
     * The mode is stashed on the controller so onAssetSelected (fired from a
     * thumbnail click or an upload completion inside the modal) knows
     * whether to set the canvas background or drop the image as a new
     * Fabric object.
     */
    showBackgroundModal() {
        this.galleryMode = 'background';
        const modal = new bootstrap.Modal('#imageGalleryModal');
        modal.show();
    }

    /**
     * Stage 7: same modal as showBackgroundModal but in "addImage" mode.
     */
    showAddImageModal() {
        this.galleryMode = 'addImage';
        const modal = new bootstrap.Modal('#imageGalleryModal');
        modal.show();
    }

    /**
     * Same modal in "replaceImage" mode: the picked asset swaps ONLY the
     * selected image object's picture (mini-toolbar image button).
     */
    showReplaceImageModal() {
        const active = this.canvas.getActiveObject();
        if (!active || (active.type || '').toLowerCase() !== 'image') {
            return;
        }
        this.galleryMode = 'replaceImage';
        const modal = new bootstrap.Modal('#imageGalleryModal');
        modal.show();
    }

    /**
     * Swap the selected image object's PICTURE, nothing else: position, angle,
     * stack index and every custom prop (inputId, placeholder metadata, …)
     * stay — the scale is re-fitted so the DISPLAYED bounding box is preserved
     * across differing natural sizes. Background layers get the deterministic
     * top-left cover swap instead (the same edit the "Pozadí" pick makes).
     *
     * Deliberately NOT group-propagated: like backgrounds, the picture itself
     * is per-variant — group sync diffs props by inputId and an assetPath
     * copy without the pixels would make sibling saves lie.
     */
    async replaceSelectedImage(imageUrl, assetPath = null, assetId = null) {
        const active = this.canvas.getActiveObject();
        if (!active || (active.type || '').toLowerCase() !== 'image') {
            return;
        }

        if (active.isBackground === true) {
            await this.setBackgroundLayer(imageUrl, assetPath, assetId);
            this.dispatch('background:changed', { detail: { url: imageUrl, path: assetPath, layerMode: true } });
            return;
        }

        const displayedWidth = active.width * (active.scaleX || 1);
        const displayedHeight = active.height * (active.scaleY || 1);

        await active.setSrc(imageUrl, { crossOrigin: 'anonymous' });

        // setSrc updated width/height to the new picture's natural size.
        active.set({
            scaleX: displayedWidth / (active.width || 1),
            scaleY: displayedHeight / (active.height || 1),
        });
        active.assetPath = assetPath || undefined;
        active.assetId = assetId || undefined;
        active.setCoords();

        this.canvas.requestRenderAll();
        // set()/setSrc() fire no Fabric events — announce (dirty + history).
        this.canvas.fire('object:modified', { target: active });
        this.markUnsaved();
    }

    /**
     * Stage 7: handler for the gallery modal's `asset-selected` window event.
     * Routes the picked asset's URL to the right canvas operation based on
     * the mode set when the modal was opened. For backgrounds we ALSO POST
     * the path to the module's edit-variant endpoint so the variant
     * entity stays in sync with the visible canvas — without this, the
     * picked background would only be set visually and would revert on
     * reload.
     */
    async onAssetSelected(event) {
        const { url, path, id } = event.detail || {};
        if (!url) {
            return;
        }

        // Hide the modal FIRST — the background branch below awaits an image
        // fetch, and a modal that stays open until the picture lands reads
        // as a hang.
        const galleryModalElement = document.getElementById('imageGalleryModal');
        if (galleryModalElement) {
            const galleryModal = bootstrap.Modal.getInstance(galleryModalElement);
            if (galleryModal) {
                galleryModal.hide();
            }
        }

        const mode = this.galleryMode || 'addImage';

        if (mode === 'background') {
            if (this.backgroundModeValue === 'layer') {
                // Layer mode: an ordinary (undoable, dirty-until-saved) canvas
                // edit — no side-channel POST, the entity column follows the
                // saved canvas server-side. AWAITED, and that await is
                // load-bearing: the group editor's background:changed handler
                // reads the fresh isBackground object off the canvas, and a
                // dispatch fired before the layer landed found the OLD layer
                // (or nothing) — the pick then never reached the sibling
                // variants even with "Úprava více variant" on.
                await this.setBackgroundLayer(url, path, id);
            } else {
                this.setBackgroundImage(url);
                if (path && this.hasEditVariantUrlValue) {
                    this.persistBackgroundPath(path);
                }
            }
            // The group editor tracks per-variant backgrounds — tell it the
            // active variant's background just changed.
            this.dispatch('background:changed', { detail: { url, path, layerMode: this.backgroundModeValue === 'layer' } });
        } else if (mode === 'replaceImage') {
            this.replaceSelectedImage(url, path, id);
        } else if (mode === 'bulletImage') {
            // List bullet pick for the input-properties popover — both
            // controllers share the editor root, so a plain Stimulus dispatch
            // reaches its element listener without template wiring.
            this.dispatch('bullet-image', { detail: { url, path, id } });
        } else if (mode === 'checkboxImage') {
            // Checkbox state image pick (which state is being set is
            // remembered by canvas_input_properties itself).
            this.dispatch('checkbox-image', { detail: { url, path, id } });
        } else {
            this.addImageToCanvas(url, path, id);
        }
    }

    persistBackgroundPath(path) {
        const formData = new FormData();
        formData.append('backgroundImagePath', path);

        fetch(this.editVariantUrlValue, {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' },
        }).catch((error) => {
            console.error('Failed to persist background path:', error);
        });
    }

    submitAddText(event) {
        event.preventDefault();

        const form = document.getElementById('addTextForm');
        const locked = document.getElementById('lockedCheckbox').checked;
        const uppercase = document.getElementById('uppercaseCheckbox').checked;
        const description = document.getElementById('description').value || null;
        const inputName = document.getElementById('textName').value || 'Text';
        const hidable = document.getElementById('hidableCheckbox').checked;

        // The designed font: the modal's pick, else the first project font,
        // else Arial when the project has no fonts at all.
        const fontSelect = document.getElementById('addTextFont');
        const fontFamily = (fontSelect && fontSelect.value)
            || (this.customFontsValue.length > 0 ? this.customFontsValue[0] : 'Arial');

        // Font choice: the EXTRA faces the end user may switch to (the
        // checklist's checked, unlocked rows) — only while the toggle is on.
        const fontChoiceToggle = document.getElementById('addTextFontChoice');
        const allowedFonts = fontChoiceToggle && fontChoiceToggle.checked
            ? collectAllowedFonts(document.getElementById('addTextFontChoiceList'))
            : [];

        const textBox = new Textbox(inputName, {
            left: 100,
            top: 100,
            width: 200,
            fontFamily: fontFamily,
            fill: '#000000',
            fontSize: 24,
            lineHeight: DEFAULT_LINE_HEIGHT,
            textAlign: 'left',
            editable: true,
            // Fabric v7 changed the default origin to 'center'/'center'.
            // Pin to 'left'/'top' so newly created objects render at the
            // same coordinates as legacy v5 data (which all has explicit
            // originX/Y) and so the export renderer treats them identically.
            originX: 'left',
            originY: 'top',
            lockScalingX: true,
            lockScalingY: true,
            lockScalingFlip: true,
            lockRotation: true,
            hasControls: true,
            cornerStyle: 'circle',
            cornerSize: 8,
            selectable: true,
            inputId: crypto.randomUUID(),
            name: inputName,
            locked: locked,
            uppercase: uppercase,
            description: description,
            hidable: hidable,
            allowedFonts: allowedFonts,
        });

        this.canvas.add(textBox);
        this.canvas.setActiveObject(textBox);
        this.canvas.renderAll();

        const modal = bootstrap.Modal.getInstance('#addTextModal');
        modal.hide();

        form.reset();
    }

    /**
     * Dedicated CHECKLIST component: one textbox whose value is a checkbox
     * list. Under the hood it is a plain rich+lists+listCheckboxes input —
     * same render pipeline, same API contract — plus `checklist: true` so
     * the fill page swaps the WYSIWYG for the simple per-item editor, and
     * the four capability flags. The default items become BOTH the designed
     * stand-in text and the sample value (envelope with all-'cb' lines), so
     * canvas, preview and untouched export agree from the first save.
     */
    submitAddChecklist(event) {
        event.preventDefault();

        const form = document.getElementById('addChecklistForm');
        const inputName = document.getElementById('checklistName').value || 'Zaškrtávací seznam';
        const rawItems = document.getElementById('checklistItems').value
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== '');
        const items = rawItems.length > 0 ? rawItems : ['První položka', 'Druhá položka', 'Třetí položka'];
        const text = items.join('\n');

        const fontFamily = this.customFontsValue.length > 0 ? this.customFontsValue[0] : 'Arial';

        const textBox = new Textbox(text, {
            left: 100,
            top: 100,
            width: 300,
            fontFamily: fontFamily,
            fill: '#000000',
            fontSize: 24,
            lineHeight: DEFAULT_LINE_HEIGHT,
            textAlign: 'left',
            editable: true,
            originX: 'left',
            originY: 'top',
            lockScalingX: true,
            lockScalingY: true,
            lockScalingFlip: true,
            lockRotation: true,
            hasControls: true,
            cornerStyle: 'circle',
            cornerSize: 8,
            selectable: true,
            inputId: crypto.randomUUID(),
            name: inputName,
            locked: false,
            uppercase: false,
            description: null,
            hidable: document.getElementById('checklistHidableCheckbox').checked,
            richText: true,
            lists: true,
            listCheckboxes: true,
            checklist: true,
            checklistToggle: document.getElementById('checklistToggleCheckbox').checked,
            checklistEditText: document.getElementById('checklistEditTextCheckbox').checked,
            checklistAdd: document.getElementById('checklistAddCheckbox').checked,
            checklistRemove: document.getElementById('checklistRemoveCheckbox').checked,
            sampleValue: JSON.stringify({
                runs: [{ text: text, fontFamily: null, color: null, underline: false }],
                lines: items.map(() => 'cb'),
            }),
        });

        this.canvas.add(textBox);
        this.canvas.setActiveObject(textBox);
        this.canvas.renderAll();

        const modal = bootstrap.Modal.getInstance('#addChecklistModal');
        modal.hide();

        form.reset();
    }

    /**
     * "Přidat tvar" — drop a vector shape on the canvas. The kind rides as a
     * Stimulus action param from the left-panel picker
     * (`data-canvas-editor-kind-param="circle"`).
     *
     * Shapes are DECORATIVE: they carry no fillable-input metadata, so they
     * never reach the textInputs / imageInputs payloads (canvas_payload filters
     * both by type) nor the API. They do get an `inputId` — that is the join
     * key the group editor fans structure out by and the container engine
     * addresses members with — which is minted inside createShapeObject.
     *
     * Everything downstream is automatic: `object:added` marks the form dirty,
     * pushes an undo snapshot, rebuilds the layers panel and, in the group
     * editor, projects the shape into every sibling dimension.
     */
    addShape(event) {
        const kind = event && event.params ? event.params.kind : null;
        if (!kind || !this.canvas) return;

        const brandColors = Array.isArray(this.brandColorsValue) ? this.brandColorsValue : [];
        const shape = createShapeObject(kind, {
            canvasWidth: this.canvas.getWidth(),
            canvasHeight: this.canvas.getHeight(),
            fill: brandColors.length > 0 ? brandColors[0] : null,
            cascade: this._shapesAdded || 0,
        });
        if (!shape) return;

        this._shapesAdded = (this._shapesAdded || 0) + 1;

        this.canvas.add(shape);
        this.canvas.setActiveObject(shape);
        this.canvas.renderAll();
        // setActiveObject fires no Fabric selection event — surface the shape
        // popover straight away (same reason addImageToCanvas does it), so the
        // designer can restyle without hunting for the freshly added object.
        this.dispatchSelectionChanged();
    }

    async addImageToCanvas(imageUrl, assetPath = null, assetId = null) {
        // Fabric v7: FabricImage.fromURL is Promise-based.
        const img = await FabricImage.fromURL(imageUrl, { crossOrigin: 'anonymous' });
        img.set({
            angle: 0,
            // (`cornersize`/`hasRotatingPoint` were dead props — the casing was
            // wrong and the rotating point ships by default since Fabric v6.)
            cornerSize: 10,
        });
        // Position + scale: contain-fit so the picture is never dropped partly
        // (or almost entirely) outside the canvas. Sets the left/top origins.
        containForDimensions(img, this.canvas.width, this.canvas.height, IMAGE_DROP_OFFSET);
        // Stamp inputId proactively (Stage 2 convention) so it can be promoted
        // to a fillable image placeholder by id.
        if (!img.inputId) {
            img.inputId = crypto.randomUUID();
        }
        // Decorative by default — the designer flips "placeholder" in the image
        // properties panel. Carry the gallery storage path + id so the server
        // renderer can inline the picture without reverse-mapping its URL.
        img.imagePlaceholder = false;
        if (assetPath) {
            img.assetPath = assetPath;
        }
        if (assetId) {
            img.assetId = assetId;
        }
        this.canvas.add(img);
        this.canvas.setActiveObject(img);
        this.canvas.renderAll();
        // setActiveObject() does not fire Fabric's selection events, so surface
        // the image properties panel (placeholder toggle + settings) right away
        // instead of making the designer click the freshly-added image first.
        this.dispatchSelectionChanged();
    }

    /**
     * Pure serialization of the current canvas into the editor-save payload
     * strings — shared with the group editor via canvas_payload.js so both
     * save paths emit byte-identical data.
     *
     * @returns {{canvas: string, textInputs: string, imageInputs: string}}
     */
    collectEditorPayload() {
        return buildVariantPayload(this.canvas);
    }

    submitForm() {
        const form = this.canvasTarget.closest('form');

        const payload = this.collectEditorPayload();
        this.canvasTarget.value = payload.canvas;
        this.textInputsTarget.value = payload.textInputs;
        this.imageInputsTarget.value = payload.imageInputs;

        // Preview thumbnail via canvas.toDataURL(). A cross-origin image can
        // taint the canvas (SecurityError "operation is insecure"), which must
        // never block the save — persist the canvas + inputs without a fresh
        // preview (the thumbnail falls back to the background server-side).
        // Gallery images are loaded crossorigin="anonymous" so this normally
        // succeeds; this guard only catches the tainted-canvas edge cases.
        try {
            this.previewImageTarget.value = this.getScaledCanvasDataURI(PREVIEW_MAX_WIDTH);
        } catch (err) {
            console.warn('Preview generation skipped (tainted canvas):', err);
            this.previewImageTarget.value = '';
        }

        // Returns a Promise<boolean> resolving to whether the save succeeded,
        // so callers (e.g. saveAndExport) can chain navigation on success.
        return fetch(form.action, {
            method: form.method,
            body: new FormData(form),
            headers: {
                'Accept': 'application/json',
            },
        })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    this.markSaved();
                    return true;
                }

                console.error('Ukládání se nepovedlo:', data.message);
                alert('Ukládání se nepovedlo. Prosím zkuste to znovu později.');
                return false;
            })
            .catch(error => {
                console.error('Error during save:', error);
                alert('Ukládání se nepovedlo. Prosím zkuste to znovu později.');
                return false;
            });
    }

    getScaledCanvasDataURI(maxWidth) {
        // Deselect all objects to hide controls
        const previousActiveObject = this.canvas.getActiveObject();
        this.canvas.discardActiveObject();
        this.canvas.renderAll();

        const originalWidth = this.canvas.width;
        const originalHeight = this.canvas.height;
        const aspectRatio = originalWidth / originalHeight;

        // Never upscale: a canvas narrower than the cap has nothing more to
        // give, and stretching it would only inflate the PNG.
        const newWidth = Math.min(maxWidth, originalWidth);
        const newHeight = newWidth / aspectRatio;

        // Create an off-screen canvas
        const offScreenCanvas = document.createElement('canvas');
        offScreenCanvas.width = newWidth;
        offScreenCanvas.height = newHeight;
        const ctx = offScreenCanvas.getContext('2d');

        // Draw the scaled canvas. canvas.getElement() still exists in v7.
        ctx.drawImage(this.canvas.getElement(), 0, 0, newWidth, newHeight);

        // Convert the off-screen canvas to a Data URI
        const dataURI = offScreenCanvas.toDataURL('image/png');

        // Restore any previous selection if needed (optional)
        this.canvas.setActiveObject(previousActiveObject);
        this.canvas.renderAll();

        return dataURI;
    }
}
