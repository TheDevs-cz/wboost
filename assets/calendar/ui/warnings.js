// Warnings in the UI (architecture §D.8, handoff §4.8) — basic version of phase P3, owned by P7b.
//
// Contract (final):
//   `warningChip(ctx, opts: { onClick(): void }): { el: HTMLElement, destroy() }`
//     the chip `button[data-cal-action="warnings"]` reading `{n} upozornění`; hidden while there are
//     none; keeps itself up to date from store events. The step decides what a click does
//     (step 4: jump to the next page with a warning; step 5: `openWarnings`).
//   `openWarnings(ctx, opts?: { onPick(pageIndex): void }): { close() }`
//     the list `Upozornění ({n})` grouped by page title, empty `Bez kolizí.`; a click on a row
//     calls `onPick` and closes.
// Warnings never block anything.

import { debounce, h, icon, openModal } from './dom.js';

const count = (ctx) => [...ctx.store.warnings().values()].reduce((sum, list) => sum + list.length, 0);

export function warningChip(ctx, opts = {}) {
    const text = h('span');
    const el = h('button', { type: 'button', class: 'cal-wchip', 'data-cal-action': 'warnings', hidden: true, onClick: () => opts.onClick && opts.onClick() }, icon('alert', 's14'), text);
    const sync = () => {
        const n = count(ctx);
        el.hidden = n === 0;
        el.dataset.count = String(n);
        text.textContent = `${n} upozornění`;
    };
    const later = debounce(sync, 150);
    const unsubscribe = ctx.store.subscribe((event) => {
        if (event.type === 'ui' || event.type === 'status' || (event.type === 'layout' && event.live)) return;
        later();
    });
    sync();
    return {
        el,
        destroy() {
            unsubscribe();
            later.cancel();
            el.remove();
        },
    };
}

export function openWarnings(ctx, opts = {}) {
    const pages = ctx.store.pages();
    const warnings = ctx.store.warnings();
    const body = h('div', { class: 'cal-wlist' });
    let modal = null;
    const indexes = [...warnings.keys()].sort((a, b) => a - b);
    if (!indexes.length) body.append(h('p', { class: 'cal-empty-note' }, 'Bez kolizí.'));
    for (const index of indexes) {
        const page = pages[index];
        body.append(
            h(
                'section',
                { class: 'cal-wsec' },
                h('h3', null, page ? page.title : ''),
                ...warnings.get(index).map((warning) =>
                    h(
                        'button',
                        {
                            type: 'button',
                            class: 'cal-wi',
                            'data-warning-page': String(index),
                            onClick: () => {
                                if (opts.onPick) opts.onPick(index);
                                modal.close();
                            },
                        },
                        icon('alert', 's14'),
                        h('span', null, warning.message),
                    ),
                ),
            ),
        );
    }
    modal = openModal({ title: `Upozornění (${count(ctx)})`, body, size: 'lg' });
    modal.el.dataset.calDialog = 'warnings';
    return { close: () => modal.close() };
}
