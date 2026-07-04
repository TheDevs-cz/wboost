import { Controller } from "@hotwired/stimulus";
import { Canvas, Textbox, FabricImage, cache } from "fabric";

import { CANVAS_CUSTOM_PROPERTIES } from './canvas_custom_properties.js';
import { DEFAULT_LINE_HEIGHT } from './canvas_text_toolbar_controller.js';

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
        customFonts: Array,
        editVariantUrl: String,
    };

    connect() {
        this.canvas = new Canvas('c');

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

        // Always override background when loaded
        if (this.backgroundImageValue) {
            this.setBackgroundImage(this.backgroundImageValue);
        }

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

    markUnsaved() {
        this.unsavedChangesMessageTarget.classList.remove('d-none');
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

            // CRITICAL: Fabric v7's _fromObject does not copy arbitrary
            // custom properties from the source JSON onto the deserialized
            // object. Only properties registered as customProperties (or in
            // the class's known SerializedObjectProps) are restored — our
            // inputId / name / locked / etc. are stripped. Without this
            // restore pass:
            //   - the editor toolbar shows empty input metadata after
            //     reload (the "I renamed the field, refreshed, the name was
            //     empty" report);
            //   - the export renderer cannot match overrides by inputId
            //     because every loaded object has obj.inputId === undefined
            //     (the "placeholder text is not replaced" report).
            // Walk by positional index — Fabric preserves object order
            // through loadFromJSON.
            const sourceObjects = Array.isArray(sourceCanvas.objects) ? sourceCanvas.objects : [];
            this.canvas.getObjects().forEach((obj, idx) => {
                const source = sourceObjects[idx];
                if (source) {
                    CANVAS_CUSTOM_PROPERTIES.forEach((prop) => {
                        if (source[prop] !== undefined) {
                            obj[prop] = source[prop];
                        }
                    });
                }
                // Defensive: if a textbox/image still has no inputId (legacy
                // data, fresh-on-canvas object, etc.), mint one. Type match
                // is case-insensitive — v5 emitted 'textbox', v7 emits
                // 'Textbox'.
                const t = (obj.type || '').toLowerCase();
                if ((t === 'textbox' || t === 'image') && !obj.inputId) {
                    obj.inputId = crypto.randomUUID();
                }
            });

            // A background restored from saved JSON may predate the cover fix
            // (center origin, no scale → cropped to a quadrant under Fabric v7).
            // Re-apply cover/center from the image's natural size so the editor
            // matches the export. Idempotent for backgrounds already covered.
            if (this.canvas.backgroundImage) {
                this.coverBackgroundImage(this.canvas.backgroundImage);
            }

            this.canvas.renderAll();
        } finally {
            this.loadingCanvas = false;
        }
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
        const activeObject = this.canvas.getActiveObject();
        if (activeObject) {
            // Discard FIRST so selection:cleared fires and the floating chrome
            // hides; then remove.
            this.canvas.discardActiveObject();
            this.canvas.remove(activeObject);
            this.canvas.renderAll();
        }
    }

    moveSelectedObject(key) {
        const activeObject = this.canvas.getActiveObject();
        if (!activeObject) return;

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
        const element = typeof img.getElement === 'function' ? img.getElement() : null;
        const imageWidth = (element && (element.naturalWidth || element.width)) || img.width || 1;
        const imageHeight = (element && (element.naturalHeight || element.height)) || img.height || 1;
        const scale = Math.max(this.canvas.width / imageWidth, this.canvas.height / imageHeight);
        img.set({
            originX: 'center',
            originY: 'center',
            left: this.canvas.width / 2,
            top: this.canvas.height / 2,
            cropX: 0,
            cropY: 0,
            scaleX: scale,
            scaleY: scale,
        });
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
        const fontFamilySelect = document.getElementById('font-family-control');
        if (!fontFamilySelect) {
            return;
        }
        fontFamilySelect.innerHTML = '';

        (this.customFontsValue || []).forEach((font) => this.addFontOption(fontFamilySelect, font));
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
     * Stage 7: handler for the gallery modal's `asset-selected` window event.
     * Routes the picked asset's URL to the right canvas operation based on
     * the mode set when the modal was opened. For backgrounds we ALSO POST
     * the path to `edit_social_network_template_variant` so the variant
     * entity stays in sync with the visible canvas — without this, the
     * picked background would only be set visually and would revert on
     * reload.
     */
    onAssetSelected(event) {
        const { url, path, id } = event.detail || {};
        if (!url) {
            return;
        }

        const mode = this.galleryMode || 'addImage';

        if (mode === 'background') {
            this.setBackgroundImage(url);
            if (path && this.hasEditVariantUrlValue) {
                this.persistBackgroundPath(path);
            }
        } else {
            this.addImageToCanvas(url, path, id);
        }

        const modalElement = document.getElementById('imageGalleryModal');
        if (modalElement) {
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
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

        // Determine the font family: use the first custom font, or fall back to 'Arial' if none are provided
        const fontFamily = this.customFontsValue.length > 0 ? this.customFontsValue[0] : 'Arial';

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
        });

        this.canvas.add(textBox);
        this.canvas.setActiveObject(textBox);
        this.canvas.renderAll();

        const modal = bootstrap.Modal.getInstance('#addTextModal');
        modal.hide();

        form.reset();
    }

    async addImageToCanvas(imageUrl, assetPath = null, assetId = null) {
        // Fabric v7: FabricImage.fromURL is Promise-based.
        const img = await FabricImage.fromURL(imageUrl, { crossOrigin: 'anonymous' });
        img.set({
            left: 100,
            top: 100,
            angle: 0,
            // Pin origin to 'left'/'top' to override v7's new 'center' default
            // — keeps newly-added images consistent with legacy data and the
            // server-side renderer's expectations.
            originX: 'left',
            originY: 'top',
            cornersize: 10,
            hasRotatingPoint: true,
        });
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

    submitForm() {
        const form = this.canvasTarget.closest('form');

        // Serialize the canvas JSON.
        //
        // Fabric v7 silently drops some custom properties from
        // toJSON(propertiesToInclude) — specifically, properties that aren't
        // declared in a class's stateProperties don't always survive the
        // serialization round-trip even when listed in propertiesToInclude.
        // To guarantee round-trip integrity for our custom annotation
        // properties (inputId, name, locked, uppercase, etc.) we manually
        // merge each in-memory object's values back onto the serialized
        // entry by positional index. This is bulletproof regardless of how
        // Fabric internally classifies the property.
        const canvasJSON = this.canvas.toJSON(CANVAS_CUSTOM_PROPERTIES);
        const inMemoryObjects = this.canvas.getObjects();
        canvasJSON.objects.forEach((serialized, idx) => {
            const live = inMemoryObjects[idx];
            if (!live) return;
            CANVAS_CUSTOM_PROPERTIES.forEach((prop) => {
                const value = live[prop];
                if (value !== undefined) {
                    serialized[prop] = value;
                }
            });
        });
        this.canvasTarget.value = JSON.stringify(canvasJSON);

        // Serialize only the textbox inputs.
        //
        // Type filter is case-insensitive: Fabric v7's `getObjects('textbox')`
        // does NOT match v7-saved objects (whose .type is 'Textbox'), so we
        // walk all objects and filter by lower-case type name. This matches
        // the same convention used in loadCanvasWithoutHistory.
        const textboxes = inMemoryObjects.filter((obj) => (obj.type || '').toLowerCase() === 'textbox');
        const textInputs = textboxes.map((textbox) => {
            if (!textbox.inputId) {
                textbox.inputId = crypto.randomUUID();
            }
            return {
                inputId: textbox.inputId,
                name: textbox.name,
                maxLength: textbox.maxLength || null,
                locked: textbox.locked || false,
                uppercase: textbox.uppercase || false,
                description: textbox.description || '',
                hidable: textbox.hidable || false,
            };
        });

        this.textInputsTarget.value = JSON.stringify(textInputs);

        // Image placeholders: every image object the designer marked fillable.
        // (see imageInputs extraction + preview below)
        const imageInputs = inMemoryObjects
            .filter((obj) => (obj.type || '').toLowerCase() === 'image' && obj.imagePlaceholder === true)
            .map((img) => {
                if (!img.inputId) {
                    img.inputId = crypto.randomUUID();
                }
                return {
                    inputId: img.inputId,
                    name: img.name || null,
                    description: img.description || null,
                    allowMove: img.allowMove || false,
                    allowResize: img.allowResize || false,
                    allowRotate: img.allowRotate || false,
                    hidable: img.hidable || false,
                    allowedDirectoryIds: Array.isArray(img.allowedDirectoryIds) ? img.allowedDirectoryIds : [],
                };
            });
        this.imageInputsTarget.value = JSON.stringify(imageInputs);

        // Preview thumbnail via canvas.toDataURL(). A cross-origin image can
        // taint the canvas (SecurityError "operation is insecure"), which must
        // never block the save — persist the canvas + inputs without a fresh
        // preview (the thumbnail falls back to the background server-side).
        // Gallery images are loaded crossorigin="anonymous" so this normally
        // succeeds; this guard only catches the tainted-canvas edge cases.
        try {
            this.previewImageTarget.value = this.getScaledCanvasDataURI(400); // 400px max-width
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

        let newWidth = maxWidth;
        let newHeight = maxWidth / aspectRatio;

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
