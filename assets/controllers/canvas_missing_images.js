/**
 * Missing-picture handling for canvas loads, shared by the single-variant
 * editor (canvas_editor_controller.loadCanvasWithoutHistory) and the group
 * editor (group_editor_controller._loadShadow).
 *
 * Fabric's loadFromJSON does NOT reject when an image src fails to fetch: it
 * resolves with that object silently DROPPED (verified against the committed
 * 7.3.1 build). The 2026-08-31 hardening turned that drop into a load
 * failure (count check → retry → "Nenačteno"), which is right for a network
 * flake — but a picture that is GONE FOR GOOD (a gallery file purged from
 * the Koš; 2026-09-08) fails identically on every attempt, and a variant that
 * never hydrates is inert: no propagation, no save. With every variant of a
 * group referencing the same dead picture the whole group editor was
 * bricked — nothing could be saved, so the designer could not even delete
 * the dead object to recover. The single editor was quieter and worse: it
 * dropped the object and the next save persisted the loss.
 *
 * The two cases are told apart by PROBING the dropped objects' srcs: a
 * definitive 404 / 410 means the picture is gone; anything else (network
 * error, 5xx, CORS) counts as transient and keeps the caller's retry
 * semantics. Gone pictures are reloaded as a STAND-IN — a red hatched tile
 * with the original's pixel size, so the designer sees exactly what is
 * missing where — carrying the original src in the `missingSrc` custom
 * property. That property is the contract:
 *
 *   - the SAVED document keeps the ORIGINAL reference: buildVariantPayload
 *     puts `missingSrc` back into `src` and strips the marker, so a save
 *     never rewrites the design behind the designer's back (the render side
 *     already tolerates a missing file — AssetInliner returns null and
 *     Chromium drops the object — so the export is unchanged by any of this);
 *   - inside the session it rides toJSON / clone / restoreCustomProperties
 *     (it is in CANVAS_CUSTOM_PROPERTIES), so undo/redo, group-editor tab
 *     switches and group-sync clones keep the marker; the save-time preview
 *     thumbnail hides stand-ins (`withStandInsHidden`);
 *   - the layers panel badges the row ("chybí soubor") off it.
 *
 * Only ever consulted on a count mismatch — the happy path costs one
 * comparison. Probe verdicts are cached per src for the page's lifetime.
 *
 * Dependency-free on purpose (no Fabric import — the canvas is passed in), so
 * the pure parts run under plain node.
 */

export const MISSING_SRC_PROP = 'missingSrc';

const verdictCache = new Map();
const reportedSrcs = new Set();
let pendingReport = [];
let pendingReportTimer = null;

function sourceMatchesLoaded(source, obj) {
    if (String(source.type || '').toLowerCase() !== String(obj.type || '').toLowerCase()) {
        return false;
    }
    const close = (a, b) => typeof a !== 'number' || typeof b !== 'number' || Math.abs(a - b) < 1;
    return close(source.left, obj.left) && close(source.top, obj.top);
}

/**
 * Align the SOURCE document's objects with what Fabric actually LOADED.
 * Positional when the counts agree; on a shortfall an ordered-subsequence
 * match on type + position, so a dropped source entry doesn't consume a
 * loaded object — a pure index map would re-stamp every object above the
 * gap with the PREVIOUS entry's identity (wrong inputId) and scramble the
 * group editor's inputId-keyed propagation.
 *
 * @param {Array<Object>} sourceObjects  entries of the source document
 * @param {Array<Object>} loadedObjects  canvas.getObjects() after the load
 * @returns {{ sourceFor: Array<Object|null>, dropped: number[] }}
 *   `sourceFor[i]` = the source entry matched to loaded object i (null when
 *   nothing matches); `dropped` = indexes of source entries no loaded object
 *   consumed, ascending.
 */
export function alignLoadedObjects(sourceObjects, loadedObjects) {
    const sourceFor = [];
    const consumed = new Set();
    const shortfall = sourceObjects.length - loadedObjects.length;

    let cursor = 0;
    loadedObjects.forEach((obj, idx) => {
        let sourceIndex = -1;
        if (shortfall <= 0) {
            if (idx < sourceObjects.length) {
                sourceIndex = idx;
            }
        } else {
            while (cursor < sourceObjects.length && !sourceMatchesLoaded(sourceObjects[cursor], obj)) {
                cursor += 1;
            }
            if (cursor < sourceObjects.length) {
                sourceIndex = cursor;
                cursor += 1;
            }
        }
        sourceFor.push(sourceIndex >= 0 ? sourceObjects[sourceIndex] : null);
        if (sourceIndex >= 0) {
            consumed.add(sourceIndex);
        }
    });

    const dropped = [];
    sourceObjects.forEach((_, index) => {
        if (!consumed.has(index)) {
            dropped.push(index);
        }
    });

    return { sourceFor, dropped };
}

/**
 * Probe verdict for one dropped object's picture:
 *   'missing'   — 404 / 410: gone for good
 *   'reachable' — 2xx: the drop was a flake, a retry will do
 *   'error'     — anything else (network, 5xx, CORS): treated as transient
 * Data URIs cannot be probed and never drop for network reasons → 'error'.
 * Definitive verdicts are cached per src; errors are not (a retry may
 * resolve them).
 *
 * @param {string} src
 * @param {Function} [fetchImpl]  injectable for node-side tests
 * @returns {Promise<'missing'|'reachable'|'error'>}
 */
export async function probeImageSource(src, fetchImpl = globalThis.fetch) {
    if (typeof src !== 'string' || src === '' || src.startsWith('data:') || typeof fetchImpl !== 'function') {
        return 'error';
    }
    if (verdictCache.has(src)) {
        return verdictCache.get(src);
    }

    let verdict = 'error';
    try {
        // Same terms Fabric loads under (crossOrigin: 'anonymous' → no
        // credentials), so the verdict describes the very fetch that failed.
        const response = await fetchImpl(src, { method: 'GET', mode: 'cors', credentials: 'omit' });
        if (response.status === 404 || response.status === 410) {
            verdict = 'missing';
        } else if (response.ok) {
            verdict = 'reachable';
        }
    } catch (err) {
        verdict = 'error';
    }

    if (verdict !== 'error') {
        verdictCache.set(src, verdict);
    }
    return verdict;
}

/**
 * The stand-in picture: a red hatched tile with a dashed border at the
 * original's pixel size (Fabric keeps the serialized width/height and the
 * scale, so the tile lands exactly where the picture was), labelled when
 * there is room. SVG so it scales crisply at any editor zoom; a data URI so
 * it can never fail to load.
 */
export function standInDataUri(width, height) {
    const w = Math.max(1, Math.round(Number(width) || 0) || 1);
    const h = Math.max(1, Math.round(Number(height) || 0) || 1);
    const short = Math.min(w, h);
    const unit = Math.max(6, Math.round(short / 8));
    const stroke = Math.max(1, Math.round(short / 40));
    const fontSize = Math.max(10, Math.round(short / 8));
    const label = w >= fontSize * 8 && h >= fontSize * 2
        ? `<text x="${w / 2}" y="${h / 2}" text-anchor="middle" dominant-baseline="middle" font-family="sans-serif" font-weight="600" font-size="${fontSize}" fill="#be123c">Chybí obrázek</text>`
        : '';

    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">`
        + `<defs><pattern id="h" width="${unit * 2}" height="${unit * 2}" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">`
        + `<rect width="${unit * 2}" height="${unit * 2}" fill="#fff1f2"/><rect width="${unit}" height="${unit * 2}" fill="#fecdd3"/></pattern></defs>`
        + `<rect width="${w}" height="${h}" fill="url(#h)"/>`
        + `<rect x="${stroke / 2}" y="${stroke / 2}" width="${w - stroke}" height="${h - stroke}" fill="none" stroke="#e11d48" stroke-width="${stroke}" stroke-dasharray="${stroke * 4} ${stroke * 3}"/>`
        + label
        + '</svg>';

    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}

/**
 * The source document with the given object indexes swapped for stand-ins:
 * `src` becomes the tile, the original reference moves to `missingSrc`.
 * Everything else about the object (geometry, inputId, metadata,
 * crossOrigin) is untouched, so the stand-in behaves exactly like the
 * picture did.
 */
export function substituteMissingImages(source, indexes) {
    const wanted = new Set(indexes);
    const objects = (Array.isArray(source.objects) ? source.objects : []).map((object, index) => {
        if (!wanted.has(index)) {
            return object;
        }
        return {
            ...object,
            [MISSING_SRC_PROP]: object.src,
            src: standInDataUri(object.width, object.height),
        };
    });

    return { ...source, objects };
}

/**
 * loadFromJSON with missing-picture handling. Resolves with the (possibly
 * substituted) source the caller must run restoreCustomProperties against —
 * that is what stamps `missingSrc` onto the loaded objects — plus the
 * stand-ins it made ({index, src}). Never throws for a shortfall: the caller
 * keeps its own count check, which from here on only trips for TRANSIENT
 * drops (unreachable host, 5xx, a dropped non-image).
 *
 * @param {Object} canvas   a Fabric Canvas / StaticCanvas
 * @param {Object} source   the parsed canvas document
 * @param {{fetchImpl?: Function}} [options]
 * @returns {Promise<{source: Object, missing: Array<{index: number, src: string}>}>}
 */
export async function loadCanvasDocument(canvas, source, { fetchImpl } = {}) {
    await canvas.loadFromJSON(source);

    const sourceObjects = Array.isArray(source.objects) ? source.objects : [];
    if (canvas.getObjects().length >= sourceObjects.length) {
        return { source, missing: [] };
    }

    const { dropped } = alignLoadedObjects(sourceObjects, canvas.getObjects());
    const verdicts = await Promise.all(dropped.map(async (index) => {
        const object = sourceObjects[index] || {};
        const isImage = String(object.type || '').toLowerCase() === 'image';
        return { index, verdict: isImage ? await probeImageSource(object.src, fetchImpl) : 'error' };
    }));
    const gone = verdicts.filter((entry) => entry.verdict === 'missing').map((entry) => entry.index);
    if (gone.length === 0) {
        return { source, missing: [] };
    }

    const substituted = substituteMissingImages(source, gone);
    await canvas.loadFromJSON(substituted);

    return {
        source: substituted,
        missing: gone.map((index) => ({ index, src: sourceObjects[index].src })),
    };
}

/** Whether a live canvas object is a stand-in for a gone picture. */
export function isMissingImage(obj) {
    return Boolean(obj) && typeof obj[MISSING_SRC_PROP] === 'string' && obj[MISSING_SRC_PROP] !== '';
}

/**
 * Run `render` with every stand-in hidden and restore afterwards — for the
 * save-time preview thumbnail, which is a picture of the DESIGN as it
 * exports (where a gone picture simply isn't there), not of the editor.
 */
export function withStandInsHidden(canvas, render) {
    const hidden = canvas.getObjects().filter((obj) => isMissingImage(obj) && obj.visible !== false);
    hidden.forEach((obj) => { obj.visible = false; });
    const restore = () => {
        hidden.forEach((obj) => { obj.visible = true; });
        // The single editor's thumbnail draws the LIVE canvas element, which
        // it re-rendered with the stand-ins hidden — bring them back on screen.
        if (hidden.length > 0 && typeof canvas.requestRenderAll === 'function') {
            canvas.requestRenderAll();
        }
    };

    let result;
    try {
        result = render();
    } catch (err) {
        restore();
        throw err;
    }
    if (result && typeof result.then === 'function') {
        return result.finally(restore);
    }
    restore();
    return result;
}

/**
 * Tell the designer, once per picture per page load: the layers panel
 * badges the rows, but a gone picture in a hidden layer or in a sibling
 * variant of a group would otherwise go unnoticed until the export. Loads
 * arrive spread out (active canvas, then each shadow), so reports coalesce
 * into one toast. Reuses the gallery toast chrome.
 */
export function reportMissingImages(missing) {
    const fresh = (missing || []).map((entry) => entry.src).filter((src) => src && !reportedSrcs.has(src));
    if (fresh.length === 0) {
        return;
    }
    fresh.forEach((src) => reportedSrcs.add(src));
    console.warn('Obrázky v návrhu už neexistují (soubor byl smazán z galerie):', fresh);

    if (typeof document === 'undefined') {
        return;
    }
    pendingReport.push(...fresh);
    if (pendingReportTimer) {
        clearTimeout(pendingReportTimer);
    }
    pendingReportTimer = setTimeout(() => {
        const count = pendingReport.length;
        pendingReport = [];
        pendingReportTimer = null;
        showToast(missingImagesMessage(count));
    }, 600);
}

export function missingImagesMessage(count) {
    if (count === 1) {
        return 'Jeden obrázek v návrhu už neexistuje – jeho soubor byl smazán z galerie. Vrstva je označená červeně: smažte ji, nebo vložte obrázek znovu.';
    }
    const verb = count >= 2 && count <= 4 ? `${count} obrázky v návrhu už neexistují` : `${count} obrázků v návrhu už neexistuje`;
    return `${verb} – jejich soubory byly smazány z galerie. Vrstvy jsou označené červeně: smažte je, nebo vložte obrázky znovu.`;
}

function showToast(message) {
    let container = document.getElementById('gallery-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'gallery-toast-container';
        container.className = 'gallery-toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = 'gallery-toast gallery-toast--warning';
    toast.setAttribute('role', 'alert');
    const icon = document.createElement('i');
    icon.className = 'mdi mdi-image-broken-variant me-2 fs-5';
    icon.setAttribute('aria-hidden', 'true');
    toast.appendChild(icon);
    toast.appendChild(document.createTextNode(message));
    container.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('gallery-toast--show'));
    setTimeout(() => {
        toast.classList.remove('gallery-toast--show');
        setTimeout(() => toast.remove(), 300);
    }, 12000);
}
