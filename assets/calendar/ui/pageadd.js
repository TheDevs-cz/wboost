// Adding, moving and removing special pages from the page list (feedback K13) — designer only;
// a client never changes which pages exist (`specialPages` is designer-writable, the server
// refuses a client op with a 403).
//
// Contract (used by `ui/pagelist.js`):
//   `openListMenu({ anchor, containers, label, build, onClose? }): { el, close(focus?) }`
//        a small menu `.cal-spam[role=menu]` fixed to `anchor` (the list scrolls and would clip an
//        absolutely placed one); `build(close)` returns its items. Closed by Esc (focus back on the
//        anchor), a pointer outside, or when the anchor scrolls out of any of `containers` (the
//        list scrolls vertically on a desktop, sideways on narrow screens).
//        `anchor` gets `aria-expanded` while it is open; `onClose()` runs once, whatever closed it.
//   `addMenuItems(ctx, { slot, anchor, close, onAdded(id) })`  the chooser of a „+“: the generated
//        kinds that are not in the calendar yet + Nahrát grafiku + Text z Wordu + Prázdná strana
//        (K20 P2 — a page for the designer's own layers). `slot` null = the
//        old plus menu at the top of the list (the page keeps its stored place and is shown as the
//        preview); a slot (`state/special-slots.js`) places the page there and calls `onAdded`
//        with its id — the caller selects it.
//   `pageMenuItems(ctx, id, { close, run? })`  the „⋯“ menu of a special page in the list: Posunout
//        výš / níž (inside its section), Přesunout za titulní stranu / před zadní stranu, Odebrat
//        stránku. `run(change)` wraps every write (the list keeps the shown page with it).
//   `removeSpecialPage(ctx, id, run?): Promise<boolean>`  asks first (`ctx.confirm`); a generated
//        page is switched off (its texts stay stored), an uploaded one is deleted.
//
// DOM hooks: `[data-special-add="<id>"]`, `[data-special-upload="image|text"]` (file inputs),
// `[data-special-add-blank]`,
// `[data-page-move="up|down|front|back"]`, `[data-page-remove]`.

import { specialSections } from '../state/special-slots.js';
import { specialPageName } from '../generate/pages.js';
import { h, icon, onOutside } from './dom.js';
import { GRAPHIC_ACCEPT, TEXT_ACCEPT, addBlankPage, addGraphicPage, addTextPage, placeSpecialPage, specialEntries, switchOnSpecial } from './tools/special.js';

export function openListMenu({ anchor, containers, label, build, onClose = null }) {
    const el = h('div', { class: 'cal-spam', role: 'menu', 'aria-label': label });
    let open = true;
    const place = () => {
        const rect = anchor.getBoundingClientRect();
        // the anchor left the visible part of the list: the menu has nothing to hang on
        for (const container of containers) {
            const bounds = container.getBoundingClientRect();
            if (rect.bottom < bounds.top || rect.top > bounds.bottom || rect.right < bounds.left || rect.left > bounds.right) return false;
        }
        el.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - el.offsetWidth - 8))}px`;
        const below = rect.bottom + 6;
        // no room below: above the anchor
        const top = below + el.offsetHeight > window.innerHeight - 8 && rect.top - 6 - el.offsetHeight >= 8 ? rect.top - 6 - el.offsetHeight : below;
        el.style.top = `${Math.max(8, Math.min(top, window.innerHeight - el.offsetHeight - 8))}px`;
        return true;
    };
    const onKey = (event) => {
        if (event.key === 'Escape') {
            event.stopPropagation();
            close(true);
        }
    };
    const onMove = () => {
        if (!place()) close();
    };
    const offOutside = onOutside(el, (event) => {
        if (!anchor.contains(event.target)) close();
    });
    function close(focus = false) {
        if (!open) return;
        open = false;
        offOutside();
        document.removeEventListener('keydown', onKey, true);
        window.removeEventListener('resize', onMove);
        document.removeEventListener('scroll', onMove, true);
        el.remove();
        anchor.setAttribute('aria-expanded', 'false');
        if (focus && anchor.isConnected) anchor.focus();
        if (onClose) onClose();
    }
    el.append(...build(close));
    document.body.append(el);
    place();
    anchor.setAttribute('aria-expanded', 'true');
    document.addEventListener('keydown', onKey, true);
    window.addEventListener('resize', onMove);
    // any scroll (the list, the page): capture catches the scrolls of elements too
    document.addEventListener('scroll', onMove, { capture: true, passive: true });
    const first = el.querySelector('button:not([disabled]), input');
    if (first) first.focus({ preventScroll: true });
    return { el, close };
}

const menuItem = (attrs, glyph, title, note) => h('button', { type: 'button', role: 'menuitem', ...attrs }, icon(glyph, 's16'), h('span', null, title, note ? h('span', { class: 'cap' }, note) : null));

export function addMenuItems(ctx, { slot = null, anchor, close, onAdded }) {
    const { store } = ctx;
    const featureOn = store.getState().doc.design.features.specialPages;
    const done = (id) => {
        if (id && slot && onAdded) onAdded(id);
    };
    const items = specialEntries(ctx)
        .filter((entry) => entry.generated && !(featureOn && entry.item.on))
        .map((entry) =>
            menuItem(
                {
                    'data-special-add': entry.id,
                    onClick: () => {
                        close();
                        if (slot) {
                            placeSpecialPage(ctx, entry.id, slot);
                            done(entry.id);
                        } else switchOnSpecial(ctx, entry.id);
                    },
                },
                'layers',
                entry.label,
                'Vygeneruje se automaticky',
            ),
        );
    const upload = (kind, glyph, title, note, accept, run) => {
        const input = h('input', { type: 'file', class: 'sr', accept, 'data-special-upload': kind });
        const label = h('label', { role: 'menuitem' }, input, icon(glyph, 's16'), h('span', null, title, h('span', { class: 'cap' }, note)));
        input.addEventListener('change', async () => {
            const file = input.files[0];
            input.value = '';
            if (!file) return;
            close();
            anchor.classList.add('busy');
            try {
                done(await run(ctx, file, slot));
            } finally {
                anchor.classList.remove('busy');
            }
        });
        return label;
    };
    items.push(
        upload('image', 'image', 'Nahrát grafiku', 'Hotová strana jako PDF nebo obrázek', GRAPHIC_ACCEPT, addGraphicPage),
        upload('text', 'file', 'Text z Wordu', 'Vysází se do stylu kalendáře', TEXT_ACCEPT, addTextPage),
        menuItem(
            {
                'data-special-add-blank': '',
                onClick: () => {
                    close();
                    done(addBlankPage(ctx, slot));
                },
            },
            'square',
            'Prázdná strana',
            'Pro vlastní obrázky a tvary z kroku 3',
        ),
    );
    return items;
}

export async function removeSpecialPage(ctx, id, run = (change) => change()) {
    const entry = specialEntries(ctx).find((e) => e.id === id);
    if (!entry) return false;
    const ok = await ctx.confirm({
        title: 'Odebrat stránku?',
        text: entry.generated
            ? `Stránka „${entry.label}“ z kalendáře zmizí. Její texty zůstanou uložené — když ji později přidáte znovu, budou zpátky.`
            : entry.item.kind === 'blank'
              ? `Stránka „${entry.label}“ se z kalendáře smaže. Vlastní vrstvy, které byly jen na ní, zůstanou ve Vrstvách — na žádné straně.`
              : `Stránka „${entry.label}“ i s nahraným souborem se z kalendáře smaže.`,
        confirmLabel: 'Odebrat stránku',
        danger: true,
    });
    if (!ok) return false;
    // the page may have changed while the dialog was open
    const current = specialEntries(ctx).find((e) => e.id === id);
    if (!current) return false;
    run(() => {
        if (current.generated) ctx.store.putItem('specialPages', id, { ...current.item, on: false }, current.label);
        else ctx.store.removeItem('specialPages', id, current.label);
    });
    ctx.toast(`Stránka „${current.label}“ odebrána`);
    return true;
}

export function pageMenuItems(ctx, id, { close, run = (change) => change() }) {
    const sections = specialSections(ctx.store.getState().doc);
    const position = sections.back.includes(id) ? 'back' : 'front';
    const list = sections[position];
    const index = list.indexOf(id);
    const move = (slot) => {
        close();
        run(() => placeSpecialPage(ctx, id, slot));
    };
    const entry = specialEntries(ctx).find((e) => e.id === id);
    const name = entry ? specialPageName(entry.item) : '';
    return [
        menuItem({ 'data-page-move': 'up', disabled: index <= 0, onClick: () => move({ position, index: index - 1 }) }, 'arrow-up', 'Posunout výš'),
        menuItem({ 'data-page-move': 'down', disabled: index < 0 || index >= list.length - 1, onClick: () => move({ position, index: index + 1 }) }, 'arrow-down', 'Posunout níž'),
        position === 'back'
            ? menuItem({ 'data-page-move': 'front', onClick: () => move({ position: 'front', index: sections.front.length }) }, 'arrow-up', 'Přesunout za titulní stranu', 'Mezi titulní stranu a první týden')
            : menuItem({ 'data-page-move': 'back', onClick: () => move({ position: 'back', index: sections.back.length }) }, 'arrow-down', 'Přesunout před zadní stranu', 'Mezi poslední týden a zadní stranu'),
        h('span', { class: 'cal-spam-sep', role: 'separator' }),
        menuItem(
            {
                'data-page-remove': '',
                class: 'danger',
                'aria-label': name ? `Odebrat stránku „${name}“` : 'Odebrat stránku',
                onClick: () => {
                    close();
                    removeSpecialPage(ctx, id, run);
                },
            },
            'trash',
            'Odebrat stránku',
        ),
    ];
}
