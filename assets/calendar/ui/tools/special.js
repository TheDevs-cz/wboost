// Speciální stránky — the right-hand panel of step 4 while `ui.contentView === 'special'`
// (handoff §4.6, map §8.11) — phase P6a.
//
// The four generated kinds (checkbox, layout thumbnails, position, Roční kalendárium this / next
// year, preview) and the uploaded pages (rename, replace the file, delete), reordered by dragging.
//
// Contract: `mountSpecialPanel(root, ctx): { destroy() }`, called by `ui/step4.js`. Everything is
// content (`specialPages`, never undoable); the feature is switched with
// `store.setFeature('specialPages', true)`. The page shown in the middle of step 4 is
// `ui.toolState.specialPreview` (a special page id) — `previewedSpecial(ctx)` resolves it.
//
// Also exported for the plus menu of the page list (`ui/pagelist.js`):
//   `specialEntries(ctx)`            every special page in order: `[{ id, item, label, generated }]`
//   `switchOnSpecial(ctx, id)`       a generated kind: feature on, page on, shown as the preview
//   `addGraphicPage(ctx, file)`      Nahrát grafiku — a finished page as PDF or picture
//   `addTextPage(ctx, file)`         Text z Wordu — `.docx` / `.txt`, typeset in the calendar style
//
// DOM hooks: `[data-special="<id>"]` (+ class `on` / `sel`), `[data-special-on]` (native checkbox —
// click its label), `[data-special-preview]`, `[data-special-position] [data-value]`,
// `[data-special-year] [data-value]`, `[data-special-layout="<n>"]`, `[data-special-name]`,
// `[data-special-file]`, `[data-special-delete]`, `.cal-grip` (drag handle),
// `[data-cal-action="feature-on"]`.

import Sortable from 'sortablejs';
import { newId, reorder } from '../../model/content.js';
import { SPECIAL_KIND_LABELS, orderedSpecialPages, specialPageName } from '../../generate/pages.js';
import { SPECIAL_LAYOUTS } from '../../render/special.js';
import { checkRow, debounce, h, icon, segmented } from '../dom.js';
import { renderThumb } from '../thumb.js';

export const GRAPHIC_ACCEPT = 'image/*,.pdf,application/pdf';
export const TEXT_ACCEPT = '.docx,.txt,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain';
const NAME_MAX = 60;
const FILE_NAME_MAX = 120;

const isGenerated = (item) => item.kind !== 'image' && item.kind !== 'text';
const isTextFile = (file) => /\.(docx|txt)$/i.test(file.name) || file.type === 'text/plain';
const baseName = (fileName) => String(fileName).replace(/\.[^.]+$/, '').trim().slice(0, NAME_MAX) || 'Vlastní strana';

export function specialEntries(ctx) {
    return orderedSpecialPages(ctx.store.getState().doc.content).map(([id, item]) => ({ id, item, label: specialPageName(item), generated: isGenerated(item) }));
}

/** The special page shown in the middle of step 4: the chosen one while it is on, else the first one that is on. */
export function previewedSpecial(ctx) {
    const state = ctx.store.getState();
    if (!state.doc.design.features.specialPages) return null;
    const on = specialEntries(ctx).filter((entry) => entry.item.on);
    const wanted = state.ui.toolState && state.ui.toolState.specialPreview;
    return on.find((entry) => entry.id === wanted) || on[0] || null;
}

function showSpecial(ctx, id) {
    const { store } = ctx;
    const ui = store.getState().ui;
    store.setUi({ contentView: 'special', toolState: { ...(ui.toolState || {}), specialPreview: id } });
}

function ensureFeature(ctx) {
    if (!ctx.store.getState().doc.design.features.specialPages) ctx.store.setFeature('specialPages', true);
}

/** Position of a new page: after everything that exists, the four generated defaults included. */
function nextPosition(ctx) {
    return specialEntries(ctx).reduce((max, entry) => Math.max(max, Number(entry.item.pos) || 0), -1) + 1;
}

export function switchOnSpecial(ctx, id) {
    const entry = specialEntries(ctx).find((e) => e.id === id);
    if (!entry) return;
    ensureFeature(ctx);
    if (!entry.item.on) ctx.store.putItem('specialPages', id, { ...entry.item, on: true }, entry.label);
    showSpecial(ctx, id);
}

/** Reads an uploaded file into the fields of a special page item; `null` after an error toast. */
async function readPageFile(ctx, file) {
    const fileName = String(file.name).slice(0, FILE_NAME_MAX);
    try {
        if (isTextFile(file)) {
            const answer = await ctx.api.documentText(file);
            return { kind: 'text', asset: null, text: String(answer.text || ''), fileName };
        }
        const info = await ctx.api.upload(file, 'page');
        ctx.store.addAsset(info);
        return { kind: 'image', asset: info.id, text: null, fileName };
    } catch (error) {
        ctx.toast((error && error.message) || 'Soubor se nepodařilo nahrát', true);
        return null;
    }
}

async function addUploadedPage(ctx, file) {
    const fields = await readPageFile(ctx, file);
    if (!fields) return null;
    const id = newId();
    const name = baseName(file.name);
    ensureFeature(ctx);
    ctx.store.putItem('specialPages', id, { on: true, layout: 0, position: 'front', nextYear: false, name, pos: nextPosition(ctx), ...fields }, name);
    showSpecial(ctx, id);
    return id;
}

export function addGraphicPage(ctx, file) {
    if (isTextFile(file)) {
        ctx.toast('Nahrajte stránku jako PDF nebo obrázek.', true);
        return Promise.resolve(null);
    }
    return addUploadedPage(ctx, file);
}

export function addTextPage(ctx, file) {
    if (!isTextFile(file)) {
        ctx.toast('Nahrajte dokument ve formátu DOCX nebo TXT.', true);
        return Promise.resolve(null);
    }
    return addUploadedPage(ctx, file);
}

export function mountSpecialPanel(root, ctx) {
    const { store } = ctx;
    const body = h('div', { class: 'cal-spb' });
    root.replaceChildren(h('div', { class: 'cal-p4b' }, h('div', { class: 'cal-p4h' }, h('b', null, 'Speciální stránky')), body));

    let sortable = null;
    let cards = [];
    let signature = null;
    let renaming = false;

    const state = () => store.getState();
    const featureOn = () => state().doc.design.features.specialPages === true;
    const itemOf = (id) => specialEntries(ctx).find((entry) => entry.id === id) || null;

    /** Writes a changed item; the label names the page in Historie. */
    function write(id, patch, select = true) {
        const entry = itemOf(id);
        if (!entry) return;
        const next = { ...entry.item, ...patch };
        store.putItem('specialPages', id, next, specialPageName(next));
        if (select && next.on) showSpecial(ctx, id);
    }

    /** What decides the structure of the list (values inside a card are refreshed in place). */
    const signatureOf = () => {
        const year = state().doc.design.year;
        return `${featureOn()}#${year}#${specialEntries(ctx)
            .map((e) => `${e.id}:${e.item.kind}:${e.item.on ? 1 : 0}`)
            .join(',')}`;
    };

    function layoutThumb(entry, layout) {
        const { doc } = state();
        const item = { ...entry.item, on: true, layout };
        const page = { index: 0, kind: 'special', key: `sp:${entry.id}`, title: entry.label, subtitle: 'Speciální strana', special: { id: entry.id, item } };
        try {
            return renderThumb(doc, page, ctx, { dayContext: store.dayContext(), holes: false });
        } catch (error) {
            console.error('[calendar] special page thumbnail failed', error);
            return '';
        }
    }

    function buildCard(entry) {
        const { id, item, generated } = entry;
        const year = state().doc.design.year;
        const parts = { id, generated };

        parts.check = checkRow({ label: entry.label, checked: item.on, strong: true, attrs: { 'data-special-on': '' }, onChange: (on) => write(id, { on }) });
        parts.title = parts.check.querySelector('.cal-check-t');
        parts.preview = h(
            'button',
            { type: 'button', class: 'btn xs btn-ghost', 'data-special-preview': '', 'aria-pressed': 'false', onClick: () => showSpecial(ctx, id) },
            icon('eye', 's14'),
            'Náhled',
        );
        const head = h('div', { class: 'cal-sph' }, h('span', { class: 'cal-grip', title: 'Přetáhněte pro změnu pořadí', 'aria-hidden': 'true' }, icon('grip', 's14')), parts.check, h('span', { class: 'cal-spa' }, item.on ? parts.preview : null));
        const children = [head];

        if (item.on) {
            parts.position = segmented({
                options: [
                    { value: 'front', label: 'Za titulní stranou' },
                    { value: 'back', label: 'Před zadní stranou' },
                ],
                value: item.position === 'back' ? 'back' : 'front',
                label: 'Umístění',
                attrs: { 'data-special-position': '' },
                onChange: (position) => write(id, { position }),
            });
            const options = [parts.position];
            if (item.kind === 'year') {
                parts.year = segmented({
                    options: [
                        { value: '0', label: `Rok ${year}` },
                        { value: '1', label: `Následující rok ${year + 1}` },
                    ],
                    value: item.nextYear ? '1' : '0',
                    label: 'Rok',
                    attrs: { 'data-special-year': '' },
                    onChange: (value) => write(id, { nextYear: value === '1' }),
                });
                options.push(parts.year);
            }
            children.push(h('div', { class: 'cal-spo' }, ...options));

            const names = SPECIAL_LAYOUTS[item.kind] || [];
            if (names.length) {
                parts.layouts = names.map((name, layout) => {
                    const picture = h('span', { class: 'cal-splp' });
                    const button = h('button', { type: 'button', role: 'radio', class: 'cal-splc', 'aria-checked': 'false', 'data-special-layout': String(layout), onClick: () => write(id, { layout }) }, picture, h('span', { class: 'cap' }, name));
                    return { layout, button, picture };
                });
                children.push(h('span', { class: 'lbl' }, 'Rozvržení stránky'), h('div', { class: 'cal-spl', role: 'radiogroup', 'aria-label': `Rozvržení: ${entry.label}` }, ...parts.layouts.map((l) => l.button)));
            }
        }
        if (!generated) {
            // rename / replace the file / delete stay at hand even while the page is switched off
            parts.name = h('input', {
                type: 'text',
                maxlength: String(NAME_MAX),
                autocomplete: 'off',
                'aria-label': 'Název stránky',
                'data-special-name': '',
                onInput: () => {
                    renaming = true;
                    write(id, { name: parts.name.value }, false);
                    renaming = false;
                },
                onKeydown: (event) => {
                    if (event.key === 'Enter') parts.name.blur();
                },
                onBlur: () => {
                    // an uploaded page never stays nameless
                    const current = itemOf(id);
                    if (current && current.item.name.trim() === '') write(id, { name: baseName(current.item.fileName || '') }, false);
                },
            });
            parts.file = h('input', { type: 'file', class: 'sr', accept: `${GRAPHIC_ACCEPT},${TEXT_ACCEPT}`, 'data-special-file': '' });
            parts.fileLabel = h('label', { class: 'btn xs btn-ghost cal-spfile' }, parts.file, icon('upload', 's14'), 'Vyměnit soubor');
            parts.file.addEventListener('change', async () => {
                const file = parts.file.files[0];
                parts.file.value = '';
                if (!file) return;
                parts.fileLabel.classList.add('busy');
                const fields = await readPageFile(ctx, file);
                parts.fileLabel.classList.remove('busy');
                if (fields) write(id, fields, false);
            });
            parts.remove = h(
                'button',
                {
                    type: 'button',
                    class: 'btn icon xs btn-ghost',
                    'aria-label': 'Smazat stránku',
                    title: 'Smazat stránku',
                    'data-special-delete': '',
                    onClick: () => {
                        const current = itemOf(id);
                        const name = current ? current.label : entry.label;
                        store.removeItem('specialPages', id, name);
                        ctx.toast(`Stránka „${name}“ smazána`);
                    },
                },
                icon('trash', 's16'),
            );
            children.splice(1, 0, h('div', { class: 'cal-spown' }, h('span', { class: 'inp sm' }, parts.name), parts.fileLabel, parts.remove));
        }
        parts.el = h('section', { class: 'cal-sp', 'data-special': id }, ...children);
        return parts;
    }

    function syncCard(card) {
        const entry = itemOf(card.id);
        if (!entry) return;
        const { item } = entry;
        const selected = previewedSpecial(ctx);
        const isSelected = Boolean(selected && selected.id === card.id && item.on);
        card.el.classList.toggle('on', item.on);
        card.el.classList.toggle('sel', isSelected);
        card.check.setChecked(item.on);
        if (card.title) card.title.textContent = entry.label;
        card.preview.setAttribute('aria-pressed', String(isSelected));
        if (card.position) card.position.setValue(item.position === 'back' ? 'back' : 'front');
        if (card.year) card.year.setValue(item.nextYear ? '1' : '0');
        if (card.name && document.activeElement !== card.name) card.name.value = item.name;
        for (const l of card.layouts || []) {
            const on = (Number(item.layout) || 0) === l.layout;
            l.button.classList.toggle('on', on);
            l.button.setAttribute('aria-checked', String(on));
        }
    }

    function paintThumbs() {
        for (const card of cards) {
            const entry = itemOf(card.id);
            if (!entry) continue;
            for (const l of card.layouts || []) l.picture.innerHTML = layoutThumb(entry, l.layout);
        }
    }
    const paintThumbsLater = debounce(paintThumbs, 150);

    /** Where the keyboard focus is inside the list, so a rebuild can put it back. */
    function focusMark() {
        const active = document.activeElement;
        const card = active && body.contains(active) ? active.closest('[data-special]') : null;
        if (!card) return null;
        const hook = [...active.attributes].find((a) => a.name.startsWith('data-special-'));
        return hook ? `[data-special="${card.dataset.special}"] [${hook.name}="${hook.value}"]` : null;
    }

    function build() {
        const mark = focusMark();
        signature = signatureOf();
        if (sortable) sortable.destroy();
        sortable = null;
        cards = [];
        if (!featureOn()) {
            body.replaceChildren(
                h('p', { class: 'cap' }, 'Speciální stránky jsou vypnuté.'),
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'btn sm btn-tertiary cal-spon',
                        'data-cal-action': 'feature-on',
                        onClick: () => {
                            store.setFeature('specialPages', true);
                            ctx.toast('Zapnuto');
                        },
                    },
                    icon('plus', 's16'),
                    'Zapnout',
                ),
            );
            return;
        }
        const { width, height } = state().doc.design.page;
        body.style.setProperty('--cal-page-ratio', String(width / height));
        cards = specialEntries(ctx).map(buildCard);
        const list = h('div', { class: 'cal-sps' }, ...cards.map((card) => card.el));
        body.replaceChildren(h('p', { class: 'cap' }, 'Zaškrtnuté stránky se vygenerují automaticky. Pořadí změníte přetažením.'), list);
        cards.forEach(syncCard);
        paintThumbs();
        const again = mark ? body.querySelector(mark) : null;
        if (again) again.focus({ preventScroll: true });
        sortable = Sortable.create(list, {
            handle: '.cal-grip',
            draggable: '.cal-sp',
            animation: 120,
            // the HTML5 drag ghost cannot be driven by synthetic pointers and looks wrong on a dark theme
            forceFallback: true,
            fallbackClass: 'cal-sp-ghost',
            chosenClass: 'drag',
            onEnd: () => {
                const ids = [...list.querySelectorAll('[data-special]')].map((el) => el.dataset.special);
                const all = Object.fromEntries(specialEntries(ctx).map((entry) => [entry.id, entry.item]));
                const changed = reorder(all, ids);
                const ops = Object.entries(changed).map(([id, item]) => ({ c: 'specialPages', id, v: item, label: specialPageName(item) }));
                if (ops.length) store.applyOps(ops);
            },
        });
    }

    build();

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status' || (event.type === 'layout' && event.live)) return;
        if (event.type === 'ui' && !event.keys.includes('toolState')) return;
        if (signatureOf() !== signature) {
            build();
            return;
        }
        if (event.type === 'ui') {
            cards.forEach(syncCard);
            return;
        }
        const order = specialEntries(ctx).map((entry) => entry.id);
        if (order.join(',') !== cards.map((card) => card.id).join(',')) {
            // the order changed elsewhere (a save answer): rebuild in the new order
            build();
            return;
        }
        cards.forEach(syncCard);
        if (!renaming) paintThumbsLater();
    });

    return {
        destroy() {
            unsubscribe();
            paintThumbsLater.cancel();
            if (sortable) sortable.destroy();
            root.replaceChildren();
        },
    };
}

/** Label of a generated kind (`Úvodní slovo`, …). */
export const specialKindLabel = (kind) => SPECIAL_KIND_LABELS[kind] || 'Vlastní strana';
