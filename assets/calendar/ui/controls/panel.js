// Layout pieces of a property panel (right panel of the editor). Owner: P5a; reused by P5b.
// CSS: calendar-controls.css. A panel is
//
//     div.cal-props
//       panelHead('Měsíc', 'Platí pro všechny strany kalendáře')
//       section('Pozice a velikost', pair(labelled('X', field), …))
//       section('Barva textu', colorPicker(…))
//       …
//
// Nothing here knows the store; the helpers only build DOM.

import { h, icon } from '../dom.js';

/** `div.cal-props` — the padded column every panel lives in. */
export function panelRoot(...children) {
    return h('div', { class: 'cal-props' }, ...children);
}

/**
 * Panel header: name + caption (map §8.8 / §8.9).
 *
 * @param title    element or part name
 * @param caption  `Platí pro všechny strany kalendáře` · `Kalendárium · platí pro všechny dny`
 * @param o.back   optional `{ label, title, onClick }` → a back arrow before the name (part panels)
 */
export function panelHead(title, caption, o = {}) {
    return h(
        'div',
        { class: 'cal-pph' },
        o.back ? h('button', { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': o.back.label, title: o.back.title || o.back.label, onClick: o.back.onClick }, icon('arrow-left', 's16')) : null,
        h('b', { 'data-props-title': '' }, title),
        caption ? h('span', { class: 'cap' }, caption) : null,
    );
}

/** One block of a panel: an optional small label and its controls stacked under it. */
export function section(label, ...children) {
    return h('div', { class: 'cal-psec' }, label ? h('span', { class: 'lbl' }, label) : null, ...children);
}

/** Two columns of equal width (X | Y, Rozostření | Krytí, cut | size). */
export function pair(...children) {
    return h('div', { class: 'cal-2' }, ...children);
}

/** A control with a tiny caption above it (`X`, `Posun Y`). */
export function labelled(text, control) {
    return h('span', { class: 'cal-ml' }, h('span', null, text), control);
}

/** Explanatory caption line. */
export function note(text) {
    return h('p', { class: 'cap cal-note' }, text);
}

/** Shows / hides a node without removing it (conditional sub-sections keep their state). */
export function setShown(node, shown) {
    node.hidden = !shown;
}
