import { Controller } from "@hotwired/stimulus";

/**
 * Multi-file font upload for the fonts page and the manual fonts page — the
 * gallery uploader pattern: a dropzone that also opens the file picker,
 * files POSTed ONE per request to `upload_font` so a refused file (WOFF2, an
 * unreadable table) never blocks the rest of the batch. Each file gets a
 * result row: the family it was filed under and the face it became, "already
 * uploaded", or the server's refusal message. The family cards are
 * server-rendered, so a batch with at least one new face reloads the page
 * once it has drained (after the rows have been readable for a moment).
 *
 * The CSRF token (`upload_font_form[_token]`) is a hidden input inside the
 * controller root.
 */
export default class extends Controller {
    static targets = ["input", "dropzone", "queue"];

    static values = {
        url: String,
        // Symfony's `maxSize: '10m'` is DECIMAL — mirror it exactly.
        maxSize: { type: Number, default: 10 * 1000 * 1000 },
        reloadDelay: { type: Number, default: 1200 },
    };

    static ACCEPTED = ["ttf", "otf", "woff"];

    connect() {
        this.queue = [];
        this.seq = 0;
        this._processing = false;
        this._batchAdded = 0;
    }

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
        if (files.length === 0) return;

        for (const file of files) {
            const extension = (file.name.split(".").pop() || "").toLowerCase();
            if (extension === "woff2") {
                this.queue.push({ id: ++this.seq, file, status: "error", message: "WOFF2 zatím nepodporujeme — nahrajte TTF, OTF nebo WOFF." });
            } else if (!this.constructor.ACCEPTED.includes(extension)) {
                this.queue.push({ id: ++this.seq, file, status: "error", message: "Není soubor písma (TTF, OTF, WOFF)." });
            } else if (file.size > this.maxSizeValue) {
                const limitMb = Math.round(this.maxSizeValue / (1000 * 1000));
                this.queue.push({ id: ++this.seq, file, status: "error", message: `Větší než ${limitMb} MB.` });
            } else {
                this.queue.push({ id: ++this.seq, file, status: "queued" });
            }
        }

        this.inputTarget.value = "";
        this.render();
        this.process();
    }

    removeFile(event) {
        const id = event.params.id;
        const idx = this.queue.findIndex((item) => item.id === id);
        if (idx === -1) return;
        this.queue.splice(idx, 1);
        this.render();
    }

    async process() {
        if (this._processing) return;
        this._processing = true;

        let item;
        while ((item = this.queue.find((i) => i.status === "queued"))) {
            item.status = "uploading";
            this.render();
            try {
                const result = await this.uploadOne(item.file);
                if (result.status === "added") {
                    item.status = "done";
                    item.message = result.faces > 1
                        ? `Přidáno do písma ${result.family} jako řez „${result.face}“ (${result.faces} řezy).`
                        : `Nové písmo ${result.family} — řez „${result.face}“.`;
                    this._batchAdded++;
                } else {
                    item.status = "exists";
                    item.message = result.message || "Tento řez už je nahraný.";
                }
            } catch (error) {
                item.status = "error";
                item.message = error.message || "Nahrání selhalo.";
            }
            this.render();
        }

        this._processing = false;

        if (this._batchAdded > 0) {
            const added = this._batchAdded;
            this._batchAdded = 0;
            this.dispatch("uploaded", { detail: { added }, bubbles: true });
            setTimeout(() => window.location.reload(), this.reloadDelayValue);
        }
    }

    async uploadOne(file) {
        const formData = new FormData();
        const token = this.element.querySelector('input[name="upload_font_form[_token]"]');
        if (token) formData.append("upload_font_form[_token]", token.value);
        formData.append("upload_font_form[file]", file);

        const response = await fetch(this.urlValue, {
            method: "POST",
            body: formData,
            headers: { Accept: "application/json" },
        });

        let data = {};
        try {
            data = await response.json();
        } catch (error) {
            // Non-JSON body (a 413 from the web server) — fall through.
        }

        if (!response.ok || !data.status) {
            throw new Error(data.error || "Nahrání selhalo.");
        }

        return data;
    }

    render() {
        if (!this.hasQueueTarget) return;
        this.queueTarget.innerHTML = "";
        for (const item of this.queue) {
            this.queueTarget.appendChild(this.renderItem(item));
        }
    }

    renderItem(item) {
        const row = document.createElement("div");
        row.className = "gallery-upload-item d-flex align-items-center gap-2 border rounded p-2";
        row.innerHTML =
            '<span class="gallery-upload-item__thumb gallery-upload-item__thumb--ph"><i class="mdi mdi-format-font"></i></span>' +
            '<div class="flex-grow-1 min-w-0">' +
            '<div class="text-truncate small">' + this.escape(item.file.name) + "</div>" +
            '<div class="small ' + this.msgClass(item.status) + '">' + this.escape(this.msgText(item)) + "</div>" +
            "</div>" +
            '<span class="gallery-upload-item__icon">' + this.iconHtml(item.status) + "</span>";

        if (item.status !== "uploading") {
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "btn btn-sm btn-link text-muted p-0 ms-1";
            remove.setAttribute("aria-label", "Odebrat ze seznamu");
            remove.innerHTML = '<i class="mdi mdi-close"></i>';
            remove.addEventListener("click", () => this.removeFile({ params: { id: item.id } }));
            row.appendChild(remove);
        }

        return row;
    }

    msgClass(status) {
        return { done: "text-success", error: "text-danger", exists: "text-warning" }[status] || "text-muted";
    }

    msgText(item) {
        if (item.status === "queued") return "Čeká…";
        if (item.status === "uploading") return "Nahrávám…";
        return item.message || "";
    }

    iconHtml(status) {
        if (status === "uploading") return '<span class="spinner-border spinner-border-sm text-primary" role="status"></span>';
        if (status === "done") return '<i class="mdi mdi-check-circle text-success"></i>';
        if (status === "exists") return '<i class="mdi mdi-information-outline text-warning"></i>';
        if (status === "error") return '<i class="mdi mdi-alert-circle text-danger"></i>';
        return '<i class="mdi mdi-clock-outline text-muted"></i>';
    }

    escape(text) {
        const div = document.createElement("div");
        div.textContent = String(text);
        return div.innerHTML;
    }
}
