// Vrstvy — STUB of phase P3, owned by P4 (architecture §D.6 "Vrstvy").
//
// What the stub does: a plain list of the element rows (`[data-layer="<element>"]`, top-most
// first, then Pozadí); a click toggles the selection. No reordering, eyes, parts or layout menu.
//
// Contract P4 keeps: `mountLayers(root, ctx, opts: { embedded: boolean }): { destroy() }`.

import { ELEMENTS, elementLabel } from '../../model/elements.js';
import { h, icon } from '../dom.js';

export function mountLayers(root, ctx, opts = {}) {
    void opts;
    const { store } = ctx;
    const list = h('div', { class: 'cal-els', role: 'listbox', 'aria-label': 'Vrstvy' });
    root.replaceChildren(h('div', { class: 'cal-panel-h' }, h('h3', null, 'Vrstvy')), list);

    function render() {
        const state = store.getState();
        const { design } = state.doc;
        if (!design.layout) return;
        const selected = state.ui.selection ? state.ui.selection.el : null;
        const keys = [...design.layout.order.filter((key) => key !== 'qr' || design.features.qr), 'bg'];
        list.replaceChildren(
            ...keys.map((key) => {
                const spec = ELEMENTS.find((e) => e.key === key);
                return h(
                    'button',
                    { type: 'button', class: `cal-elr${key === selected ? ' sel' : ''}`, role: 'option', 'aria-selected': String(key === selected), 'data-layer': key, onClick: () => store.setUi({ selection: key === selected ? null : { el: key } }) },
                    icon(spec ? spec.icon : 'layers', 's16'),
                    h('span', { class: 'cal-eln' }, elementLabel(key)),
                );
            }),
        );
    }

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status' || (event.type === 'layout' && event.live)) return;
        render();
    });
    render();

    return {
        destroy() {
            unsubscribe();
            root.replaceChildren();
        },
    };
}
