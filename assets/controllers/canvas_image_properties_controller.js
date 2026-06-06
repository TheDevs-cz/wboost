import { Controller } from "@hotwired/stimulus";

/**
 * Editor-side metadata for IMAGE objects — the image counterpart of
 * canvas_input_properties. The designer promotes an image to a fillable
 * placeholder and sets the per-slot limits (move / resize / rotate), hidability,
 * and which gallery folders the end-user may pick a picture from. All values
 * live on the Fabric object as custom properties and round-trip through
 * CANVAS_CUSTOM_PROPERTIES; the orchestrator's submitForm() extracts the
 * placeholder objects into the imageInputs payload on save.
 *
 * The panel is shown only while an image is selected (mirrors the text toolbar);
 * the placeholder-only details (name, limits, folders) are shown only once the
 * image is marked a placeholder.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = [
        "panel", "details", "placeholder", "name", "description",
        "allowMove", "allowResize", "allowRotate", "hidable", "directory",
        "warning",
    ];

    canvasEditorOutletConnected(outlet) {
        this.updateFromSelection({ detail: { activeObject: outlet.canvas.getActiveObject() } });
    }

    updateFromSelection(event) {
        const activeObject = event.detail.activeObject;
        const isImage = activeObject && (activeObject.type || '').toLowerCase() === 'image';

        if (this.hasPanelTarget) {
            this.panelTarget.style.display = isImage ? 'block' : 'none';
        }

        if (!isImage) {
            return;
        }

        const isPlaceholder = activeObject.imagePlaceholder === true;

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
     * folder allow-list is NOT an error — it means "offer the user every
     * gallery folder" — but the designer should know that's what will happen.
     * A project with no folders at all is a real dead end (the user can neither
     * pick nor upload), so that warning is stronger and points at the gallery.
     */
    _refreshWarning(isPlaceholder) {
        if (!this.hasWarningTarget) return;

        if (!isPlaceholder) {
            this.warningTarget.style.display = 'none';
            return;
        }

        const hasFolders = this.directoryTargets.length > 0;
        const anyChecked = this.directoryTargets.some((checkbox) => checkbox.checked);

        if (!hasFolders) {
            this.warningTarget.textContent = 'Projekt nemá žádné složky galerie — vytvořte složku v galerii obrázků, jinak uživatel nebude moci tento obrázek vyplnit.';
            this.warningTarget.style.display = 'block';
        } else if (!anyChecked) {
            this.warningTarget.textContent = 'Nevybrali jste žádnou složku — uživateli budou nabídnuty všechny složky galerie. Pro omezení vyberte konkrétní složky.';
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
