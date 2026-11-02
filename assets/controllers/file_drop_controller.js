import { Controller } from "@hotwired/stimulus";

/**
 * Styled stand-in for a native <input type="file"> inside a regular Symfony
 * form (Nocturne redesign): the input itself is visually hidden (`.sr`) and a
 * <label for> zone opens it. This controller only adds feedback — nothing is
 * uploaded until the surrounding form is submitted.
 *
 *   targets  input    the (hidden) file input
 *            zone     one or more drop zones (labels for the input)
 *            name     where the picked file name is announced (optional)
 *            preview  an <img> that previews a picked image (optional)
 *
 * Dropping files on a zone assigns them to the input (DataTransfer) and fires
 * the same `changed` path as picking them, so the form submits them normally.
 */
export default class extends Controller {
    static targets = ["input", "zone", "name", "preview"];

    zoneTargetConnected(zone) {
        zone.addEventListener("dragover", this.dragOver);
        zone.addEventListener("dragleave", this.dragLeave);
        zone.addEventListener("drop", this.drop);
    }

    zoneTargetDisconnected(zone) {
        zone.removeEventListener("dragover", this.dragOver);
        zone.removeEventListener("dragleave", this.dragLeave);
        zone.removeEventListener("drop", this.drop);
    }

    dragOver = (event) => {
        event.preventDefault();
        event.currentTarget.classList.add("is-over");
    };

    dragLeave = (event) => {
        event.currentTarget.classList.remove("is-over");
    };

    drop = (event) => {
        event.preventDefault();
        event.currentTarget.classList.remove("is-over");
        if (!this.hasInputTarget || !event.dataTransfer || event.dataTransfer.files.length === 0) {
            return;
        }
        try {
            const transfer = new DataTransfer();
            const files = this.inputTarget.multiple ? Array.from(event.dataTransfer.files) : [event.dataTransfer.files[0]];
            files.forEach((file) => transfer.items.add(file));
            this.inputTarget.files = transfer.files;
        } catch (error) {
            // Very old browsers cannot assign FileList — clicking still works.
            return;
        }
        this.changed();
    };

    changed() {
        if (!this.hasInputTarget) return;
        const files = Array.from(this.inputTarget.files || []);
        this.element.classList.toggle("has-file", files.length > 0);

        if (this.hasNameTarget) {
            if (files.length === 0) {
                this.nameTarget.hidden = true;
                this.nameTarget.textContent = "";
            } else {
                const label = files.length === 1 ? files[0].name : `${files.length} souborů`;
                this.nameTarget.textContent = `Vybráno: ${label} — uloží se tlačítkem Uložit`;
                this.nameTarget.hidden = false;
            }
        }

        if (this.hasPreviewTarget && files.length > 0 && files[0].type.startsWith("image/")) {
            if (this._objectUrl) URL.revokeObjectURL(this._objectUrl);
            this._objectUrl = URL.createObjectURL(files[0]);
            this.previewTarget.src = this._objectUrl;
            this.previewTarget.hidden = false;
            const broken = this.previewTarget.closest(".is-broken");
            if (broken) broken.classList.remove("is-broken");
        }
    }

    disconnect() {
        if (this._objectUrl) URL.revokeObjectURL(this._objectUrl);
    }
}
