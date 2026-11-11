// Content tool „QR kódy“ (handoff §4.6, map §8.11) — phase P6a. Designer only.
//
// One QR picture per week page. The list exists only while the QR element is set to a different
// code on every page (`styles.qr.perPage`); otherwise the tool explains where the shared code is
// uploaded and offers the switch.
//
// Contract: `ui/tools/index.js` opens the modal (and shows the step-1 "feature is off" notice) and
// calls `mountQrTool(root, ctx)` with the modal body as `root`; edits go to `pageQr` through the
// store, the mode switch is one undoable layout change. Returns `{ destroy() }`.
//
// DOM hooks: `[data-cal-action="qr-per-page"]`, `[data-qr-row="<pageKey>"]`, `[data-qr-file]`,
// `[data-qr-remove]`, `[data-qr-count="<filled>/<total>"]`.

import { weekPages } from '../../generate/pages.js';
import { h, icon } from '../dom.js';
import { fileTile, uploadAsset } from './photos.js';

export const QR_ACCEPT = 'image/svg+xml,image/png,image/jpeg';

export function mountQrTool(root, ctx) {
    const { store } = ctx;
    let rows = [];
    let signature = null;

    const state = () => store.getState();
    const perPage = () => {
        const { layout } = state().doc.design;
        return Boolean(layout && layout.styles.qr.perPage);
    };
    const weeks = () => weekPages(store.pages());
    const signatureOf = () => `${perPage()}#${weeks().map((p) => `${p.key}|${p.title}|${p.subtitle}`).join('\n')}`;
    const assetOf = (key) => {
        const item = state().doc.content.pageQr[key];
        return item ? ctx.env.assets[item.asset] || null : null;
    };

    const summary = h('p', { class: 'cap cal-qrn' });

    function buildRow(page) {
        const tile = fileTile({
            asset: assetOf(page.key),
            label: `QR kód: ${page.title}`,
            tip: (has) => (has ? 'Vyměnit QR kód' : 'Nahrát QR kód'),
            accept: QR_ACCEPT,
            small: true,
            fit: 'contain',
            attrs: { 'data-qr-file': '' },
            onFiles: async ([file]) => {
                const info = await uploadAsset(ctx, file, 'qr');
                if (info) store.putItem('pageQr', page.key, { asset: info.id }, page.title);
            },
        });
        const remove = h(
            'button',
            { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': 'Odebrat QR kód', title: 'Odebrat QR kód', 'data-qr-remove': '', onClick: () => store.removeItem('pageQr', page.key, page.title) },
            icon('trash', 's14'),
        );
        const none = h('span', { class: 'cap' }, 'Bez QR kódu');
        const el = h('div', { class: 'cal-qrr', 'data-qr-row': page.key }, h('span', { class: 'cal-qrt' }, h('b', null, page.title), h('span', { class: 'cap' }, page.subtitle)), h('span', { class: 'cal-qrc' }, tile, remove, none));
        return { key: page.key, el, tile, remove, none };
    }

    function sync() {
        const items = state().doc.content.pageQr;
        let filled = 0;
        for (const row of rows) {
            const has = Boolean(items[row.key]);
            if (has) filled += 1;
            row.tile.setAsset(assetOf(row.key));
            row.remove.hidden = !has;
            row.none.hidden = has;
        }
        summary.textContent = `QR kód pro každý týden — strana bez QR kódu ho nemá. Vyplněno ${filled} z ${rows.length}.`;
        summary.dataset.qrCount = `${filled}/${rows.length}`;
    }

    function build() {
        signature = signatureOf();
        if (!perPage()) {
            rows = [];
            root.replaceChildren(
                h(
                    'div',
                    { class: 'cal-tool-off cal-qroff' },
                    h('span', { class: 'ico-c sm' }, icon('grid')),
                    h('p', null, 'QR kód je teď stejný na všech stranách — nahrajete ho ve Stylu stránek › QR kód.'),
                    h(
                        'button',
                        {
                            type: 'button',
                            class: 'btn sm btn-tertiary',
                            'data-cal-action': 'qr-per-page',
                            onClick: () => store.change((layout) => ({ ...layout, styles: { ...layout.styles, qr: { ...layout.styles.qr, perPage: true } } })),
                        },
                        'Na každé straně jiný QR kód',
                    ),
                ),
            );
            return;
        }
        rows = weeks().map(buildRow);
        root.replaceChildren(h('div', { class: 'cal-qr' }, summary, h('div', { class: 'cal-qrl' }, ...rows.map((row) => row.el))));
        sync();
    }

    build();

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status' || event.type === 'ui' || (event.type === 'layout' && event.live)) return;
        if (signatureOf() !== signature) build();
        else if ((event.type === 'content' && event.collection === 'pageQr') || event.type === 'replace') sync();
    });

    return {
        destroy() {
            unsubscribe();
            root.replaceChildren();
        },
    };
}
