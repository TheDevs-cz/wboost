// Placeholder used by the stub modules of phase P3. A later phase replaces the BODY of its own
// files and stops calling this; the file can be deleted once no stub is left.

import { h } from './dom.js';

/**
 * @param owner  the phase that replaces the stub (`'P4'`, `'P6b'`, …)
 * @param title  what will be here
 * @returns `div.cal-stub[data-cal-stub="<owner>"]` — e2e scripts detect an unfinished module by it
 */
export function stubNote(owner, title) {
    return h('div', { class: 'cal-stub', 'data-cal-stub': owner }, h('b', null, title), h('span', { class: 'cap' }, 'Tuto část právě stavíme.'));
}
