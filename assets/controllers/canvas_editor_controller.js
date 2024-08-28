import { Controller } from "@hotwired/stimulus";
import { fabric } from "fabric";
import FontFaceObserver from 'fontfaceobserver';

export default class extends Controller {
    static targets = ["canvas", "textInputs", "event", "bringToFrontButton", "sendToBackButton", "deleteObjectButton", "autosaveMessage", "lastAutosave", "undoButton", "redoButton", "autosaveDelay"];

    static values = {
        backgroundImage: String,
        customFonts: Array
    }

    connect() {
        this.canvas = new fabric.Canvas('c'); // Initialize the Fabric.js canvas

        const canvasJson = this.element.dataset.canvasEditorCanvasJson;
        if (canvasJson && canvasJson.trim() !== '') {
            this.loadCanvasWithoutHistory(canvasJson);
        } else {
            this.initializeNewCanvas();
        }

        this.autosaveTimeout = null;
        this.lastAutosaveTime = 0;
        this.history = [];
        this.redoStack = [];
        this.maxHistorySize = 20;

        this.loadFontsAndPopulateSelect();

        // Initially hide buttons since no object is selected
        this.hideActionButtons();

        window.addEventListener('keydown', this.handleKeydown.bind(this));

        this.canvas.on('selection:created', this.updateControlsVisibility.bind(this));
        this.canvas.on('selection:updated', this.updateControlsVisibility.bind(this));
        this.canvas.on('selection:cleared', this.updateControlsVisibility.bind(this));
        this.canvas.on('text:changed', () => this.scheduleAutosave());

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

    loadCanvasWithoutHistory(canvasJson) {
        this.loadingCanvas = true;
        this.canvas.loadFromJSON(canvasJson, () => {
            this.canvas.renderAll();
            this.loadingCanvas = false;
        });
    }

    initializeNewCanvas() {
        if (this.backgroundImageValue) {
            this.setBackgroundImage(this.backgroundImageValue);
        }
    }

    handleKeydown(event) {
        // Check if the focus is on an input, textarea, or contenteditable element
        const activeElement = document.activeElement;
        const isInputField = activeElement.tagName === 'INPUT' ||
            activeElement.tagName === 'TEXTAREA' ||
            activeElement.isContentEditable;

        if (!isInputField && (event.key === 'Delete' || event.key === 'Backspace')) {
            event.preventDefault(); // Prevent default browser behavior
            this.deleteObject();
        }
    }

    setBackgroundImage(imageUrl) {
        fabric.Image.fromURL(imageUrl, (img) => {
            this.canvas.setBackgroundImage(img, this.canvas.renderAll.bind(this.canvas));
        }, {crossOrigin: 'anonymous'});
    }

    async loadFontsAndPopulateSelect() {
        const fontFamilySelect = document.getElementById('font-family');
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

    addText() {
        // Prompt the user for the text name
        const inputName = prompt("Prosím zadejte název textového pole:");

        // Determine the font family: use the first custom font, or fall back to 'Arial' if none are provided
        const fontFamily = this.customFontsValue.length > 0 ? this.customFontsValue[0] : 'Arial';

        const textBox = new fabric.Textbox(inputName, {
            left: 100,
            top: 100,
            width: 200,
            fontFamily: fontFamily,
            fill: '#000000',
            fontSize: 24,
            textAlign: 'left',
            editable: true,
            lockScalingX: true,  // Prevent scaling in the X direction
            lockScalingY: true,  // Prevent scaling in the Y direction
            lockScalingFlip: true, // Prevent flipping while scaling
            lockRotation: true,  // Optional: Prevent rotation
            hasControls: true, // Enable controls (if you still want them for positioning)
            cornerStyle: 'circle', // Optional: Customize corner controls
            cornerSize: 8,  // Size of the control corners
            selectable: true, // Keep it selectable for moving
            name: inputName // Store the name as metadata
        });

        this.canvas.add(textBox);
        this.canvas.setActiveObject(textBox);
        this.canvas.renderAll();
        this.scheduleAutosave();
    }

    bringToFront() {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject) {
            activeObject.bringToFront();
            this.canvas.discardActiveObject();
            this.canvas.renderAll();
            this.scheduleAutosave()
        }
    }

    sendToBack() {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject) {
            activeObject.sendToBack();
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
        this.bringToFrontButtonTarget.style.display = 'inline-block';
        this.sendToBackButtonTarget.style.display = 'inline-block';
        this.deleteObjectButtonTarget.style.display = 'inline-block';
    }

    hideActionButtons() {
        this.bringToFrontButtonTarget.style.display = 'none';
        this.sendToBackButtonTarget.style.display = 'none';
        this.deleteObjectButtonTarget.style.display = 'none';
    }

    updateControlsVisibility() {
        const activeObject = this.canvas.getActiveObject();
        const fontControls = document.getElementById('font-controls');

        if (activeObject) {
            this.showActionButtons();
        } else {
            this.hideActionButtons();
        }

        if (activeObject && activeObject.type === 'textbox') {
            fontControls.style.display = 'block';

            const defaultFont = this.customFontsValue.length > 0 ? this.customFontsValue[0] : '';

            // Set input values based on the active object's current properties
            document.getElementById('font-size').value = activeObject.fontSize;
            document.getElementById('font-color').value = activeObject.fill || '#000000';
            document.getElementById('text-align').value = activeObject.textAlign;
            document.getElementById('font-family').value = activeObject.fontFamily || defaultFont;
            document.getElementById('text-decoration').value = activeObject.textDecoration || 'none';
            document.getElementById('max-length').value = activeObject.maxLength || '';
            document.getElementById('locked').checked = activeObject.locked || false;
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

    updateLocked(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.locked = event.target.checked;
            activeObject.set({
                lockMovementX: activeObject.locked,
                lockMovementY: activeObject.locked,
                lockScalingX: activeObject.locked,
                lockScalingY: activeObject.locked,
                lockRotation: activeObject.locked,
                hasControls: !activeObject.locked,
            });
            this.canvas.renderAll();
            this.scheduleAutosave()
        }
    }

    scheduleAutosave() {
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

        this.eventTarget.value = 'autosave';
        this.submitForm();
        this.eventTarget.value = '';

    }

    showAutosavingMessage() {
        this.autosaveMessageTarget.classList.remove('d-none');
    }

    showLastAutosaveTime() {
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
        const canvasJSON = this.canvas.toJSON(['name', 'maxLength', 'locked']);
        this.canvasTarget.value = JSON.stringify(canvasJSON);

        // Serialize only the text inputs
        const textInputs = this.canvas.getObjects('textbox').map(textbox => ({
            name: textbox.name,
            maxLength: textbox.maxLength || null,
            locked: textbox.locked || false,
        }));
        this.textInputsTarget.value = JSON.stringify(textInputs);

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
                    console.error('Autosave failed:', data.message);
                    alert('Autosave failed. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error during autosave:', error);
                alert('Autosave failed. Please try again.');
            });
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
                    const modalElement = this.element.querySelector('#imageUploadModal');
                    const modal = bootstrap.Modal.getInstance(modalElement);
                    modal.hide();
                } else {
                    alert('Image upload failed.');
                }
            })
            .catch(error => {
                console.error('Error uploading image:', error);
                alert('Error uploading image.');
            });
    }

    addImageToCanvas(imageUrl) {
        fabric.Image.fromURL(imageUrl, (img) => {
            img.set({
                left: 100,
                top: 100,
                angle: 0,
                cornersize: 10,
                hasRotatingPoint: true,
            });
            this.canvas.add(img);
            this.canvas.setActiveObject(img);
            this.canvas.renderAll();
            this.scheduleAutosave()
        }, {crossOrigin: 'anonymous'});
    }

    addToHistory() {
        if (this.history.length >= this.maxHistorySize) {
            this.history.shift(); // Remove the oldest entry if history size is exceeded
        }

        this.history.push(this.canvas.toJSON(['name', 'maxLength', 'locked']));
        this.redoStack = []; // Clear the redo stack when a new action is performed
        this.updateButtonStates(); // Update button states
    }

    undo() {
        if (this.history.length > 1) { // Keep at least one state in history
            const currentState = this.history.pop(); // Remove the current state from history
            this.redoStack.push(currentState); // Move the current state to the redo stack

            const previousState = this.history[this.history.length - 1]; // Get the previous state
            this.loadCanvasWithoutHistory(previousState);

            this.updateButtonStates(); // Update button states
        }
    }

    redo() {
        if (this.redoStack.length > 0) {
            const nextState = this.redoStack.pop(); // Get the next state from the redo stack
            this.history.push(nextState); // Push it to the history stack

            this.loadCanvasWithoutHistory(nextState);

            this.updateButtonStates(); // Update button states
        }
    }

    updateButtonStates() {
        /*
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
        */
    }
}
