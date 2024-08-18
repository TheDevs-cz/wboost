import { Controller } from "@hotwired/stimulus";
import { fabric } from "fabric";
import FontFaceObserver from 'fontfaceobserver';

export default class extends Controller {
    static targets = ["canvas", "textInputs"];

    static values = {
        backgroundImage: String,
        customFonts: Array
    }

    connect() {
        this.canvas = new fabric.Canvas('c'); // Initialize the Fabric.js canvas

        if (this.backgroundImageValue) {
            this.setBackgroundImage(this.backgroundImageValue);
        }

        this.loadFontsAndPopulateSelect();

        this.canvas.on('selection:created', this.updateControlsVisibility.bind(this));
        this.canvas.on('selection:updated', this.updateControlsVisibility.bind(this));
        this.canvas.on('selection:cleared', this.updateControlsVisibility.bind(this));

        window.addEventListener('keydown', this.handleKeydown.bind(this));
    }

    disconnect() {
        // Clean up the event listener when the controller is disconnected
        window.removeEventListener('keydown', this.handleKeydown.bind(this));
    }

    handleKeydown(event) {
        if (event.key === 'Delete' || event.key === 'Backspace') {
            this.deleteObject();
        }
    }

    setBackgroundImage(imageUrl) {
        fabric.Image.fromURL(imageUrl, (img) => {
            this.canvas.setBackgroundImage(img, this.canvas.renderAll.bind(this.canvas));
        });
    }

    async loadFontsAndPopulateSelect() {
        const fontFamilySelect = document.getElementById('font-family');
        fontFamilySelect.innerHTML = ''; // Clear existing options

        const fontPromises = this.customFontsValue.map(font => {
            if (this.isSystemFont(font)) {
                // Directly add system fonts without loading
                this.addFontOption(fontFamilySelect, font);
                return Promise.resolve(); // System fonts don't require loading
            } else {
                // Use FontFaceObserver for custom fonts
                const fontObserver = new FontFaceObserver(font);
                return fontObserver.load().then(() => {
                    console.log(`${font} has loaded.`);
                    this.addFontOption(fontFamilySelect, font);
                }).catch(err => {
                    console.error(`Font ${font} failed to load:`, err);
                });
            }
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

    isSystemFont(font) {
        // Define your list of common system fonts
        const systemFonts = [
            'Arial', 'Helvetica', 'Times New Roman', 'Courier New', 'Verdana', 'Georgia', 'Palatino', 'Garamond',
            'Comic Sans MS', 'Trebuchet MS', 'Arial Black', 'Impact'
        ];
        return systemFonts.includes(font);
    }

    addText() {
        // Prompt the user for the text name
        const inputName = prompt("Prosím zadejte název textového pole:");

        const textBox = new fabric.Textbox(inputName, {
            left: 100,
            top: 100,
            width: 200,
            fontFamily: 'Arial',
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
    }

    bringToFront() {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject) {
            activeObject.bringToFront();
            this.canvas.discardActiveObject();
            this.canvas.renderAll();
        }
    }

    sendToBack() {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject) {
            activeObject.sendToBack();
            this.canvas.discardActiveObject();
            this.canvas.renderAll();
        }
    }

    deleteObject() {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject) {
            this.canvas.remove(activeObject);
            this.canvas.renderAll();
        }
    }

    updateControlsVisibility() {
        const activeObject = this.canvas.getActiveObject();
        const fontControls = document.getElementById('font-controls');

        if (activeObject && activeObject.type === 'textbox') {
            fontControls.style.display = 'block';

            // Set input values based on the active object's current properties
            document.getElementById('font-size').value = activeObject.fontSize;
            document.getElementById('font-color').value = activeObject.fill || '#000000';
            document.getElementById('text-align').value = activeObject.textAlign;
            document.getElementById('font-family').value = activeObject.fontFamily || 'Arial';
            document.getElementById('text-decoration').value = activeObject.textDecoration || 'none';
            document.getElementById('font-weight').value = activeObject.fontWeight || 'normal';
            document.getElementById('max-length').value = activeObject.maxLength || '';
        } else {
            fontControls.style.display = 'none';
        }
    }

    updateFontSize(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.set({ fontSize: event.target.value });
            this.canvas.renderAll();
        }
    }

    updateFontColor(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.set({ fill: event.target.value });
            this.canvas.renderAll();
        }
    }

    updateTextAlign(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.set({ textAlign: event.target.value });
            this.canvas.renderAll();
        }
    }

    updateFontFamily(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            activeObject.set({ fontFamily: event.target.value });
            this.canvas.renderAll();
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
        }
    }

    updateFontWeight(event) {
        const activeObject = this.canvas.getActiveObject();
        if (activeObject && activeObject.type === 'textbox') {
            let fontWeight = event.target.value;

            activeObject.set({fontWeight: fontWeight});
            this.canvas.renderAll();
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

    submitForm(event) {
        event.preventDefault(); // Prevent the default form submission

        // Serialize the canvas JSON
        const canvasJSON = this.canvas.toJSON(['name', 'maxLength']);
        this.canvasTarget.value = JSON.stringify(canvasJSON);

        // Serialize only the text inputs
        const textInputs = this.canvas.getObjects('textbox').map(textbox => ({
            name: textbox.name,
            maxLength: textbox.maxLength || null,
        }));
        this.textInputsTarget.value = JSON.stringify(textInputs);

        // Submit the form programmatically
        this.canvasTarget.closest('form').submit();
    }
}
