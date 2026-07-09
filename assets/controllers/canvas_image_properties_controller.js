import { Controller } from "@hotwired/stimulus";
import { applyEditorLock } from "./canvas_custom_properties.js";

/**
 * Editor-side metadata for IMAGE objects — the image counterpart of
 * canvas_input_properties. The designer promotes an image to a fillable
 * placeholder and sets the per-slot limits (move / resize / rotate), hidability,
 * and which gallery folders the end-user may pick a picture from. All values
 * live on the Fabric object as custom properties and round-trip through
 * CANVAS_CUSTOM_PROPERTIES; the orchestrator's submitForm() extracts the
 * placeholder objects into the imageInputs payload on save.
 *
 * These fields live inside the floating image popover; the popover's visibility
 * is owned by canvas-floating-toolbar, so this controller only populates the
 * fields when an image is selected. The placeholder-only details (name, limits,
 * folders) are still shown only once the image is marked a placeholder.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = [
        "details", "placeholder", "name", "description",
        "allowMove", "allowResize", "allowRotate", "hidable", "directory",
        "warning", "editorLocked",
    ];

    canvasEditorOutletConnected(outlet) {
        this.updateFromSelection({ detail: { activeObject: outlet.canvas.getActiveObject() } });
    }

    updateFromSelection(event) {
        const activeObject = event.detail.activeObject;
        const isImage = activeObject && (activeObject.type || '').toLowerCase() === 'image';

        if (!isImage) {
            return;
        }

        const isPlaceholder = activeObject.imagePlaceholder === true;

        if (this.hasEditorLockedTarget) this.editorLockedTarget.checked = activeObject.editorLocked === true;
        if (this.hasPlaceholderTarget)  this.placeholderTarget.checked  = isPlaceholder;
        if (this.hasNameTarget)         this.nameTarget.value           = activeObject.name || '';
        if (this.hasDescriptionTarget)  this.descriptionTarget.value    = activeObject.description || '';
        if (this.hasAllowMoveTarget)    this.allowMoveTarget.checked    = activeObject.allowMove || false;
        if (this.hasAllowResizeTarget)  this.allowResizeTarget.checked  = activeObject.allowResize || false;
        if (this.hasAllowRotateTarget)  this.allowRotateTarget.checked  = activeObject.allowRotate || false;
        if (this.hasHidableTarget)      this.hidableTarget.checked      = activeObject.hidable || false;

        const allowed = Array.isArray(activeObject.allowedDirectoryIds) ? activeObject.allowedDirectoryIds : [];
        this.directoryTargets.forEach((checkbox) => {
            checkbox.checked = allowed.includes(checkbox.dataset.directoryId);
        });

        this._toggleDetails(isPlaceholder);
        this._refreshWarning(isPlaceholder);
    }

    updatePlaceholder(event) {
        const image = this._getActiveImage();
        if (!image) return;

        image.imagePlaceholder = event.target.checked;
        if (image.imagePlaceholder && !image.inputId) {
            image.inputId = crypto.randomUUID();
        }

        this._toggleDetails(image.imagePlaceholder);
        this._refreshWarning(image.imagePlaceholder);
        this.canvasEditorOutlet.markUnsaved();
    }

    /**
     * Inline placeholder toggle from the floating mini-toolbar. Flips the
     * popover checkbox and routes through updatePlaceholder so all the side
     * effects (mint inputId, toggle details, refresh warning) run identically.
     */
    togglePlaceholder() {
        if (!this.hasPlaceholderTarget) return;
        this.placeholderTarget.checked = !this.placeholderTarget.checked;
        this.updatePlaceholder({ target: this.placeholderTarget });
    }

    /**
     * EDITOR-ONLY lock: freeze the image against accidental drags/resizes while
     * the designer works on nearby elements. Not a user-facing constraint (it
     * never reaches the imageInputs DTO nor the server render) — it only flips
     * Fabric's interaction flags in the editor. Persisted via the editorLocked
     * custom prop so the lock survives a reload.
     */
    updateEditorLock(event) {
        const image = this._getActiveImage();
        if (!image) return;
        image.editorLocked = event.target.checked;
        applyEditorLock(image);
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    /**
     * Inline editor-lock toggle from the floating mini-toolbar. Flips the flag,
     * applies it, and mirrors the state onto the popover checkbox so both stay
     * in sync (mirrors togglePlaceholder / input-properties#toggleLocked).
     */
    toggleEditorLock() {
        const image = this._getActiveImage();
        if (!image) return;
        image.editorLocked = !image.editorLocked;
        applyEditorLock(image);
        if (this.hasEditorLockedTarget) this.editorLockedTarget.checked = image.editorLocked === true;
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateName(event) {
        const image = this._getActiveImage();
        if (!image) return;
        image.name = event.target.value;
        this.canvasEditorOutlet.markUnsaved();
    }

    updateDescription(event) {
        const image = this._getActiveImage();
        if (!image) return;
        image.description = event.target.value;
        this.canvasEditorOutlet.markUnsaved();
    }

    updateAllowMove(event) {
        const image = this._getActiveImage();
        if (!image) return;
        image.allowMove = event.target.checked;
        this.canvasEditorOutlet.markUnsaved();
    }

    updateAllowResize(event) {
        const image = this._getActiveImage();
        if (!image) return;
        image.allowResize = event.target.checked;
        this.canvasEditorOutlet.markUnsaved();
    }

    updateAllowRotate(event) {
        const image = this._getActiveImage();
        if (!image) return;
        image.allowRotate = event.target.checked;
        this.canvasEditorOutlet.markUnsaved();
    }

    updateHidable(event) {
        const image = this._getActiveImage();
        if (!image) return;
        image.hidable = event.target.checked;
        this.canvasEditorOutlet.markUnsaved();
    }

    updateDirectories() {
        const image = this._getActiveImage();
        if (!image) return;
        image.allowedDirectoryIds = this.directoryTargets
            .filter((checkbox) => checkbox.checked)
            .map((checkbox) => checkbox.dataset.directoryId);
        this._refreshWarning(true);
        this.canvasEditorOutlet.markUnsaved();
    }

    _toggleDetails(show) {
        this.detailsTargets.forEach((element) => {
            element.style.display = show ? 'block' : 'none';
        });
    }

    /**
     * Tell the designer what's still missing for a placeholder. An empty
     * folder allow-list is NOT an error — it means "the whole gallery is open"
     * (every folder plus the gallery root, for picking AND uploading) — but the
     * designer should know that's what will happen.
     */
    _refreshWarning(isPlaceholder) {
        if (!this.hasWarningTarget) return;

        if (!isPlaceholder) {
            this.warningTarget.style.display = 'none';
            return;
        }

        const anyChecked = this.directoryTargets.some((checkbox) => checkbox.checked);

        if (!anyChecked) {
            this.warningTarget.textContent = 'Nevybrali jste žádnou složku — uživateli bude otevřená celá galerie (všechny složky i obrázky mimo složky). Pro omezení vyberte konkrétní složky.';
            this.warningTarget.style.display = 'block';
        } else {
            this.warningTarget.style.display = 'none';
        }
    }

    _getActiveImage() {
        if (!this.hasCanvasEditorOutlet) return null;
        const activeObject = this.canvasEditorOutlet.canvas.getActiveObject();
        if (!activeObject || (activeObject.type || '').toLowerCase() !== 'image') return null;
        return activeObject;
    }
}
