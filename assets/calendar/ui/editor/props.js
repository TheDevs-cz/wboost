// Properties panel — STUB of phase P3, owned by P5a (architecture §D.6 "Properties").
//
// What the stub does: names the selected element and hands the Kalendárium to `mountKalProps`.
//
// Contract (final): `mountProps(root, ctx): { destroy() }`
//   - dispatches on `ui.selection` (`null` → the empty state; `{ el, frame?, part? }`)
//   - rebuilds on a `ui` event whose keys include `selection`, and on `replace` / `undo`; a value
//     change that came from the panel itself must NOT rebuild it (inputs keep focus)
//   - for `selection.el === 'cal'` it mounts `mountKalProps(sub, ctx)` (P5b) into a child element
//     and destroys it before the next rebuild
//   - `Pozice a velikost` inputs carry `data-field="x|y|w|h"`; every other control `data-prop="<path in styles>"`

import { elementLabel, partLabel } from '../../model/elements.js';
import { h } from '../dom.js';
import { stubNote } from '../stub.js';
import { mountKalProps } from './props-kal.js';

export function mountProps(root, ctx) {
    const { store } = ctx;
    let kal = null;

    function render() {
        if (kal) kal.destroy();
        kal = null;
        const selection = store.getState().ui.selection;
        if (!selection) {
            root.replaceChildren(h('div', { class: 'cal-panel-h' }, h('h3', null, 'Vlastnosti')), stubNote('P5a', 'Vyberte prvek na stránce nebo ve vrstvách'));
            return;
        }
        const title = selection.part ? `${elementLabel(selection.el)} › ${partLabel(selection.part)}` : elementLabel(selection.el);
        const body = h('div', { class: 'cal-props-b' });
        root.replaceChildren(h('div', { class: 'cal-panel-h' }, h('h3', { 'data-props-title': '' }, title)), body);
        if (selection.el === 'cal') kal = mountKalProps(body, ctx);
        else body.append(stubNote('P5a', `Vlastnosti prvku ${elementLabel(selection.el)}`));
    }

    const unsubscribe = store.subscribe((event) => {
        if ((event.type === 'ui' && event.keys.includes('selection')) || event.type === 'replace' || event.type === 'undo') render();
    });
    render();

    return {
        destroy() {
            unsubscribe();
            if (kal) kal.destroy();
            root.replaceChildren();
        },
    };
}
