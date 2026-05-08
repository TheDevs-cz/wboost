import { Controller } from "@hotwired/stimulus";
import { Canvas, Textbox, FabricImage } from "fabric";
import FontFaceObserver from 'fontfaceobserver';

import { CANVAS_CUSTOM_PROPERTIES } from './canvas_custom_properties.js';

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
        "canvas", "textInputs", "previewImage", "unsavedChangesMessage",
    ];

    static values = {
        backgroundImage: String,
        customFonts: Array,
        editVariantUrl: String,
    };

    connect() {
        this.canvas = new Canvas('c');

        const canvasJson = this.element.dataset.canvasEditorCanvasJson;
        if (canvasJson && canvasJson.trim() !== '') {
            // loadCanvasWithoutHistory is async in v7 (Promise-based loadFromJSON);
            // Stimulus connect() can't be async, so we fire-and-forget.
            this.loadCanvasWithoutHistory(canvasJson);
        }

        // Always override background when loaded
        if (this.backgroundImageValue) {
            this.setBackgroundImage(this.backgroundImageValue);
        }

        this.loadFontsAndPopulateSelect();

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

    async loadCanvasWithoutHistory(canvasJson) {
        this.loadingCanvas = true;
        try {
            // Fabric v7 loadFromJSON returns a Promise (no callback form).
            await this.canvas.loadFromJSON(canvasJson);

            // Defensive: stamp inputId on any object that was loaded without
            // one. Handles legacy data loaded into the editor before the
            // server-side migration has run, plus future-proofs against any
            // other source that might emit objects without ids.
            this.canvas.getObjects().forEach((obj) => {
                if ((obj.type === 'textbox' || obj.type === 'image') && !obj.inputId) {
                    obj.inputId = crypto.randomUUID();
                }
            });
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
        this.canvas.backgroundImage = img;
        this.canvas.renderAll();
    }

    async loadFontsAndPopulateSelect() {
        const fontFamilySelect = document.getElementById('font-family-control');
        if (!fontFamilySelect) {
            return;
        }
        fontFamilySelect.innerHTML = '';

        const fontPromises = this.customFontsValue.map(font => {
            const fontObserver = new FontFaceObserver(font);
            return fontObserver.load().then(() => {
                this.addFontOption(fontFamilySelect, font);
            }).catch(err => {
                console.error(`Font ${font} failed to load:`, err);
            });
        });

        await Promise.all(fontPromises);
    }

    addFontOption(selectElement, font) {
        const option = document.createElement('option');
        option.value = font;
        option.textContent = font;
        selectElement.appendChild(option);
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
        const { url, path } = event.detail || {};
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
            this.addImageToCanvas(url);
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

    async addImageToCanvas(imageUrl) {
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
        // Stamp inputId proactively (Stage 2 convention) so future
        // image-placeholder inputs can address this object by id.
        if (!img.inputId) {
            img.inputId = crypto.randomUUID();
        }
        this.canvas.add(img);
        this.canvas.setActiveObject(img);
        this.canvas.renderAll();
    }

    submitForm() {
        const form = this.canvasTarget.closest('form');

        // Serialize the canvas JSON
        const canvasJSON = this.canvas.toJSON(CANVAS_CUSTOM_PROPERTIES);
        this.canvasTarget.value = JSON.stringify(canvasJSON);

        // Serialize only the text inputs. inputId is stamped here as a last
        // line of defence; it should already be present (set on creation,
        // restored from JSON, or fixed up in loadCanvasWithoutHistory).
        const textInputs = this.canvas.getObjects('textbox').map(textbox => {
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
        this.previewImageTarget.value = this.getScaledCanvasDataURI(400); // 400px max-width

        fetch(form.action, {
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
                } else {
                    console.error('Ukládání se nepovedlo:', data.message);
                    alert('Ukládání se nepovedlo. Prosím zkuste to znovu později.');
                }
            })
            .catch(error => {
                console.error('Error during save:', error);
                alert('Ukládání se nepovedlo. Prosím zkuste to znovu později.');
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
