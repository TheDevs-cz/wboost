// HTTP client of the calendar endpoints (architecture §C.4, §C.5, §C.7).
//
// One of the two places allowed to touch `fetch` (§D.1). Every POST is multipart `FormData` with
// `_token`; a non-2xx answer rejects with `Error(json.error || fallback)`.

const SAVE_FAILED = 'Uložení se nepovedlo';

async function readJson(response, fallback) {
    let json = null;
    try {
        json = await response.json();
    } catch (e) {
        json = null;
    }
    if (!response.ok) {
        const error = new Error((json && typeof json.error === 'string' && json.error) || fallback);
        error.status = response.status;
        throw error;
    }
    return json || {};
}

async function send(url, fields, fallback) {
    if (!url) throw new Error(fallback);
    const body = new FormData();
    for (const [key, value] of Object.entries(fields)) {
        if (value !== undefined && value !== null) body.append(key, value);
    }
    let response;
    try {
        response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    } catch (e) {
        throw new Error(fallback);
    }
    return readJson(response, fallback);
}

/** GET of a history URL → `[{ who: 'Grafik' | 'Klient', what, at }]`, newest first. Used by the list page too. */
export async function fetchHistory(url) {
    let response;
    try {
        response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    } catch (e) {
        throw new Error('Historii se nepodařilo načíst');
    }
    const json = await readJson(response, 'Historii se nepodařilo načíst');
    return Array.isArray(json.entries) ? json.entries : [];
}

const EXPORT_FAILED = 'PDF se nepodařilo připravit';

/**
 * Asks for a PDF export (`variant`: `print` | `approval`) → the export to poll:
 * `{ id, variant, status: 'queued' | 'running' | 'done' | 'failed', done, total, error, sizeBytes, fileName, urls: { status, download } }`.
 * Used by the list page too.
 */
export async function requestExport(url, csrf, variant) {
    const json = await send(url, { _token: csrf, variant }, EXPORT_FAILED);
    if (!json.export || typeof json.export !== 'object') throw new Error(EXPORT_FAILED);
    return json.export;
}

/**
 * GET of the export request address → `{ print, approval }`: per variant the export that answers a
 * request right now (a job on its way, or the finished file of the saved calendar), else `null`.
 * Never rejects — the dialog works without it.
 */
export async function fetchCurrentExports(url) {
    try {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const json = await readJson(response, EXPORT_FAILED);
        return json.exports && typeof json.exports === 'object' ? json.exports : {};
    } catch (e) {
        return {};
    }
}

/** GET of an export's `urls.status` → the export (same shape). */
export async function fetchExport(url) {
    let response;
    try {
        response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    } catch (e) {
        throw new Error(EXPORT_FAILED);
    }
    const json = await readJson(response, EXPORT_FAILED);
    if (!json.export || typeof json.export !== 'object') throw new Error(EXPORT_FAILED);
    return json.export;
}

/**
 * @param env  `{ csrf, urls: { save, asset, history, export, clientLink?, layoutImport?, documentText? } }`
 */
export function createApi(env) {
    const urls = env.urls || {};
    const token = env.csrf;
    return {
        /** @returns `{ calendar, assets, savedAt }` */
        save(payload) {
            return send(urls.save, { _token: token, payload: JSON.stringify(payload) }, SAVE_FAILED);
        },
        /** @param kind `photo` | `logo` | `icon` | `qr` | `image` | `page` → `{ id, url, previewUrl, width, height, mime, name, description, kind }` */
        upload(file, kind) {
            return send(urls.asset, { _token: token, kind, file }, 'Soubor se nepodařilo nahrát');
        },
        history() {
            return fetchHistory(urls.history);
        },
        /** @param action `copied` | `regenerate` → `{ url }` */
        clientLink(action) {
            return send(urls.clientLink, { _token: token, action }, 'Odkaz se nepodařilo vytvořit');
        },
        /** @param body `{ from: calendarId }` or `{ layout: object | string }` → `{ layout, assets, name }` */
        importLayout(body) {
            const fields = { _token: token };
            if (body && body.from) fields.from = body.from;
            if (body && body.layout !== undefined) fields.layout = typeof body.layout === 'string' ? body.layout : JSON.stringify(body.layout);
            return send(urls.layoutImport, fields, 'Layout se nepodařilo načíst');
        },
        /** → `{ text, name }` */
        documentText(file) {
            return send(urls.documentText, { _token: token, file }, 'Dokument se nepodařilo přečíst');
        },
    };
}
