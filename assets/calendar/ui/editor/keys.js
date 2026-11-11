// Editor keyboard (architecture §D.6, handoff §5.3, §5.10, §8) — owner P4.
//
// `mountKeys(ctx): { destroy() }` — one `keydown` listener on `window` while the editor is mounted:
//   arrows            move the selected element by `K.NUDGE` mm, with Shift by `K.NUDGE_BIG` mm; one
//                     undo step per key press; never links, never snaps; nothing while a
//                     Kalendárium part or Pozadí is selected
//   Esc               the open Nahrát layout menu → the part → the element → nothing
//   Ctrl/⌘+Z          undo;  Ctrl/⌘+Shift+Z and Ctrl/⌘+Y  redo
// Everything is ignored while focus is in `input, select, textarea, [contenteditable]` or a dialog
// is open — there the keys belong to the field or the dialog. Ctrl+S is NOT handled here: the
// workspace controller owns it.

import { K, bleedOf } from '../../model/defaults.js';
import { nudge } from '../../editor/drag.js';
import { selectedBoxKey } from './overlay.js';

const ARROWS = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };

function typing(target) {
    return Boolean(target && target.closest && target.closest('input, select, textarea, [contenteditable=""], [contenteditable="true"], [role="slider"], [role="menu"], [role="listbox"]'));
}

const dialogOpen = () => Boolean(document.querySelector('.modal.show, dialog[open]'));

export function mountKeys(ctx) {
    const { store } = ctx;

    function onKeyDown(event) {
        if (event.defaultPrevented || typing(event.target) || dialogOpen()) return;
        const state = store.getState();
        const { design } = state.doc;
        if (!design.layout) return;
        const key = event.key;
        const mod = event.ctrlKey || event.metaKey;

        if (mod && !event.altKey && (key === 'z' || key === 'Z' || key === 'y' || key === 'Y')) {
            event.preventDefault();
            if ((key === 'z' || key === 'Z') && !event.shiftKey) store.undo();
            else store.redo();
            return;
        }
        if (mod || event.altKey) return;

        if (key === 'Escape') {
            const selection = state.ui.selection;
            if (state.ui.layoutMenuOpen) store.setUi({ layoutMenuOpen: false });
            else if (selection && selection.part) store.setUi({ selection: selection.frame === undefined ? { el: selection.el } : { el: selection.el, frame: selection.frame } });
            else if (selection) store.setUi({ selection: null });
            else return;
            event.preventDefault();
            return;
        }

        const arrow = ARROWS[key];
        if (!arrow) return;
        const selection = state.ui.selection;
        if (!selection || selection.part) return;
        const boxKey = selectedBoxKey(design, selection);
        if (!boxKey) return;
        // the page must not scroll under the element being moved
        event.preventDefault();
        const step = event.shiftKey ? K.NUDGE_BIG : K.NUDGE;
        store.change((layout) => ({ ...layout, boxes: nudge(layout.boxes, boxKey, arrow[0] * step, arrow[1] * step, design.page, bleedOf(design.print)) }));
    }

    window.addEventListener('keydown', onKeyDown);
    return {
        destroy() {
            window.removeEventListener('keydown', onKeyDown);
        },
    };
}
