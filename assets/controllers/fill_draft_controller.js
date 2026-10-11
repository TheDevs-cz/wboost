import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';

/**
 * "Uložit rozpracované" on both fill pages: work in progress must be savable
 * WHATEVER state the form is in — an overflowing text blocks the export (and
 * with it the export-history snapshot), and leaving the page then threw the
 * work away.
 *
 * - save() POSTs the fill form as it is to the page's draft endpoint (no
 *   render); the answer is a Turbo Stream re-rendering the history dropdown,
 *   banner and anchors around the saved draft, which becomes the page's
 *   loaded version: its id lands in the `draftVersionId` field (the next save
 *   overwrites it, an export promotes it) and in the URL (`?version=<id>`),
 *   so a reload or a shared link brings the work back. Ctrl/⌘+S saves too.
 * - Unsaved work is guarded on leave (beforeunload). "Unsaved" = the form's
 *   values differ from the BASELINE: the state at the user's first touch of
 *   the page (by then every controller has written its initial fields), then
 *   the state of each successful save and of each export submit (an export
 *   records its values too).
 *
 * Mounted on the group fill <form> itself, and on the single page on a
 * wrapper around the deferred Live component — the form is looked up lazily
 * there, and the two hidden fields sit outside it, joined via `form="…"`.
 */
export default class extends Controller {
    static targets = ['button', 'versionField'];

    static values = {
        url: String,
    };

    connect() {
        this._baseline = null;
        this._saving = false;

        this._onFirstTouch = () => {
            if (this._baseline === null) {
                this._baseline = this._snapshot();
            }
        };
        this._onSubmit = () => {
            this._baseline = this._snapshot();
        };
        this._onBeforeUnload = (event) => {
            if (this._isDirty()) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        this._onKeydown = (event) => {
            if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 's') {
                event.preventDefault();
                this.save();
            }
        };

        this.element.addEventListener('pointerdown', this._onFirstTouch, true);
        this.element.addEventListener('keydown', this._onFirstTouch, true);
        this.element.addEventListener('submit', this._onSubmit);
        window.addEventListener('beforeunload', this._onBeforeUnload);
        document.addEventListener('keydown', this._onKeydown);
    }

    disconnect() {
        this.element.removeEventListener('pointerdown', this._onFirstTouch, true);
        this.element.removeEventListener('keydown', this._onFirstTouch, true);
        this.element.removeEventListener('submit', this._onSubmit);
        window.removeEventListener('beforeunload', this._onBeforeUnload);
        document.removeEventListener('keydown', this._onKeydown);
    }

    async save() {
        const form = this._form();
        if (!form || this._saving) {
            return;
        }

        this._saving = true;
        this._setBusy(true);
        const snapshot = this._snapshot();

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'text/vnd.turbo-stream.html' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Draft save failed: HTTP ${response.status}`);
            }

            const versionId = response.headers.get('X-Fill-Draft-Version');
            renderStreamMessage(await response.text());

            if (versionId) {
                this.versionFieldTargets.forEach((field) => { field.value = versionId; });
                const url = new URL(window.location.href);
                url.searchParams.set('version', versionId);
                window.history.replaceState(window.history.state, '', url);
            }

            this._baseline = snapshot;
            this._toast('Rozpracovaná práce je uložená — najdete ji v Historii exportů.', 'success');
        } catch (error) {
            console.error(error);
            this._toast('Uložení se nepodařilo. Zkuste to prosím znovu.', 'warning');
        } finally {
            this._saving = false;
            this._setBusy(false);
        }
    }

    _form() {
        return this.element.tagName === 'FORM' ? this.element : this.element.querySelector('form.fill-form');
    }

    /** The form's values as one comparable string (without the save plumbing). */
    _snapshot() {
        const form = this._form();
        if (!form) {
            return null;
        }

        const parts = [];
        for (const [key, value] of new FormData(form)) {
            if (key === '_fill_draft_token' || key === 'draftVersionId') {
                continue;
            }
            parts.push(`${key}=${typeof value === 'string' ? value : ''}`);
        }

        return parts.join('&');
    }

    _isDirty() {
        if (this._baseline === null) {
            return false;
        }

        const current = this._snapshot();

        return current !== null && current !== this._baseline;
    }

    _setBusy(busy) {
        this.buttonTargets.forEach((button) => {
            button.disabled = busy;
            button.classList.toggle('is-saving', busy);
        });
    }

    _toast(message, tone) {
        let container = document.getElementById('gallery-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'gallery-toast-container';
            container.className = 'gallery-toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = tone === 'warning' ? 'gallery-toast gallery-toast--warning' : 'gallery-toast';
        toast.setAttribute('role', tone === 'warning' ? 'alert' : 'status');
        const icon = document.createElement('i');
        icon.className = `mdi ${tone === 'warning' ? 'mdi-alert-circle-outline' : 'mdi-content-save-check-outline'} me-2 fs-5`;
        icon.setAttribute('aria-hidden', 'true');
        toast.appendChild(icon);
        toast.appendChild(document.createTextNode(message));
        container.appendChild(toast);

        requestAnimationFrame(() => toast.classList.add('gallery-toast--show'));
        setTimeout(() => {
            toast.classList.remove('gallery-toast--show');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }
}
