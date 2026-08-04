import { Controller } from "@hotwired/stimulus";

/**
 * Folder-navigable gallery picker for the user-fill pages' image modals —
 * the fill-side counterpart of the admin gallery modal, delivering the same
 * experience (folder cards, breadcrumb, dropzone with immediate upload,
 * "Nahraje se do" target feedback, new-upload highlight) while respecting
 * the placeholder rules the admin defined: the server renders ONLY the
 * slot's allowed folders/images (PlaceholderAllowedDirectories), and the
 * upload endpoint re-validates the target server-side
 * (PlaceholderImageUploader) — this controller is pure presentation over
 * that pre-filtered DOM. No folder CRUD / delete / move here: fill users
 * consume the gallery, they don't manage it.
 *
 * Page-agnostic by design: it never picks an image itself. Thumbs carry the
 * host page's own pick wiring (variant-image-fill#pickImage on the variant
 * page, group-fill#pickImage on the group page), and freshly uploaded thumbs
 * are cloned from a per-modal <template> holding that page-specific markup
 * with __ID__ / __URL__ placeholders. A single-file upload auto-picks by
 * clicking its new thumb (uploading INTO a slot means "use this picture");
 * multi-file batches stay open with the new thumbs highlighted, like the
 * admin modal.
 *
 * View state: `currentDirectoryId === null` is the root view (folder cards
 * + root images when the slot allows the root). A restricted slot with
 * exactly one folder auto-enters it — the only unambiguous target, matching
 * the server's resolveTargetDirectory. Thumb visibility is filtered by
 * `data-directory-id` ('' = root); `data-always-visible` thumbs (the group
 * page's "Výchozí" keep-designed option) show in every view.
 */
export default class extends Controller {
    static targets = [
        "folders", "folderCard", "grid", "thumb", "thumbTemplate", "emptyNote",
        "rootCrumb", "crumbSep", "crumbName",
        "uploadWrap", "uploadTargetLabel", "pickFolderHint",
        "dropzone", "fileInput", "queue",
    ];

    static values = {
        uploadUrl: String,
        includesRoot: Boolean,
        // Must match the server constraint exactly — Symfony's `maxSize: '10m'`
        // is DECIMAL (10 * 1000 * 1000), not 10 MiB.
        maxSize: { type: Number, default: 10 * 1000 * 1000 },
    };

    connect() {
        this.currentDirectoryId = null;
        this.currentDirectoryName = '';
        this.queue = [];
        this.seq = 0;
        this._processing = false;
        this._batchSuccess = 0;
        this._lastThumb = null;

        // Restricted slot with a single folder: enter it right away.
        if (!this.includesRootValue && this.folderCardTargets.length === 1) {
            const card = this.folderCardTargets[0];
            this.currentDirectoryId = card.dataset.fillGalleryIdParam || null;
            this.currentDirectoryName = card.dataset.fillGalleryNameParam || '';
        }

        this.render();
    }

    disconnect() {
        this.queue.forEach((item) => item.url && URL.revokeObjectURL(item.url));
        this.queue = [];
    }

    // --- navigation --------------------------------------------------------

    openFolder(event) {
        this.currentDirectoryId = event.params.id;
        this.currentDirectoryName = event.params.name;
        this.render();
    }

    openRoot() {
        this.currentDirectoryId = null;
        this.currentDirectoryName = '';
        this.render();
    }

    render() {
        const inFolder = this.currentDirectoryId !== null;

        if (this.hasFoldersTarget) {
            this.foldersTarget.classList.toggle('d-none', inFolder || this.folderCardTargets.length === 0);
        }

        if (this.hasRootCrumbTarget) {
            this.rootCrumbTarget.classList.toggle('fw-bold', !inFolder);
            this.rootCrumbTarget.classList.toggle('text-body', !inFolder);
        }
        if (this.hasCrumbSepTarget) this.crumbSepTarget.classList.toggle('d-none', !inFolder);
        if (this.hasCrumbNameTarget) {
            this.crumbNameTarget.classList.toggle('d-none', !inFolder);
            this.crumbNameTarget.textContent = this.currentDirectoryName;
        }

        let visibleThumbs = 0;
        this.thumbTargets.forEach((thumb) => {
            const always = thumb.dataset.alwaysVisible === 'true';
            const dir = thumb.dataset.directoryId || '';
            const show = inFolder
                ? dir === this.currentDirectoryId
                : (this.includesRootValue && dir === '');
            const on = always || show;
            thumb.classList.toggle('d-none', !on);
            if (show) visibleThumbs++;
        });

        if (this.hasEmptyNoteTarget) {
            const rootWithFoldersOnly = !inFolder && !this.includesRootValue;
            this.emptyNoteTarget.classList.toggle('d-none', rootWithFoldersOnly || visibleThumbs > 0);
        }

        // Upload target: the open folder, or the root for unrestricted slots.
        // At a restricted slot's root there is no unambiguous target — hide
        // the dropzone and say why.
        const canTarget = inFolder || this.includesRootValue;
        if (this.hasUploadWrapTarget) this.uploadWrapTarget.classList.toggle('d-none', !canTarget);
        if (this.hasPickFolderHintTarget) this.pickFolderHintTarget.classList.toggle('d-none', canTarget);
        if (this.hasUploadTargetLabelTarget) {
            this.uploadTargetLabelTarget.textContent = inFolder ? this.currentDirectoryName : 'Galerie (bez složky)';
        }
    }

    // --- picking files -----------------------------------------------------

    browse() {
        this.fileInputTarget.click();
    }

    keydown(event) {
        if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            this.browse();
        }
    }

    dragOver(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.add("gallery-dropzone--over");
    }

    dragLeave(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.remove("gallery-dropzone--over");
    }

    drop(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.remove("gallery-dropzone--over");
        this.addFiles(event.dataTransfer ? event.dataTransfer.files : []);
    }

    filesChosen(event) {
        this.addFiles(event.target.files);
    }

    addFiles(fileList) {
        const files = Array.from(fileList || []);
        if (files.length === 0) {
            return;
        }

        for (const file of files) {
            if (!file.type.startsWith("image/")) {
                this.queue.push({ id: ++this.seq, file, status: "error", message: "Není obrázek" });
            } else if (file.size > this.maxSizeValue) {
                const limitMb = Math.round(this.maxSizeValue / (1000 * 1000));
                this.queue.push({ id: ++this.seq, file, status: "error", message: `Větší než ${limitMb} MB` });
            } else {
                this.queue.push({ id: ++this.seq, file, status: "queued" });
            }
        }

        this.fileInputTarget.value = "";
        this.renderQueue();
        this.process();
    }

    removeFile(event) {
        const idx = this.queue.findIndex((i) => i.id === event.params.id);
        if (idx !== -1) {
            this.queue.splice(idx, 1);
            this.renderQueue();
        }
    }

    // --- uploading ---------------------------------------------------------

    async process() {
        if (this._processing) {
            return;
        }
        this._processing = true;

        let item;
        while ((item = this.queue.find((i) => i.status === "queued"))) {
            item.status = "uploading";
            this.renderQueue();

            try {
                const asset = await this.uploadOne(item.file);
                item.status = "done";
                this._batchSuccess++;
                this._lastThumb = this.insertThumb(asset);

                const doneId = item.id;
                setTimeout(() => {
                    const idx = this.queue.findIndex((i) => i.id === doneId);
                    if (idx !== -1) {
                        this.queue.splice(idx, 1);
                        this.renderQueue();
                    }
                }, 2000);
            } catch (error) {
                item.status = "error";
                item.message = error.message || "Nahrání selhalo";
            }
            this.renderQueue();
        }

        this._processing = false;

        // Exactly one uploaded file = the user's picture for this slot: pick
        // it via its own thumb so the page's pick wiring (and modal close)
        // runs. Batches stay open with the highlights.
        if (this._batchSuccess === 1 && this._lastThumb) {
            this._lastThumb.click();
        }
        this._batchSuccess = 0;
        this._lastThumb = null;
    }

    async uploadOne(file) {
        const formData = new FormData();
        formData.append("file", file);
        if (this.currentDirectoryId) {
            formData.append("directoryId", this.currentDirectoryId);
        }

        const response = await fetch(this.uploadUrlValue, {
            method: "POST",
            body: formData,
            headers: { Accept: "application/json" },
        });

        let data = {};
        try {
            data = await response.json();
        } catch (error) {
            // Non-JSON body (e.g. a 413 from the web server) — fall through.
        }

        if (!response.ok || !data.id || !data.url) {
            throw new Error(data.error || "Nahrání selhalo");
        }

        return { id: data.id, url: data.url, directoryId: data.directoryId || '' };
    }

    /** Clone the page-specific thumb markup for a fresh upload, tag it with
     *  its folder and pulse it — visibility rules then apply as usual. */
    insertThumb(asset) {
        const html = this.thumbTemplateTarget.innerHTML
            .replaceAll("__ID__", asset.id)
            .replaceAll("__URL__", asset.url);
        const wrap = document.createElement("div");
        wrap.innerHTML = html.trim();
        const thumb = wrap.firstElementChild;
        if (!thumb) {
            return null;
        }

        thumb.dataset.directoryId = asset.directoryId || '';
        this.gridTarget.prepend(thumb);
        thumb.classList.add("gallery-asset--new");
        setTimeout(() => thumb.classList.remove("gallery-asset--new"), 4000);

        this.render();

        return thumb;
    }

    // --- queue rendering ---------------------------------------------------

    renderQueue() {
        if (!this.hasQueueTarget) {
            return;
        }
        this.queueTarget.innerHTML = "";
        for (const item of this.queue) {
            this.queueTarget.appendChild(this.renderItem(item));
        }
    }

    renderItem(item) {
        const row = document.createElement("div");
        row.className = "gallery-upload-item d-flex align-items-center gap-2 border rounded p-2";

        const icon = item.status === "uploading"
            ? '<span class="spinner-border spinner-border-sm text-muted" role="status"></span>'
            : item.status === "done"
                ? '<i class="mdi mdi-check-circle text-success fs-5"></i>'
                : item.status === "error"
                    ? '<i class="mdi mdi-alert-circle text-danger fs-5"></i>'
                    : '<i class="mdi mdi-image-outline text-muted fs-5"></i>';

        const message = item.status === "uploading" ? "Nahrávám…"
            : item.status === "done" ? "Nahráno"
                : item.status === "error" ? (item.message || "Chyba") : "";

        row.innerHTML =
            '<div class="flex-grow-1 min-w-0">' +
            '<div class="text-truncate small">' + this.escape(item.file.name) + "</div>" +
            '<div class="small ' + (item.status === "error" ? "text-danger" : item.status === "done" ? "text-success" : "text-muted") + '">' + message + "</div>" +
            "</div>" +
            '<span>' + icon + "</span>";

        if (item.status === "error") {
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "btn btn-sm btn-link text-muted p-0 ms-1";
            remove.title = "Odebrat";
            remove.innerHTML = '<i class="mdi mdi-close"></i>';
            remove.dataset.action = "fill-gallery#removeFile";
            remove.dataset.fillGalleryIdParam = String(item.id);
            row.appendChild(remove);
        }

        return row;
    }

    escape(text) {
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
    }
}
