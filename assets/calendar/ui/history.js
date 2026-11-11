// Historie (handoff §4.1, §4.7; map §8.3, §8.12) — basic version of phase P3, owned by P7b.
//
// Contract (final): `openHistory({ name, load }): { close() }`
//   name  calendar name — the title reads `Historie — {name}`
//   load  `() => Promise<Entry[]>`, `Entry = { who: 'Grafik' | 'Klient', what: string, at: ISO string }`,
//         newest first. The workspace passes `() => ctx.api.history()`; the list page passes
//         `() => fetchHistory(card.historyUrl)` (`state/api.js`) — this module needs no `Ctx`, so it
//         works on the list page, where no store exists.
// The dialog carries `data-cal-dialog="history"`; rows are `li.cal-hrow`.

import { h, openModal, relativeTime } from './dom.js';

export function openHistory({ name, load }) {
    const body = h('div', { class: 'cal-hist-list', 'aria-busy': 'true' }, h('p', { class: 'cal-empty-note' }, 'Načítám…'));
    const modal = openModal({ title: `Historie — ${name}`, body, size: 'lg' });
    modal.el.dataset.calDialog = 'history';
    Promise.resolve()
        .then(load)
        .then((entries) => {
            body.removeAttribute('aria-busy');
            if (!entries.length) {
                body.replaceChildren(h('p', { class: 'cal-empty-note' }, 'Zatím žádné změny.'));
                return;
            }
            body.replaceChildren(
                h(
                    'ul',
                    { class: 'cal-hrows' },
                    ...entries.map((entry) =>
                        h(
                            'li',
                            { class: 'cal-hrow' },
                            h('span', { class: `pill${entry.who === 'Klient' ? ' pch' : ''}` }, entry.who),
                            h('span', { class: 'cal-hwhat' }, entry.what),
                            h('time', { class: 'cap', datetime: entry.at, title: new Date(entry.at).toLocaleString('cs-CZ') }, relativeTime(entry.at)),
                        ),
                    ),
                ),
            );
        })
        .catch((error) => {
            body.removeAttribute('aria-busy');
            body.replaceChildren(h('p', { class: 'cal-empty-note err' }, (error && error.message) || 'Historii se nepodařilo načíst'));
        });
    return { close: () => modal.close() };
}
