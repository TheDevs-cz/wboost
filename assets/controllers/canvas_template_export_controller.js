import { Controller } from "@hotwired/stimulus";
import { fabric } from "fabric";
import FontFaceObserver from 'fontfaceobserver';

export default class extends Controller {
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

    exportAsImage() {
        const dataURL = this.canvas.toDataURL({
            format: 'png',
            quality: 1.0,
        });

        const link = document.createElement('a');
        link.href = dataURL;
        link.download = 'canvas.png';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
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
        link.download = 'canvas.svg';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // Revoke the object URL after download
        URL.revokeObjectURL(url);
    }
}
