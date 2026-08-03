import { Controller } from "@hotwired/stimulus";

/**
 * Upload controller for the Project:ImageGallery's compact upload strip.
 *
 * A drag-and-drop dropzone that also opens the file picker on click and
 * accepts MULTIPLE images at once. Files upload IMMEDIATELY on drop/pick
 * (no confirm step): each file gets a progress row (spinner → check / error)
 * in the queue island, POSTed one at a time to the existing
 * `project_upload_file` route. Per successful file a bubbling
 * `gallery-uploader:uploaded` event carries `{ asset: { url, path, id } }` —
 * the parent image-gallery controller refreshes the Live grid and pulses the
 * new thumbnail, which is the user-facing "it landed in THIS folder" feedback.
 * Successful rows dissolve shortly after (the grid shows the real thumbnail
 * by then); failed rows stay dismissable with the reason. A toast summarises
 * each drained batch.
 *
 * Live Components have no built-in way to stream a File through a LiveProp,
 * so we post directly via fetch(). The hidden CSRF `_token` and the current
 * `directoryId` fields live in the same <form> OUTSIDE the data-live-ignore
 * island and are read fresh for every request — the Live re-render keeps the
 * folder field pointing at the currently open folder. The queue rows live
 * INSIDE data-live-ignore so the per-file grid refreshes can never wipe an
 * in-flight batch.
 */
export default class extends Controller {
    static targets = ["input", "dropzone", "queue"];

    static values = {
        url: String,
        // Must match the server constraint exactly, or a file could pass here
        // and still be rejected on upload. Symfony's `maxSize: '10m'` is
        // DECIMAL (10 * 1000 * 1000), not 10 MiB.
        maxSize: { type: Number, default: 10 * 1000 * 1000 },
    };

    connect() {
        // Upload queue. Each entry: { id, file, url (objectURL|null),
        // status: 'queued'|'uploading'|'done'|'error', message }.
        this.queue = [];
        this.seq = 0;
        this._processing = false;
        this._batchSuccess = 0;
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

        for (const file of files) {
            if (!file.type.startsWith("image/")) {
                this.queue.push({ id: ++this.seq, file, url: null, status: "error", message: "Není obrázek" });
            } else if (file.size > this.maxSizeValue) {
                const limitMb = Math.round(this.maxSizeValue / (1000 * 1000));
                this.queue.push({ id: ++this.seq, file, url: null, status: "error", message: `Větší než ${limitMb} MB` });
            } else {
                this.queue.push({ id: ++this.seq, file, url: URL.createObjectURL(file), status: "queued" });
            }
        }

        // Let the same file be re-picked later (change only fires on a new value).
        this.inputTarget.value = "";
        this.render();
        this.process();
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

    // --- uploading ---------------------------------------------------------

    /** Sequential worker: uploads queued files one at a time; new drops while
     *  a batch runs simply extend the queue the running loop drains. */
    async process() {
        if (this._processing) {
            return;
        }
        this._processing = true;

        let item;
        while ((item = this.queue.find((i) => i.status === "queued"))) {
            item.status = "uploading";
            this.render();

            try {
                const asset = await this.uploadOne(item.file);
                item.status = "done";
                this._batchSuccess++;

                this.dispatch("uploaded", {
                    detail: { asset },
                    bubbles: true,
                });

                // The grid refresh triggered by the event shows the real
                // thumbnail highlighted — dissolve the ✓ row shortly after.
                const doneId = item.id;
                setTimeout(() => this.dropItem(doneId), 2500);
            } catch (error) {
                item.status = "error";
                item.message = error.message || "Nahrání selhalo";
            }
            this.render();
        }

        this._processing = false;

        if (this._batchSuccess > 0) {
            this.showToast(this._batchSuccess);
            this._batchSuccess = 0;
        }
    }

    dropItem(id) {
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

        return { url: data.filePath, path: data.storagePath, id: data.id || null };
    }

    // --- rendering ---------------------------------------------------------

    render() {
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

        // A dismiss control, hidden only while that row is uploading.
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
}
