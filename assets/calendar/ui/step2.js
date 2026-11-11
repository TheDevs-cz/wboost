// Step 2 — Rozvržení (handoff §4.4, map §8.6, architecture §D.7, §E.8).
//
// 7 days: two cards with a live preview of the first week. The active card shows the current
// layout; the other card shows what a click gives — the default layout while nothing exists yet,
// the current layout mirrored once one does. 14 days: no choice — the single two-sided layout is
// applied on entering the step and shown on one card with Pokračovat.

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
const TWO_SIDED = { preset: 'twoSided', title: 'Dvoustranné rozvržení', text: 'První týden vlevo, fotografie uprostřed, druhý týden vpravo' };

/**
 * @param root  the step slot
 * @param ctx   `Ctx`
 * @returns `{ destroy() }`
 */
export function mountStep2(root, ctx) {
    const { store, env } = ctx;
    const design = () => store.getState().doc.design;

    /** The document a card previews. */
    function previewDoc(preset) {
        const doc = store.getState().doc;
        const d = doc.design;
        if (d.layout && d.preset === preset) return doc;
        const layout = d.layout && d.type === 'week'
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

    function build() {
        for (const thumb of thumbs) thumb.destroy();
        thumbs = [];
        cards = [];
        const fortnight = design().type === 'fortnight';
        builtFor = design().type;

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
            const button = h('button', { type: 'button', class: 'cal-layc', role: 'radio', 'data-preset': spec.preset, onClick: () => store.choosePreset(spec.preset) }, preview, body);
            cards.push({ spec, button });
            return button;
        };

        const list = fortnight
            ? h('div', { class: 'cal-lays one' }, card(TWO_SIDED, false))
            : h('div', { class: 'cal-lays', role: 'radiogroup', 'aria-label': 'Rozvržení stránky' }, ...CARDS.map((spec) => card(spec, true)));
        root.replaceChildren(h('div', { class: 'cal-s2' }, h('div', { class: 'cal-s2c' }, list)));
        sync();
    }

    function sync() {
        const active = design().layout ? design().preset : null;
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
        if (design().type !== builtFor) {
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
            root.replaceChildren();
        },
    };
}
