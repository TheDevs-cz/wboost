// Content tools: registry, toolbar and the modal every tool opens in (handoff §4.6–4.7, map §8.10).
//
// Each tool file exports `mount<Name>Tool(root, ctx): { destroy() }` (see the header of any
// `ui/tools/*.js`). This module owns everything around it: the toolbar buttons, the modal with
// **Hotovo**, the "feature is off" notice with **Zapnout**, and `ui.tool`.

import { h, icon, openModal } from '../dom.js';
import { mountPhotosTool } from './photos.js';
import { mountWasteTool } from './waste.js';
import { mountEventsTool } from './events.js';
import { mountNamesTool } from './names.js';
import { mountMoonTool } from './moon.js';
import { mountShiftsTool } from './shifts.js';
import { mountQrTool } from './qr.js';

/** `feature`: the step-1 switch the tool depends on (`null` = always available). */
export const TOOLS = Object.freeze([
    { key: 'photos', label: 'Fotky a popisky', icon: 'image', feature: null, clientAllowed: true },
    { key: 'waste', label: 'Odpady', icon: 'trash', feature: 'waste', clientAllowed: true },
    { key: 'events', label: 'Akce a spolky', icon: 'star', feature: 'events', clientAllowed: true },
    { key: 'names', label: 'Jména navíc', icon: 'users', feature: null, clientAllowed: true },
    { key: 'moon', label: 'Fáze měsíce', icon: 'moon', feature: 'moon', clientAllowed: false },
    { key: 'shifts', label: 'Šichtovnice', icon: 'list', feature: 'shifts', clientAllowed: false },
    { key: 'qr', label: 'QR kódy', icon: 'grid', feature: 'qr', clientAllowed: false },
]);

const MOUNT = {
    photos: mountPhotosTool,
    waste: mountWasteTool,
    events: mountEventsTool,
    names: mountNamesTool,
    moon: mountMoonTool,
    shifts: mountShiftsTool,
    qr: mountQrTool,
};

const featureOn = (ctx, tool) => tool.feature === null || ctx.store.getState().doc.design.features[tool.feature] === true;

/** Tools the current role may see: a client gets the four client tools whose feature is on. */
export function visibleTools(ctx) {
    if (ctx.env.mode === 'designer') return TOOLS.slice();
    return TOOLS.filter((tool) => tool.clientAllowed && featureOn(ctx, tool));
}

/**
 * Opens a tool in its modal. One tool at a time; `ui.tool` holds its key while open.
 *
 * @returns `{ close() }` or `null` (unknown key, or a tool the role may not open)
 */
export function openTool(key, ctx) {
    const tool = visibleTools(ctx).find((t) => t.key === key);
    if (!tool) return null;
    const { store } = ctx;
    const body = h('div', { class: 'cal-tool', 'data-tool-body': tool.key });
    let mounted = null;
    let shownOn = null;

    const render = () => {
        const on = featureOn(ctx, tool);
        if (on === shownOn) return;
        shownOn = on;
        if (mounted) mounted.destroy();
        mounted = null;
        if (on) {
            mounted = MOUNT[tool.key](body, ctx);
            return;
        }
        body.replaceChildren(
            h(
                'div',
                { class: 'cal-tool-off' },
                h('span', { class: 'ico-c sm' }, icon(tool.icon)),
                h('p', null, `${tool.label} jsou vypnuté v kroku 1, v kalendáři se nezobrazí.`),
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'btn sm btn-tertiary',
                        'data-cal-action': 'feature-on',
                        onClick: () => {
                            store.setFeature(tool.feature, true);
                            ctx.toast('Zapnuto');
                        },
                    },
                    'Zapnout',
                ),
            ),
        );
    };

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'design' || event.type === 'replace') render();
    });
    const modal = openModal({
        title: tool.label,
        body,
        size: 'xl',
        onClose: () => {
            unsubscribe();
            if (mounted) mounted.destroy();
            mounted = null;
            if (store.getState().ui.tool === tool.key) store.setUi({ tool: null });
        },
    });
    modal.el.classList.add('cal-tool-modal');
    modal.el.dataset.toolModal = tool.key;
    store.setUi({ tool: tool.key });
    render();
    return { close: () => modal.close() };
}

/**
 * The tool buttons of steps 4 and 5: `button[data-tool="<key>"]` inside
 * `div.cal-tools[role=toolbar]`. A tool whose feature is off is dimmed (designer) or absent (client).
 *
 * @param root  element the toolbar is appended to (the step owns the rest of that row: warning
 *              chip, Odkaz pro klienta, Historie)
 * @param ctx   `Ctx`
 * @param opts  `{ step: 4 | 5 }`
 * @returns `{ el, destroy() }`
 */
export function mountToolbar(root, ctx, opts = {}) {
    const el = h('div', { class: 'cal-tools', role: 'toolbar', 'aria-label': 'Obsah kalendáře', 'data-step': String(opts.step || '') });
    root.append(el);

    const render = () => {
        el.replaceChildren(
            ...visibleTools(ctx).map((tool) => {
                const on = featureOn(ctx, tool);
                const title = on ? tool.label : `${tool.label} · vypnuto`;
                return h('button', { type: 'button', class: `cal-tool-b${on ? '' : ' off'}`, 'data-tool': tool.key, title, onClick: () => openTool(tool.key, ctx) }, icon(tool.icon, 's16'), h('span', null, tool.label));
            }),
        );
    };
    render();
    const unsubscribe = ctx.store.subscribe((event) => {
        if ((event.type === 'design' && event.keys.includes('features')) || event.type === 'replace') render();
    });

    return {
        el,
        destroy() {
            unsubscribe();
            el.remove();
        },
    };
}
