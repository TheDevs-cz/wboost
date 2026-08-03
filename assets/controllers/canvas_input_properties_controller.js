import { Controller } from "@hotwired/stimulus";

/**
 * Editor-side input metadata: name / description / locked / hidable /
 * uppercase. These are the properties consumed by the export form
 * (template-input fields) — they live on the canvas object as custom
 * properties and round-trip through CANVAS_CUSTOM_PROPERTIES.
 *
 * Name↔text smart sync: submitAddText seeds a new textbox's CANVAS text from
 * its name, so as long as the designer hasn't written real stand-in text yet
 * (canvas text still equals the name), renaming keeps the canvas text in
 * step — matching the expectation the seeding itself creates. The link is
 * computed when the selection populates the panel and is broken by any
 * inline canvas edit (Fabric fires text:changed only for interactive edits,
 * never for our programmatic set), after which renaming never touches the
 * designed text again.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["name", "description", "locked", "hidable", "uppercase", "richText"];

    canvasEditorOutletConnected(outlet) {
        // Apply uppercase live as the user types into a textbox — and break
        // the name↔text link, because an interactive text:changed means the
        // designer is writing real stand-in text.
        this._applyUppercaseOnInput = () => {
            const activeObject = outlet.canvas.getActiveObject();
            if (activeObject && (activeObject.type || '').toLowerCase() === 'textbox') {
                // Our own synthetic text:changed (rename sync) must not
                // unlink — only genuine inline edits do.
                if (!this._syncingText) {
                    this._nameTextLinked = false;
                }
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

        this._nameTextLinked = this._textEqualsName(activeObject);

        if (this.hasLockedTarget)    this.lockedTarget.checked    = activeObject.locked || false;
        if (this.hasUppercaseTarget) this.uppercaseTarget.checked = activeObject.uppercase || false;
        if (this.hasNameTarget)      this.nameTarget.value        = activeObject.name || '';
        if (this.hasDescriptionTarget) this.descriptionTarget.value = activeObject.description || '';
        if (this.hasHidableTarget)   this.hidableTarget.checked   = activeObject.hidable || false;
        if (this.hasRichTextTarget) {
            this.richTextTarget.checked = activeObject.richText || false;
            // Locked inputs are never user-fillable, so the WYSIWYG toggle is
            // meaningless for them — keep the stored flag, just gray the box.
            this.richTextTarget.disabled = activeObject.locked || false;
        }
    }

    updateLocked(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.locked = event.target.checked;
        if (this.hasRichTextTarget) this.richTextTarget.disabled = activeObject.locked;
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

    updateRichText(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.richText = event.target.checked;
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateName(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.name = event.target.value;

        // Seeded state: the canvas text is still just the (old) name — keep
        // it following the rename. An empty mid-typing value passes through
        // (the element visibly empties, and the link self-heals: '' === '').
        if (this._nameTextLinked) {
            const canvas = this.canvasEditorOutlet.canvas;
            activeObject.set('text', activeObject.uppercase
                ? event.target.value.toUpperCase()
                : event.target.value);
            if (typeof activeObject.initDimensions === 'function') {
                activeObject.initDimensions();
            }
            activeObject.setCoords();
            // Synthetic announce so container reflow and the group editor
            // treat this like typing (our programmatic set fires nothing).
            this._syncingText = true;
            canvas.fire('text:changed', { target: activeObject });
            this._syncingText = false;
        }

        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    _textEqualsName(textbox) {
        const name = textbox.name || '';
        const text = textbox.text || '';
        return text === name || (textbox.uppercase === true && text === name.toUpperCase());
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
