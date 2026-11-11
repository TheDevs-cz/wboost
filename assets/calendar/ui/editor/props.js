// Properties panel of the editor — the right column of step 3 and of step 4 „Styl stránek“
// (handoff §5.6, §6.1; map §8.8; architecture §D.6 "Properties"). Owner: P5a.
//
// Contract: `mountProps(root, ctx): { destroy() }`
//   - nothing selected → the empty state `Vyberte prvek na stránce nebo v seznamu vlevo` (`.cal-noprop`)
//   - `selection.el === 'cal'` → ONLY `mountKalProps(sub, ctx)` (P5b) inside `div.cal-props-kal`. It is
//     re-mounted whenever the selection changes (element, frame or part), after an undo / redo, after
//     a `features` / `type` change, and after a save response that really changed the layout. Between
//     those moments the Kalendárium panel keeps its own values fresh (subscribe to `layout`).
//   - any other element → its panel, built ONCE per selection from `ui/controls/*`. Values coming
//     from elsewhere (a drag on the page, undo, auto-grow, a save response) are written into the
//     existing controls through `setValue` — the panel is never rebuilt for a value, so a field keeps
//     its focus while the page redraws.
//   - DOM hooks (§D.10): panel title `[data-props-title]`, root `[data-props="<element>"]`,
//     `Pozice a velikost` inputs `data-field="x|y|w|h"`, every other control `data-prop="<path in styles>"`
//     (on the input / select, or on the group element of a segmented control / colour picker).
//
// Writing rule: a discrete control → `store.change` (one undo step); a slider → `beginChange` /
// `updateLive` / `commitChange` (one undo step per drag) — both through `styleBinding` (`controls/bind.js`).

import { elementLabel } from '../../model/elements.js';
import { fillIsOn } from '../../render/week-layout.js';
import { h, switchRow } from '../dom.js';
import { alignControl, sideControl, valignControl } from '../controls/align.js';
import { createRefresher, getPath, styleBinding, stylesOf } from '../controls/bind.js';
import { colorPalette, colorPicker } from '../controls/color.js';
import { fillPanel } from '../controls/fill.js';
import { limitField } from '../controls/number.js';
import { labelled, note, pair, panelHead, panelRoot, section, setShown } from '../controls/panel.js';
import { positionSection } from '../controls/position.js';
import { textProps } from '../controls/text.js';
import { uploadTile } from '../controls/upload.js';
import { mountKalProps } from './props-kal.js';

const ALL_PAGES = 'Platí pro všechny strany kalendáře';

/** Everything a panel builder needs: bound controls that register their own refresh. */
function createKit(ctx, refresher) {
    const { store, env } = ctx;
    const palette = colorPalette(env);
    const read = (path) => () => getPath(stylesOf(store), path);
    const bound = (path, make) => {
        const binding = styleBinding(store, path);
        const control = make(binding);
        refresher.track(read(path), (value) => control.setValue(value));
        return control;
    };

    return {
        /** Stepper on a `LIMITS` row. */
        number: (path, limit, unit, label) => bound(path, (b) => limitField({ limit, unit, label, value: b.get(), attrs: b.attrs, onChange: b.onChange })),
        color: (path, label) => bound(path, (b) => colorPicker({ value: b.get(), palette, label, attrs: b.attrs, onChange: b.onChange })),
        toggle: (path, label) => {
            const binding = styleBinding(store, path);
            const control = switchRow({ label, checked: binding.get() === true, attrs: binding.attrs, onChange: binding.onChange });
            refresher.track(read(path), (value) => control.setChecked(value === true));
            return control;
        },
        side: (path, label, options) => bound(path, (b) => sideControl({ label, options, value: b.get(), attrs: b.attrs, onChange: b.onChange })),
        align: (path) => bound(path, (b) => alignControl({ value: b.get(), attrs: b.attrs, onChange: b.onChange })),
        valign: (path) => bound(path, (b) => valignControl({ value: b.get(), attrs: b.attrs, onChange: b.onChange })),
        text: (path) => bound(path, (b) => textProps({ value: b.get(), fonts: env.fonts, path, onChange: b.patch })),
        fill: (path, options = {}) => bound(path, (b) => fillPanel({ value: b.get(), ctx, path, ...options, onStart: b.onStart, onChange: b.onChange, onCommit: b.onCommit })),
        upload: (path, options) => bound(path, (b) => uploadTile({ value: b.get(), ctx, attrs: b.attrs, onChange: b.onChange, ...options })),
        /** Shows `node` only while `test(styles)` holds. */
        when(node, test) {
            const check = () => test(stylesOf(store)) === true;
            setShown(node, check());
            refresher.track(check, (shown) => setShown(node, shown));
            return node;
        },
    };
}

const PANELS = {
    photo(kit) {
        const s = (styles) => styles.photo;
        return [
            section(
                'Fotky',
                kit.side('photo.source', 'Kdo doplní fotky', [
                    { value: 'client', label: 'Doplní klient' },
                    { value: 'self', label: 'Doplním sám' },
                ]),
            ),
            section('Zaoblení rohů', kit.number('photo.radius', 'generic', 'mm', 'Zaoblení rohů')),
            section(
                'Ohraničení',
                kit.toggle('photo.border.on', 'Zobrazit ohraničení'),
                kit.when(
                    h('div', { class: 'cal-sub' }, h('span', { class: 'lbl' }, 'Tloušťka'), kit.number('photo.border.width', 'photoBorder', 'mm', 'Tloušťka ohraničení'), h('span', { class: 'lbl' }, 'Barva'), kit.color('photo.border.color', 'Barva')),
                    (styles) => s(styles).border.on,
                ),
            ),
            section(
                'Stín',
                kit.toggle('photo.shadow.on', 'Zobrazit stín'),
                kit.when(
                    h(
                        'div',
                        { class: 'cal-sub' },
                        pair(
                            labelled('Rozostření', kit.number('photo.shadow.blur', 'generic', 'mm', 'Rozostření')),
                            labelled('Krytí', kit.number('photo.shadow.opacity', 'percent', '%', 'Krytí stínu')),
                            labelled('Posun X', kit.number('photo.shadow.dx', 'shadowOffset', 'mm', 'Posun X')),
                            labelled('Posun Y', kit.number('photo.shadow.dy', 'shadowOffset', 'mm', 'Posun Y')),
                        ),
                        h('span', { class: 'lbl' }, 'Barva stínu'),
                        kit.color('photo.shadow.color', 'Barva stínu'),
                    ),
                    (styles) => s(styles).shadow.on,
                ),
            ),
        ];
    },

    cap(kit) {
        return [
            kit.text('cap'),
            section('Zarovnání', h('div', { class: 'cal-al2' }, kit.align('cap.align'), kit.valign('cap.valign'))),
            section('Barva textu', kit.color('cap.color', 'Barva textu')),
            kit.fill('cap.bg'),
            kit.when(section('Odsazení od okraje pozadí', kit.number('cap.pad', 'pad', 'mm', 'Odsazení')), (styles) => fillIsOn(styles.cap.bg)),
        ];
    },

    logo(kit) {
        return [
            section(
                'Logo',
                kit.upload('logo.asset', { kind: 'logo', accept: 'image/svg+xml,image/png,image/jpeg', tip: 'Nahrát logo (SVG, PNG)', captionEmpty: 'Nahrajte logo (SVG, PNG)', captionSet: 'Nahrané logo' }),
                kit.when(
                    kit.side('logo.fit', 'Umístění loga', [
                        { value: 'contain', label: 'Přizpůsobit' },
                        { value: 'cover', label: 'Vyplnit' },
                    ]),
                    (styles) => Boolean(styles.logo.asset),
                ),
            ),
        ];
    },

    month(kit) {
        return [
            kit.text('month'),
            section('Zarovnání', kit.align('month.align')),
            section('Barva textu', kit.color('month.color', 'Barva textu')),
            section(null, kit.toggle('month.upper', 'Verzálky')),
            kit.fill('month.bg'),
            kit.when(section('Odsazení od okraje pozadí', kit.number('month.pad', 'pad', 'mm', 'Odsazení')), (styles) => fillIsOn(styles.month.bg)),
        ];
    },

    wk(kit) {
        return [
            kit.text('wk'),
            section('Zarovnání', kit.align('wk.align')),
            section('Barva', kit.color('wk.color', 'Barva')),
            section(
                'Tvar',
                kit.side('wk.form', 'Tvar', [
                    { value: 'a', label: '1. týden' },
                    { value: 'b', label: 'Týden 1' },
                    { value: 'c', label: 'T1' },
                ]),
            ),
        ];
    },

    head(kit) {
        return [
            kit.fill('head.bg', {
                image: {
                    tip: 'Nahrát výchozí obrázek',
                    captionEmpty: 'Prázdné místo — obrázek vloží klient, nebo nahrajte výchozí',
                    captionSet: 'Výchozí obrázek — u stran ho lze vyměnit',
                    fitLabel: 'Umístění obrázku',
                },
            }),
        ];
    },

    qr(kit) {
        return [
            section(
                null,
                kit.toggle('qr.perPage', 'Na každé straně jiný QR kód'),
                kit.when(note('QR kódy nahrajete v kroku Obsah › QR kódy, nebo u každé strany po vygenerování.'), (styles) => styles.qr.perPage),
                kit.when(
                    h(
                        'div',
                        { class: 'cal-sub' },
                        h('span', { class: 'lbl' }, 'QR kód'),
                        kit.upload('qr.asset', { kind: 'qr', accept: 'image/svg+xml,image/png,image/jpeg', tip: 'Nahrát QR kód (SVG, PNG)', captionEmpty: 'Nahrajte QR kód (SVG, PNG)', captionSet: 'Stejný na všech týdenních stranách' }),
                    ),
                    (styles) => !styles.qr.perPage,
                ),
            ),
        ];
    },

    bg(kit) {
        return [kit.fill('bg', { label: null, image: { captionSet: 'Výchozí fotka — u jednotlivých stran ji lze vyměnit' } })];
    },
};

const selectionKey = (selection) => (selection ? `${selection.el}|${selection.frame === undefined ? '' : selection.frame}|${selection.part || ''}` : '');

export function mountProps(root, ctx) {
    const { store } = ctx;
    const refresher = createRefresher();
    let kal = null;
    let position = null;
    let shown = null; // selection key the DOM was built for
    let seenLayout = null; // layout object the Kalendárium panel was mounted / last refreshed with

    function build() {
        if (kal) kal.destroy();
        kal = null;
        position = null;
        refresher.clear();

        const state = store.getState();
        const { design } = state.doc;
        const selection = design.layout ? state.ui.selection : null;
        shown = selectionKey(selection);
        seenLayout = design.layout;

        if (!selection) {
            root.replaceChildren(h('div', { class: 'cal-noprop' }, h('span', { class: 'cap' }, 'Vyberte prvek na stránce nebo v seznamu vlevo')));
            return;
        }
        const key = selection.el;
        if (key === 'cal') {
            const sub = h('div', { class: 'cal-props-kal', 'data-props': 'cal' });
            root.replaceChildren(sub);
            kal = mountKalProps(sub, ctx);
            return;
        }
        const builder = PANELS[key];
        if (!builder) {
            root.replaceChildren();
            return;
        }
        if (key === 'bg') {
            root.replaceChildren(panelRootFor(key, panelHead('Pozadí', 'Celá stránka až do krajů spadávky'), ...builder(createKit(ctx, refresher))));
            return;
        }
        const head = panelHead(elementLabel(key), ALL_PAGES);
        if (key === 'wk' && !design.features.weekNumbers) {
            root.replaceChildren(panelRootFor(key, head, note('Číslo týdne je vypnuté v kroku 1.')));
            return;
        }
        position = positionSection(ctx, key);
        root.replaceChildren(panelRootFor(key, head, position.el, ...builder(createKit(ctx, refresher))));
    }

    function panelRootFor(key, ...children) {
        const el = panelRoot(...children);
        el.dataset.props = key;
        return el;
    }

    function refresh() {
        if (position) position.refresh();
        refresher.refresh();
    }

    const unsubscribe = store.subscribe((event) => {
        const state = store.getState();
        const layout = state.doc.design.layout;
        if (event.type === 'ui') {
            if (event.keys.includes('selection') && selectionKey(layout ? state.ui.selection : null) !== shown) build();
            return;
        }
        if (event.type === 'design') {
            // week display switched in step 1, the type changed, a layout appeared / went away
            if (event.keys.includes('features') || event.keys.includes('type') || event.keys.includes('preset')) build();
            return;
        }
        if (event.type === 'undo') {
            if (kal) build();
            else refresh();
            return;
        }
        if (event.type === 'replace') {
            if (kal) {
                if (JSON.stringify(layout) !== JSON.stringify(seenLayout)) build();
                else seenLayout = layout;
            } else if (selectionKey(layout ? state.ui.selection : null) !== shown) build();
            else refresh();
            return;
        }
        if (event.type === 'layout') {
            if (kal) {
                if (!event.live) seenLayout = layout;
                return;
            }
            if (!layout || selectionKey(state.ui.selection) !== shown) build();
            else refresh();
        }
    });
    build();

    return {
        destroy() {
            unsubscribe();
            if (kal) kal.destroy();
            kal = null;
            refresher.clear();
            root.replaceChildren();
        },
    };
}
