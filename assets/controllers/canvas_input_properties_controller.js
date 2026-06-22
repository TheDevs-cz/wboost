import { Controller } from "@hotwired/stimulus";

/**
 * Editor-side input metadata: name / description / locked / hidable /
 * uppercase. These are the properties consumed by the export form
 * (template-input fields) — they live on the canvas object as custom
 * properties and round-trip through CANVAS_CUSTOM_PROPERTIES.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["name", "description", "locked", "hidable", "uppercase"];

    canvasEditorOutletConnected(outlet) {
        // Apply uppercase live as the user types into a textbox.
        this._applyUppercaseOnInput = () => {
            const activeObject = outlet.canvas.getActiveObject();
            if (activeObject && (activeObject.type || '').toLowerCase() === 'textbox') {
                this._applyUppercase(activeObject);
            }
        };
        outlet.canvas.on('text:changed', this._applyUppercaseOnInput);

        this.updateFromSelection({ detail: { activeObject: outlet.canvas.getActiveObject() } });
    }

    canvasEditorOutletDisconnected(outlet) {
        if (this._applyUppercaseOnInput && outlet.canvas) {
            outlet.canvas.off('text:changed', this._applyUppercaseOnInput);
        }
    }

    updateFromSelection(event) {
        const activeObject = event.detail.activeObject;
        const isTextbox = activeObject && (activeObject.type || '').toLowerCase() === 'textbox';
        if (!isTextbox) return;

        if (this.hasLockedTarget)    this.lockedTarget.checked    = activeObject.locked || false;
        if (this.hasUppercaseTarget) this.uppercaseTarget.checked = activeObject.uppercase || false;
        if (this.hasNameTarget)      this.nameTarget.value        = activeObject.name || '';
        if (this.hasDescriptionTarget) this.descriptionTarget.value = activeObject.description || '';
        if (this.hasHidableTarget)   this.hidableTarget.checked   = activeObject.hidable || false;
    }

    updateLocked(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.locked = event.target.checked;
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    /**
     * Inline lock toggle from the floating mini-toolbar. Flips locked, mirrors
     * the change onto the popover checkbox so both stay in sync.
     */
    toggleLocked() {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.locked = !activeObject.locked;
        if (this.hasLockedTarget) this.lockedTarget.checked = activeObject.locked;
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateHidable(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.hidable = event.target.checked;
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateName(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.name = event.target.value;
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateDescription(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.description = event.target.value;
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateUppercase(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.uppercase = event.target.checked;
        this._applyUppercase(activeObject);
        this.canvasEditorOutlet.markUnsaved();
    }

    _applyUppercase(textbox) {
        if (textbox.uppercase) {
            textbox.text = textbox.text.toUpperCase();
        }
        this.canvasEditorOutlet.canvas.renderAll();
    }

    _getActiveTextbox() {
        if (!this.hasCanvasEditorOutlet) return null;
        const activeObject = this.canvasEditorOutlet.canvas.getActiveObject();
        if (!activeObject || (activeObject.type || '').toLowerCase() !== 'textbox') return null;
        return activeObject;
    }
}
