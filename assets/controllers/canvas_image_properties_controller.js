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
 * fields when an image is selected. The name is offered for EVERY image — it is
 * the row label in the layers panel (an unnamed image shows as a numbered
 * "Obrázek N" there) and, for a placeholder, also the fill-page field tag. The
 * placeholder-only details (description, limits, folders) are still shown only
 * once the image is marked a placeholder.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = [
        "details", "placeholder", "name", "description",
        "allowMove", "allowResize", "allowRotate", "hidable", "directory", "folderItem",
        "warning", "editorLocked", "transformLimit", "backgroundHint",
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
        const isBackground = activeObject.isBackground === true;

        if (this.hasEditorLockedTarget) this.editorLockedTarget.checked = activeObject.editorLocked === true;
        if (this.hasPlaceholderTarget)  this.placeholderTarget.checked  = isPlaceholder;
        if (this.hasNameTarget)         this.nameTarget.value           = activeObject.name || '';
        if (this.hasDescriptionTarget)  this.descriptionTarget.value    = activeObject.description || '';
        if (this.hasAllowMoveTarget)    this.allowMoveTarget.checked    = !isBackground && (activeObject.allowMove || false);
        if (this.hasAllowResizeTarget)  this.allowResizeTarget.checked  = !isBackground && (activeObject.allowResize || false);
        if (this.hasAllowRotateTarget)  this.allowRotateTarget.checked  = !isBackground && (activeObject.allowRotate || false);
        if (this.hasHidableTarget)      this.hidableTarget.checked      = activeObject.hidable || false;

        // Background fills are a fixed top-left cover — the transform limits
        // don't apply, so hide them and show the explanatory hint instead.
        this.transformLimitTargets.forEach((el) => { el.style.display = isBackground ? 'none' : ''; });
        if (this.hasBackgroundHintTarget) this.backgroundHintTarget.style.display = isBackground ? 'block' : 'none';

        // Folder tree: re-open the branches leading to ticked folders only
        // when a DIFFERENT image gets selected — a re-sync of the same image
        // keeps whatever the designer expanded / collapsed.
        const otherImage = activeObject !== this._foldersObject;
        this._foldersObject = activeObject;
        this._renderFolders(activeObject, otherImage);

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
        // A fill re-places a placeholder's picture and resets its flip
        // (ImagePlacement), so a mirrored stand-in would preview a state no
        // export reproduces — mirroring is a decorative-image feature.
        if (image.imagePlaceholder && image.flipX) {
            image.set('flipX', false);
            this.canvasEditorOutlet.canvas.renderAll();
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

    /**
     * Horizontal mirror from the floating mini-toolbar (decorative images
     * only — the toolbar hides the button on placeholders and backgrounds).
     * Plain Fabric `flipX`: it serializes with the canvas JSON and the
     * headless render honours it, and Fabric flips around the object's centre
     * so the bounding box (left/top/size) stays put. `object:modified` lets
     * the group editor's diff pass carry it to the other variants.
     */
    toggleMirror() {
        const image = this._getActiveImage();
        if (!image || image.imagePlaceholder || image.isBackground === true) return;
        image.set('flipX', !image.flipX);
        const canvas = this.canvasEditorOutlet.canvas;
        canvas.renderAll();
        canvas.fire('object:modified', { target: image });
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

    // --- Složky galerie (folder tree) ---------------------------------------
    //
    // Allowing a folder allows its WHOLE sub-tree server-side
    // (PlaceholderAllowedDirectories), so the stored allow-list keeps only the
    // TOP-MOST ticked folders: ticking a parent drops its ticked descendants
    // from `allowedDirectoryIds` and shows the whole sub-tree as ticked +
    // disabled ("Zahrnuto v nadřazené složce"). The dropped child ticks are
    // remembered per image (editor session only, never saved) so unticking
    // the parent brings them back. Ticking only children works as before; a
    // parent with some ticked descendants shows the indeterminate dash (pure
    // display — nothing is stored for it).

    updateDirectories(event) {
        const image = this._getActiveImage();
        if (!image) return;

        const checkbox = event && event.target;
        const ids = new Set(this._explicitDirectoryIds(image));
        const memory = this._shadowMemory(image);
        const id = checkbox && checkbox.dataset ? checkbox.dataset.directoryId : null;

        if (id) {
            if (checkbox.checked) {
                ids.add(id);
                this._setBranchOpen(this._folderItem(id), true);
            } else {
                ids.delete(id);
                (memory.get(id) || []).forEach((childId) => ids.add(childId));
                memory.delete(id);
            }
        }

        image.allowedDirectoryIds = this._normalizeDirectoryIds(ids, memory);
        this._renderFolders(image, false);
        this._refreshWarning(true);
        this.canvasEditorOutlet.markUnsaved();
    }

    /** Chevron: show / hide one folder's sub-folders. */
    toggleBranch(event) {
        const item = event.currentTarget.closest('[data-canvas-image-properties-target~="folderItem"]');
        if (!item) return;
        const button = event.currentTarget;
        this._setBranchOpen(item, button.getAttribute('aria-expanded') !== 'true');
    }

    /**
     * Keep only the top-most ticked folders (in tree order), parking each
     * covered descendant in `memory` under its top-most ticked ancestor.
     * Ids the tree doesn't know (folders deleted since) drop out — the same
     * as the old flat list did on any change.
     */
    _normalizeDirectoryIds(ids, memory) {
        const result = [];
        this.folderItemTargets.forEach((item) => {
            const folderId = item.dataset.directoryId;
            if (!ids.has(folderId)) return;
            const top = this._ancestorItems(item).reverse().find((ancestor) => ids.has(ancestor.dataset.directoryId));
            if (!top) {
                result.push(folderId);
                return;
            }
            const parked = memory.get(top.dataset.directoryId) || [];
            if (!parked.includes(folderId)) parked.push(folderId);
            memory.set(top.dataset.directoryId, parked);
        });
        return result;
    }

    /**
     * Paint every folder row from the image's allow-list: explicitly ticked,
     * included via a ticked ancestor (ticked + disabled), partially ticked
     * below (indeterminate) or off. `resetExpansion` collapses the tree and
     * opens just the branches leading to ticked folders.
     */
    _renderFolders(image, resetExpansion) {
        if (!this.hasFolderItemTarget) return;
        const explicit = new Set(this._explicitDirectoryIds(image));

        this.folderItemTargets.forEach((item) => {
            const folderId = item.dataset.directoryId;
            const row = item.querySelector(':scope > .ed-ftree__row');
            const checkbox = row && row.querySelector('input[type="checkbox"]');
            if (!row || !checkbox) return;

            const ancestor = this._ancestorItems(item).find((candidate) => explicit.has(candidate.dataset.directoryId));
            if (ancestor) {
                checkbox.checked = true;
                checkbox.disabled = true;
                checkbox.indeterminate = false;
                row.classList.add('is-inherited');
                row.classList.remove('is-partial');
                row.title = `Zahrnuto v nadřazené složce „${this._folderName(ancestor)}“`;
                return;
            }

            const checked = explicit.has(folderId);
            const partial = !checked && this._descendantIds(item).some((childId) => explicit.has(childId));
            checkbox.disabled = false;
            checkbox.checked = checked;
            checkbox.indeterminate = partial;
            row.classList.remove('is-inherited');
            row.classList.toggle('is-partial', partial);
            if (partial) row.title = 'Vybrané jsou jen některé podsložky';
            else row.removeAttribute('title');
        });

        if (resetExpansion) {
            this.folderItemTargets.forEach((item) => this._setBranchOpen(item, false));
            explicit.forEach((folderId) => {
                const item = this._folderItem(folderId);
                if (item) this._ancestorItems(item).forEach((ancestor) => this._setBranchOpen(ancestor, true));
            });
        }
    }

    _explicitDirectoryIds(image) {
        return Array.isArray(image && image.allowedDirectoryIds) ? image.allowedDirectoryIds : [];
    }

    /** Per-image memory: ticked folder id → child ticks it covered. */
    _shadowMemory(image) {
        if (!this._shadowed) this._shadowed = new WeakMap();
        if (!this._shadowed.has(image)) this._shadowed.set(image, new Map());
        return this._shadowed.get(image);
    }

    _folderItem(folderId) {
        return this.folderItemTargets.find((item) => item.dataset.directoryId === folderId) || null;
    }

    /** Folder items enclosing `item`, nearest first. */
    _ancestorItems(item) {
        const result = [];
        let parent = item.parentElement && item.parentElement.closest('[data-canvas-image-properties-target~="folderItem"]');
        while (parent) {
            result.push(parent);
            parent = parent.parentElement && parent.parentElement.closest('[data-canvas-image-properties-target~="folderItem"]');
        }
        return result;
    }

    _descendantIds(item) {
        return Array.from(item.querySelectorAll(':scope > ul [data-canvas-image-properties-target~="folderItem"]'))
            .map((descendant) => descendant.dataset.directoryId);
    }

    _folderName(item) {
        const name = item.querySelector(':scope > .ed-ftree__row .ed-ftree__name');
        return name ? name.textContent.trim() : '';
    }

    _setBranchOpen(item, open) {
        if (!item) return;
        const list = item.querySelector(':scope > ul');
        const button = item.querySelector(':scope > .ed-ftree__row .ed-ftree__chev[aria-expanded]');
        if (!list || !button) return;
        list.hidden = !open;
        button.setAttribute('aria-expanded', String(open));
        button.setAttribute('aria-label', `${open ? 'Sbalit' : 'Rozbalit'} složku ${this._folderName(item)}`);
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

        const anyChecked = this._explicitDirectoryIds(this._getActiveImage()).length > 0;

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
