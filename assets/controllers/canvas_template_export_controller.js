import { Controller } from "@hotwired/stimulus";
import { Canvas } from "fabric";
import FontFaceObserver from 'fontfaceobserver';

export default class extends Controller {
    static values = {
        renderUrl: String,
    };

    connect() {
        const customFonts = JSON.parse(this.element.dataset.canvasTemplateExportCustomFonts);

        // Stimulus connect() can't be async; chain on loadFonts then run an
        // async block to await the v7 Promise-based loadFromJSON.
        this.loadFonts(customFonts).then(async () => {
            this.canvas = new Canvas('c', {
                selection: false // Disable group selection
            });

            // Load the canvas from JSON. v7's loadFromJSON returns a Promise
            // (callback form removed). Await before calling renderAll.
            const canvasJson = JSON.parse(this.element.dataset.canvasTemplateExportCanvasJson);
            await this.canvas.loadFromJSON(canvasJson);
            this.canvas.renderAll();
            this.lockCanvasObjects();
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
        const inputId = event.target.dataset.inputId;
        if (!inputId) return;
        const textbox = this.canvas.getObjects().find((o) => o.inputId === inputId);
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
        const inputId = event.target.dataset.inputId;
        if (!inputId) return;
        const textbox = this.canvas.getObjects().find((o) => o.inputId === inputId);
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
        this.element.querySelectorAll('[data-input-id]').forEach((el) => {
            const inputId = el.dataset.inputId;
            if (!inputId) return;
            // Skip the hide checkboxes — they share data-input-id with their
            // text field but should be merged into the same entry below.
            if (el.type === 'checkbox') return;
            inputs[inputId] = { value: el.value || '' };
        });
        // Pick up hide checkboxes for hidable inputs and merge them into the
        // entry for the same inputId (both reference the same canvas object).
        this.element.querySelectorAll('input[type="checkbox"][data-input-id]').forEach((el) => {
            const inputId = el.dataset.inputId;
            if (!inputId) return;
            if (!inputs[inputId] || typeof inputs[inputId] !== 'object') {
                inputs[inputId] = {};
            }
            inputs[inputId].hide = el.checked;
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
        // Generate SVG data from the canvas (canvas.toSVG unchanged in v7).
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
