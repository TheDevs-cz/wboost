// Properties of an own layer (feedback K20, docs/calendar/custom-layers-plan.md §4.2) — the right
// column of step 3 while `ui.selection.el` is `u:<id>`. Mounted by `props.js`, which owns the
// rebuild rules (once per selection; values flow in through `refresh()`).
//
// `layerPanel(ctx, key, refresher) → { el, refresh() }`
//
//   Název         [data-prop="layer.name"]          empty = the numbered fallback („Obdélník 2“)
//   Na stránkách  [data-prop="layer.pages"] (select: weeks | all | pick | here) — K20 P2: všechny
//                 týdenní strany (D2, the default) / všechny strany / vybrané strany / jen tato
//                 strana (= the page on the stage); „Vybrané“ opens the checklist of every page
//                 `[data-layer-page="<pageKey>"]`; a set with `except` says how many pages hide
//                 the layer + [data-cal-action="layer-except-clear"] „Zobrazit všude“; keys
//                 without a page this year are counted („2 vybrané strany v kalendáři nejsou“)
//   Pozice a velikost                               `positionSection` (never links: own layers
//                                                   are decoration — elements.js linkBoxKeys)
//   image:   Obrázek (upload `image` / [data-cal-action="layer-gallery"] Z galerie / Odebrat),
//            layer.fit (Přizpůsobit | Vyplnit), layer.radius
//   rect / ellipse: layer.fill.on · .color · .opacity, layer.stroke.on · .color · .width · .style,
//            layer.radius (rect only — „Zaoblený obdélník“ is a rect with a radius)
//   line:    layer.stroke.color · .width · .style · .cap
//   Vrstva:  layer.opacity (Krytí vrstvy), layer.blend (Prolnutí — the 16 PDF modes)
//   [data-cal-action="layer-duplicate"] · [data-cal-action="layer-delete"]
//
// Writing rule as everywhere in the editor: a discrete control = one `store.change` (one undo
// step), a slider = `beginChange` / `updateLive` / `commitChange` (one undo step per drag). Every
// write goes through `normalizeLayer`, so the stored item is always the normalised shape.

import { BLEND_MODES } from '../../model/effects.js';
import { KIND_LABELS, duplicateLayer, layerIdOf, layerLabel, missingPageKeys, newLayer, normalizeLayer, pageSetText, removeLayer, setLayer } from '../../model/layers.js';
import { stagePageOf } from '../../editor/stage-page.js';
import { newId } from '../../model/content.js';
import { bleedOf } from '../../model/defaults.js';
import { pageBounds } from '../../editor/geometry.js';
import { checkRow, h, icon, segmented, selectField, switchRow } from '../dom.js';
import { getPath, setPath } from '../controls/bind.js';
import { colorPalette, colorPicker } from '../controls/color.js';
import { limitField, sliderRow } from '../controls/number.js';
import { note, panelHead, panelRoot, section, setShown } from '../controls/panel.js';
import { positionSection } from '../controls/position.js';
import { uploadTile } from '../controls/upload.js';
import { hasGallery, pickGalleryAsset } from '../gallery-pick.js';

const STYLES = [
    { value: 'solid', label: 'Plná' },
    { value: 'dash', label: 'Čárkovaná' },
    { value: 'dot', label: 'Tečkovaná' },
];

/** The callbacks of one path inside `layout.layers[id]` — `styleBinding` for an own layer. */
function layerBinding(store, id, path) {
    let live = false;
    const layerOf = (layout) => (layout && layout.layers ? layout.layers[id] : null);
    const apply = (layout, value) => {
        const layer = layerOf(layout);
        if (!layer) return layout;
        const next = normalizeLayer(setPath(layer, path, value));
        return next ? setLayer(layout, id, next) : layout;
    };
    return {
        attrs: { 'data-prop': `layer.${path}` },
        get: () => getPath(layerOf(store.getState().doc.design.layout), path),
        onStart() {
            live = true;
            store.beginChange();
        },
        onChange(value) {
            if (live) store.updateLive((layout) => apply(layout, value));
            else store.change((layout) => apply(layout, value));
        },
        onCommit() {
            if (!live) return;
            live = false;
            store.commitChange();
        },
    };
}

export function layerPanel(ctx, key, refresher) {
    const { store, env } = ctx;
    const id = layerIdOf(key);
    const layout = () => store.getState().doc.design.layout;
    const layer = () => (layout() && layout().layers ? layout().layers[id] : null);
    const palette = colorPalette(env);
    const kind = layer().kind;

    const bound = (path, make) => {
        const b = layerBinding(store, id, path);
        const control = make(b);
        refresher.track(b.get, (value) => {
            if (value !== undefined) control.setValue(value);
        });
        return control;
    };
    const color = (path, label) => bound(path, (b) => colorPicker({ value: b.get(), palette, label, attrs: b.attrs, onChange: b.onChange }));
    const number = (path, limit, unit, label) => bound(path, (b) => limitField({ limit, unit, label, value: b.get(), attrs: b.attrs, onChange: b.onChange }));
    const slider = (path, label) => bound(path, (b) => sliderRow({ label, limit: 'percent', unit: '%', value: b.get(), attrs: b.attrs, onStart: b.onStart, onChange: b.onChange, onCommit: b.onCommit }));
    const seg = (path, label, options) => {
        const control = bound(path, (b) => segmented({ label, options, value: b.get(), full: true, attrs: b.attrs, onChange: b.onChange }));
        control.classList.add('cal-side');
        return control;
    };
    const toggle = (path, label) => {
        const b = layerBinding(store, id, path);
        const control = switchRow({ label, checked: b.get() === true, attrs: b.attrs, onChange: b.onChange });
        refresher.track(b.get, (value) => control.setChecked(value === true));
        return control;
    };
    const when = (node, test) => {
        const check = () => Boolean(layer() && test(layer()));
        setShown(node, check());
        refresher.track(check, (shown) => setShown(node, shown));
        return node;
    };

    // ---- name -------------------------------------------------------------------------------

    const nameInput = h('input', { type: 'text', class: 'cal-lname', maxlength: '60', 'data-prop': 'layer.name', 'aria-label': 'Název vrstvy', autocomplete: 'off' });
    const nameBox = h('span', { class: 'inp sm' }, nameInput);
    const fallbackName = () => layerLabel({ ...layout(), layers: { ...layout().layers, [id]: { ...layer(), name: '' } } }, key);
    const paintName = () => {
        if (document.activeElement === nameInput) return;
        nameInput.value = layer() ? layer().name : '';
        nameInput.placeholder = fallbackName();
    };
    const commitName = () => {
        const current = layer();
        if (!current) return;
        const next = normalizeLayer({ ...current, name: nameInput.value });
        if (next && next.name !== current.name) store.change((l) => setLayer(l, id, next));
        nameInput.value = next ? next.name : '';
    };
    nameInput.addEventListener('change', commitName);
    nameInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            nameInput.blur();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            nameInput.value = layer() ? layer().name : '';
            nameInput.blur();
        }
    });
    paintName();
    refresher.track(() => layer() && layer().name, paintName);

    // ---- Na stránkách (K20 P2) ------------------------------------------------------------------

    const pages = () => store.pages();
    const shownPage = () => stagePageOf(store.getState().doc, pages(), store.getState().ui);
    const writePages = (patch) => {
        const current = layer();
        if (!current) return;
        const next = normalizeLayer({ ...current, ...patch });
        if (next && JSON.stringify(next) !== JSON.stringify(current)) store.change((l) => setLayer(l, id, next));
    };
    // „Vybrané strany…“ chosen in this panel: a list of one page stays „Vybrané“ (with its checklist)
    // even when that page is the one on the stage — „Jen tato strana“ is the same data
    let picking = false;
    const modeOf = (l) => {
        if (!l) return 'weeks';
        if (!Array.isArray(l.pages)) return l.pages;
        if (picking) return 'pick';
        const shown = shownPage();
        return l.pages.length === 1 && shown && l.pages[0] === shown.key ? 'here' : 'pick';
    };
    const modeOptions = () => {
        const shown = shownPage();
        return [
            { value: 'weeks', label: 'Všechny týdenní strany' },
            { value: 'all', label: 'Všechny strany kalendáře' },
            { value: 'pick', label: 'Vybrané strany…' },
            { value: 'here', label: shown ? `Jen tato strana (${shown.title})` : 'Jen tato strana', disabled: !shown },
        ];
    };
    const modeSelect = selectField({
        label: 'Na stránkách',
        options: modeOptions(),
        value: modeOf(layer()),
        attrs: { 'data-prop': 'layer.pages' },
        onChange: (mode) => {
            const current = layer();
            const shown = shownPage();
            picking = mode === 'pick';
            if (mode === 'weeks' || mode === 'all') writePages({ pages: mode, except: Array.isArray(current.pages) ? [] : current.except });
            else if (mode === 'here') writePages({ pages: shown ? [shown.key] : [], except: [] });
            else writePages({ pages: Array.isArray(current.pages) ? current.pages : shown ? [shown.key] : [], except: [] });
            paintPages();
        },
    });
    const checklist = h('div', { class: 'cal-lpages', role: 'group', 'aria-label': 'Vybrané strany' });
    const setNote = h('p', { class: 'cap cal-note', 'data-layer-pages-note': '' });
    const clearExcept = h('button', { type: 'button', class: 'btn xs btn-ghost', 'data-cal-action': 'layer-except-clear', onClick: () => writePages({ except: [] }) }, icon('eye', 's14'), 'Zobrazit všude');
    const paintPages = () => {
        const current = layer();
        if (!current) return;
        modeSelect.setOptions(modeOptions(), modeOf(current));
        const list = Array.isArray(current.pages);
        checklist.hidden = !(list && modeOf(current) === 'pick');
        if (!checklist.hidden) {
            const scroll = checklist.scrollTop;
            checklist.replaceChildren(
                ...pages().map((page) => {
                    const row = checkRow({
                        label: page.kind === 'week' ? `${page.title} · ${page.subtitle}` : page.title,
                        checked: current.pages.includes(page.key),
                        attrs: { 'data-layer-page': page.key },
                        onChange: (on) => {
                            const now = layer();
                            if (!now || !Array.isArray(now.pages)) return;
                            writePages({ pages: on ? [...now.pages.filter((k) => k !== page.key), page.key] : now.pages.filter((k) => k !== page.key) });
                        },
                    });
                    return row;
                }),
            );
            checklist.scrollTop = scroll;
        }
        const missing = missingPageKeys(current, pages()).length;
        const hidden = list ? 0 : current.except.length;
        const parts = [];
        if (list && modeOf(current) === 'pick') parts.push(`Vybráno: ${pageSetText(current)}.`);
        if (missing) parts.push(missing === 1 ? '1 vybraná strana v kalendáři není.' : `${missing} ${missing <= 4 ? 'vybrané strany' : 'vybraných stran'} v kalendáři nejsou.`);
        if (hidden) parts.push(hidden === 1 ? 'Na 1 straně je skrytá (krok 4).' : `Na ${hidden} stranách je skrytá (krok 4).`);
        setNote.textContent = parts.join(' ');
        setNote.hidden = parts.length === 0;
        clearExcept.hidden = hidden === 0;
    };
    paintPages();
    refresher.track(() => `${JSON.stringify(layer() ? [layer().pages, layer().except] : null)}#${(shownPage() || {}).key}#${pages().map((p) => p.key).join(',')}`, paintPages);

    // ---- kind-specific ------------------------------------------------------------------------

    const sections = [];
    if (kind === 'image') {
        const tile = bound('asset', (b) => uploadTile({ value: b.get(), kind: 'image', ctx, attrs: b.attrs, onChange: b.onChange, tip: 'Nahrát obrázek (SVG, PNG, JPEG)', captionEmpty: 'Nahrajte obrázek (SVG, PNG, JPEG)', captionSet: 'Obrázek vrstvy', accept: 'image/svg+xml,image/png,image/jpeg,image/webp' }));
        const gallery = hasGallery()
            ? h(
                  'button',
                  {
                      type: 'button',
                      class: 'btn xs btn-ghost',
                      'data-cal-action': 'layer-gallery',
                      onClick: async () => {
                          const info = await pickGalleryAsset(ctx, 'image');
                          if (info) layerBinding(store, id, 'asset').onChange(info.id);
                      },
                  },
                  icon('image', 's14'),
                  'Z galerie',
              )
            : null;
        sections.push(
            section('Obrázek', tile, gallery),
            section(
                'Umístění',
                seg('fit', 'Umístění obrázku', [
                    { value: 'contain', label: 'Přizpůsobit' },
                    { value: 'cover', label: 'Vyplnit' },
                ]),
            ),
            section('Zaoblení rohů', number('radius', 'layerRadius', 'mm', 'Zaoblení rohů')),
        );
    } else if (kind === 'line') {
        sections.push(
            section('Barva', color('stroke.color', 'Barva čáry')),
            section('Tloušťka', number('stroke.width', 'layerStroke', 'pt', 'Tloušťka čáry')),
            section('Styl', seg('stroke.style', 'Styl čáry', STYLES)),
            section(
                'Zakončení',
                seg('stroke.cap', 'Zakončení čáry', [
                    { value: 'butt', label: 'Rovné' },
                    { value: 'round', label: 'Zaoblené' },
                ]),
            ),
        );
    } else {
        sections.push(
            section('Výplň', toggle('fill.on', 'Vyplnit barvou'), when(h('div', { class: 'cal-sub' }, color('fill.color', 'Barva výplně'), slider('fill.opacity', 'Krytí')), (l) => l.fill.on)),
            section(
                'Obrys',
                toggle('stroke.on', 'Zobrazit obrys'),
                when(
                    h('div', { class: 'cal-sub' }, color('stroke.color', 'Barva obrysu'), h('span', { class: 'lbl' }, 'Tloušťka'), number('stroke.width', 'layerStroke', 'pt', 'Tloušťka obrysu'), seg('stroke.style', 'Styl obrysu', STYLES)),
                    (l) => l.stroke.on,
                ),
            ),
        );
        if (kind === 'rect') sections.push(section('Zaoblení rohů', number('radius', 'layerRadius', 'mm', 'Zaoblení rohů')));
    }

    // ---- the whole layer ---------------------------------------------------------------------

    const blend = bound('blend', (b) => selectField({ label: 'Prolnutí', options: BLEND_MODES.map((m) => ({ value: m.value, label: m.label })), value: b.get(), attrs: b.attrs, onChange: b.onChange }));
    sections.push(section('Vrstva', slider('opacity', 'Krytí vrstvy'), h('span', { class: 'lbl' }, 'Prolnutí'), blend));

    const duplicate = () => {
        const d = store.getState().doc.design;
        const next = newId();
        store.change((l) => duplicateLayer(l, id, next, pageBounds(d.page, bleedOf(d.print))));
        store.setUi({ selection: { el: `u:${next}` } });
    };
    const remove = () => {
        store.change((l) => removeLayer(l, id));
        ctx.toast('Vrstva smazána — Ctrl+Z ji vrátí');
    };
    sections.push(
        h(
            'div',
            { class: 'cal-psec cal-lacts' },
            h('button', { type: 'button', class: 'btn sm btn-ghost', 'data-cal-action': 'layer-duplicate', onClick: duplicate }, icon('copy', 's14'), 'Duplikovat'),
            h('button', { type: 'button', class: 'btn sm btn-ghost cal-ldel', 'data-cal-action': 'layer-delete', onClick: remove }, icon('trash', 's14'), 'Smazat vrstvu'),
        ),
    );

    const position = positionSection(ctx, key);
    const head = panelHead(layerLabel(layout(), key), `${KIND_LABELS[kind]} · ${pageSetText(layer(), (shownPage() || {}).key)}`);
    const sub = head.querySelector('.cap');
    refresher.track(
        () => (layer() ? `${KIND_LABELS[kind]} · ${pageSetText(layer(), (shownPage() || {}).key)}` : ''),
        (text) => {
            if (text && sub) sub.textContent = text;
        },
    );
    const title = head.querySelector('[data-props-title]');
    refresher.track(
        () => (layer() ? layerLabel(layout(), key) : ''),
        (text) => {
            if (text && title) title.textContent = text;
        },
    );
    const el = panelRoot(
        head,
        section('Název', nameBox),
        section('Na stránkách', modeSelect, checklist, setNote, clearExcept),
        position.el,
        ...sections,
        note('Vlastní vrstva je součást vzhledu: klient ji vidí, ale nemění.'),
    );
    el.dataset.props = key;
    return { el, refresh: () => position.refresh() };
}

/** A fresh layer of a menu entry (`rounded` = a rectangle with a 3 mm radius). */
export function layerOfMenuEntry(entry, colour, extra = {}) {
    if (entry === 'rounded') return newLayer('rect', colour, { radius: 3, ...extra });
    return newLayer(entry, colour, extra);
}
