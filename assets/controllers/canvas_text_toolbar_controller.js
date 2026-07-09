import { Controller } from "@hotwired/stimulus";

// Fabric's native default line-height multiplier. New textboxes are created
// with this value and legacy boxes with no explicit lineHeight fall back to it,
// so the field is never blank and the export renders identically.
export const DEFAULT_LINE_HEIGHT = 1.16;

/**
 * Font / size / colour / alignment / decoration / max-length / line-height
 * controls for the active textbox. These fields live inside the floating text
 * popover; the popover's visibility is owned by canvas-floating-toolbar, so this
 * controller only populates the fields when a textbox is selected.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = [
        "fontFamily", "fontSize", "fontColor",
        "textAlign", "textDecoration", "maxLength", "lineHeight",
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
            // Read the modern boolean props — `textDecoration` was removed from
            // Fabric ages ago, so reading it always yielded 'none' and the
            // dropdown never reflected an underlined/struck-through selection.
            this.textDecorationTarget.value = activeObject.underline ? 'underline'
                : activeObject.linethrough ? 'line-through'
                : activeObject.overline ? 'overline'
                : 'none';
        }
        if (this.hasMaxLengthTarget) {
            this.maxLengthTarget.value = activeObject.maxLength || '';
        }
        if (this.hasLineHeightTarget) {
            this.lineHeightTarget.value = activeObject.lineHeight ?? DEFAULT_LINE_HEIGHT;
        }
    }

    updateFontSize(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        // Store a NUMBER: the raw input value is a string, which Fabric mostly
        // survives (multiplication coerces) but serializes as-is into the
        // canvas JSON — and from there leaks into the API's textStyle payload
        // that consumers mirror for their own text measurement.
        const fontSize = parseFloat(event.target.value);
        if (!(fontSize > 0)) return;
        activeObject.set({ fontSize });
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

    updateLineHeight(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;

        const lineHeight = parseFloat(event.target.value);
        if (!(lineHeight > 0)) return;

        activeObject.set({ lineHeight });
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
            // `maxLength` is purely a FILL-TIME character limit — it must NEVER
            // touch the box geometry or its interaction flags. The old code
            // resized the box to fit `'W' × maxLength` and set
            // `hasControls:false` / `lockScalingX/Y:true`, which (a) threw away
            // the designer's manual width, (b) grew the box far off-canvas for
            // large limits — dragging the selection and the floating toolbar
            // off-screen — and (c) made the box impossible to resize. Width is
            // the designer's concern (drag the side handles → text re-wraps);
            // the limit only truncates what a user may type when filling.
            //
            // Truncate the design-time sample text only when the value is
            // COMMITTED (change), never on every keystroke (input): otherwise
            // typing "50" would first apply maxLength 5 and permanently cut the
            // text to 5 characters.
            if (event.type === 'change' && activeObject.text.length > maxLength) {
                activeObject.set({ text: activeObject.text.slice(0, maxLength) });
                activeObject.setCoords();
            }
        } else {
            // Remove max length restriction if the input is empty or zero
            activeObject.maxLength = undefined;
        }

        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    _getActiveTextbox() {
        if (!this.hasCanvasEditorOutlet) return null;
        const activeObject = this.canvasEditorOutlet.canvas.getActiveObject();
        if (!activeObject || (activeObject.type || '').toLowerCase() !== 'textbox') return null;
        return activeObject;
    }
}
