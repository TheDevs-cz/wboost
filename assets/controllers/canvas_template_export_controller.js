import { Controller } from "@hotwired/stimulus";
import { fabric } from "fabric";
import FontFaceObserver from 'fontfaceobserver';

export default class extends Controller {
    static values = {
        renderUrl: String,
    };

    connect() {
        const customFonts = JSON.parse(this.element.dataset.canvasTemplateExportCustomFonts);

        this.loadFonts(customFonts).then(() => {
            this.canvas = new fabric.Canvas('c', {
                selection: false // Disable group selection
            });

            // Load the canvas from JSON
            const canvasJson = JSON.parse(this.element.dataset.canvasTemplateExportCanvasJson);
            this.canvas.loadFromJSON(canvasJson, () => {
                this.canvas.renderAll();
                this.lockCanvasObjects();
            });
        });
    }

    loadFonts(fonts) {
        const fontPromises = fonts.map(font => {
            const fontObserver = new FontFaceObserver(font);
            return fontObserver.load().then(() => {
                console.log(`Loaded custom font: ${font}`);
            }).catch(err => {
                console.error(`Failed to load custom font: ${font}`, err);
            });
        });

        return Promise.all(fontPromises);
    }

    lockCanvasObjects() {
        this.canvas.getObjects().forEach((obj) => {
            obj.selectable = false;
            obj.evented = false; // Disable events like moving, scaling, etc.
        });
    }

    updateCanvasText(event) {
        const index = event.target.dataset.index;
        const textbox = this.canvas.getObjects('textbox')[index];
        if (textbox) {
            const uppercase = textbox.uppercase || false

            textbox.text = event.target.value;

            if (uppercase) {
                textbox.text = textbox.text.toUpperCase();
            }

            this.canvas.renderAll();
        }
    }

    updateCanvasTextVisibility(event) {
        const index = event.target.dataset.index;
        const textbox = this.canvas.getObjects('textbox')[index];
        if (textbox) {
            textbox.visible = !event.target.checked;
            this.canvas.renderAll();
        }
    }

    async exportAsImage() {
        // Server-side render: collects current input values and POSTs them to
        // the render endpoint, which uses the same renderer as the public API.
        // This guarantees the downloaded PNG matches what API consumers receive
        // and lets us add image inputs in the future without diverging code.
        const inputs = {};
        this.element.querySelectorAll('[data-input-name]').forEach((el) => {
            const name = el.dataset.inputName;
            if (!name) return;
            inputs[name] = { value: el.value || '' };
        });
        // Pick up hide checkboxes for hidable inputs (data-index matches the
        // input field with the same data-index — both reference variant.inputs[i]).
        this.element.querySelectorAll('[id^="hide-control-"]').forEach((el) => {
            const idx = el.dataset.index;
            const textField = this.element.querySelector(`[data-index="${idx}"][data-input-name]`);
            const name = textField && textField.dataset.inputName;
            if (!name) return;
            if (!inputs[name] || typeof inputs[name] !== 'object') {
                inputs[name] = {};
            }
            inputs[name].hide = el.checked;
        });

        const response = await fetch(this.renderUrlValue, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ inputs }),
        });

        if (!response.ok) {
            console.error('Export failed', response.status, await response.text());
            return;
        }

        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'export.png';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    exportAsSvg() {
        // Generate SVG data from the canvas
        const svgData = this.canvas.toSVG();

        // Create a Blob from the SVG data
        const svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });

        // Create a download link
        const link = document.createElement('a');
        const url = URL.createObjectURL(svgBlob);
        link.href = url;
        link.download = 'export.svg';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // Revoke the object URL after download
        URL.revokeObjectURL(url);
    }
}
