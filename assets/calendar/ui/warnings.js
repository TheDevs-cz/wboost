// Warnings in the UI (architecture §D.8, §E.12; handoff §4.8; map §7) — phase P7b.
//
// The data is `store.warnings()` (`Map<pageIndex, Warning[]>`, computed by `render/warnings.js`
// from the page geometry with the real text measurer and from the asset map — nothing is stored).
// `Warning = { pageIndex, kind: 'portrait' | 'dpi' | 'icons' | 'event', target: { el: 'photo' } | { date }, message }`.
// Warnings inform; they never block saving, Dokončit or anything else.
//
// Contract:
//   `warningChip(ctx, opts: { onClick(): void }): { el: HTMLElement, destroy() }`
//     the chip `button[data-cal-action="warnings"]` reading `{n} upozornění` (`data-count="<n>"`);
//     hidden while there are none; keeps itself up to date from store events. The step decides
//     what a click does (step 4: `nextWarningPage`; step 5: `openWarnings`).
//   `openWarnings(ctx, opts?: { onPick(pageIndex): void }): { close() }`
//     the dialog `Upozornění ({n})` (`data-cal-dialog="warnings"`): one
//     `button.cal-wpage[data-warning-page="<index>"]` per page that has warnings, in page order,
//     holding the page title and one `.cal-wrow[data-warning-kind]` per warning; empty `Bez kolizí.`.
//     A click calls `onPick(pageIndex)` and closes. The list follows the document while open.
//
// Data for the other surfaces (page, thumbnail, day) — pure reads of `store.warnings()`:
//   `warningCount(ctx): number`                      all warnings of the calendar
//   `pageWarnings(ctx, pageIndex): Warning[]`        of one page (thumbnail badge, step-4 page panel)
//   `photoWarnings(ctx, pageIndex): Warning[]`       the page's photo warnings (mark on the photo frame)
//   `dayWarnings(ctx, pageIndex): Map<date, Warning[]>`   the page's per-day warnings (mark beside a row)
//   `nextWarningPage(ctx, after): number | null`     next page index with a warning after `after`, wrapping
//   `warningTip(list): string`                       the messages joined by ` · ` (tooltip of a mark)
//   `warningMark(list, attrs?): HTMLElement | null`  `span.cal-wb[role=img]` — the `!` badge with the
//                                                    tooltip and `aria-label`; `null` for an empty list
//   `warningNotes(list): HTMLElement | null`         `ul.cal-wnotes` — the messages as a list (page panel)

import { debounce, h, icon, openModal } from './dom.js';

const EMPTY = Object.freeze([]);

/** The four kinds in the order they are listed on a page. */
export const WARNING_KINDS = Object.freeze(['portrait', 'dpi', 'icons', 'event']);

export function warningCount(ctx) {
    let n = 0;
    for (const list of ctx.store.warnings().values()) n += list.length;
    return n;
}

export function pageWarnings(ctx, pageIndex) {
    return ctx.store.warnings().get(pageIndex) || EMPTY;
}

export function photoWarnings(ctx, pageIndex) {
    return pageWarnings(ctx, pageIndex).filter((warning) => warning.target && warning.target.el === 'photo');
}

export function dayWarnings(ctx, pageIndex) {
    const out = new Map();
    for (const warning of pageWarnings(ctx, pageIndex)) {
        const date = warning.target && warning.target.date;
        if (!date) continue;
        if (!out.has(date)) out.set(date, []);
        out.get(date).push(warning);
    }
    return out;
}

export function nextWarningPage(ctx, after) {
    const indexes = [...ctx.store.warnings().keys()].sort((a, b) => a - b);
    if (!indexes.length) return null;
    const next = indexes.find((index) => index > after);
    return next === undefined ? indexes[0] : next;
}

export const warningTip = (list) => (list || EMPTY).map((warning) => warning.message).join(' · ');

export function warningMark(list, attrs = null) {
    if (!list || !list.length) return null;
    const tip = warningTip(list);
    return h('span', { class: 'cal-wb', role: 'img', title: tip, 'aria-label': `${list.length} upozornění: ${tip}`, 'data-warn': String(list.length), ...(attrs || {}) }, '!');
}

export function warningNotes(list) {
    if (!list || !list.length) return null;
    return h('ul', { class: 'cal-wnotes' }, ...list.map((warning) => h('li', { class: 'cal-wrow', 'data-warning-kind': warning.kind }, h('span', { class: 'cal-wb', 'aria-hidden': 'true' }, '!'), h('span', null, warning.message))));
}

export function warningChip(ctx, opts = {}) {
    const text = h('span');
    const el = h('button', { type: 'button', class: 'cal-wchip', 'data-cal-action': 'warnings', hidden: true, onClick: () => opts.onClick && opts.onClick() }, icon('alert', 's14'), text);
    const sync = () => {
        const n = warningCount(ctx);
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
    const { store } = ctx;
    const body = h('div', { class: 'cal-wlist' });
    let modal = null;

    const pick = (index) => {
        if (opts.onPick) opts.onPick(index);
        modal.close();
    };

    function render() {
        const pages = store.pages();
        const warnings = store.warnings();
        const indexes = [...warnings.keys()].sort((a, b) => a - b);
        const n = warningCount(ctx);
        const title = `Upozornění (${n})`;
        if (modal) {
            modal.el.setAttribute('aria-label', title);
            const heading = modal.el.querySelector('.modal-title');
            if (heading) heading.textContent = title;
        }
        if (!indexes.length) {
            body.replaceChildren(h('p', { class: 'cal-empty-note' }, 'Bez kolizí.'));
            return title;
        }
        body.replaceChildren(
            h('p', { class: 'cal-wlead cap' }, 'Upozornění nic neblokují — kalendář můžete uložit i dokončit. Kliknutím přejdete na stranu.'),
            ...indexes.map((index) => {
                const page = pages[index];
                const list = warnings.get(index);
                return h(
                    'button',
                    { type: 'button', class: 'cal-wpage', 'data-warning-page': String(index), 'data-warn': String(list.length), onClick: () => pick(index) },
                    h('span', { class: 'cal-wpage-h' }, h('b', null, page ? page.title : `Strana ${index + 1}`), page && page.subtitle ? h('span', { class: 'cap' }, page.subtitle) : null, h('span', { class: 'cal-wpage-go', 'aria-hidden': 'true' }, icon('chev-right', 's16'))),
                    ...list.map((warning) => h('span', { class: 'cal-wrow', 'data-warning-kind': warning.kind }, h('span', { class: 'cal-wb', 'aria-hidden': 'true' }, '!'), h('span', null, warning.message))),
                );
            }),
        );
        return title;
    }

    const later = debounce(render, 150);
    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'ui' || event.type === 'status' || (event.type === 'layout' && event.live)) return;
        later();
    });
    modal = openModal({
        title: render(),
        body,
        size: 'lg',
        onClose: () => {
            unsubscribe();
            later.cancel();
        },
    });
    modal.el.dataset.calDialog = 'warnings';
    return { close: () => modal.close() };
}
