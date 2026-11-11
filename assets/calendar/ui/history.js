// Historie (handoff §4.1, §4.7, §8 "Roles, saving and history"; map §8.3, §8.12–8.13) — phase P7b.
//
// Contract: `openHistory({ name, load }): { close() }`
//   name  calendar name — the title reads `Historie — {name}` (`Historie změn` without a name)
//   load  `() => Promise<Entry[]>`, `Entry = { who: 'Grafik' | 'Klient', what: string, at: ISO string }`,
//         newest first (the server sends at most 200). The workspace passes `() => ctx.api.history()`;
//         the list page passes `() => fetchHistory(card.historyUrl)` (`state/api.js`) — this module
//         needs no `Ctx`, so it works on the list page, where no store exists.
// The dialog carries `data-cal-dialog="history"`. Rows are `li.cal-hrow[data-who="Grafik|Klient"]`
// (author pill, sentence, time) under one `h3.cal-hday` per calendar day. When both authors appear,
// a filter `[data-history-filter]` (Vše / Grafik / Klient) narrows the list — the designer's
// question is usually "what did the client change".

import { h, openModal, relativeTime, segmented } from './dom.js';

const DAY_MS = 86400000;

const startOfDay = (time) => {
    const d = new Date(time);
    d.setHours(0, 0, 0, 0);
    return d.getTime();
};

/** `Dnes` / `Včera` / `12. března 2027`. */
export function dayLabel(iso, now = Date.now()) {
    const then = new Date(iso).getTime();
    if (!Number.isFinite(then)) return '';
    const days = Math.round((startOfDay(now) - startOfDay(then)) / DAY_MS);
    if (days === 0) return 'Dnes';
    if (days === 1) return 'Včera';
    return new Date(then).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' });
}

/** Within the last hour the relative form of map §8.1, otherwise the time of day. */
function timeLabel(iso, now = Date.now()) {
    const then = new Date(iso).getTime();
    if (!Number.isFinite(then)) return '';
    if (now - then < 3600000) return relativeTime(iso, now);
    return new Date(then).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
}

function rows(entries) {
    const out = [];
    let day = null;
    let list = null;
    for (const entry of entries) {
        const label = dayLabel(entry.at);
        if (label !== day) {
            day = label;
            list = h('ul', { class: 'cal-hrows' });
            out.push(h('section', { class: 'cal-hsec' }, h('h3', { class: 'cal-hday lbl' }, label), list));
        }
        const client = entry.who === 'Klient';
        list.append(
            h(
                'li',
                { class: 'cal-hrow', 'data-who': client ? 'Klient' : 'Grafik' },
                h('span', { class: `pill${client ? ' pch' : ''}` }, client ? 'Klient' : 'Grafik'),
                h('span', { class: 'cal-hwhat' }, entry.what),
                h('time', { class: 'cap', datetime: entry.at, title: new Date(entry.at).toLocaleString('cs-CZ') }, timeLabel(entry.at)),
            ),
        );
    }
    return out;
}

export function openHistory({ name, load }) {
    const list = h('div', { class: 'cal-hist-rows' });
    const body = h('div', { class: 'cal-hist-list', 'aria-busy': 'true' }, h('p', { class: 'cal-empty-note' }, 'Načítám…'));
    const modal = openModal({ title: name ? `Historie — ${name}` : 'Historie změn', body, size: 'lg' });
    modal.el.dataset.calDialog = 'history';

    Promise.resolve()
        .then(load)
        .then((entries) => {
            body.removeAttribute('aria-busy');
            if (!Array.isArray(entries) || !entries.length) {
                body.replaceChildren(h('p', { class: 'cal-empty-note' }, 'Zatím žádné změny.'));
                return;
            }
            const counts = { Grafik: 0, Klient: 0 };
            for (const entry of entries) counts[entry.who === 'Klient' ? 'Klient' : 'Grafik'] += 1;
            const show = (who) => {
                const shown = who === 'all' ? entries : entries.filter((entry) => (entry.who === 'Klient' ? 'Klient' : 'Grafik') === who);
                list.replaceChildren(...rows(shown));
                body.dataset.historyShown = String(shown.length);
            };
            let filter = null;
            if (counts.Grafik && counts.Klient) {
                filter = h(
                    'div',
                    { class: 'cal-hfilter', 'data-history-filter': '' },
                    segmented({
                        label: 'Kdo změnu udělal',
                        value: 'all',
                        options: [
                            { value: 'all', label: `Vše · ${entries.length}` },
                            { value: 'Grafik', label: `Grafik · ${counts.Grafik}` },
                            { value: 'Klient', label: `Klient · ${counts.Klient}` },
                        ],
                        onChange: show,
                    }),
                );
            }
            body.replaceChildren(...[filter, list].filter(Boolean));
            show('all');
        })
        .catch((error) => {
            body.removeAttribute('aria-busy');
            body.replaceChildren(h('p', { class: 'cal-empty-note err' }, (error && error.message) || 'Historii se nepodařilo načíst'));
        });
    return { close: () => modal.close() };
}
