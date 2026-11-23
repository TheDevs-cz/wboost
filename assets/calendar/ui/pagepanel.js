// Per-page right panel of step 4 (handoff §4.6 "Right panel for a page", map §8.10) — phase P6a.
//
// Warnings of the page, then only the fields that apply to it:
//   Fotografie          upload while the photo element is „Doplním sám“, else the note that the
//                       client supplies photos (week pages)
//   Popisek fotografie  ≤ 80 characters, while captions are on (week pages)
//   Obrázek v hlavičce  while the Hlavička style has its picture switched on (week pages)
//   Fotka pozadí        while the Pozadí style has its picture switched on (any page)
//   QR kód              while QR is on (week pages): „QR kód této strany“ when every page has its
//                       own code, „Jiný QR kód jen pro tuto stranu“ over the shared code otherwise —
//                       a page's own code wins in both modes
// the page's own layer effects of its pictures (`ui/page-effects.js`, designer; K2),
// and „Vrátit na styl“, which removes every override of the page.
// The cover, the back and the generated special pages show their CONTENT first — titles, texts,
// photo, contact groups, layout of the cover (`ui/pagecontent.js`, shared with step 5); that is
// content of the page, not an override, so „Vrátit na styl“ and the „Upraveno“ pill ignore it.
//
// Warnings: one `warningCard` per warning (`ui/warnings.js`) — title, facts, fix and its actions;
// `opts.warningHandlers?(index)` adds the step's actions, the panel does 'page-content' itself
// (moves to the content fields above the rest).
//
// Contract: `mountPagePanel(root, ctx, opts: { pageIndex: number, warningHandlers? }): { destroy() }` — one mount per
// shown page; `ui/step4.js` destroys and re-mounts it when the page changes. All edits are content
// items keyed by the page key, labelled with the page title for Historie.
//
// DOM hooks: `[data-page-panel="<pageKey>"]`, `[data-page-pill]` (Upraveno), `[data-page-warning]`,
// `[data-page-field="photo|caption|head|bg|qr"]` (the file input or the text input),
// `[data-page-remove="photo|head|bg|qr"]`, `[data-page-edit="photo"]` („Upravit“ → the photo editor,
// `ui/photo-editor.js`; closed with the panel), `[data-cal-action="page-reset"]`.

import { clearPageOverrides, pageHasOverrides } from '../model/content.js';
import { h, icon } from './dom.js';
import { hasPageContent, mountPageContent } from './pagecontent.js';
import { closePhotoEditor, openPhotoEditor, weekPhotoEditing } from './photo-editor.js';
import { mountPageEffects } from './page-effects.js';
import { CAPTION_MAX, fileTile, setPageCaption, setPagePhoto, uploadAsset } from './tools/photos.js';
import { QR_ACCEPT } from './tools/qr.js';
import { pageWarnings, warningCard } from './warnings.js';

export function mountPagePanel(root, ctx, opts = {}) {
    const { store } = ctx;
    const body = h('div', { class: 'cal-p4b' });
    root.replaceChildren(body);

    let signature = null;
    let captionInput = null;
    let parts = null;
    // mounted once and re-attached by every rebuild: a field being typed in keeps its focus when a
    // warning of the page appears or goes
    const contentHost = h('div', { class: 'cal-p4c' });
    const contentEditor = mountPageContent(contentHost, ctx, { page: () => store.pages()[opts.pageIndex] || null });
    // a page's own layer effects (K2): mounted once, re-attached by every rebuild like the content
    const effectsHost = h('div', { class: 'cal-p4fx' });
    const effectsEditor = mountPageEffects(effectsHost, ctx, { page: () => store.pages()[opts.pageIndex] || null });

    const state = () => store.getState();
    const page = () => store.pages()[opts.pageIndex] || null;
    const warningHandlers = (index) => ({
        ...(opts.warningHandlers ? opts.warningHandlers(index) : {}),
        'page-content': () => {
            const field = contentHost.querySelector('input:not([type="file"]), textarea, select');
            contentHost.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            if (field) field.focus({ preventScroll: true });
        },
    });
    const assetOf = (id) => (id ? ctx.env.assets[id] || null : null);

    /** Which fields the page has — the structure of the panel. */
    function fields() {
        const current = page();
        const { design } = state().doc;
        const styles = design.layout ? design.layout.styles : null;
        if (!current || !styles) return [];
        const week = current.kind === 'week';
        const out = [];
        if (week) out.push(styles.photo.source === 'self' ? 'photo' : 'photo-note');
        if (week && design.features.captions) out.push('caption');
        if (week && styles.head.bg.imageOn) out.push('head');
        if (styles.bg.imageOn) out.push('bg');
        if (week && design.features.qr) out.push(styles.qr.perPage ? 'qr' : 'qr-own');
        return out;
    }

    const signatureOf = () => {
        const current = page();
        return current ? `${current.key}|${current.title}|${current.subtitle}|${store.pages().length}|${fields().join(',')}|${pageWarnings(ctx, current.index).map((w) => w.message).join('\n')}` : '';
    };

    function setImage(current, field, assetId) {
        const stored = state().doc.content.pageImages[current.key] || { head: null, bg: null };
        const next = { head: stored.head || null, bg: stored.bg || null, [field]: assetId };
        if (!next.head && !next.bg) store.removeItem('pageImages', current.key, current.title);
        else store.putItem('pageImages', current.key, next, current.title);
    }

    /** One upload field: label, tile, „Jen pro tuto stranu“ / „Výchozí ze stylu“, Odebrat. */
    function imageField(name, label, o) {
        const tile = fileTile({ asset: null, label, tip: 'Nahrát', accept: o.accept, fit: o.fit, attrs: { 'data-page-field': name }, onFiles: ([file]) => o.upload(file) });
        const hint = h('span', { class: 'cap' });
        const remove = h('button', { type: 'button', class: 'btn xs btn-ghost', 'data-page-remove': name, onClick: o.remove }, icon('trash', 's14'), 'Odebrat');
        // „Upravit“ — the photo editor (feedback K14), for a field that has one
        const edit = o.edit ? h('button', { type: 'button', class: 'btn xs btn-ghost', 'data-page-edit': name, title: 'Výřez, otočení a zrcadlení', onClick: (event) => o.edit(event.currentTarget) }, icon('image-edit', 's14'), 'Upravit') : null;
        const el = h('div', { class: 'cal-p4s' }, h('span', { class: 'lbl' }, label), h('div', { class: 'cal-ftr' }, tile, h('div', { class: 'cal-ftb' }, hint, edit, remove)));
        return {
            el,
            sync() {
                const asset = o.asset();
                const own = o.has();
                tile.setAsset(asset);
                hint.textContent = own ? 'Jen pro tuto stranu' : o.fallback;
                hint.hidden = hint.textContent === '';
                remove.hidden = !own;
                if (edit) edit.hidden = !(o.editable ? o.editable() : own);
            },
        };
    }

    function build() {
        signature = signatureOf();
        captionInput = null;
        const current = page();
        if (!current) {
            parts = null;
            body.replaceChildren();
            return;
        }
        const key = current.key;
        const content = () => state().doc.content;
        const pill = h('span', { class: 'pill', 'data-page-pill': '', hidden: true }, 'Upraveno');
        const range = current.kind === 'week' ? `${current.subtitle} · ` : '';
        const head = h('div', { class: 'cal-p4h' }, h('b', null, current.title, ' ', pill), h('span', { class: 'cap' }, `${range}strana ${current.index + 1} z ${store.pages().length}`));

        const warnings = pageWarnings(ctx, current.index);
        const warningBox = warnings.length
            ? h(
                  'div',
                  { class: 'cal-p4s cal-pw' },
                  h('span', { class: 'lbl' }, 'Upozornění'),
                  ...warnings.map((warning) => {
                      const card = warningCard(ctx, warning, { handlers: warningHandlers(current.index) });
                      card.dataset.pageWarning = warning.kind;
                      return card;
                  }),
              )
            : null;

        const sections = [];
        const syncs = [];
        for (const field of fields()) {
            if (field === 'photo-note') {
                sections.push(h('div', { class: 'cal-p4s' }, h('span', { class: 'lbl' }, 'Fotografie'), h('span', { class: 'cap' }, 'Fotky doplní klient.')));
            } else if (field === 'photo') {
                const f = imageField('photo', 'Fotografie', {
                    fallback: '',
                    asset: () => assetOf((content().pagePhotos[key] || {}).asset),
                    has: () => Boolean(content().pagePhotos[key]),
                    upload: async (file) => {
                        const info = await uploadAsset(ctx, file, 'photo');
                        if (info) setPagePhoto(ctx, current, info, { nameCaption: true });
                    },
                    remove: () => store.removeItem('pagePhotos', key, current.title),
                    editable: () => Boolean(weekPhotoEditing(ctx, current)),
                    edit: (anchor) => {
                        const editing = weekPhotoEditing(ctx, current);
                        if (!editing) return;
                        openPhotoEditor({ ...editing, title: `Upravit fotku — ${current.title}`, anchor, returnFocus: () => body.querySelector('[data-page-edit="photo"]'), onDone: (next) => editing.save(next) });
                    },
                });
                sections.push(f.el);
                syncs.push(f.sync);
            } else if (field === 'caption') {
                captionInput = h('input', {
                    type: 'text',
                    placeholder: 'Text popisku',
                    maxlength: String(CAPTION_MAX),
                    autocomplete: 'off',
                    'aria-label': 'Popisek fotografie',
                    'data-page-field': 'caption',
                    onInput: () => setPageCaption(ctx, current, captionInput.value),
                    onKeydown: (event) => {
                        if (event.key === 'Enter') captionInput.blur();
                    },
                });
                const input = captionInput;
                sections.push(h('div', { class: 'cal-p4s' }, h('span', { class: 'lbl' }, 'Popisek fotografie'), h('span', { class: 'inp sm' }, input)));
                syncs.push(() => {
                    if (document.activeElement === input) return;
                    const item = content().pageCaptions[key];
                    input.value = item ? item.text : '';
                });
            } else if (field === 'head' || field === 'bg') {
                const styleAsset = () => {
                    const styles = state().doc.design.layout.styles;
                    return assetOf(field === 'head' ? styles.head.bg.asset : styles.bg.asset);
                };
                const own = () => (content().pageImages[key] || {})[field] || null;
                const f = imageField(field, field === 'head' ? 'Obrázek v hlavičce' : 'Fotka pozadí', {
                    fallback: 'Výchozí ze stylu',
                    asset: () => assetOf(own()) || styleAsset(),
                    has: () => Boolean(own()),
                    upload: async (file) => {
                        const info = await uploadAsset(ctx, file, 'image');
                        if (info) setImage(current, field, info.id);
                    },
                    remove: () => setImage(current, field, null),
                });
                sections.push(f.el);
                syncs.push(f.sync);
            } else if (field === 'qr' || field === 'qr-own') {
                // 'qr-own': the code is shared by all pages and this page may replace it for itself
                const shared = field === 'qr-own';
                const f = imageField('qr', shared ? 'Jiný QR kód jen pro tuto stranu' : 'QR kód této strany', {
                    fallback: shared ? 'Výchozí ze stylu' : 'Bez QR kódu',
                    accept: QR_ACCEPT,
                    fit: 'contain',
                    asset: () => assetOf((content().pageQr[key] || {}).asset) || (shared ? assetOf(state().doc.design.layout.styles.qr.asset) : null),
                    has: () => Boolean(content().pageQr[key]),
                    upload: async (file) => {
                        const info = await uploadAsset(ctx, file, 'qr');
                        if (info) store.putItem('pageQr', key, { asset: info.id }, current.title);
                    },
                    remove: () => store.removeItem('pageQr', key, current.title),
                });
                sections.push(f.el);
                syncs.push(f.sync);
            }
        }
        // the note about client photos alone is not something to fill in
        const fillable = fields().some((field) => field !== 'photo-note') || hasPageContent(current);
        const empty = fillable || sections.length ? null : h('p', { class: 'cap' }, 'Tato strana nemá nic k doplnění — vzhled určuje styl z kroku 3.');
        const reset = h(
            'button',
            {
                type: 'button',
                class: 'btn sm btn-ghost cal-p4r',
                'data-cal-action': 'page-reset',
                hidden: true,
                onClick: () => store.applyOps(clearPageOverrides(state().doc.content, key).map((op) => ({ ...op, label: current.title }))),
            },
            icon('undo', 's16'),
            'Vrátit na styl',
        );

        parts = { key, pill, reset, syncs };
        body.dataset.pagePanel = key;
        body.replaceChildren(...[head, warningBox, hasPageContent(current) ? contentHost : null, ...sections, empty, effectsHost, reset].filter(Boolean));
        sync();
    }

    function sync() {
        if (!parts) return;
        const overridden = pageHasOverrides(state().doc.content, parts.key);
        parts.pill.hidden = !overridden;
        parts.reset.hidden = !overridden;
        for (const fn of parts.syncs) fn();
    }

    build();

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status' || event.type === 'ui' || (event.type === 'layout' && event.live)) return;
        if (signatureOf() !== signature) build();
        else sync();
    });

    return {
        destroy() {
            closePhotoEditor();
            unsubscribe();
            contentEditor.destroy();
            effectsEditor.destroy();
            root.replaceChildren();
        },
    };
}
