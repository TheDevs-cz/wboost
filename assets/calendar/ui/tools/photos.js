// Content tool „Fotky a popisky“ (handoff §4.6, map §8.11, architecture §D.7) — phase P6a.
//
// One row per week page: photo, caption, remove; a counter; and „Nahrát všechny fotky najednou“,
// which assigns the chosen files to the weeks in file-name order, starting with the first week.
// A filled row says which file it holds (feedback K11): the original file name (`asset.name`,
// ellipsised in the middle so the number and extension stay visible, full name in `title`), the
// pixel size, and — under `PHOTO_MIN_DPI` for the photo box — the „Nízká kvalita“ dpi hint of the
// page warnings (`render/warnings.js` `lowPhotoDpi`, the one threshold). The line sits under the
// caption field (or in its column when captions are off); at ≤ 600 px it wraps under the row.
// An upload here (single or bulk) into a week WITHOUT a caption and without an embedded picture
// description starts the caption from the file name (`model/photo-caption.js`; camera names like
// `IMG_1234` give none) — an ordinary content edit the user sees in the field and can change.
//
// Contract: `ui/tools/index.js` opens the modal and calls `mountPhotosTool(root, ctx)` with the
// modal body as `root`; the tool edits `pagePhotos` / `pageCaptions` through the store (labels =
// page titles, for Historie) and returns `{ destroy() }`. Designer and client both use it.
//
// Also exported for the other step-4 modules (page panel, QR tool, special pages):
//   `fileTile(options)`            the dashed upload tile with a hidden file input
//   `uploadAsset(ctx, file, kind)` `api.upload` → `store.addAsset`, error toast on failure
//   `setPagePhoto(ctx, page, info, { nameCaption })` photo of a week page: crop reset to centred /
//                                   100 %, caption from the picture's embedded description; with
//                                   `nameCaption` (this tool only) an empty caption falls back to
//                                   the file name
//   `sortedImageFiles(files)`      images only, in Czech numeric file-name order
//
// DOM hooks: `[data-photo-bulk]` (the multi-file input), `[data-photo-row="<pageKey>"]`,
// `[data-photo-file]`, `[data-photo-caption]`, `[data-photo-remove]`, `[data-photo-count]`,
// `[data-photo-progress]`, `[data-photo-name]` (the file line; `data-photo-dpi` when too small).

import { weekPages } from '../../generate/pages.js';
import { captionFromFileName } from '../../model/photo-caption.js';
import { lowPhotoDpi } from '../../render/warnings.js';
import { h, icon } from '../dom.js';

export const CAPTION_MAX = 80;
const PARALLEL_UPLOADS = 3;

/**
 * The upload tile: a dashed box that shows the current picture (or an icon) over a hidden file
 * input. Keyboard: the input is focusable, the tile shows its focus ring.
 *
 * @param o.asset     AssetInfo | null — the picture shown
 * @param o.label     aria-label of the file input
 * @param o.tip       tooltip, or `(hasAsset: boolean) => string`
 * @param o.accept    `accept` of the input (default `image/*`)
 * @param o.multiple  allow several files
 * @param o.small     44 px square variant (QR rows)
 * @param o.fit       `cover` (default) | `contain`
 * @param o.glyph     icon name while empty (default `upload`)
 * @param o.attrs     attributes of the `<input>` (test hooks)
 * @param o.onFiles   `(files: File[]) => void | Promise` — the tile is busy until it settles
 * @returns `label.cal-ft` with `setAsset(asset | null)`, `setBusy(on)` and `input`
 */
export function fileTile({ asset = null, label, tip, accept = 'image/*', multiple = false, small = false, fit = 'cover', glyph = 'upload', attrs, onFiles }) {
    const input = h('input', { type: 'file', class: 'sr', accept, multiple, 'aria-label': label, ...(attrs || {}) });
    const picture = h('span', { class: 'cal-fti' });
    const el = h('label', { class: `cal-ft${small ? ' sm' : ''}${fit === 'contain' ? ' contain' : ''}` }, picture, input);
    const tipOf = (has) => (typeof tip === 'function' ? tip(has) : tip);
    const paint = (current) => {
        const url = current ? current.previewUrl || current.url : null;
        picture.replaceChildren(url ? h('img', { src: url, alt: '', loading: 'lazy', decoding: 'async' }) : icon(glyph, small ? 's16' : 's20'));
        el.classList.toggle('has', Boolean(url));
        const text = tipOf(Boolean(url));
        if (text) el.title = text;
    };
    const setBusy = (on) => {
        el.classList.toggle('busy', on);
        if (on) el.setAttribute('aria-busy', 'true');
        else el.removeAttribute('aria-busy');
        input.disabled = on;
    };
    input.addEventListener('change', async () => {
        const files = [...input.files];
        // the same file can be chosen again later
        input.value = '';
        if (!files.length || !onFiles) return;
        setBusy(true);
        try {
            await onFiles(files);
        } finally {
            setBusy(false);
        }
    });
    paint(asset);
    el.input = input;
    el.setAsset = paint;
    el.setBusy = setBusy;
    return el;
}

/**
 * Uploads one file and registers the asset. Resolves with the asset info (`{ id, url, previewUrl,
 * width, height, description, … }` — the pixel size comes back from the server for every upload
 * path), or `null` after an error toast.
 */
export async function uploadAsset(ctx, file, kind) {
    try {
        const info = await ctx.api.upload(file, kind);
        ctx.store.addAsset(info);
        return info;
    } catch (error) {
        ctx.toast((error && error.message) || 'Soubor se nepodařilo nahrát', true);
        return null;
    }
}

/**
 * Puts an uploaded photo on a week page. A new photo always starts centred at 100 % (handoff §8);
 * a description embedded in the picture becomes the caption, an existing caption is kept otherwise.
 */
export function setPagePhoto(ctx, page, info, { nameCaption = false } = {}) {
    const ops = [{ c: 'pagePhotos', id: page.key, v: { asset: info.id, zoom: 1, px: 0.5, py: 0.5 }, label: page.title }];
    const { doc } = ctx.store.getState();
    const description = typeof info.description === 'string' ? info.description.trim() : '';
    if (doc.design.features.captions) {
        const current = doc.content.pageCaptions[page.key];
        const fromName = nameCaption && !description && !(current && current.text.trim()) ? captionFromFileName(info.name, CAPTION_MAX) : null;
        const text = description ? description.slice(0, CAPTION_MAX) : fromName;
        if (text) ops.push({ c: 'pageCaptions', id: page.key, v: { text }, label: page.title });
    }
    ctx.store.applyOps(ops);
}

/** Images only, sorted by file name the way a person reads them: `2.jpg` before `10.jpg`. */
export function sortedImageFiles(files) {
    return [...files].filter((file) => String(file.type || '').startsWith('image/')).sort((a, b) => a.name.localeCompare(b.name, 'cs', { numeric: true }));
}

/** Writes or removes the caption of a page (an empty caption is no caption). */
export function setPageCaption(ctx, page, text) {
    const value = String(text).slice(0, CAPTION_MAX);
    if (value.trim() === '') ctx.store.removeItem('pageCaptions', page.key, page.title);
    else ctx.store.putItem('pageCaptions', page.key, { text: value }, page.title);
}

export function mountPhotosTool(root, ctx) {
    const { store } = ctx;
    const designer = ctx.env.mode === 'designer';
    let rows = [];
    let signature = '';
    let uploading = false;

    const state = () => store.getState();
    const weeks = () => weekPages(store.pages());
    const assetOf = (key) => {
        const item = state().doc.content.pagePhotos[key];
        return item ? ctx.env.assets[item.asset] || null : null;
    };
    const signatureOf = () => `${weeks().map((p) => `${p.key}|${p.title}|${p.subtitle}`).join('\n')}#${state().doc.design.features.captions}#${note()}`;

    /** Map §8.10: the designer is told when nothing here is his to fill. */
    function note() {
        const { design } = state().doc;
        if (!designer || !design.layout) return '';
        return design.layout.styles.photo.source === 'client' && !design.features.captions ? 'Fotky doplní klient a popisky jsou vypnuté.' : '';
    }

    const list = h('div', { class: 'cal-phl' });
    const count = h('p', { class: 'cap cal-phn', 'data-photo-count': '' });
    const progress = h('span', { class: 'cap', 'data-photo-progress': '', hidden: true, role: 'status' });
    const noteEl = h('p', { class: 'cap cal-phnote', hidden: true });
    const bulkInput = h('input', { type: 'file', class: 'sr', accept: 'image/*', multiple: true, 'data-photo-bulk': '' });
    const bulk = h(
        'label',
        { class: 'cal-dpx' },
        bulkInput,
        icon('upload', 's20'),
        h(
            'span',
            null,
            h('b', null, 'Nahrát všechny fotky najednou'),
            h('span', { class: 'cap' }, 'Vyberte všechny fotky ze složky — přiřadí se k týdnům podle pořadí názvů souborů. Popisky se doplní z popisků uložených ve fotkách, jinak z názvů souborů.'),
            progress,
        ),
    );

    /**
     * Bulk upload: files in name order go to the weeks from the first one; surplus files are
     * ignored, missing ones leave weeks empty. At most three requests run at a time.
     */
    async function uploadAll(files) {
        if (uploading) return;
        const pages = weeks();
        const jobs = sortedImageFiles(files)
            .slice(0, pages.length)
            .map((file, index) => ({ file, page: pages[index] }));
        if (!jobs.length) return;
        uploading = true;
        bulk.classList.add('busy');
        bulkInput.disabled = true;
        let done = 0;
        let failed = 0;
        let firstError = '';
        const show = () => {
            progress.hidden = false;
            progress.textContent = `Nahrávám fotky… ${done} z ${jobs.length}`;
        };
        show();
        let next = 0;
        const worker = async () => {
            while (next < jobs.length) {
                const job = jobs[next++];
                try {
                    const info = await ctx.api.upload(job.file, 'photo');
                    store.addAsset(info);
                    // the page list may have changed meanwhile (never for a week key that still exists)
                    setPagePhoto(ctx, job.page, info, { nameCaption: true });
                } catch (error) {
                    failed += 1;
                    if (!firstError) firstError = (error && error.message) || 'Soubor se nepodařilo nahrát';
                }
                done += 1;
                show();
            }
        };
        await Promise.all(Array.from({ length: Math.min(PARALLEL_UPLOADS, jobs.length) }, worker));
        uploading = false;
        bulk.classList.remove('busy');
        bulkInput.disabled = false;
        progress.hidden = true;
        if (failed) ctx.toast(failed === jobs.length ? firstError : `${firstError} (${failed} z ${jobs.length})`, true);
    }

    bulkInput.addEventListener('change', () => {
        const files = [...bulkInput.files];
        bulkInput.value = '';
        uploadAll(files);
    });
    bulk.addEventListener('dragover', (event) => {
        event.preventDefault();
        bulk.classList.add('over');
    });
    bulk.addEventListener('dragleave', () => bulk.classList.remove('over'));
    bulk.addEventListener('drop', (event) => {
        event.preventDefault();
        bulk.classList.remove('over');
        if (event.dataTransfer && event.dataTransfer.files.length) uploadAll(event.dataTransfer.files);
    });

    function buildRow(page) {
        const captions = state().doc.design.features.captions;
        const tile = fileTile({
            asset: assetOf(page.key),
            label: `Fotka: ${page.title}`,
            tip: (has) => (has ? 'Vyměnit fotku' : 'Nahrát fotku'),
            glyph: 'image',
            attrs: { 'data-photo-file': '' },
            onFiles: async ([file]) => {
                const info = await uploadAsset(ctx, file, 'photo');
                if (info) setPagePhoto(ctx, page, info, { nameCaption: true });
            },
        });
        const caption = captions
            ? h('input', {
                  type: 'text',
                  placeholder: 'Popisek fotky',
                  maxlength: String(CAPTION_MAX),
                  autocomplete: 'off',
                  'aria-label': `Popisek: ${page.title}`,
                  'data-photo-caption': '',
                  onInput: () => setPageCaption(ctx, page, caption.value),
                  onKeydown: (event) => {
                      if (event.key === 'Enter') caption.blur();
                  },
              })
            : null;
        const remove = h(
            'button',
            { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': 'Odebrat fotku', title: 'Odebrat fotku', 'data-photo-remove': '', onClick: () => store.removeItem('pagePhotos', page.key, page.title) },
            icon('trash', 's16'),
        );
        const meta = h('span', { class: 'cap cal-phm', 'data-photo-name': '', hidden: true });
        const cell = h('span', { class: 'cal-phc' }, captions ? h('span', { class: 'inp sm' }, caption) : null, meta);
        const el = h(
            'div',
            { class: `cal-phr${captions ? '' : ' np'}`, 'data-photo-row': page.key },
            tile,
            h('span', { class: 'cal-pht' }, h('b', null, page.title), h('span', { class: 'cap' }, page.subtitle)),
            cell,
            h('span', { class: 'cal-phx' }, remove),
        );
        return { key: page.key, el, tile, caption, remove, meta, cell, captions };
    }

    /** The file line of a filled row: name (middle ellipsis) · pixel size · low-dpi hint. */
    function paintMeta(row, asset) {
        const { meta } = row;
        const photo = state().doc.content.pagePhotos[row.key];
        if (!photo || !asset) {
            meta.hidden = true;
            meta.replaceChildren();
            delete meta.dataset.photoDpi;
            row.cell.hidden = !row.captions;
            return;
        }
        const name = String(asset.name || '').normalize('NFC');
        // the tail keeps the last characters before the extension and the extension itself
        const dot = name.lastIndexOf('.');
        const cut = Math.max(0, (dot > 0 ? dot : name.length) - 6);
        const parts = [];
        if (name) parts.push(h('span', { class: 'cal-phf' }, h('span', { class: 'cal-phf1' }, name.slice(0, cut)), h('span', { class: 'cal-phf2' }, name.slice(cut))));
        const sized = asset.width > 0 && asset.height > 0;
        if (sized) parts.push(h('span', { class: 'cal-phs' }, `${asset.width} × ${asset.height} px`));
        const dpi = lowPhotoDpi(state().doc.design, photo, asset);
        if (dpi !== null) {
            parts.push(h('span', { class: 'cal-phd', title: `Nízká kvalita fotky — ${dpi} dpi (pro tisk aspoň 300 dpi)` }, `Nízká kvalita — ${dpi} dpi`));
            meta.dataset.photoDpi = String(dpi);
        } else delete meta.dataset.photoDpi;
        meta.replaceChildren(...parts);
        meta.title = [name, sized ? `${asset.width} × ${asset.height} px` : ''].filter(Boolean).join(' · ');
        meta.hidden = parts.length === 0;
        row.cell.hidden = !row.captions && meta.hidden;
    }

    function syncRow(row) {
        const content = state().doc.content;
        const asset = assetOf(row.key);
        row.tile.setAsset(asset);
        paintMeta(row, asset);
        row.remove.hidden = !content.pagePhotos[row.key];
        row.el.classList.toggle('has', Boolean(content.pagePhotos[row.key]));
        if (row.caption && document.activeElement !== row.caption) {
            const item = content.pageCaptions[row.key];
            row.caption.value = item ? item.text : '';
        }
    }

    function syncCount() {
        const photos = state().doc.content.pagePhotos;
        const filled = rows.filter((row) => photos[row.key]).length;
        count.textContent = `Fotky: ${filled} z ${rows.length}`;
        count.dataset.photoCount = `${filled}/${rows.length}`;
    }

    function build() {
        signature = signatureOf();
        rows = weeks().map(buildRow);
        list.replaceChildren(...rows.map((row) => row.el));
        const text = note();
        noteEl.hidden = text === '';
        noteEl.textContent = text;
        rows.forEach(syncRow);
        syncCount();
    }

    root.replaceChildren(h('div', { class: 'cal-ph' }, noteEl, bulk, count, list));
    build();

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status' || event.type === 'ui' || (event.type === 'layout' && event.live)) return;
        if (signatureOf() !== signature) {
            build();
            return;
        }
        if (event.type === 'content' && (event.collection === 'pagePhotos' || event.collection === 'pageCaptions')) {
            const row = rows.find((r) => r.key === event.id);
            if (row) syncRow(row);
            syncCount();
        } else if (event.type === 'replace' || event.type === 'layout' || event.type === 'design') {
            rows.forEach(syncRow);
            syncCount();
        }
    });

    return {
        destroy() {
            unsubscribe();
            root.replaceChildren();
        },
    };
}
