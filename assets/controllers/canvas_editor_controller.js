import { Controller } from "@hotwired/stimulus";
import { Canvas, Textbox, FabricImage, ActiveSelection } from "fabric";
import FontFaceObserver from 'fontfaceobserver';

const customProperties = ['inputId', 'name', 'maxLength', 'locked', 'uppercase', 'description', 'hidable'];

export default class extends Controller {
    static targets = [
        "canvas", "textInputs", "previewImage", "bringToFrontButton", "sendToBackButton", "deleteObjectButton", "scaleDisplay",
        "autosaveMessage", "lastAutosave", "undoButton", "redoButton", "autosaveDelay", "zoomInButton", "zoomOutButton", "canvasContainer",
        "unsavedChangesMessage", "duplicateButton", "alignLeftButton", "alignRightButton", "alignCenterButton", "alignTopButton", "alignBottomButton", "alignMiddleButton"
    ];

    static values = {
        backgroundImage: String,
        customFonts: Array
    }


    connect() {
        this.clipboard = null;
        this.canvas = new Canvas('c'); // Initialize the Fabric.js canvas

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

        this.autosaveTimeout = null;
        this.lastAutosaveTime = 0;
        this.history = [];
        this.redoStack = [];
        this.maxHistorySize = 20;
        this.currentScale = 1;
        this.minScale = 0.5;
        this.maxScale = 1.0;

        this.loadFontsAndPopulateSelect();

        // Initially hide buttons since no object is selected
        this.hideActionButtons();

        window.addEventListener('keydown', this.handleKeydown.bind(this));

        this.canvas.on('selection:created', this.updateControlsVisibility.bind(this));
        this.canvas.on('selection:updated', this.updateControlsVisibility.bind(this));
        this.canvas.on('selection:cleared', this.updateControlsVisibility.bind(this));
        this.canvas.on('text:changed', () => this.scheduleAutosave());
        this.canvas.on('text:changed', () => this.applyTextTransformOnInput());

        this.canvas.on('object:removed', () => {
            this.addToHistory();
            this.scheduleAutosave();
        });

        this.canvas.on('object:modified', () => {
            this.addToHistory();
            this.scheduleAutosave();
        });

        this.canvas.on('object:added', () => {
            if (!this.loadingCanvas) {
                this.addToHistory();
                this.scheduleAutosave();
            }
        });

        this.addToHistory();
    }

    disconnect() {
        // Clean up the event listener when the controller is disconnected
        window.removeEventListener('keydown', this.handleKeydown.bind(this));
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
            event.preventDefault(); // Prevent default browser behavior
            this.deleteObject();
            return;
        }

        // Check if there is an active object on the canvas

        // Handle arrow keys for moving the selected object only if an object is selected
        if (activeObject && ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
            event.preventDefault(); // Prevent scrolling or other default actions only if an object is selected
            this.moveSelectedObject(event.key);
        }

        if ((event.ctrlKey || event.metaKey) && event.key === 'c') {
            event.preventDefault();
            this.copy();
        } else if ((event.ctrlKey || event.metaKey) && event.key === 'v') {
            event.preventDefault();
            this.paste();
        }
    }

    moveSelectedObject(key) {
        const activeObject = this.canvas.getActiveObject();
        if (!activeObject) return; // No object selected, nothing to move

        // Adjust the object's position based on the arrow key pressed
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

        activeObject.setCoords(); // Update the object's coordinates
        this.canvas.renderAll(); // Re-render the canvas to reflect the changes
        this.scheduleAutosave(); // Optionally save the state after moving
    }

    zoomIn() {
        if (this.currentScale < this.maxScale) {
            this.currentScale += 0.1;
            this.applyScale();
        }
    }

    applyScale() {
        // Ensure scale is within bounds
        this.currentScale = Math.max(this.minScale, Math.min(this.maxScale, this.currentScale));

        // Apply the scale to the canvas container
        this.canvasContainerTarget.style.transform = `scale(${this.currentScale})`;

        this.updateButtonStates(); // Update the state of the buttons

        const scalePercentage = Math.round(this.currentScale * 100);
        this.scaleDisplayTarget.textContent = `${scalePercentage}%`;
    }

    zoomOut() {
        if (this.currentScale > this.minScale) {
            this.currentScale -= 0.1;
            this.applyScale();
        }
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
        fontFamilySelect.innerHTML = ''; // Clear existing options

        const fontPromises = this.customFontsValue.map(font => {

            const fontObserver = new FontFaceObserver(font);
            return fontObserver.load().then(() => {
                this.addFontOption(fontFamilySelect, font);
            }).catch(err => {
                console.error(`Font ${font} failed to load:`, err);
            });

        });

        // Wait for all custom fonts to be loaded
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

    showBackgroundModal() {
        const modal = new bootstrap.Modal('#backgroundModal');
        modal.show();
    }

    showAddImageModal() {
        // Show the Bootstrap modal
        const modal = new bootstrap.Modal('#imageUploadModal');
        modal.show();
    }

    submitAddText(event) {
        event.preventDefault();

        const form = document.getElementById('addTextForm');
        const locked = document.getElementById('lockedCheckbox').checked;
        const uppercase = document.getElementById('uppercaseCheckbox').checked;
        const description = document.getElementById('description').value || null;
        const inputName = document.getElementById('textName').value || 'Text'; // Default name if empty
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
        this.scheduleAutosave();

        // Hide the modal after submission
        const modal = bootstrap.Modal.getInstance('#addTextModal');
        modal.hide();

        // Clear the form inputs
        form.reset();
    }

    bringToFront() {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject) {
            // Fabric v7: stacking order methods moved to the canvas.
            this.canvas.bringObjectToFront(activeObject);
            this.canvas.discardActiveObject();
            this.canvas.renderAll();
            this.scheduleAutosave()
        }
    }

    sendToBack() {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject) {
            // Fabric v7: stacking order methods moved to the canvas.
            this.canvas.sendObjectToBack(activeObject);
            this.canvas.discardActiveObject();
            this.canvas.renderAll();
            this.scheduleAutosave()
        }
    }

    deleteObject() {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject) {
            this.canvas.remove(activeObject);
            this.canvas.renderAll();
            this.hideActionButtons(); // Hide buttons after deletion
        }
    }

    showActionButtons() {
        this.bringToFrontButtonTarget.removeAttribute('disabled');
        this.bringToFrontButtonTarget.classList.remove('disabled');
        this.sendToBackButtonTarget.removeAttribute('disabled');
        this.sendToBackButtonTarget.classList.remove('disabled');
        this.sendToBackButtonTarget.removeAttribute('disabled');
        this.deleteObjectButtonTarget.classList.remove('disabled');

        this.alignLeftButtonTarget.removeAttribute('disabled');
        this.alignLeftButtonTarget.classList.remove('disabled');
        this.alignRightButtonTarget.removeAttribute('disabled');
        this.alignRightButtonTarget.classList.remove('disabled');
        this.alignCenterButtonTarget.removeAttribute('disabled');
        this.alignCenterButtonTarget.classList.remove('disabled');

        this.alignTopButtonTarget.removeAttribute('disabled');
        this.alignTopButtonTarget.classList.remove('disabled');
        this.alignBottomButtonTarget.removeAttribute('disabled');
        this.alignBottomButtonTarget.classList.remove('disabled');
        this.alignMiddleButtonTarget.removeAttribute('disabled');
        this.alignMiddleButtonTarget.classList.remove('disabled');
    }

    hideActionButtons() {
        this.bringToFrontButtonTarget.setAttribute('disabled', 'disabled');
        this.bringToFrontButtonTarget.classList.add('disabled');
        this.sendToBackButtonTarget.setAttribute('disabled', 'disabled');
        this.sendToBackButtonTarget.classList.add('disabled');
        this.sendToBackButtonTarget.setAttribute('disabled', 'disabled');
        this.deleteObjectButtonTarget.classList.add('disabled');

        this.alignLeftButtonTarget.setAttribute('disabled', 'disabled');
        this.alignLeftButtonTarget.classList.add('disabled');
        this.alignRightButtonTarget.setAttribute('disabled', 'disabled');
        this.alignRightButtonTarget.classList.add('disabled');
        this.alignCenterButtonTarget.setAttribute('disabled', 'disabled');
        this.alignCenterButtonTarget.classList.add('disabled');

        this.alignTopButtonTarget.setAttribute('disabled', 'disabled');
        this.alignTopButtonTarget.classList.add('disabled');
        this.alignBottomButtonTarget.setAttribute('disabled', 'disabled');
        this.alignBottomButtonTarget.classList.add('disabled');
        this.alignMiddleButtonTarget.setAttribute('disabled', 'disabled');
        this.alignMiddleButtonTarget.classList.add('disabled');
    }

    updateControlsVisibility() {
        const activeObject = this.canvas.getActiveObject();
        const fontControls = document.getElementById('font-controls');

        if (activeObject) {
            this.showActionButtons();
        } else {
            this.hideActionButtons();
        }

        this.updateDuplicateButton();

        if (activeObject && activeObject.type === 'textbox') {
            fontControls.style.display = 'block';

            const defaultFont = this.customFontsValue.length > 0 ? this.customFontsValue[0] : '';

            // Set input values based on the active object's current properties
            document.getElementById('font-size-control').value = activeObject.fontSize;
            document.getElementById('font-color-control').value = activeObject.fill || '#000000';
            document.getElementById('text-align-control').value = activeObject.textAlign;
            document.getElementById('font-family-control').value = activeObject.fontFamily || defaultFont;
            document.getElementById('text-decoration-control').value = activeObject.textDecoration || 'none';
            document.getElementById('max-length-control').value = activeObject.maxLength || '';
            document.getElementById('locked-control').checked = activeObject.locked || false;
            document.getElementById('uppercase-control').checked = activeObject.uppercase || false;
            document.getElementById('name-control').value = activeObject.name || '';
            document.getElementById('description-control').value = activeObject.description || '';
            document.getElementById('hidable-control').checked = activeObject.hidable || false;

            console.log(activeObject.description);
        } else {
            fontControls.style.display = 'none';
        }
    }

    updateFontSize(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.set({ fontSize: event.target.value });
            this.canvas.renderAll();
            this.scheduleAutosave()
        }
    }

    updateFontColor(event) {
        let color = event.target.value.trim();

        // Add '#' if it's missing and the input is a valid hex color
        if (color && !color.startsWith('#')) {
            color = '#' + color;
        }

        // Validate hex color format (supports 3 or 6 character hex codes)
        const isValidHex = /^#([0-9A-F]{3,6})$/i.test(color);

        if (isValidHex) {
            const activeObject = this.canvas.getActiveObject();
            if (activeObject && activeObject.type === 'textbox') {
                activeObject.set({ fill: color });
                this.canvas.renderAll();
                this.scheduleAutosave()
            }
        }
    }

    updateTextAlign(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.set({ textAlign: event.target.value });
            this.canvas.renderAll();
            this.scheduleAutosave()
        }
    }

    updateFontFamily(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.set({ fontFamily: event.target.value });
            this.canvas.renderAll();
            this.scheduleAutosave()
        }
    }

    updateTextDecoration(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            // Reset all text decorations
            activeObject.set({
                underline: false,
                linethrough: false,
                overline: false,
            });

            // Apply the selected text decoration
            switch (event.target.value) {
                case 'underline':
                    activeObject.set({ underline: true });
                    break;
                case 'line-through':
                    activeObject.set({ linethrough: true });
                    break;
                case 'overline':
                    activeObject.set({ overline: true });
                    break;
                default:
                    break;
            }

            this.canvas.renderAll();
            this.scheduleAutosave()
        }
    }

    updateMaxLength(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            const maxLength = parseInt(event.target.value, 10);

            if (maxLength > 0) {
                activeObject.maxLength = maxLength;
                activeObject.text = activeObject.text.slice(0, maxLength);
                this.adjustTextWidth(activeObject);
            } else {
                activeObject.maxLength = undefined; // Remove max length restriction if the input is empty or zero
            }

            this.canvas.renderAll();
            this.scheduleAutosave()
        }
    }

    adjustTextWidth(textObject) {
        if (textObject.maxLength) {
            const canvasContext = this.canvas.getContext();
            canvasContext.font = `${textObject.fontSize}px ${textObject.fontFamily}`;
            const sampleText = 'W'.repeat(textObject.maxLength); // Use a wide character to estimate the maximum width
            const textWidth = canvasContext.measureText(sampleText).width;

            textObject.set({
                width: textWidth,
                lockScalingX: true,  // Lock horizontal scaling
                lockScalingY: true,  // Lock vertical scaling
                editable: true,      // Keep text editable
                hasControls: false   // Disable resize controls
            });
        }
    }

    scheduleAutosave() {
        this.unsavedChangesMessageTarget.classList.remove('d-none')

        // AUTOSAVE MECHANISM DISABLED
        return;

        const now = Date.now(); // Current timestamp in milliseconds
        const timeSinceLastSave = now - this.lastAutosaveTime;

        // Clear any existing timeout to debounce the autosave
        if (this.autosaveTimeout) {
            clearTimeout(this.autosaveTimeout);
        }

        if (timeSinceLastSave >= 5000) {
            this.autosave();  // Autosave immediately if 10s have passed
        } else {
            // Schedule autosave to run after x if not enough time has passed

            this.autosaveDelayTarget.classList.remove('d-none');

            this.autosaveTimeout = setTimeout(() => {
                this.autosave();
            }, 5000 - timeSinceLastSave);
        }
    }

    autosave() {
        clearTimeout(this.autosaveTimeout);
        this.autosaveTimeout = null;
        this.showAutosavingMessage();
        this.submitForm();
    }

    showAutosavingMessage() {
        this.autosaveMessageTarget.classList.remove('d-none');
    }

    showLastAutosaveTime() {
        this.unsavedChangesMessageTarget.classList.add('d-none')

        // FOR NOW DISABLED AUTOSAVE MECHANISM
        return;

        this.lastAutosaveTime = Date.now();
        const lastSaveDate = new Date(this.lastAutosaveTime); // Convert timestamp to Date object
        const hours = lastSaveDate.getHours().toString().padStart(2, '0');
        const minutes = lastSaveDate.getMinutes().toString().padStart(2, '0');
        const seconds = lastSaveDate.getSeconds().toString().padStart(2, '0');

        const formattedTime = `${hours}:${minutes}:${seconds}`;
        this.lastAutosaveTarget.textContent = `Automaticky uloženo: ${formattedTime}`;
        this.autosaveMessageTarget.classList.add('d-none');
        this.autosaveDelayTarget.classList.add('d-none');
    }

    submitForm() {
        const form = this.canvasTarget.closest('form');

        // Serialize the canvas JSON
        const canvasJSON = this.canvas.toJSON(customProperties);
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

        // Submit the form with fetch
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
                    this.showLastAutosaveTime();
                } else {
                    console.error('Ukládání se nepovedlo:', data.message);
                    alert('Ukládání se nepovedlo. Prosím zkuste to znovu později.');
                }
            })
            .catch(error => {
                console.error('Error during autosave:', error);
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

    uploadImage(event) {
        event.preventDefault();

        const form = this.element.querySelector('#image-upload-form');
        const formData = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            body: formData,
        })
            .then(response => response.json())
            .then(data => {
                if (data.filePath) {
                    this.addImageToCanvas(data.filePath);
                    // Hide the modal after submission
                    const modal = bootstrap.Modal.getInstance('#imageUploadModal');
                    modal.hide();
                    form.reset();
                } else {
                    alert('Image upload failed.');
                }
            })
            .catch(error => {
                console.error('Error uploading image:', error);
                alert('Error uploading image.');
            });
    }

    uploadBackground(event) {
        event.preventDefault();

        const form = this.element.querySelector('#background-form');
        const formData = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            body: formData,
        })
            .then(response => response.json())
            .then(data => {
                if (data.filePath) {
                    this.setBackgroundImage(data.filePath);
                    // Hide the modal after submission
                    const modal = bootstrap.Modal.getInstance('#backgroundModal');
                    modal.hide();
                    form.reset();
                } else {
                    alert('Image upload failed.');
                }
            })
            .catch(error => {
                console.error('Error uploading image:', error);
                alert('Error uploading image.');
            });
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
        this.scheduleAutosave();
    }

    addToHistory() {
        if (this.history.length >= this.maxHistorySize) {
            this.history.shift(); // Remove the oldest entry if history size is exceeded
        }

        this.history.push(this.canvas.toJSON(customProperties));
        this.redoStack = []; // Clear the redo stack when a new action is performed
        this.updateButtonStates(); // Update button states
    }

    undo() {
        if (this.history.length > 1) {
            const currentState = this.history.pop();
            this.redoStack.push(currentState);

            const previousState = this.history[this.history.length - 1];
            // loadCanvasWithoutHistory is async in v7; fire-and-forget — the
            // canvas re-renders inside the function once the JSON resolves.
            this.loadCanvasWithoutHistory(previousState);

            this.updateButtonStates();
        }
    }

    redo() {
        if (this.redoStack.length > 0) {
            const nextState = this.redoStack.pop();
            this.history.push(nextState);

            // Async fire-and-forget; see undo().
            this.loadCanvasWithoutHistory(nextState);

            this.updateButtonStates();
        }
    }

    updateButtonStates() {
        // History
        if (this.history.length > 1) {
            console.log(this.history.length);
            this.undoButtonTarget.classList.remove('disabled');
        } else {
            this.undoButtonTarget.classList.add('disabled');
        }

        if (this.redoStack.length > 0) {
            this.redoButtonTarget.classList.remove('disabled');
        } else {
            this.redoButtonTarget.classList.add('disabled');
        }

        // Zoom
        if ((this.currentScale - 0.01) <= this.minScale) {
            this.zoomOutButtonTarget.classList.add('disabled');
        } else {
            this.zoomOutButtonTarget.classList.remove('disabled');
        }

        if ((this.currentScale + 0.01) >= this.maxScale) {
            this.zoomInButtonTarget.classList.add('disabled');
        } else {
            this.zoomInButtonTarget.classList.remove('disabled');
        }
    }

    updateLocked(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.locked = event.target.checked;
            this.canvas.renderAll();
        }
    }

    updateHidable(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.hidable = event.target.checked;
            this.canvas.renderAll();
        }
    }

    updateName(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.name = event.target.value;

            this.scheduleAutosave();
            this.canvas.renderAll();
        }
    }

    updateDescription(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.description = event.target.value;

            this.scheduleAutosave();
            this.canvas.renderAll();
        }
    }

    updateUppercase(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.uppercase = event.target.checked;

            this.applyUppercase(activeObject);
            this.scheduleAutosave();
        }
    }

    applyUppercase(textbox) {
        let uppercase = textbox.uppercase || false

        if (uppercase) {
            textbox.text = textbox.text.toUpperCase();
        }

        this.canvas.renderAll();
    }

    applyTextTransformOnInput() {
        const activeObject = this.canvas.getActiveObject();

        if (activeObject && activeObject.type === 'textbox') {
            this.applyUppercase(activeObject);
        }
    }

    async copy() {
        const activeObject = this.canvas.getActiveObject();
        if (!activeObject) {
            return;
        }
        // Fabric v7: clone() returns a Promise and respects custom property
        // whitelist directly — no more extendToObject/restoreToObject hack.
        this.clipboard = await activeObject.clone(customProperties);
    }

    async paste() {
        if (!this.clipboard) {
            return;
        }
        // Clone the clipboard object so successive pastes produce independent
        // copies. v7 clone respects the custom-property whitelist natively.
        const clonedObj = await this.clipboard.clone(customProperties);

        this.canvas.discardActiveObject();

        clonedObj.set({
            left: clonedObj.left + 10,
            top: clonedObj.top + 10,
            evented: true,
        });

        if (clonedObj instanceof ActiveSelection) {
            clonedObj.canvas = this.canvas;
            clonedObj.forEachObject((obj) => {
                // Always overwrite inputId on paste to avoid id collisions.
                obj.inputId = crypto.randomUUID();
                this.canvas.add(obj);
            });
            clonedObj.setCoords();
        } else {
            // Always overwrite inputId on paste to avoid id collisions.
            clonedObj.inputId = crypto.randomUUID();
            this.canvas.add(clonedObj);
        }

        this.canvas.setActiveObject(clonedObj);
        this.canvas.requestRenderAll();
    }

    duplicate() {
        // copy() is async and stores into this.clipboard; await it before paste
        // so paste() sees the freshly-cloned object.
        this.copy().then(() => this.paste());
    }

    updateDuplicateButton() {
        const activeObject = this.canvas.getActiveObject();

        if (activeObject) {
            // Enable the duplicate button
            this.duplicateButtonTarget.removeAttribute('disabled');
            this.duplicateButtonTarget.classList.remove('disabled');
        } else {
            // Disable the duplicate button
            this.duplicateButtonTarget.setAttribute('disabled', 'disabled');
            this.duplicateButtonTarget.classList.add('disabled');
        }
    }

    alignLeft() {
        this.alignObjects('left');
    }

    alignCenter() {
        this.alignObjects('center');
    }

    alignRight() {
        this.alignObjects('right');
    }

    alignTop() {
        this.alignObjects('top');
    }

    alignMiddle() {
        this.alignObjects('middle');
    }

    alignBottom() {
        this.alignObjects('bottom');
    }

    alignObjects(alignment) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'activeSelection') {
            const objects = activeObject.getObjects();

            let positionValue;
            if (alignment === 'left' || alignment === 'right' || alignment === 'center') {
                // Horizontal Alignment
                const positions = objects.map(obj => obj.getBoundingRect());
                if (alignment === 'left') {
                    positionValue = Math.min(...positions.map(pos => pos.left));
                } else if (alignment === 'right') {
                    positionValue = Math.max(...positions.map(pos => pos.left + pos.width));
                } else if (alignment === 'center') {
                    const minLeft = Math.min(...positions.map(pos => pos.left));
                    const maxRight = Math.max(...positions.map(pos => pos.left + pos.width));
                    positionValue = (minLeft + maxRight) / 2;
                }

                objects.forEach(obj => {
                    const boundingRect = obj.getBoundingRect();
                    let deltaX;
                    if (alignment === 'left') {
                        deltaX = positionValue - boundingRect.left;
                    } else if (alignment === 'right') {
                        deltaX = positionValue - (boundingRect.left + boundingRect.width);
                    } else if (alignment === 'center') {
                        deltaX = positionValue - (boundingRect.left + boundingRect.width / 2);
                    }
                    obj.left += deltaX;
                    obj.setCoords();
                });
            } else {
                // Vertical Alignment
                const positions = objects.map(obj => obj.getBoundingRect());
                if (alignment === 'top') {
                    positionValue = Math.min(...positions.map(pos => pos.top));
                } else if (alignment === 'bottom') {
                    positionValue = Math.max(...positions.map(pos => pos.top + pos.height));
                } else if (alignment === 'middle') {
                    const minTop = Math.min(...positions.map(pos => pos.top));
                    const maxBottom = Math.max(...positions.map(pos => pos.top + pos.height));
                    positionValue = (minTop + maxBottom) / 2;
                }

                objects.forEach(obj => {
                    const boundingRect = obj.getBoundingRect();
                    let deltaY;
                    if (alignment === 'top') {
                        deltaY = positionValue - boundingRect.top;
                    } else if (alignment === 'bottom') {
                        deltaY = positionValue - (boundingRect.top + boundingRect.height);
                    } else if (alignment === 'middle') {
                        deltaY = positionValue - (boundingRect.top + boundingRect.height / 2);
                    }
                    obj.top += deltaY;
                    obj.setCoords();
                });
            }

            this.canvas.requestRenderAll();
        }
    }
}
