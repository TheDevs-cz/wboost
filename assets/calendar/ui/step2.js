// Step 2 — Rozvržení (handoff §4.4, map §8.6, architecture §D.7, §E.8).
//
// 7 days: two cards with a live preview of the first week. The active card shows the current
// layout; the other card shows what a click gives — the default layout while nothing exists yet,
// the current layout mirrored once one does. 14 days: no choice — the single two-sided layout is
// applied on entering the step and shown on one card with Pokračovat (two radios — the layout and
// „Naskenovaný design“ — once its layout came from a scan).
//
// AI scan (ux.md §6, `ui/scan.js`): under the cards — of a 7-day and of a 14-day calendar — sits the action card
// „Naskenovat design“ — or its panel — drawn by `ctx.scan` into `.cal-scan`. When the calendar's
// layout came from a scan (`store.getState().layoutOrigin === 'scan'`) a third REAL radio
// `[data-preset="scan"]` „Naskenovaný design“ is shown selected with the current layout; the two
// preset cards then preview — and a click on them applies — the preset's DEFAULT layout (never a
// mirror of the scanned one), with the toast „Rozvržení nahrazeno výchozím“ · Vrátit.

import { bleedOf } from '../model/defaults.js';
import { defaultLayout, mirrorLayout } from '../model/layouts.js';
import { normalizeLayout } from '../model/normalize.js';
import { buildPages, weekPages } from '../generate/pages.js';
import { debounce, h, icon } from './dom.js';
import { mountLazyThumb, renderThumb } from './thumb.js';

const CARDS = [
    { preset: 'photoLeft', title: 'Fotka vlevo', text: 'Fotografie vlevo, dny v týdnu vpravo' },
    { preset: 'photoRight', title: 'Fotka vpravo', text: 'Dny v týdnu vlevo, fotografie vpravo' },
];
const SCANNED = { preset: 'scan', title: 'Naskenovaný design', text: 'Rozvržení převzaté z vašeho kalendáře' };
const TWO_SIDED = { preset: 'twoSided', title: 'Dvoustranné rozvržení', text: 'První týden vlevo, fotografie uprostřed, druhý týden vpravo' };

/**
 * @param root  the step slot
 * @param ctx   `Ctx`
 * @returns `{ destroy() }`
 */
export function mountStep2(root, ctx) {
    const { store, env } = ctx;
    const design = () => store.getState().doc.design;
    const scanned = () => store.getState().layoutOrigin === 'scan' && Boolean(design().layout);

    /** The document a card previews. */
    function previewDoc(preset) {
        const doc = store.getState().doc;
        const d = doc.design;
        if (preset === 'scan') return doc;
        if (d.layout && d.preset === preset && !scanned()) return doc;
        const layout = d.layout && d.type === 'week' && !scanned()
            ? mirrorLayout(d.layout, d.page)
            : normalizeLayout(defaultLayout(preset, d.page, bleedOf(d.print), env), d.type, d.page, env);
        return { ...doc, design: { ...d, preset, layout } };
    }

    function previewMarkup(preset) {
        const doc = previewDoc(preset);
        const page = weekPages(buildPages(doc))[0];
        return page ? renderThumb(doc, page, ctx, { placeholders: 'all' }) : '';
    }

    let thumbs = [];
    let builtFor = null;
    let cards = [];
    let scanMount = null;

    /** A preset card: over a scanned layout it replaces the layout and says so. */
    function choose(preset) {
        if (preset === 'scan') {
            store.setStep(3);
            return;
        }
        const replaced = scanned();
        store.choosePreset(preset);
        if (replaced) ctx.toast('Rozvržení nahrazeno výchozím', false, { label: 'Vrátit', onClick: () => store.undo() });
    }

    function build() {
        for (const thumb of thumbs) thumb.destroy();
        thumbs = [];
        cards = [];
        const fortnight = design().type === 'fortnight';
        builtFor = `${design().type}:${scanned()}`;
        if (scanMount) scanMount.destroy();
        scanMount = null;

        const card = (spec, interactive) => {
            const preview = h('span', { class: 'cal-layp' });
            thumbs.push(mountLazyThumb(preview, () => previewMarkup(spec.preset)));
            const body = h('span', { class: 'cal-type-b' }, h('b', null, spec.title), h('span', { class: 'cap' }, spec.text));
            if (!interactive) {
                return h(
                    'div',
                    { class: 'cal-layc static on', 'data-preset': spec.preset },
                    preview,
                    h('span', { class: 'cal-layf' }, body, h('button', { type: 'button', class: 'btn sm btn-tertiary', 'data-cal-action': 'continue', onClick: () => store.setStep(3) }, 'Pokračovat', icon('arrow-right', 's16'))),
                );
            }
            const button = h('button', { type: 'button', class: 'cal-layc', role: 'radio', 'data-preset': spec.preset, onClick: () => choose(spec.preset) }, preview, body);
            cards.push({ spec, button });
            return button;
        };

        // 14 days: the one layout on a static card — until the layout came from a scan: then it is a
        // choice between the scanned design and the default two-sided layout, as for 7 days
        const list = fortnight && !scanned()
            ? h('div', { class: 'cal-lays one' }, card(TWO_SIDED, false))
            : h('div', { class: 'cal-lays', role: 'radiogroup', 'aria-label': 'Rozvržení stránky' }, ...[...(fortnight ? [TWO_SIDED] : CARDS), ...(scanned() ? [SCANNED] : [])].map((spec) => card(spec, true)));
        // „Naskenovat design“: the action card or the panel
        const scanSlot = ctx.scan ? h('div', { class: 'cal-scan' }) : null;
        root.replaceChildren(h('div', { class: 'cal-s2' }, h('div', { class: 'cal-s2c' }, list, scanSlot)));
        if (scanSlot) scanMount = ctx.scan.mount(scanSlot);
        sync();
    }

    function sync() {
        const active = !design().layout ? null : scanned() ? 'scan' : design().preset;
        for (const { spec, button } of cards) {
            const on = spec.preset === active;
            button.classList.toggle('on', on);
            button.setAttribute('aria-checked', String(on));
        }
    }

    const refresh = debounce(() => {
        for (const thumb of thumbs) thumb.refresh();
    }, 150);

    // 14 days: the single layout is applied without a choice and without leaving the step
    if (design().type === 'fortnight' && !design().layout) store.choosePreset('twoSided', { advance: false });
    build();

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'ui' || event.type === 'status' || (event.type === 'layout' && event.live)) return;
        if (`${design().type}:${scanned()}` !== builtFor) {
            if (design().type === 'fortnight' && !design().layout) store.choosePreset('twoSided', { advance: false });
            build();
            return;
        }
        sync();
        refresh();
    });

    return {
        destroy() {
            unsubscribe();
            refresh.cancel();
            for (const thumb of thumbs) thumb.destroy();
            if (scanMount) scanMount.destroy();
            root.replaceChildren();
        },
    };
}
