// Step 1 — Typ (handoff §4.3, map §8.5): type, year, print settings, feature checklist.
//
// Built once; store events update the controls in place (checkboxes keep keyboard focus).

import { LIMITS } from '../model/defaults.js';
import { PAGE_COLLECTIONS } from '../model/content.js';
import { buildPages } from '../generate/pages.js';
import { pluralPages } from '../generate/czech.js';
import { checkRow, h, numberField } from './dom.js';

const TYPES = [
    { key: 'week', title: '7 dní', pill: 'Týdenní', text: 'Na každé straně jeden týden.', rows: 7 },
    { key: 'fortnight', title: '14 dní', pill: 'Dvojtýdenní', text: 'Na každé straně dva týdny — poloviční počet stran.', rows: 14 },
];

/** Step-1 order and labels of the features (architecture §B.1). */
export const FEATURES = Object.freeze([
    ['captions', 'Popisky fotografií'],
    ['weekNumbers', 'Zobrazení týdnů'],
    ['moon', 'Fáze měsíce'],
    ['timeChange', 'Změna času'],
    ['seasons', 'Změna ročního období'],
    ['waste', 'Odpady'],
    ['events', 'Upravitelné akce'],
    ['shifts', 'Šichtovnice'],
    ['specialPages', 'Speciální stránky'],
    ['qr', 'QR kód'],
]);

function wireframe(rows) {
    const card = '<rect class="cal-wf-card" x="20" y="22" width="160" height="76" rx="5"/>';
    if (rows > 7) {
        // 14 days = the two-sided layout: first week left, photo in the middle, second week right
        const gap = 60 / 7;
        let lines = '';
        for (let i = 0; i < 7; i++) {
            const y = (32 + i * gap).toFixed(1);
            const width = i === 6 ? 20 : 30;
            lines += `<rect class="cal-wf-b" x="26" y="${y}" width="${width}" height="3.2" rx="1.6"/><rect class="cal-wf-b" x="${174 - width}" y="${y}" width="${width}" height="3.2" rx="1.6"/>`;
        }
        return `<svg viewBox="0 0 200 120" aria-hidden="true">${card}<rect class="cal-wf-ph" x="64" y="30" width="72" height="62" rx="3"/>${lines}<rect class="cal-wf-acc" x="26" y="26" width="30" height="3" rx="1.5"/><rect class="cal-wf-acc" x="144" y="26" width="30" height="3" rx="1.5"/></svg>`;
    }
    const gap = 60 / rows;
    let lines = '';
    for (let i = 0; i < rows; i++) {
        lines += `<rect class="cal-wf-b" x="130" y="${(32 + i * gap).toFixed(1)}" width="${i % 7 === 6 ? 26 : 40}" height="3.6" rx="1.8"/>`;
    }
    return `<svg viewBox="0 0 200 120" aria-hidden="true">${card}<rect class="cal-wf-ph" x="26" y="30" width="96" height="62" rx="3"/>${lines}<rect class="cal-wf-acc" x="130" y="26" width="44" height="3" rx="1.5"/></svg>`;
}

function hasPageOverrides(content) {
    return PAGE_COLLECTIONS.some((c) => Object.keys(content[c] || {}).length > 0);
}

/**
 * @param root  the step slot
 * @param ctx   `Ctx`
 * @returns `{ destroy() }`
 */
export function mountStep1(root, ctx) {
    const { store, env } = ctx;
    const doc = () => store.getState().doc;

    // ---- type ------------------------------------------------------------------------------
    const typeCards = TYPES.map((type) => {
        const count = h('span', { class: 'cap' });
        const button = h(
            'button',
            { type: 'button', class: 'cal-type', role: 'radio', 'data-type': type.key, onClick: () => pickType(type.key) },
            h('span', { class: 'cal-type-wf', html: wireframe(type.rows) }),
            h('span', { class: 'cal-type-b' }, h('b', null, type.title), h('span', { class: 'pill' }, type.pill), count),
        );
        return { type, button, count };
    });

    async function pickType(type) {
        const { design, content } = doc();
        if (type === design.type) return;
        if (design.layout) {
            const ok = await ctx.confirm({
                title: 'Změnit typ kalendáře?',
                text: `Rozmístění prvků se vrátí do výchozího rozvržení pro nový typ. Písma, barvy a nastavení částí zůstanou.${hasPageOverrides(content) ? ' Fotky a popisky u stran, které v novém typu nezačínají stranu, se nezobrazí.' : ''}`,
                confirmLabel: 'Změnit typ',
            });
            if (!ok) return;
        }
        store.changeType(type);
    }

    // ---- year ------------------------------------------------------------------------------
    const years = h('div', { class: 'seg soft cal-yrs', role: 'group', 'aria-label': 'Rok' });

    async function pickYear(year) {
        const { design, content } = doc();
        if (year === design.year) return;
        if (hasPageOverrides(content)) {
            const ok = await ctx.confirm({
                title: 'Změnit rok?',
                text: 'Fotky a popisky zůstanou u týdnů se stejným číslem. Akce, jména a svozy se přesunou na stejný den nového roku.',
                confirmLabel: 'Změnit rok',
            });
            if (!ok) return;
        }
        const { dropped } = store.changeYear(year);
        if (dropped > 0) ctx.toast(`29. 2. v roce ${year} není — odebráno položek: ${dropped}`);
    }

    function syncYears() {
        const current = doc().design.year;
        const list = [env.currentYear, env.currentYear + 1, env.currentYear + 2];
        if (!list.includes(current)) list.push(current);
        list.sort((a, b) => a - b);
        const focused = years.contains(document.activeElement) ? document.activeElement.dataset.year : null;
        const rendered = [...years.children].map((b) => `${b.dataset.year}${b.classList.contains('on') ? '*' : ''}`).join(',');
        if (rendered === list.map((y) => `${y}${y === current ? '*' : ''}`).join(',')) return;
        years.replaceChildren(
            ...list.map((year) => h('button', { type: 'button', class: year === current ? 'on' : '', 'aria-pressed': String(year === current), 'data-year': String(year), onClick: () => pickYear(year) }, String(year))),
        );
        if (focused) {
            const again = years.querySelector(`[data-year="${focused}"]`);
            if (again) again.focus();
        }
    }

    // ---- print -----------------------------------------------------------------------------
    const bleed = checkRow({ label: 'Spadávka', checked: false, attrs: { 'data-print': 'bleed' }, onChange: (on) => store.setPrint({ bleed: on }) });
    const bleedMm = numberField({
        value: 0,
        min: LIMITS.bleedMm.min,
        max: LIMITS.bleedMm.max,
        step: LIMITS.bleedMm.step,
        unit: 'mm',
        label: 'Spadávka',
        attrs: { 'data-print': 'bleedMm' },
        onChange: (value) => store.setPrint({ bleedMm: value }),
    });
    const marks = checkRow({ label: 'Ořezové značky', checked: false, attrs: { 'data-print': 'marks' }, onChange: (on) => store.setPrint({ marks: on }) });

    // ---- features --------------------------------------------------------------------------
    const all = checkRow({ label: 'Označit vše', checked: false, attrs: { 'data-feature-all': '' }, onChange: () => toggleAll() });
    all.classList.add('cal-all');
    const featureRows = FEATURES.map(([key, label]) => ({
        key,
        row: checkRow({ label, checked: false, strong: true, attrs: { 'data-feature': key }, onChange: (on) => store.setFeature(key, on) }),
    }));

    function toggleAll() {
        // everything on when at least one item is off, otherwise everything off
        const features = doc().design.features;
        store.setAllFeatures(FEATURES.some(([key]) => !features[key]));
    }

    // ---- sync ------------------------------------------------------------------------------
    function sync() {
        const { design } = doc();
        for (const { type, button, count } of typeCards) {
            const on = design.type === type.key;
            button.classList.toggle('on', on);
            button.setAttribute('aria-checked', String(on));
            const pages = buildPages({ ...doc(), design: { ...design, type: type.key } }).length;
            count.textContent = `${type.text} ${pages} ${pluralPages(pages)} v roce ${design.year}.`;
        }
        syncYears();
        bleed.setChecked(design.print.bleed);
        bleedMm.setValue(design.print.bleedMm);
        bleedMm.hidden = !design.print.bleed;
        marks.setChecked(design.print.marks);
        const on = FEATURES.filter(([key]) => design.features[key]).length;
        all.setChecked(on === FEATURES.length, on > 0 && on < FEATURES.length);
        for (const { key, row } of featureRows) row.setChecked(design.features[key]);
    }

    const section = (title, ...content) => h('section', { class: 'cal-sec' }, typeof title === 'string' ? h('h3', null, title) : title, ...content);

    root.replaceChildren(
        h(
            'div',
            { class: 'cal-s1' },
            h(
                'div',
                { class: 'cal-s1c' },
                section('Typ kalendáře', h('div', { class: 'cal-types', role: 'radiogroup', 'aria-label': 'Typ kalendáře' }, ...typeCards.map((c) => c.button))),
                section('Rok', years),
                section('Tisk', h('div', { class: 'cal-pr' }, bleed, bleedMm, marks)),
                section(h('div', { class: 'cal-allh' }, h('h3', null, 'Co bude kalendář zobrazovat?'), all), h('div', { class: 'cal-acc' }, ...featureRows.map((f) => f.row))),
            ),
        ),
    );
    sync();

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'design' || event.type === 'replace' || event.type === 'undo') sync();
    });

    return {
        destroy() {
            unsubscribe();
            root.replaceChildren();
        },
    };
}
