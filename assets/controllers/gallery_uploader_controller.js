import { Controller } from "@hotwired/stimulus";

/**
 * Upload controller for the Project:ImageGallery modal's "Nahrát nový" tab.
 *
 * A drag-and-drop dropzone that also opens the file picker on click and accepts
 * MULTIPLE images at once. Selected files are NOT uploaded immediately: they
 * first appear as a preview list (thumbnail + name + size) with a confirm
 * button, so the user can review / remove files before committing. Only on
 * "Nahrát" does it POST each file (one at a time) to the existing
 * `project_upload_file` route, updating each row's status (spinner → check /
 * error) and showing a success toast when done. The user stays on the dropzone
 * tab so they can queue another batch.
 *
 * Live Components have no built-in way to stream a File through a LiveProp, so
 * we post directly via fetch(). The hidden CSRF `_token` and the current
 * `directoryId` fields live in the same <form> and are read fresh for every
 * request (the folder field is kept up to date by the Live Component when the
 * user navigates folders).
 *
 * Dispatches `gallery-uploader:uploaded` (bubbling) once after a batch with
 * `{ count, autoSelect, asset, modal }`. The parent image-gallery controller
 * refreshes the grid (lazily on the standalone page, immediately in the modal);
 * when `autoSelect` is true (modal host + exactly one file) it also routes that
 * single asset straight onto the canvas.
 */
export default class extends Controller {
    static targets = ["input", "dropzone", "preview", "actions", "confirmButton", "error"];

    static values = {
        url: String,
        modal: Boolean,
        maxSize: { type: Number, default: 2 * 1024 * 1024 },
    };

    connect() {
        // Queue of files chosen but not yet (or already) uploaded. Each entry:
        // { id, file, url (objectURL|null), status: 'pending'|'uploading'|'done'|'error', message }.
        this.queue = [];
        this.seq = 0;
    }

    disconnect() {
        this.queue.forEach((item) => item.url && URL.revokeObjectURL(item.url));
        this.queue = [];
    }

    // --- picking files -----------------------------------------------------

    browse() {
        this.inputTarget.click();
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

        this.clearError();

        // If the previous batch is finished (nothing pending/uploading left),
        // start fresh so old ✓/✗ rows don't pile up under the new selection.
        const active = this.queue.some((i) => i.status === "pending" || i.status === "uploading");
        if (!active && this.queue.length > 0) {
            this.resetQueue();
        }

        for (const file of files) {
            if (!file.type.startsWith("image/")) {
                this.queue.push({ id: ++this.seq, file, url: null, status: "error", message: "Není obrázek" });
            } else if (file.size > this.maxSizeValue) {
                this.queue.push({ id: ++this.seq, file, url: null, status: "error", message: "Větší než 2 MB" });
            } else {
                this.queue.push({ id: ++this.seq, file, url: URL.createObjectURL(file), status: "pending" });
            }
        }

        // Let the same file be re-picked later (change only fires on a new value).
        this.inputTarget.value = "";
        this.render();
    }

    removeFile(event) {
        const id = event.params.id;
        const idx = this.queue.findIndex((i) => i.id === id);
        if (idx === -1) {
            return;
        }
        const [item] = this.queue.splice(idx, 1);
        if (item.url) {
            URL.revokeObjectURL(item.url);
        }
        this.render();
    }

    clearQueue() {
        this.resetQueue();
        this.clearError();
        this.render();
    }

    resetQueue() {
        this.queue.forEach((item) => item.url && URL.revokeObjectURL(item.url));
        this.queue = [];
        this.inputTarget.value = "";
    }

    // --- uploading ---------------------------------------------------------

    async confirm() {
        const pending = this.queue.filter((i) => i.status === "pending");
        if (pending.length === 0) {
            return;
        }

        this.confirmButtonTarget.disabled = true;

        let successCount = 0;
        let lastAsset = null;

        for (const item of pending) {
            item.status = "uploading";
            this.render();
            try {
                lastAsset = await this.uploadOne(item.file);
                item.status = "done";
                successCount++;
            } catch (error) {
                item.status = "error";
                item.message = error.message || "Nahrání selhalo";
            }
            this.render();
        }

        this.confirmButtonTarget.disabled = false;

        if (successCount === 0) {
            this.showError("Žádný soubor se nepodařilo nahrát.");
            return;
        }

        this.showToast(successCount);

        this.dispatch("uploaded", {
            detail: {
                count: successCount,
                autoSelect: this.modalValue && successCount === 1 && lastAsset !== null,
                asset: lastAsset,
                modal: this.modalValue,
            },
            bubbles: true,
        });
    }

    async uploadOne(file) {
        const formData = new FormData();
        const token = this.element.querySelector('input[name="upload_project_file_form[_token]"]');
        const directoryId = this.element.querySelector('input[name="directoryId"]');
        if (token) {
            formData.append("upload_project_file_form[_token]", token.value);
        }
        if (directoryId) {
            formData.append("directoryId", directoryId.value);
        }
        formData.append("upload_project_file_form[file]", file);

        const response = await fetch(this.urlValue, {
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

        if (!response.ok || !data.filePath) {
            throw new Error(data.error || "Nahrání selhalo");
        }

        return { url: data.filePath, path: data.storagePath };
    }

    // --- rendering ---------------------------------------------------------

    render() {
        if (this.queue.length === 0) {
            this.previewTarget.classList.add("d-none");
            this.previewTarget.innerHTML = "";
            this.actionsTarget.classList.add("d-none");
            return;
        }

        this.previewTarget.classList.remove("d-none");
        this.previewTarget.innerHTML = "";
        for (const item of this.queue) {
            this.previewTarget.appendChild(this.renderItem(item));
        }

        this.actionsTarget.classList.remove("d-none");

        const pending = this.queue.filter((i) => i.status === "pending").length;
        const uploading = this.queue.some((i) => i.status === "uploading");
        if (pending > 0) {
            this.confirmButtonTarget.classList.remove("d-none");
            this.confirmButtonTarget.textContent =
                pending === 1 ? "Nahrát obrázek" : "Nahrát " + pending + " obrázků";
        } else {
            this.confirmButtonTarget.classList.add("d-none");
        }
        this.confirmButtonTarget.disabled = uploading;
    }

    renderItem(item) {
        const row = document.createElement("div");
        row.className = "gallery-upload-item d-flex align-items-center gap-2 border rounded p-2";

        const thumb = item.url
            ? '<img src="' + item.url + '" alt="" class="gallery-upload-item__thumb">'
            : '<span class="gallery-upload-item__thumb gallery-upload-item__thumb--ph"><i class="mdi mdi-image-off-outline"></i></span>';

        row.innerHTML =
            thumb +
            '<div class="flex-grow-1 min-w-0">' +
            '<div class="text-truncate small">' + this.escape(item.file.name) + "</div>" +
            '<div class="small ' + this.msgClass(item.status) + '">' + this.msgText(item) + "</div>" +
            "</div>" +
            '<span class="gallery-upload-item__icon">' + this.iconHtml(item.status) + "</span>";

        // A remove/dismiss control, hidden only while that row is uploading.
        if (item.status !== "uploading") {
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "btn btn-sm btn-link text-muted p-0 ms-1";
            remove.title = "Odebrat";
            remove.setAttribute("aria-label", "Odebrat");
            remove.innerHTML = '<i class="mdi mdi-close"></i>';
            remove.dataset.action = "gallery-uploader#removeFile";
            remove.dataset.galleryUploaderIdParam = String(item.id);
            row.appendChild(remove);
        }

        return row;
    }

    iconHtml(status) {
        if (status === "uploading") {
            return '<span class="spinner-border spinner-border-sm text-muted" role="status"></span>';
        }
        if (status === "done") {
            return '<i class="mdi mdi-check-circle text-success fs-5"></i>';
        }
        if (status === "error") {
            return '<i class="mdi mdi-alert-circle text-danger fs-5"></i>';
        }
        return '<i class="mdi mdi-image-outline text-muted fs-5"></i>';
    }

    msgClass(status) {
        if (status === "done") return "text-success";
        if (status === "error") return "text-danger";
        return "text-muted";
    }

    msgText(item) {
        if (item.status === "uploading") return "Nahrávám…";
        if (item.status === "done") return "Nahráno";
        if (item.status === "error") return item.message || "Chyba";
        return this.formatSize(item.file.size);
    }

    formatSize(bytes) {
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + " kB";
        return (bytes / (1024 * 1024)).toFixed(1) + " MB";
    }

    // --- feedback ----------------------------------------------------------

    showToast(count) {
        let container = document.getElementById("gallery-toast-container");
        if (!container) {
            container = document.createElement("div");
            container.id = "gallery-toast-container";
            container.className = "gallery-toast-container";
            document.body.appendChild(container);
        }

        const toast = document.createElement("div");
        toast.className = "gallery-toast";
        toast.innerHTML = '<i class="mdi mdi-check-circle me-2 fs-5"></i>' + this.escape(this.uploadedMessage(count));
        container.appendChild(toast);

        requestAnimationFrame(() => toast.classList.add("gallery-toast--show"));
        setTimeout(() => {
            toast.classList.remove("gallery-toast--show");
            setTimeout(() => toast.remove(), 300);
        }, 3200);
    }

    uploadedMessage(count) {
        if (count === 1) {
            return "Obrázek byl nahrán";
        }
        if (count >= 2 && count <= 4) {
            return count + " obrázky byly nahrány";
        }
        return count + " obrázků bylo nahráno";
    }

    escape(text) {
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
    }

    clearError() {
        this.errorTarget.classList.add("d-none");
        this.errorTarget.textContent = "";
    }

    showError(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.classList.remove("d-none");
    }
}
