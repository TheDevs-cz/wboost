/*
 * The save transport both canvas editors share (canvas_editor_controller's
 * single-variant save and group_editor_controller's "Uložit vše").
 *
 * Why it exists (Sentry WEB-4X, 2026-11-15): a designer on a slow uplink
 * clicked "Uložit vše" while nothing visibly happened — the 1–2 MB multipart
 * upload of every variant's canvas + thumbnail took longer than 30 s — and
 * every click started ANOTHER upload of the same size. The parallel uploads
 * shared the uplink, each one outlived PHP's limit, and all of them failed.
 * Saving now:
 *
 * - shows the upload on the save button ("Ukládám… 42 %"), via XHR — fetch()
 *   still has no upload progress;
 * - never runs two saves at once: a click during a save queues ONE follow-up
 *   save, which serializes the editor afresh when it starts, so the newest
 *   state is what lands last (SaveRunner);
 * - keeps the page from being left while an upload is in flight;
 * - tells the designer what went wrong in words (too large / connection /
 *   server / expired page) in a toast, instead of one generic alert.
 *
 * The thumbnails go up as binary PNG files (dataUriToBlob) instead of base64
 * text fields — a third less to upload.
 */

/** Generous: Traefik bounds a request body at 180 s and PHP the handling. */
const SAVE_TIMEOUT_MS = 10 * 60 * 1000;

const PROGRESS_LABEL = 'Ukládám…';

/** A save that did not land, with the reason the designer is told. */
export class SaveError extends Error {
    /**
     * @param {'network'|'timeout'|'aborted'|'too_large'|'session'|'rejected'|'server'|'unknown'} kind
     * @param {{status?: number, serverMessage?: string|null}} [details]
     */
    constructor(kind, { status = 0, serverMessage = null } = {}) {
        super(`Save failed: ${kind}${status ? ` (HTTP ${status})` : ''}`);
        this.kind = kind;
        this.status = status;
        this.serverMessage = serverMessage;
    }
}

/**
 * A `data:<type>;base64,…` URI as a Blob, or null for anything else (an empty
 * string when the canvas was tainted — the server then keeps the old thumbnail).
 *
 * @param {string} dataUri
 * @returns {Blob|null}
 */
export function dataUriToBlob(dataUri) {
    if (typeof dataUri !== 'string' || !dataUri.startsWith('data:')) {
        return null;
    }

    const comma = dataUri.indexOf(',');
    const meta = comma < 0 ? '' : dataUri.slice(5, comma);
    if (!meta.endsWith(';base64')) {
        return null;
    }

    let binary;
    try {
        binary = atob(dataUri.slice(comma + 1));
    } catch {
        return null;
    }

    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return new Blob([bytes], { type: meta.slice(0, -';base64'.length) || 'application/octet-stream' });
}

/**
 * POST a FormData expecting the editors' JSON answer. Resolves when the
 * server answered `{status: "success"}`, rejects with a SaveError otherwise.
 *
 * @param {string} url
 * @param {FormData} formData
 * @param {{onProgress?: (fraction: number) => void}} [options]
 * @returns {Promise<object>} the JSON answer
 */
export function postEditorSave(url, formData, { onProgress = null } = {}) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.timeout = SAVE_TIMEOUT_MS;

        if (onProgress) {
            xhr.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable && event.total > 0) {
                    onProgress(event.loaded / event.total);
                }
            });
        }

        xhr.addEventListener('load', () => {
            let data = null;
            try {
                data = JSON.parse(xhr.responseText);
            } catch {
                data = null;
            }

            if (xhr.status >= 200 && xhr.status < 300 && data && data.status === 'success') {
                resolve(data);
                return;
            }

            reject(classifyFailure(xhr.status, data));
        });
        xhr.addEventListener('error', () => reject(new SaveError('network')));
        xhr.addEventListener('timeout', () => reject(new SaveError('timeout')));
        xhr.addEventListener('abort', () => reject(new SaveError('aborted')));

        xhr.send(formData);
    });
}

function classifyFailure(status, data) {
    const serverMessage = data && typeof data.message === 'string' ? data.message : null;
    const code = data && typeof data.code === 'string' ? data.code : null;

    if (status === 413 || code === 'request_too_large') {
        return new SaveError('too_large', { status, serverMessage });
    }
    if (code === 'csrf' || status === 401 || status === 403 || status === 419) {
        return new SaveError('session', { status, serverMessage });
    }
    if (status === 422 && serverMessage) {
        return new SaveError('rejected', { status, serverMessage });
    }
    // 5xx, no answer, 429 (the edge caps concurrent uploads per client), or
    // a 2xx that is not the save contract (a PHP fatal rendered as a page):
    // the server did not finish the save — try again shortly.
    if (status === 0 || status === 429 || status >= 500 || (status >= 200 && status < 300)) {
        return new SaveError('server', { status, serverMessage });
    }

    return new SaveError('unknown', { status, serverMessage });
}

/**
 * The sentence the designer reads. Every failure says the work is still in
 * the editor — nothing on screen is lost by a failed save.
 *
 * @param {unknown} error
 * @returns {string}
 */
export function describeSaveFailure(error) {
    const kind = error instanceof SaveError ? error.kind : 'unknown';
    const keep = 'Změny zůstávají v editoru.';

    switch (kind) {
        case 'too_large':
            return error.serverMessage
                ?? `Návrh je na uložení příliš velký. ${keep} Zkuste zmenšit vložené obrázky nebo uložit méně formátů najednou.`;
        case 'network':
            return `Spojení se přerušilo a návrh se neuložil. ${keep} Zkontrolujte připojení a uložte znovu.`;
        case 'timeout':
            return `Ukládání trvalo příliš dlouho a nedokončilo se. ${keep} Zkuste uložit znovu.`;
        case 'aborted':
            return `Ukládání bylo přerušeno. ${keep}`;
        case 'session':
            return 'Platnost stránky vypršela (nejspíš odhlášení), návrh se neuložil. Přihlaste se v jiném panelu a pak uložte znovu.';
        case 'rejected':
            return error.serverMessage;
        case 'server':
            return `Server teď ukládání nedokončil. ${keep} Zkuste uložit znovu za chvíli.`;
        default:
            return `Ukládání se nepovedlo. ${keep} Zkuste to prosím znovu.`;
    }
}

/**
 * A toast in the app's gallery-toast kit (same container the gallery, fill
 * drafts and the group editor's resync notice use).
 *
 * @param {string} message
 * @param {'success'|'warning'} [tone]
 */
export function showSaveToast(message, tone = 'success') {
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
    toast.textContent = message;
    container.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('gallery-toast--show'));
    setTimeout(() => {
        toast.classList.remove('gallery-toast--show');
        setTimeout(() => toast.remove(), 300);
    }, tone === 'warning' ? 12000 : 3000);
}

/**
 * Runs saves one at a time. `run(task)` while a save is in flight does not
 * start a second upload: it queues exactly one follow-up (further clicks join
 * it) that starts when the current one settles — the task serializes the
 * editor when it STARTS, so the follow-up carries everything edited
 * meanwhile. While anything runs, the save button shows the progress and the
 * page asks before being left.
 */
export class SaveRunner {
    /**
     * @param {{button?: HTMLElement|null}} [options]
     */
    constructor({ button = null } = {}) {
        this.button = button;
        this._current = null;
        this._queued = null;
        this._running = false;
        this._fraction = null;
        this._idleLabel = button ? button.textContent : '';
        this._onBeforeUnload = (event) => {
            if (!this.busy) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', this._onBeforeUnload);
    }

    get busy() {
        return this._current !== null || this._queued !== null;
    }

    disconnect() {
        window.removeEventListener('beforeunload', this._onBeforeUnload);
    }

    /**
     * @template T
     * @param {(onProgress: (fraction: number) => void) => Promise<T>} task
     * @returns {Promise<T>}
     */
    run(task) {
        if (this._current === null) {
            return this._start(task);
        }

        if (this._queued === null) {
            this._queued = this._current
                .catch(() => undefined)
                .then(() => {
                    this._queued = null;
                    return this._start(task);
                });
            this._render();
        }

        return this._queued;
    }

    _start(task) {
        this._fraction = null;
        this._running = true;
        this._setBusy(true);
        const current = (async () => {
            try {
                return await task((fraction) => this._progress(fraction));
            } finally {
                this._current = null;
                this._running = false;
                if (this._queued === null) {
                    this._setBusy(false);
                }
            }
        })();
        this._current = current;

        return current;
    }

    _progress(fraction) {
        this._fraction = fraction;
        this._render();
    }

    _setBusy(busy) {
        if (!this.button) {
            return;
        }
        this.button.classList.toggle('is-saving', busy);
        this.button.setAttribute('aria-busy', String(busy));
        if (busy) {
            this._render();
        } else {
            this.button.textContent = this._idleLabel;
        }
    }

    /**
     * "Ukládám… 42 %" while the bytes go up, "Ukládám…" while the server
     * works, and " (pak znovu)" when a click queued a follow-up save.
     */
    _render() {
        if (!this.button || !this._running) {
            return;
        }

        const fraction = this._fraction;
        // The upload is done when the bytes are; what remains is the server.
        let text = fraction === null || fraction >= 1
            ? PROGRESS_LABEL
            : `${PROGRESS_LABEL} ${Math.min(99, Math.round(fraction * 100))} %`;

        if (this._queued !== null) {
            text += ' (pak znovu)';
        }

        this.button.textContent = text;
    }
}
