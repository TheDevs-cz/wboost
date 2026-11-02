import { Controller } from '@hotwired/stimulus';

/*
 * Logo / symbol dropzone (prototype `.drop` in the "Nový projekt" and "Změnit
 * logo" dialogs) over a plain <input type="file"> — add_project / edit_project.
 * Click opens the picker (the input sits inside the <label>), dropping a file
 * assigns it to the input, and the chosen image is previewed in place.
 */
export default class extends Controller {
    static targets = ['input', 'preview', 'empty', 'clear'];

    connect() {
        this.url = null;
    }

    disconnect() {
        if (this.url) URL.revokeObjectURL(this.url);
    }

    over(e) {
        e.preventDefault();
        this.element.classList.add('on');
    }

    leave() {
        this.element.classList.remove('on');
    }

    drop(e) {
        e.preventDefault();
        this.element.classList.remove('on');
        const files = e.dataTransfer && e.dataTransfer.files;
        if (!files || !files.length) return;
        const dt = new DataTransfer();
        dt.items.add(files[0]);
        this.inputTarget.files = dt.files;
        this.changed();
    }

    changed() {
        const file = this.inputTarget.files && this.inputTarget.files[0];
        if (this.url) { URL.revokeObjectURL(this.url); this.url = null; }
        if (file) {
            this.url = URL.createObjectURL(file);
            this.previewTarget.src = this.url;
        }
        this.previewTarget.hidden = !file;
        this.emptyTarget.hidden = !!file;
        if (this.hasClearTarget) this.clearTarget.hidden = !file;
    }

    clear(e) {
        e.preventDefault();
        e.stopPropagation();
        this.inputTarget.value = '';
        this.changed();
    }
}
