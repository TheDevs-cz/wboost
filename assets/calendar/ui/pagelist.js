// Page thumbnails of steps 4 and 5 (handoff §4.6 "Left list", map §8.10) — phase P6a; step 5 (P7a)
// uses it through the contract below.
//
// Contract:
//   `mountPageList(root, ctx, opts): { refresh(), destroy() }`
//     opts.selected(): number | null   index of the page shown as current (`aria-current="true"`)
//     opts.onSelect(index)             a thumbnail was clicked
//     opts.warningHandlers?(index)     actions of the step for a page's warnings (`ui/warnings.js`
//                                      Handlers) — offered in the badge's popover
//     opts.views?: { current(): 'style' | 'special' | number, onPick(view: 'style' | 'special') }
//                                      step 4 only — adds, above the thumbnails, the entries
//                                      „Styl stránek“ and „Speciální stránky“ and the plus menu
//                                      that adds a special page
//   - renders `button[data-page-thumb="<index>"]` per page, in page order, inside
//     `div.cal-thl[aria-label="Strany kalendáře"]` (each in a `div.cal-thw[role=listitem]`), with
//     `data-warn="<n>"` when the page has warnings and `data-overridden` when the page has its own
//     overrides (dot); every thumbnail carries its page number (`.cal-thno`), title and range.
//     The warning badge `button.cal-wb[data-page-warn="<index>"]` lies beside the thumbnail button
//     (a button cannot hold a button): aria-label = the warning title(s); hover or click / tap
//     opens the explanation with its fixes (`bindWarningBadge`)
//   - thumbnails are lazy (`mountLazyThumb`) and refresh by themselves, debounced 150 ms, on
//     `layout` (non-live) / `design` / `content` / `replace`; the list is rebuilt when the page
//     list changes (the scroll position is kept)
//     opts.arrange?: boolean           the designer (steps 4 and 5; never a client — `specialPages`
//                                      is designer-only on the server too) adds, moves and removes
//                                      special pages right in the list (feedback K13, `ui/pageadd.js`)
//   - `refresh()` only re-reads `opts.selected()` / `opts.views.current()` (call it when the
//     selection changes) and scrolls the current thumbnail into view
//
// DOM hooks with `opts.views`: `[data-content-view="style|special"]` (`aria-current`),
// `[data-cal-action="special-add"]` (`aria-expanded`), menu `.cal-spam[role=menu]` with
// `[data-special-add="<id>"]`, `[data-special-upload="image|text"]` (file inputs).
//
// With `opts.arrange` (K13): an insertion point `.cal-thins[data-slot="front:<n>|back:<n>"]` with
// its „+“ `[data-cal-action="page-insert"]` (`aria-expanded`; opens the same chooser, the page is
// placed there and selected) after the cover, after every front special page, after the last week
// page and after every back special page — never between week pages (they follow the dates). A
// special page's thumbnail sits in `.cal-thw[data-special-thumb="<id>"]` (the list item) with a
// drag handle `.cal-grip` (SortableJS, `forceFallback`; dropped after a week page = back section)
// and a menu `[data-cal-action="page-menu"]` (`[data-page-move]`, `[data-page-remove]`). The
// selected page stays selected (by its key) when pages are added, moved or removed before it.

import Sortable from 'sortablejs';
import { pageHasOverrides } from '../model/content.js';
import { samplePage } from '../generate/pages.js';
import { slotInSequence, specialSlots } from '../state/special-slots.js';
import { debounce, h, icon } from './dom.js';
import { addMenuItems, openListMenu, pageMenuItems } from './pageadd.js';
import { mountLazyThumb, renderThumb } from './thumb.js';
import { badgeLabel, bindWarningBadge, pageWarnings } from './warnings.js';
import { placeSpecialPage, previewedSpecial } from './tools/special.js';

export function mountPageList(root, ctx, opts = {}) {
    const { store } = ctx;
    const views = opts.views || null;
    const list = h('div', { class: 'cal-thl', role: 'list', 'aria-label': 'Strany kalendáře' });

    let items = [];
    let signature = '';

    const signatureOf = (pages) => pages.map((p) => `${p.key}|${p.title}|${p.subtitle}`).join('\n');
    const thumbOf = (page, extra) => renderThumb(store.getState().doc, page, ctx, { dayContext: store.dayContext(), ...(extra || {}) });

    // ---- menus (the plus menu of step 4, the „+“ of a slot, the „⋯“ of a special page) --------
    const arrange = opts.arrange === true;
    let head = null;
    let entries = [];
    let menu = null;
    let sortable = null;

    function closeMenu(focus = false) {
        if (!menu) return;
        const open = menu;
        menu = null;
        open.close(focus);
    }

    function toggleMenu(anchor, label, build, containers = [root, list]) {
        const same = menu && menu.anchor === anchor;
        closeMenu();
        if (same) return;
        const entry = { anchor, close: () => {} };
        menu = entry;
        const opened = openListMenu({
            anchor,
            containers,
            label,
            build,
            onClose: () => {
                if (menu === entry) menu = null;
            },
        });
        entry.close = opened.close;
    }

    /**
     * Runs a change of the page list and keeps the shown page (by key), or selects `select(pages)`;
     * rebuilds the list when the pages changed (or always, with `force`).
     */
    function arranged(change, select = null, force = false) {
        const selected = opts.selected ? opts.selected() : null;
        const before = selected === null ? null : store.pages()[selected] || null;
        change();
        const pages = store.pages();
        let index = select ? select(pages) : -1;
        if (index < 0 && before) {
            index = pages.findIndex((p) => p.key === before.key);
            // the shown page itself went away: the page that took its place
            if (index < 0) index = Math.min(selected, pages.length - 1);
        }
        if (index >= 0 && index !== selected && opts.onSelect) opts.onSelect(index);
        if (force || signatureOf(pages) !== signature) build();
    }
    const indexOfSpecial = (id) => (pages) => pages.findIndex((p) => p.key === `sp:${id}`);

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
            {
                type: 'button',
                class: 'cal-thadd',
                'data-cal-action': 'special-add',
                'aria-haspopup': 'menu',
                'aria-expanded': 'false',
                'aria-label': 'Přidat speciální stránku',
                title: 'Přidat speciální stránku',
                // the plus of the head sits above the list: only the list's frame can scroll it away
                onClick: () => toggleMenu(add, 'Přidat speciální stránku', (close) => addMenuItems(ctx, { slot: null, anchor: add, close }), [root]),
            },
            icon('plus', 's14'),
        );
        head = { el: h('div', { class: 'cal-thh' }, ...entries.map((e) => e.button), h('div', { class: 'cal-thaddw' }, add), h('span', { class: 'cal-thsep', 'aria-hidden': 'true' })), add };
        root.append(head.el);
    }
    root.append(list);

    // ---- thumbnails ---------------------------------------------------------------------------
    function build() {
        // the list is rebuilt when its own drag ends
        if (list.classList.contains('dragging')) return;
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
            const badge = h('button', { type: 'button', class: 'cal-wb', 'data-page-warn': String(page.index), hidden: true }, icon('alert', 's12'));
            bindWarningBadge(badge, ctx, () => pageWarnings(ctx, page.index), opts.warningHandlers ? () => opts.warningHandlers(page.index) : null);
            const dot = h('span', { class: 'cal-thd', hidden: true, title: 'Upraveno' });
            const button = h(
                'button',
                {
                    type: 'button',
                    class: 'cal-th',
                    'data-page-thumb': String(page.index),
                    'data-page-key': page.key,
                    'aria-label': page.kind === 'week' ? `${page.title}, ${page.subtitle}` : page.title,
                    onClick: () => opts.onSelect && opts.onSelect(page.index),
                },
                picture,
                dot,
                h('span', { class: 'cal-thn' }, h('b', null, h('span', { class: 'cal-thno' }, String(page.index + 1)), page.title), page.subtitle ? h('span', { class: 'cap' }, page.subtitle) : null),
            );
            const thumb = mountLazyThumb(picture, () => {
                const current = store.pages()[page.index];
                return current ? thumbOf(current) : '';
            });
            const wrap = h('div', { class: 'cal-thw', role: 'listitem' }, button, badge);
            const item = { index: page.index, key: page.key, kind: page.kind, wrap, button, badge, dot, thumb };
            if (arrange && page.kind === 'special') makeMovable(item, page.special.id);
            return item;
        });
        list.replaceChildren(...(arrange ? withSlots(items) : items.map((item) => item.wrap)));
        // one Sortable for the list's lifetime: destroying one ends the drag of EVERY Sortable on
        // the page (its drag state is module-wide) — a rebuild while the Speciální stránky panel
        // is being dragged used to freeze that drag
        if (arrange && !sortable) sortable = sortableList();
        scroller.scrollTop = scroll.top;
        list.scrollLeft = scroll.left;
        marks();
        refresh(false);
    }

    /** A special page's list item gets a drag handle and its menu. */
    function makeMovable(item, id) {
        const more = h('button', { type: 'button', class: 'cal-thm', 'data-cal-action': 'page-menu', 'aria-haspopup': 'menu', 'aria-expanded': 'false', 'aria-label': `Možnosti stránky ${item.button.getAttribute('aria-label')}`, title: 'Možnosti stránky' }, icon('more', 's14'));
        more.addEventListener('click', () =>
            toggleMenu(more, 'Možnosti stránky', (close) =>
                pageMenuItems(ctx, id, { close, run: arranged }),
            ),
        );
        const grip = h('span', { class: 'cal-grip', title: 'Přetáhněte pro změnu pořadí', 'aria-hidden': 'true' }, icon('grip', 's14'));
        item.wrap.dataset.specialThumb = id;
        item.wrap.append(h('span', { class: 'cal-thx' }, grip, more));
    }

    /** The thumbnails with an insertion point after the cover, the last week page and every special page. */
    function withSlots(list) {
        const slots = specialSlots(store.getState().doc);
        const lastWeek = list.reduce((last, item, i) => (item.kind === 'week' ? i : last), -1);
        const out = [];
        list.forEach((item, i) => {
            out.push(item.wrap);
            const after = i === lastWeek ? 'weeks' : item.key;
            for (const slot of slots.filter((s) => s.after === after)) out.push(slotElement(slot));
        });
        return out;
    }

    function slotElement(slot) {
        const where = slot.position === 'front' ? 'mezi titulní stranu a týdny' : 'mezi týdny a zadní stranu';
        const plus = h('button', { type: 'button', class: 'cal-thinsb', 'data-cal-action': 'page-insert', 'aria-haspopup': 'menu', 'aria-expanded': 'false', 'aria-label': `Vložit stránku sem (${where})`, title: 'Vložit stránku sem' }, icon('plus', 's14'));
        plus.addEventListener('click', () =>
            toggleMenu(plus, 'Vložit stránku', (close) =>
                addMenuItems(ctx, {
                    slot: { position: slot.position, index: slot.index },
                    anchor: plus,
                    close,
                    onAdded: (id) => arranged(() => {}, indexOfSpecial(id)),
                }),
            ),
        );
        return h('div', { class: 'cal-thins', role: 'listitem', 'data-slot': `${slot.position}:${slot.index}` }, plus);
    }

    function sortableList() {
        return Sortable.create(list, {
            handle: '.cal-grip',
            // insertion points are drop targets too — the only way into an empty back section
            draggable: '.cal-thw[data-special-thumb], .cal-thins',
            animation: 120,
            // the HTML5 drag ghost cannot be driven by synthetic pointers and looks wrong on a dark theme
            forceFallback: true,
            fallbackOnBody: true,
            fallbackClass: 'cal-thw-ghost',
            chosenClass: 'drag',
            scroll: true,
            bubbleScroll: true,
            onStart: () => {
                closeMenu();
                list.classList.add('dragging');
            },
            onEnd: (event) => {
                list.classList.remove('dragging');
                const id = event.item.dataset.specialThumb;
                const sequence = [...list.children].map((el) => {
                    if (el.dataset.specialThumb) return el.dataset.specialThumb;
                    const thumb = el.querySelector('[data-page-thumb]');
                    if (!thumb) return null;
                    const page = store.pages()[Number(thumb.dataset.pageThumb)];
                    return page ? page.kind : null;
                });
                const slot = id ? slotInSequence(sequence, id) : null;
                // after Sortable has finished its drop; Sortable moved the node, so the list is
                // rebuilt the way the pages are even when nothing changed
                queueMicrotask(() =>
                    arranged(
                        () => {
                            if (slot) placeSpecialPage(ctx, id, slot);
                        },
                        null,
                        true,
                    ),
                );
            },
        });
    }

    function marks() {
        const content = store.getState().doc.content;
        for (const item of items) {
            const count = pageWarnings(ctx, item.index).length;
            if (count) item.button.dataset.warn = String(count);
            else delete item.button.dataset.warn;
            item.badge.hidden = count === 0;
            if (count) item.badge.setAttribute('aria-label', badgeLabel(pageWarnings(ctx, item.index)));
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
            if (sortable) sortable.destroy();
            for (const item of items) item.thumb.destroy();
            for (const e of entries) e.thumb.destroy();
            list.remove();
            if (head) head.el.remove();
        },
    };
}
