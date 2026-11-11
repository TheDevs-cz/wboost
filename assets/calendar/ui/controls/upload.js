// Upload tile: a small preview + caption + Odebrat, uploading through the calendar asset API
// (architecture §C.7). Owner: P5a; reused by P5b (custom icons) and anyone who needs one file.
//
//   uploadTile({ value, kind, ctx, onChange, tip, captionEmpty, captionSet, accept?, fit?, icon?, attrs? })
//
//   value         asset id (`calendar_asset.id`) or `null`
//   kind          'logo' | 'qr' | 'image' | 'icon' | 'photo' | 'page' — the server-side asset kind
//   ctx           `Ctx` — uses `ctx.api.upload`, `ctx.store.addAsset`, `ctx.env.assets`, `ctx.env.maxFileSize`, `ctx.toast`
//   onChange      `(assetId: string | null) => void` — called AFTER the file is stored and registered
//                 (`null` = Odebrat). The caller writes the id into the layout (one undo step).
//   tip           tooltip / aria-label of the tile (`Nahrát logo (SVG, PNG)`)
//   captionEmpty  caption without a file (`Nahrajte logo (SVG, PNG)`)
//   captionSet    caption with a file (`Nahrané logo`)
//   accept        `accept` of the file input (default SVG, PNG, JPEG, WebP)
//   fit           preview fit: 'contain' (logos, QR, icons — default) | 'cover' (pictures)
//   icon          icon of the empty tile (default `upload`)
//   attrs         attributes of the file `<input>` (`{ 'data-prop': 'logo.asset' }`)
//
// Returns `div.cal-himg` with `setValue(assetId | null)`. DOM hooks: the file input carries `attrs`
// and `data-upload`, the remove button `data-upload-remove`, the root `data-busy` while uploading and
// `data-asset="<id>"` when a file is set.
//
// A file over `env.maxFileSize` is refused before it is sent (`Soubor je větší než 10 MB.`); a server
// refusal shows the server's message. Nothing changes on failure.

import { h, icon as iconEl } from '../dom.js';

const DEFAULT_ACCEPT = 'image/svg+xml,image/png,image/jpeg,image/webp';

export function uploadTile({ value, kind, ctx, onChange, tip, captionEmpty, captionSet, accept = DEFAULT_ACCEPT, fit = 'contain', icon = 'upload', attrs }) {
    let current = value || null;
    let busy = false;

    const input = h('input', { type: 'file', class: 'sr', accept, 'data-upload': kind, ...(attrs || {}) });
    const preview = h('label', { class: `cal-himgp ${fit}`, title: tip, 'aria-label': tip }, input);
    const caption = h('span', { class: 'cap' });
    const remove = h(
        'button',
        {
            type: 'button',
            class: 'btn xs btn-ghost',
            'data-upload-remove': '',
            onClick: () => {
                if (busy || !current) return;
                current = null;
                paint();
                if (onChange) onChange(null);
            },
        },
        iconEl('trash', 's14'),
        'Odebrat',
    );
    const el = h('div', { class: 'cal-himg' }, preview, h('div', { class: 'cal-himgb' }, caption, remove));

    function paint() {
        const asset = current ? ctx.env.assets[current] : null;
        const src = asset ? asset.previewUrl || asset.url : null;
        for (const node of [...preview.children]) if (node !== input) node.remove();
        preview.prepend(src ? h('img', { src, alt: '' }) : iconEl(busy ? 'refresh' : icon, 's20'));
        caption.textContent = current ? captionSet : captionEmpty;
        remove.hidden = !current;
        if (current) el.dataset.asset = current;
        else delete el.dataset.asset;
        if (busy) el.dataset.busy = '1';
        else delete el.dataset.busy;
    }

    input.addEventListener('change', async () => {
        const file = input.files && input.files[0];
        input.value = '';
        if (!file || busy) return;
        const limit = Number(ctx.env.maxFileSize) || 0;
        if (limit > 0 && file.size > limit) {
            ctx.toast('Soubor je větší než 10 MB.', true);
            return;
        }
        busy = true;
        paint();
        try {
            const info = await ctx.api.upload(file, kind);
            ctx.store.addAsset(info);
            busy = false;
            current = info.id;
            paint();
            if (onChange) onChange(info.id);
        } catch (error) {
            busy = false;
            paint();
            ctx.toast((error && error.message) || 'Soubor se nepodařilo nahrát', true);
        }
    });

    paint();
    el.input = input;
    el.setValue = (next) => {
        if ((next || null) === current) return;
        current = next || null;
        paint();
    };
    return el;
}
