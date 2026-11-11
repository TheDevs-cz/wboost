// Page thumbnails of steps 4 and 5 (handoff §4.6 "Left list", map §8.10) — phase P6a; step 5 (P7a)
// uses it through the contract below.
//
// Contract:
//   `mountPageList(root, ctx, opts): { refresh(), destroy() }`
//     opts.selected(): number | null   index of the page shown as current (`aria-current="true"`)
//     opts.onSelect(index)             a thumbnail was clicked
//     opts.views?: { current(): 'style' | 'special' | number, onPick(view: 'style' | 'special') }
//                                      step 4 only — adds, above the thumbnails, the entries
//                                      „Styl stránek“ and „Speciální stránky“ and the plus menu
//                                      that adds a special page
//   - renders `button[data-page-thumb="<index>"]` per page, in page order, inside
//     `div.cal-thl[aria-label="Strany kalendáře"]`, with `data-warn="<n>"` when the page has
//     warnings (badge `!`, aria `{n} upozornění`) and `data-overridden` when the page has its own
//     overrides (dot); every thumbnail carries its page number (`.cal-thno`), title and range
//   - thumbnails are lazy (`mountLazyThumb`) and refresh by themselves, debounced 150 ms, on
//     `layout` (non-live) / `design` / `content` / `replace`; the list is rebuilt when the page
//     list changes (the scroll position is kept)
//   - `refresh()` only re-reads `opts.selected()` / `opts.views.current()` (call it when the
//     selection changes) and scrolls the current thumbnail into view
//
// DOM hooks with `opts.views`: `[data-content-view="style|special"]` (`aria-current`),
// `[data-cal-action="special-add"]` (`aria-expanded`), menu `.cal-spam[role=menu]` with
// `[data-special-add="<id>"]`, `[data-special-upload="image|text"]` (file inputs).

import { pageHasOverrides } from '../model/content.js';
import { samplePage } from '../generate/pages.js';
import { debounce, h, icon, onOutside } from './dom.js';
import { mountLazyThumb, renderThumb } from './thumb.js';
import { GRAPHIC_ACCEPT, TEXT_ACCEPT, addGraphicPage, addTextPage, previewedSpecial, specialEntries, switchOnSpecial } from './tools/special.js';

export function mountPageList(root, ctx, opts = {}) {
    const { store } = ctx;
    const views = opts.views || null;
    const list = h('div', { class: 'cal-thl', role: 'list', 'aria-label': 'Strany kalendáře' });

    let items = [];
    let signature = '';

    const signatureOf = (pages) => pages.map((p) => `${p.key}|${p.title}|${p.subtitle}`).join('\n');
    const thumbOf = (page, extra) => renderThumb(store.getState().doc, page, ctx, { dayContext: store.dayContext(), ...(extra || {}) });

    // ---- step 4: Styl stránek · Speciální stránky · plus menu --------------------------------
    let head = null;
    let entries = [];
    let menu = null;

    function closeMenu(focus = false) {
        if (!menu) return;
        menu.off();
        menu.el.remove();
        menu = null;
        if (head) {
            head.add.setAttribute('aria-expanded', 'false');
            if (focus) head.add.focus();
        }
    }

    function openMenu() {
        const add = head.add;
        const el = h('div', { class: 'cal-spam', role: 'menu', 'aria-label': 'Přidat speciální stránku' });
        const featureOn = store.getState().doc.design.features.specialPages;
        const generated = specialEntries(ctx).filter((entry) => entry.generated && !(featureOn && entry.item.on));
        for (const entry of generated) {
            el.append(
                h(
                    'button',
                    {
                        type: 'button',
                        role: 'menuitem',
                        'data-special-add': entry.id,
                        onClick: () => {
                            closeMenu();
                            switchOnSpecial(ctx, entry.id);
                        },
                    },
                    icon('layers', 's16'),
                    h('span', null, entry.label, h('span', { class: 'cap' }, 'Vygeneruje se automaticky')),
                ),
            );
        }
        const upload = (kind, glyph, title, note, accept, run) => {
            const input = h('input', { type: 'file', class: 'sr', accept, 'data-special-upload': kind });
            const label = h('label', { role: 'menuitem' }, input, icon(glyph, 's16'), h('span', null, title, h('span', { class: 'cap' }, note)));
            input.addEventListener('change', async () => {
                const file = input.files[0];
                input.value = '';
                if (!file) return;
                closeMenu();
                head.add.classList.add('busy');
                try {
                    await run(ctx, file);
                } finally {
                    head.add.classList.remove('busy');
                }
            });
            return label;
        };
        el.append(
            upload('image', 'image', 'Nahrát grafiku', 'Hotová strana jako PDF nebo obrázek', GRAPHIC_ACCEPT, addGraphicPage),
            upload('text', 'file', 'Text z Wordu', 'Vysází se do stylu kalendáře', TEXT_ACCEPT, addTextPage),
        );
        document.body.append(el);
        // fixed to the button: the list scrolls and would clip an absolutely placed menu
        const place = () => {
            const rect = add.getBoundingClientRect();
            const bounds = root.getBoundingClientRect();
            // the button left the visible part of the list: the menu has nothing to hang on
            if (rect.bottom < bounds.top || rect.top > bounds.bottom || rect.right < bounds.left || rect.left > bounds.right) return false;
            el.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - el.offsetWidth - 8))}px`;
            el.style.top = `${Math.max(8, Math.min(rect.bottom + 6, window.innerHeight - el.offsetHeight - 8))}px`;
            return true;
        };
        place();
        add.setAttribute('aria-expanded', 'true');
        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                closeMenu(true);
            }
        };
        const onMove = () => {
            if (!place()) closeMenu();
        };
        const offOutside = onOutside(el, (event) => {
            if (!add.contains(event.target)) closeMenu();
        });
        document.addEventListener('keydown', onKey, true);
        window.addEventListener('resize', onMove);
        root.addEventListener('scroll', onMove, { passive: true });
        window.addEventListener('scroll', onMove, { passive: true });
        menu = {
            el,
            off() {
                offOutside();
                document.removeEventListener('keydown', onKey, true);
                window.removeEventListener('resize', onMove);
                root.removeEventListener('scroll', onMove);
                window.removeEventListener('scroll', onMove);
            },
        };
        const first = el.querySelector('button, input');
        if (first) first.focus({ preventScroll: true });
    }

    if (views) {
        const entry = (view, glyph, label, getMarkup) => {
            const picture = h('span', { class: 'cal-thp' });
            const button = h('button', { type: 'button', class: 'cal-th sty', 'data-content-view': view, onClick: () => views.onPick(view) }, picture, h('span', { class: 'cal-thn' }, h('b', null, icon(glyph, 's14'), label)));
            return { view, button, thumb: mountLazyThumb(picture, getMarkup) };
        };
        const sample = () => {
            const state = store.getState();
            const page = samplePage(state.doc, store.pages(), null);
            return page ? thumbOf(page, { placeholders: 'all' }) : '';
        };
        entries = [
            entry('style', 'palette', 'Styl stránek', sample),
            entry('special', 'layers', 'Speciální stránky', () => {
                const chosen = previewedSpecial(ctx);
                const page = chosen ? store.pages().find((p) => p.key === `sp:${chosen.id}`) : null;
                return page ? thumbOf(page) : sample();
            }),
        ];
        const add = h(
            'button',
            { type: 'button', class: 'cal-thadd', 'data-cal-action': 'special-add', 'aria-haspopup': 'menu', 'aria-expanded': 'false', 'aria-label': 'Přidat speciální stránku', title: 'Přidat speciální stránku', onClick: () => (menu ? closeMenu() : openMenu()) },
            icon('plus', 's14'),
        );
        head = { el: h('div', { class: 'cal-thh' }, ...entries.map((e) => e.button), h('div', { class: 'cal-thaddw' }, add), h('span', { class: 'cal-thsep', 'aria-hidden': 'true' })), add };
        root.append(head.el);
    }
    root.append(list);

    // ---- thumbnails ---------------------------------------------------------------------------
    function build() {
        for (const item of items) item.thumb.destroy();
        const pages = store.pages();
        // empty (not yet rendered) thumbnails already have the shape of the page
        const { width, height } = store.getState().doc.design.page;
        root.style.setProperty('--cal-page-ratio', String(width / height));
        const scroller = root.scrollHeight > root.clientHeight ? root : list;
        const scroll = { top: scroller.scrollTop, left: list.scrollLeft };
        signature = signatureOf(pages);
        items = pages.map((page) => {
            const picture = h('span', { class: 'cal-thp' });
            const badge = h('span', { class: 'cal-wb', hidden: true }, '!');
            const dot = h('span', { class: 'cal-thd', hidden: true, title: 'Upraveno' });
            const button = h(
                'button',
                {
                    type: 'button',
                    class: 'cal-th',
                    role: 'listitem',
                    'data-page-thumb': String(page.index),
                    'data-page-key': page.key,
                    'aria-label': page.kind === 'week' ? `${page.title}, ${page.subtitle}` : page.title,
                    onClick: () => opts.onSelect && opts.onSelect(page.index),
                },
                picture,
                badge,
                dot,
                h('span', { class: 'cal-thn' }, h('b', null, h('span', { class: 'cal-thno' }, String(page.index + 1)), page.title), page.subtitle ? h('span', { class: 'cap' }, page.subtitle) : null),
            );
            const thumb = mountLazyThumb(picture, () => {
                const current = store.pages()[page.index];
                return current ? thumbOf(current) : '';
            });
            return { index: page.index, key: page.key, button, badge, dot, thumb };
        });
        list.replaceChildren(...items.map((item) => item.button));
        scroller.scrollTop = scroll.top;
        list.scrollLeft = scroll.left;
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
        const view = views ? views.current() : null;
        for (const e of entries) {
            const on = e.view === view;
            e.button.classList.toggle('on', on);
            if (on) e.button.setAttribute('aria-current', 'true');
            else e.button.removeAttribute('aria-current');
        }
    }

    const update = debounce(() => {
        for (const e of entries) e.thumb.refresh();
        if (signatureOf(store.pages()) !== signature) {
            build();
            return;
        }
        marks();
        for (const item of items) item.thumb.refresh();
    }, 150);

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status' || (event.type === 'layout' && event.live)) return;
        if (event.type === 'ui') {
            // the „Speciální stránky“ entry shows the page chosen for the preview
            if (views && event.keys.includes('toolState')) entries[1].thumb.refresh();
            return;
        }
        update();
    });
    build();

    return {
        refresh: () => refresh(true),
        destroy() {
            unsubscribe();
            update.cancel();
            closeMenu();
            for (const item of items) item.thumb.destroy();
            for (const e of entries) e.thumb.destroy();
            list.remove();
            if (head) head.el.remove();
        },
    };
}
