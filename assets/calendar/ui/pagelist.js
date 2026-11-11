// Page thumbnails of steps 4 and 5 — basic version of phase P3, owned by P6a (step 5 / P7a uses
// it through the contract below, so both phases can work in parallel).
//
// Contract (final):
//   `mountPageList(root, ctx, opts): { refresh(), destroy() }`
//     opts.selected(): number | null   index of the page shown as current (`aria-current="true"`)
//     opts.onSelect(index)             a thumbnail was clicked
//   - renders `button[data-page-thumb="<index>"]` per page inside `div.cal-thl[aria-label="Strany kalendáře"]`,
//     with `data-warn="<n>"` when the page has warnings (badge `!`, aria `{n} upozornění`) and
//     `data-overridden` when the page has its own overrides (dot)
//   - thumbnails are lazy (`mountLazyThumb`) and refresh by themselves, debounced 150 ms, on
//     `layout` (non-live) / `design` / `content` / `replace`; the list is rebuilt when the page
//     list changes
//   - `refresh()` only re-reads `opts.selected()` (call it when the selection changes) and scrolls
//     the current thumbnail into view
//
// P6a extends the look (and may add opts), but keeps the selectors and the two callbacks.

import { pageHasOverrides } from '../model/content.js';
import { debounce, h } from './dom.js';
import { mountLazyThumb, renderThumb } from './thumb.js';

export function mountPageList(root, ctx, opts = {}) {
    const { store } = ctx;
    const list = h('div', { class: 'cal-thl', role: 'list', 'aria-label': 'Strany kalendáře' });
    root.append(list);

    let items = [];
    let signature = '';

    const signatureOf = (pages) => pages.map((p) => `${p.key}|${p.title}|${p.subtitle}`).join('\n');

    function build() {
        for (const item of items) item.thumb.destroy();
        const pages = store.pages();
        signature = signatureOf(pages);
        items = pages.map((page) => {
            const picture = h('span', { class: 'cal-thp' });
            const badge = h('span', { class: 'cal-wb', hidden: true }, '!');
            const dot = h('span', { class: 'cal-thd', hidden: true, title: 'Upraveno' });
            const button = h(
                'button',
                { type: 'button', class: 'cal-th', role: 'listitem', 'data-page-thumb': String(page.index), onClick: () => opts.onSelect && opts.onSelect(page.index) },
                picture,
                badge,
                dot,
                h('span', { class: 'cal-thn' }, h('b', null, page.title), page.subtitle ? h('span', { class: 'cap' }, page.subtitle) : null),
            );
            const thumb = mountLazyThumb(picture, () => {
                const state = store.getState();
                const current = store.pages()[page.index];
                return current ? renderThumb(state.doc, current, ctx, { dayContext: store.dayContext() }) : '';
            });
            return { index: page.index, key: page.key, button, badge, dot, thumb };
        });
        list.replaceChildren(...items.map((item) => item.button));
        marks();
        refresh(false);
    }

    function marks() {
        const warnings = store.warnings();
        const content = store.getState().doc.content;
        for (const item of items) {
            const count = (warnings.get(item.index) || []).length;
            if (count) item.button.dataset.warn = String(count);
            else delete item.button.dataset.warn;
            item.badge.hidden = count === 0;
            if (count) item.badge.setAttribute('aria-label', `${count} upozornění`);
            const overridden = pageHasOverrides(content, item.key);
            item.button.toggleAttribute('data-overridden', overridden);
            item.dot.hidden = !overridden;
        }
    }

    function refresh(scroll = true) {
        const selected = opts.selected ? opts.selected() : null;
        for (const item of items) {
            const on = item.index === selected;
            item.button.classList.toggle('on', on);
            if (on) item.button.setAttribute('aria-current', 'true');
            else item.button.removeAttribute('aria-current');
            if (on && scroll) item.button.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
    }

    const update = debounce(() => {
        if (signatureOf(store.pages()) !== signature) {
            build();
            return;
        }
        marks();
        for (const item of items) item.thumb.refresh();
    }, 150);

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'ui' || event.type === 'status' || event.type === 'undo' || (event.type === 'layout' && event.live)) return;
        update();
    });
    build();

    return {
        refresh: () => refresh(true),
        destroy() {
            unsubscribe();
            update.cancel();
            for (const item of items) item.thumb.destroy();
            list.remove();
        },
    };
}
