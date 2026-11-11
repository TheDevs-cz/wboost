// Content tool „Akce a spolky“ (handoff §4.6, map §8.11, architecture §B.6 `clubs` / `events`,
// §E.11). Phase P6b.
//
// Contract: `ui/tools/index.js` owns the modal and the feature-off notice and calls
// `mountEventsTool(root, ctx)` with the modal body as `root`. Edits are `store.putItem` /
// `removeItem` on `clubs` and `events` (history label of an event = its date). An event refers to
// its organiser by club **id**, so renaming a code needs no propagation; removing a club clears
// the reference of its events (they print without a code). An event with an empty name is kept in
// the list but never printed. View state (`ui.toolState.events`): `{ filter, view, ym }` —
// `filter` is `'*'` (all), a club id, or `''` (events without an organiser).
//
// DOM hooks: `[data-ev-filter]` (select), `[data-club-add]`, `[data-club-code]`, `[data-club-name]`,
// `[data-club-remove]`, `[data-ev-view] button[data-value="m|y"]`, `[data-month]`,
// `[data-event-row="<id>"]` with `[data-event-club]`, `[data-event-date]`, `[data-event-name]`,
// `[data-event-icon]` (file input, only when the style prints an own icon per event),
// `[data-event-remove]`; `[data-event-add]`; `[data-ev-empty]`.

import { iso } from '../../generate/dates.js';
import { dateLimits, newId, nextPos, sorted } from '../../model/content.js';
import { h, icon, segmented, selectField } from '../dom.js';
import { addButton, clampYm, czDate, dateField, keyedList, monthNav, readToolState, removeButton, textField, writeToolState, ymOf, ymPrefix } from './datepicker.js';

const NOUN = ['akce', 'akce', 'akcí'];

export function mountEventsTool(root, ctx) {
    const { store } = ctx;
    const content = () => store.getState().doc.content;
    const year = () => store.getState().doc.design.year;
    const limits = () => dateLimits(year());
    const clubs = () => sorted(content().clubs || {}, 'pos');
    const events = () => sorted(content().events || {}, 'date');
    const eventOf = (id) => (content().events || {})[id] || null;
    const clubOf = (id) => (id ? (content().clubs || {})[id] || null : null);
    const perEventIcon = () => {
        const layout = store.getState().doc.design.layout;
        return Boolean(layout && layout.styles.icons && layout.styles.icons.ev && layout.styles.icons.ev.perEvent);
    };
    const eventColor = () => {
        const layout = store.getState().doc.design.layout;
        return layout ? layout.styles.ev.color : null;
    };

    const saved = readToolState(ctx, 'events');
    const view = { filter: saved.filter !== undefined ? saved.filter : '*', view: saved.view === 'y' ? 'y' : 'm', ym: saved.ym !== undefined ? saved.ym : year() * 12 };
    const remember = (patch) => {
        Object.assign(view, patch);
        writeToolState(ctx, 'events', patch);
    };

    /** `club` of an event as the filter sees it: a live club id or '' (none / deleted club). */
    const organiser = (event) => (event.club && clubOf(event.club) ? event.club : '');
    const matches = (event) => view.filter === '*' || organiser(event) === view.filter;
    const clubLabel = (club) => `${club.code ? `${club.code} — ` : ''}${club.name || 'Bez názvu'}`;
    const clubOptions = () => [{ value: '', label: '—' }, ...clubs().map(([id, club]) => ({ value: id, label: club.code || club.name ? `${club.code}${club.code && club.name ? ' — ' : ''}${club.name}` : 'Bez názvu' }))];

    function putEvent(id, patch) {
        const event = eventOf(id);
        if (!event) return;
        const next = { ...event, ...patch };
        store.putItem('events', id, next, czDate(next.date));
    }

    // ---- organisers ------------------------------------------------------------------------

    const filter = selectField({ options: [], value: view.filter, label: 'Spolek nebo pořadatel', attrs: { 'data-ev-filter': '' }, onChange: (value) => setFilter(value) });
    const addClub = h('button', { type: 'button', class: 'btn icon sm ghost', title: 'Přidat spolek', 'aria-label': 'Přidat spolek', 'data-club-add': '', onClick: () => newClub() }, icon('plus', 's16'));
    const clubEditor = h('div', { class: 'cal-cled-host' });
    let editor = null; // { id, code, name, remove }

    function setFilter(value) {
        remember({ filter: value });
        render();
    }

    function newClub() {
        const all = content().clubs || {};
        const id = newId();
        store.putItem('clubs', id, { code: `S${Object.keys(all).length + 1}`, name: '', pos: nextPos(all) });
        setFilter(id);
        if (editor) editor.name.input.focus();
    }

    function removeClub(id) {
        const ops = [{ c: 'clubs', id, v: null }];
        for (const [eventId, event] of events()) {
            if (event.club === id) ops.push({ c: 'events', id: eventId, v: { ...event, club: null }, label: czDate(event.date) });
        }
        remember({ filter: '*' });
        store.applyOps(ops);
        render();
    }

    function renderClubEditor() {
        const club = view.filter !== '*' && view.filter !== '' ? clubOf(view.filter) : null;
        if (!club) {
            editor = null;
            clubEditor.replaceChildren();
            return;
        }
        const id = view.filter;
        if (!editor || editor.id !== id) {
            const put = (patch) => {
                const current = clubOf(id);
                if (current) store.putItem('clubs', id, { ...current, ...patch });
            };
            editor = {
                id,
                code: textField({ value: club.code, placeholder: 'Zkr.', label: 'Zkratka', maxLength: 6, cls: 'cal-ab', attrs: { 'data-club-code': '' }, onInput: (value) => put({ code: value }) }),
                name: textField({ value: club.name, placeholder: 'Název spolku', maxLength: 60, attrs: { 'data-club-name': '' }, onInput: (value) => put({ name: value }) }),
                remove: removeButton(club.name || 'spolek', () => removeClub(id), { 'data-club-remove': '' }),
            };
            clubEditor.replaceChildren(h('div', { class: 'cal-cled' }, editor.code, editor.name, editor.remove));
        }
        editor.code.setValue(club.code);
        editor.name.setValue(club.name);
        editor.remove.setWhat(club.name || 'spolek');
    }

    // ---- events ----------------------------------------------------------------------------

    const heading = h('h3');
    const viewSeg = segmented({
        options: [
            { value: 'm', label: 'Měsíc' },
            { value: 'y', label: 'Celý rok' },
        ],
        value: view.view,
        label: 'Zobrazení',
        attrs: { 'data-ev-view': '' },
        onChange: (value) => {
            remember({ view: value });
            render();
        },
    });
    const nav = monthNav({
        year: year(),
        limits: limits(),
        ym: clampYm(view.ym, limits()),
        noun: NOUN,
        color: eventColor(),
        counts: (ym) => {
            const prefix = ymPrefix(ym);
            return events().filter(([, event]) => matches(event) && event.date.startsWith(prefix)).length;
        },
        onChange: (ym) => {
            remember({ ym });
            render();
        },
    });
    view.ym = nav.ym();
    const listEl = h('div', { class: 'cal-evl2' });
    const empty = h('p', { class: 'cap', 'data-ev-empty': '' });
    let clubsSignature = '';

    function iconTile(id) {
        const input = h('input', { type: 'file', class: 'sr', accept: 'image/svg+xml,image/png,image/jpeg', 'aria-label': 'Nahrát ikonu akce', 'data-event-icon': '' });
        const face = h('span', { class: 'cal-evico-f' });
        const clear = h('button', { type: 'button', class: 'cal-evico-x', 'aria-label': 'Odebrat ikonu akce', title: 'Odebrat ikonu', hidden: true, onClick: () => putEvent(id, { icon: null }) }, icon('x', 's12'));
        const label = h('label', { class: 'cal-icp sm cal-evico', title: 'Nahrát ikonu akce' }, input, face);
        input.addEventListener('change', async () => {
            const file = input.files && input.files[0];
            input.value = '';
            if (!file) return;
            label.classList.add('busy');
            try {
                const info = await ctx.api.upload(file, 'icon');
                store.addAsset(info);
                putEvent(id, { icon: info.id });
            } catch (error) {
                ctx.toast((error && error.message) || 'Soubor se nepodařilo nahrát', true);
            } finally {
                label.classList.remove('busy');
            }
        });
        const el = h('span', { class: 'cal-evico-w' }, label, clear);
        let shown;
        el.update = (event) => {
            if (event.icon === shown) return;
            shown = event.icon;
            const asset = event.icon ? ctx.env.assets[event.icon] : null;
            face.replaceChildren(asset ? h('img', { src: asset.previewUrl || asset.url, alt: '' }) : icon('upload', 's16'));
            clear.hidden = !asset;
        };
        return el;
    }

    const list = keyedList(listEl, (id, event) => {
        const club = selectField({ options: clubOptions(), value: organiser(event), label: 'Pořadatel', attrs: { 'data-event-club': '' }, onChange: (value) => putEvent(id, { club: value || null }) });
        club.classList.add('cal-evclub');
        const date = dateField({
            value: event.date,
            ...limits(),
            attrs: { 'data-event-date': '' },
            onChange: (value) => {
                // the row follows its event into the new month instead of vanishing from the list
                if (view.view === 'm' && ymOf(value) !== view.ym) {
                    remember({ ym: ymOf(value) });
                    nav.set(view.ym);
                }
                putEvent(id, { date: value });
            },
        });
        date.classList.add('cal-evd');
        const tile = perEventIcon() ? iconTile(id) : null;
        const name = textField({ value: event.name, placeholder: 'Název akce', maxLength: 80, cls: 'cal-evn', attrs: { 'data-event-name': '' }, onInput: (value) => putEvent(id, { name: value }) });
        const remove = removeButton(event.name || 'akci', () => store.removeItem('events', id, czDate((eventOf(id) || event).date)), { 'data-event-remove': '' });
        const row = h('div', { class: `cal-evx${tile ? ' ico' : ''}`, 'data-event-row': id }, club, date, tile, name, remove);
        let optionsOf = clubsSignature;
        row.update = (next) => {
            if (optionsOf !== clubsSignature) {
                optionsOf = clubsSignature;
                club.setOptions(clubOptions(), organiser(next));
            } else {
                club.setValue(organiser(next));
            }
            const known = clubOf(next.club);
            club.title = known ? known.name || known.code : 'Bez pořadatele';
            date.setValue(next.date);
            name.setValue(next.name);
            remove.setWhat(next.name || 'akci');
            if (tile) tile.update(next);
        };
        return row;
    });
    listEl.addEventListener('focusout', () => {
        // put the order right once the caret has left the list (rows never move under a focused field)
        setTimeout(() => {
            if (listEl.isConnected && !listEl.contains(document.activeElement)) renderList();
        }, 0);
    });

    function addEvent() {
        const id = newId();
        const date = view.view === 'm' ? `${ymPrefix(view.ym)}-01` : iso(year(), 1, 1);
        const { min, max } = limits();
        const club = view.filter !== '*' && view.filter !== '' && clubOf(view.filter) ? view.filter : null;
        store.putItem('events', id, { date: date < min ? min : date > max ? max : date, name: '', club, icon: null }, czDate(date));
        const row = list.get(id);
        if (row) row.querySelector('[data-event-name]').focus();
    }

    function renderList() {
        const prefix = ymPrefix(view.ym);
        const shown = events().filter(([, event]) => matches(event) && (view.view === 'y' || event.date.startsWith(prefix)));
        list.sync(shown);
        empty.hidden = shown.length > 0;
        empty.textContent = view.view === 'm' ? 'V tomto měsíci žádné akce.' : 'Zatím žádné akce.';
    }

    function render() {
        const allClubs = clubs();
        if (view.filter !== '*' && view.filter !== '' && !clubOf(view.filter)) view.filter = '*';
        const all = events();
        const count = (key) => all.filter(([, event]) => key === '*' || organiser(event) === key).length;
        filter.setOptions(
            [
                { value: '*', label: `Všechny akce (${count('*')})` },
                ...allClubs.map(([id, club]) => ({ value: id, label: `${clubLabel(club)} (${count(id)})` })),
                { value: '', label: `Bez pořadatele (${count('')})` },
            ],
            view.filter,
        );
        clubsSignature = JSON.stringify(allClubs);
        renderClubEditor();
        const club = clubOf(view.filter);
        heading.textContent = view.filter === '*' ? 'Všechny akce' : club ? club.name || club.code || 'Bez názvu' : 'Bez pořadatele';
        viewSeg.setValue(view.view);
        nav.el.hidden = view.view !== 'm';
        nav.setColor(eventColor());
        renderList();
    }

    const el = h(
        'div',
        { class: 'cal-et' },
        h('section', { class: 'cal-eclubs', 'aria-label': 'Spolek / pořadatel' }, h('span', { class: 'lbl' }, 'Spolek / pořadatel'), h('div', { class: 'cal-evsel' }, filter, addClub), clubEditor),
        h('section', { class: 'cal-elist' }, h('div', { class: 'cal-s4h' }, heading, viewSeg), nav.el, listEl, empty, addButton('Přidat akci', addEvent, { 'data-event-add': '' })),
    );
    root.replaceChildren(el);
    render();

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'replace' || (event.type === 'layout' && !event.live) || (event.type === 'content' && (event.collection === 'events' || event.collection === 'clubs'))) render();
    });

    return {
        destroy() {
            unsubscribe();
            root.replaceChildren();
        },
    };
}
