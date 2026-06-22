import { Controller } from "@hotwired/stimulus";

/**
 * Font / size / colour / alignment / decoration / max-length controls for
 * the active textbox. These fields live inside the floating text popover; the
 * popover's visibility is owned by canvas-floating-toolbar, so this controller
 * only populates the fields when a textbox is selected.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = [
        "fontFamily", "fontSize", "fontColor",
        "textAlign", "textDecoration", "maxLength",
    ];

    static values = {
        defaultFontFamily: { type: String, default: "" },
    };

    canvasEditorOutletConnected(outlet) {
        this.updateFromSelection({ detail: { activeObject: outlet.canvas.getActiveObject() } });
    }

    updateFromSelection(event) {
        const activeObject = event.detail.activeObject;
        const isTextbox = activeObject && (activeObject.type || '').toLowerCase() === 'textbox';

        if (!isTextbox) {
            return;
        }

        if (this.hasFontSizeTarget) {
            this.fontSizeTarget.value = activeObject.fontSize;
        }
        if (this.hasFontColorTarget) {
            this.fontColorTarget.value = activeObject.fill || '#000000';
        }
        if (this.hasTextAlignTarget) {
            this.textAlignTarget.value = activeObject.textAlign;
        }
        if (this.hasFontFamilyTarget) {
            this.fontFamilyTarget.value = activeObject.fontFamily || this.defaultFontFamilyValue;
        }
        if (this.hasTextDecorationTarget) {
            this.textDecorationTarget.value = activeObject.textDecoration || 'none';
        }
        if (this.hasMaxLengthTarget) {
            this.maxLengthTarget.value = activeObject.maxLength || '';
        }
    }

    updateFontSize(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.set({ fontSize: event.target.value });
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateFontColor(event) {
        let color = event.target.value.trim();

        // Add '#' if it's missing and the input is a valid hex color
        if (color && !color.startsWith('#')) {
            color = '#' + color;
        }

        // Validate hex color format (supports 3 or 6 character hex codes)
        const isValidHex = /^#([0-9A-F]{3,6})$/i.test(color);
        if (!isValidHex) return;

        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.set({ fill: color });
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateTextAlign(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.set({ textAlign: event.target.value });
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateFontFamily(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.set({ fontFamily: event.target.value });
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateTextDecoration(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;

        // Reset all text decorations
        activeObject.set({
            underline: false,
            linethrough: false,
            overline: false,
        });

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

        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateMaxLength(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;

        const maxLength = parseInt(event.target.value, 10);
        if (maxLength > 0) {
            activeObject.maxLength = maxLength;
            // Truncate the sample text only when the value is COMMITTED (change),
            // not on every keystroke (input): otherwise typing "50" would first
            // apply maxLength 5 and permanently cut the text to 5 characters.
            if (event.type === 'change') {
                activeObject.text = activeObject.text.slice(0, maxLength);
            }
            this._adjustTextWidth(activeObject);
        } else {
            // Remove max length restriction if the input is empty or zero
            activeObject.maxLength = undefined;
        }

        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    _adjustTextWidth(textObject) {
        if (!textObject.maxLength) return;
        const canvas = this.canvasEditorOutlet.canvas;
        const canvasContext = canvas.getContext();
        canvasContext.font = `${textObject.fontSize}px ${textObject.fontFamily}`;
        // Use a wide character to estimate the maximum width
        const sampleText = 'W'.repeat(textObject.maxLength);
        const textWidth = canvasContext.measureText(sampleText).width;

        textObject.set({
            width: textWidth,
            lockScalingX: true,  // Lock horizontal scaling
            lockScalingY: true,  // Lock vertical scaling
            editable: true,      // Keep text editable
            hasControls: false,  // Disable resize controls
        });
    }

    _getActiveTextbox() {
        if (!this.hasCanvasEditorOutlet) return null;
        const activeObject = this.canvasEditorOutlet.canvas.getActiveObject();
        if (!activeObject || (activeObject.type || '').toLowerCase() !== 'textbox') return null;
        return activeObject;
    }
}
