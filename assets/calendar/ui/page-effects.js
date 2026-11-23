// „Efekty … jen pro tuto stranu“ — a page's own layer effects (K2 P2; model/effects.js
// `pageEffectsOf` / `effectsFor`, content collection `pageEffects`, designer only).
//
// Contract: `mountPageEffects(root, ctx, { page() }): { destroy(), has() }` — mounted once per shown page
// by `ui/pagepanel.js` (step 4), which re-attaches `root` on every rebuild of its sections.
//
// One block per picture of the page that can carry effects (`targetsOf`): the week photo or the
// page's special photo, the logo, the header and the background picture. A block shows either
//   „Podle stylu · N efektů“ + „Upravit jen pro tuto stranu“  — the page follows the style; the button
//                                                               copies the style's list into the page
//   „Jen pro tuto stranu“ + the effects editor + „Zpět na styl“ — the page's own list REPLACES the
//                                                               style's (decision 2026-11-24)
// Edits are content items (`putItem` per change, never undoable, saved item-wise, listed in Historie);
// „Vrátit na styl“ of the page panel and the „Upraveno“ pill cover them (page-keyed collection).
//
// DOM hooks: `[data-page-fx="photo|logo|head|bg"]`, `[data-page-fx-own="<target>"]`,
// `[data-page-fx-reset="<target>"]`; inside the editor `[data-prop="pageEffects.<target>.<i>.<key>"]`.

import { effectsAt, effectsFor, pageEffectsItem, pageEffectsOf, styleEffectPath } from '../model/effects.js';
import { isElementVisible } from '../model/elements.js';
import { specialPhotoBox } from '../render/special.js';
import { colorPalette } from './controls/color.js';
import { effectsEditor } from './controls/effects.js';
import { h, icon } from './dom.js';

const LABELS = { photo: 'Fotografie', logo: 'Logo', head: 'Obrázek v hlavičce', bg: 'Fotka pozadí' };

const plural = (n) => (n === 1 ? '1 efekt' : n >= 2 && n <= 4 ? `${n} efekty` : `${n} efektů`);

/** The pictures of `page` that can carry effects (drawn on that page). */
export function targetsOf(doc, page) {
    const layout = doc.design.layout;
    if (!page || !layout) return [];
    const styles = layout.styles;
    const week = page.kind === 'week';
    const out = [];
    if (week ? true : Boolean(specialPhotoBox(doc, page))) out.push('photo');
    if (styles.logo.asset && (week ? isElementVisible(doc.design, 'logo') : page.kind === 'cover' || page.kind === 'back')) out.push('logo');
    if (styles.head.bg.imageOn && (week || page.kind === 'special')) out.push('head');
    if (styles.bg.imageOn) out.push('bg');
    return out;
}

export function mountPageEffects(root, ctx, opts) {
    const { store, env } = ctx;
    const palette = colorPalette(env);
    const body = h('div', { class: 'cal-pfx' });
    root.replaceChildren(body);

    let shape = null;
    let editors = [];

    const doc = () => store.getState().doc;
    const page = () => opts.page();
    const ownOf = (target) => {
        const p = page();
        return p ? pageEffectsOf(doc().content, p.key)[target] : null;
    };

    function write(target, list) {
        const p = page();
        if (!p) return;
        const item = pageEffectsItem(doc().content, p.key, target, list);
        if (item) store.putItem('pageEffects', p.key, item, p.title);
        else if (doc().content.pageEffects[p.key]) store.removeItem('pageEffects', p.key, p.title);
    }

    const shapeOf = () => {
        const p = page();
        if (!p || env.mode !== 'designer') return '';
        return `${p.key}|${targetsOf(doc(), p)
            .map((t) => `${t}:${ownOf(t) ? 1 : 0}`)
            .join(',')}`;
    };

    function build() {
        shape = shapeOf();
        editors = [];
        const p = page();
        if (!shape || !p) {
            body.replaceChildren();
            return;
        }
        const blocks = targetsOf(doc(), p).map((target) => {
            const own = ownOf(target);
            const head = h('span', { class: 'lbl' }, `Efekty — ${LABELS[target]}`);
            if (!own) {
                const style = effectsAt(doc().design.layout.styles, styleEffectPath(p, target));
                const on = style.filter((e) => e.on).length;
                return h(
                    'div',
                    { class: 'cal-p4s', 'data-page-fx': target },
                    head,
                    h('span', { class: 'cap' }, on ? `Podle stylu · ${plural(on)}` : 'Podle stylu · bez efektů'),
                    h(
                        'button',
                        {
                            type: 'button',
                            class: 'btn xs btn-ghost cal-pfxb',
                            'data-page-fx-own': target,
                            // copy-on-edit: the page starts from what it shows now
                            onClick: () => write(target, effectsFor(doc(), p, target)),
                        },
                        icon('edit', 's14'),
                        'Upravit jen pro tuto stranu',
                    ),
                );
            }
            const editor = effectsEditor({ value: own, palette, path: `pageEffects.${target}`, onChange: (list) => write(target, list) });
            editors.push({ target, editor });
            return h(
                'div',
                { class: 'cal-p4s', 'data-page-fx': target },
                head,
                h('span', { class: 'cap' }, 'Jen pro tuto stranu — styl z kroku 3 tu neplatí.'),
                editor,
                h('button', { type: 'button', class: 'btn xs btn-ghost cal-pfxb', 'data-page-fx-reset': target, onClick: () => write(target, null) }, icon('undo', 's14'), 'Zpět na styl'),
            );
        });
        body.replaceChildren(...blocks);
    }

    function sync() {
        if (shapeOf() !== shape) {
            build();
            return;
        }
        for (const { target, editor } of editors) {
            const own = ownOf(target);
            if (own) editor.setValue(own);
        }
    }

    build();
    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status' || event.type === 'ui' || (event.type === 'layout' && event.live)) return;
        sync();
    });

    return {
        has: () => Boolean(shape),
        destroy() {
            unsubscribe();
            root.replaceChildren();
        },
    };
}
