// „Nahrát z Excelu“ (K10b): recognising the table, dates, mapping and the ops of the write
// (model/import.js). The tables are what CalendarTableReader made of the committed fixtures
// (tests/Fixtures/calendar/import/tables.json — pinned by CalendarTableReaderTest).
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const TABLES = JSON.parse(readFileSync(join(dirname(fileURLToPath(import.meta.url)), '..', 'import', 'tables.json'), 'utf8'));
const rowsOf = (name, sheet = 0) => TABLES[name].sheets[sheet].rows;
const seq = () => {
    let n = 0;
    return () => `id${String(++n).padStart(3, '0')}`;
};

export default async function (t, load) {
    const im = await load('model/import.js');
    const { BUILTIN_WASTE } = await load('model/defaults.js');
    const Y = 2027;
    const kind = (builtin, o = {}) => ({ builtin, name: builtin ? BUILTIN_WASTE[builtin].name : 'Vlastní', on: false, place: '', mode: 'rule', weekdays: [1], interval: '2e', from: null, to: null, dates: [], pos: 0, ...o });
    const seeded = () => ({ wasteKinds: { kom: kind('kom', { pos: 0 }), bio: kind('bio', { pos: 1 }), plast: kind('plast', { pos: 2 }), papir: kind('papir', { pos: 3 }) } });

    t.test('dates: every way a Czech table writes one, the calendar year when none is given', () => {
        const cases = {
            '2027-03-01': '2027-03-01',
            '1. 3. 2027': '2027-03-01',
            '01.03.2027': '2027-03-01',
            '1.3.': '2027-03-01',
            '1. 3.': '2027-03-01',
            '1/3/2027': '2027-03-01',
            '1. 3. 27': '2027-03-01',
            '1. března 2027': '2027-03-01',
            '1. brezna': '2027-03-01',
            '46447': '2027-03-01',
            '46513': '2027-05-06',
            '30.02.2027': null,
            '31. 4.': null,
            'neznámé datum': null,
            '14.': null,
            '': null,
            '12345': null,
        };
        for (const [text, want] of Object.entries(cases)) assert.equal(im.parseDate(text, Y), want, text);
        assert.deepEqual(im.parseEventDate('12.–14. 6. 2027', Y), { date: '2027-06-12', multi: true });
        assert.deepEqual(im.parseEventDate('12. - 14. června', Y), { date: '2027-06-12', multi: true });
        assert.deepEqual(im.parseEventDate('6.3.2027', Y), { date: '2027-03-06', multi: false });
        assert.deepEqual(im.parseDayList('7., 21.', Y, 1), ['2027-01-07', '2027-01-21']);
        assert.deepEqual(im.parseDayList('3. a 17.', Y, 6), ['2027-06-03', '2027-06-17']);
        assert.deepEqual(im.parseDayList('14.', Y, 2), ['2027-02-14']);
        assert.equal(im.parseDayList('31.', Y, 2), null, 'no 31 February');
        assert.equal(im.parseDayList('jen v létě', Y, 6), null);
        assert.deepEqual(['Leden', 'ÚNOR', 'září', 'Prosinec 2027', 'XII', 'lis.', 'Pondělí'].map(im.monthOf), [1, 2, 9, 12, 12, 11, 0]);
    });

    t.test('built-in kinds are found however the file names them', () => {
        const cases = { 'Komunální odpad': 'kom', KOMUNÁL: 'kom', 'směsný odpad': 'kom', PLASTY: 'plast', 'Plast + nápojové kartony': 'plast', papír: 'papir', Bioodpad: 'bio', 'BIO odpad': 'bio', 'Termíny svozu – sklo (zelené kontejnery)': 'sklo', 'Nebezpečný odpad': null, Textil: null };
        for (const [text, want] of Object.entries(cases)) assert.equal(im.builtinOf(text), want, text);
    });

    t.test('A — one row per collection (xlsx): title and header skipped, kinds grouped, bad rows named by their row number', () => {
        const read = im.readWaste(rowsOf('odpady-radky.xlsx'), Y);
        assert.equal(read.shape, 'rows');
        assert.deepEqual(read.groups.map((g) => [g.label, g.dates.length]), [['Komunální odpad', 6], ['PLASTY', 3], ['papír', 1], ['Bioodpad', 1], ['Nebezpečný odpad', 1]]);
        assert.deepEqual(read.groups[0].dates, ['2027-01-07', '2027-01-21', '2027-02-04', '2027-02-18', '2027-03-04', '2027-05-06'], 'the formula row (an Excel serial) counts');
        assert.equal(read.groups[1].place, 'žluté pytle', 'one place for all rows of a kind');
        assert.deepEqual(read.issues, [{ row: 16, text: 'neznámé datum · PLASTY', reason: 'date' }, { row: 17, text: '2029-03-01 · PLASTY', reason: 'range' }]);

        const content = seeded();
        const mapping = im.suggestWasteMapping(content, read.groups);
        assert.deepEqual(mapping, { 'komunalni odpad': 'kom', plasty: 'plast', papir: 'papir', bioodpad: 'bio', 'nebezpecny odpad': 'new' });
        const plan = im.planWaste(content, read, mapping, 'replace', seq());
        assert.equal(plan.total, 12);
        const byId = Object.fromEntries(plan.ops.map((op) => [op.id, op.v]));
        assert.deepEqual(Object.keys(byId), ['kom', 'plast', 'papir', 'bio', 'id001']);
        assert.deepEqual(byId.plast, { ...content.wasteKinds.plast, on: true, mode: 'dates', dates: ['2027-01-14', '2027-02-11', '2027-03-11'], place: 'žluté pytle' }, 'rule fields stay — switching back loses nothing');
        assert.deepEqual(byId.id001, { builtin: null, name: 'Nebezpečný odpad', on: true, place: 'u hasičské zbrojnice', mode: 'dates', weekdays: [1], interval: '2e', from: null, to: null, dates: ['2027-04-25'], pos: 4 });
        assert.ok(plan.ops.every((op) => op.c === 'wasteKinds'));
    });

    t.test('B — one column per kind (xls), C — months × kinds (ods), D — one column of dates (CSV, Windows-1250)', () => {
        const b = im.readWaste(rowsOf('odpady-sloupce.xls'), Y);
        assert.equal(b.shape, 'columns');
        assert.deepEqual(b.groups.map((g) => [g.label, g.dates]), [
            ['Komunál', ['2027-01-07', '2027-01-21', '2027-02-04', '2027-02-18']],
            ['Plast', ['2027-01-14', '2027-02-11', '2027-03-11']],
            ['Papír', ['2027-01-28', '2027-02-25']],
        ]);
        assert.deepEqual(b.issues, []);

        const c = im.readWaste(rowsOf('odpady-mesice.ods'), Y);
        assert.equal(c.shape, 'months');
        const counts = Object.fromEntries(c.groups.map((g) => [g.label, g.dates.length]));
        assert.deepEqual(counts, { 'Komunální odpad': 24, Plasty: 12, Papír: 6, Sklo: 2 });
        assert.deepEqual(c.groups.find((g) => g.label === 'Sklo').dates, ['2027-06-03', '2027-06-17']);
        // a built-in that is not in the calendar yet is proposed as that built-in
        assert.equal(im.suggestWasteMapping(seeded(), c.groups).sklo, 'new:sklo');
        const plan = im.planWaste(seeded(), c, im.suggestWasteMapping(seeded(), c.groups), 'replace', seq());
        assert.ok(plan.ops.some((op) => op.id === 'sklo' && op.v.builtin === 'sklo' && op.v.name === 'Sklo' && op.v.dates.length === 2));

        const d = im.readWaste(rowsOf('odpady-sklo-1250.csv'), Y);
        assert.equal(d.shape, 'dates');
        assert.deepEqual(d.groups.map((g) => [g.label, g.dates.length, g.fromTitle]), [['Termíny svozu – sklo (zelené kontejnery)', 4, true]]);
        assert.deepEqual(d.issues, [{ row: 6, text: '30.02.2027', reason: 'date' }]);
        // the title names the kind; without one the dates go to the kind open in the tool
        assert.equal(im.suggestWasteMapping(seeded(), d.groups, 'bio')[d.groups[0].key], 'new:sklo');
        const bare = im.readWaste([['1.3.2027'], ['15.3.2027']], Y);
        assert.equal(im.suggestWasteMapping(seeded(), bare.groups, 'bio')[bare.groups[0].key], 'bio');
    });

    t.test('a shape picked by hand reads the table that way', () => {
        const rows = rowsOf('odpady-sloupce.xls');
        assert.equal(im.readWaste(rows, Y, 'dates').shape, 'dates');
        assert.equal(im.readWaste(rows, Y, 'dates').groups[0].dates.length, 4, 'the column with most dates');
        assert.equal(im.readWaste([['nic'], ['tady']], Y).shape, null);
    });

    t.test('merge: replace (default) vs add; two groups into one kind; skip; the 400 cap refuses a kind', async () => {
        const content = seeded();
        content.wasteKinds.plast = kind('plast', { pos: 2, on: true, mode: 'dates', dates: ['2027-01-01', '2027-01-14'] });
        const read = { groups: [{ key: 'plasty', label: 'PLASTY', dates: ['2027-01-14', '2027-02-11'], place: '' }, { key: 'plast', label: 'Plast', dates: ['2027-03-11'], place: '' }, { key: 'x', label: 'X', dates: ['2027-04-01'], place: '' }] };
        const mapping = { plasty: 'plast', plast: 'plast', x: 'skip' };
        const replace = im.planWaste(content, read, mapping, 'replace');
        assert.deepEqual(replace.ops.map((op) => [op.id, op.v.dates]), [['plast', ['2027-01-14', '2027-02-11', '2027-03-11']]]);
        assert.deepEqual(replace.targets, [{ id: 'plast', name: 'Plast', isNew: false, before: 2, after: 3, imported: 3, refused: false, wasRule: false }]);
        content.wasteKinds.kom = kind('kom', { on: true });
        assert.equal(im.planWaste(content, read, { plasty: 'kom' }).targets[0].wasRule, true, 'a switched-on rule kind is flagged: its rule gives way to the dates');
        const add = im.planWaste(content, read, mapping, 'add');
        assert.deepEqual(add.ops[0].v.dates, ['2027-01-01', '2027-01-14', '2027-02-11', '2027-03-11']);
        assert.equal(add.targets[0].after, 4);

        const { addDays } = await load('generate/dates.js');
        const many = [];
        for (let d = '2026-12-01'; many.length < 401; d = addDays(d, 1)) many.push(d);
        const capped = im.planWaste(content, { groups: [{ key: 'k', label: 'K', dates: many, place: '' }] }, { k: 'kom' }, 'replace');
        assert.equal(capped.targets[0].refused, true);
        assert.deepEqual(capped.ops, [], 'a refused kind is not written');
        assert.equal(im.planWaste(content, { groups: [{ key: 'k', label: 'K', dates: many.slice(0, 400), place: '' }] }, { k: 'kom' }, 'replace').targets[0].refused, false, '400 is fine');

        // two groups mapped to the same NEW kind share it
        const fresh = im.planWaste(seeded(), { groups: [{ key: 'a', label: 'Textil', dates: ['2027-01-02'], place: '' }, { key: 'b', label: 'textil ', dates: ['2027-01-03'], place: '' }] }, { a: 'new', b: 'new' }, 'replace', seq());
        assert.deepEqual(fresh.ops.map((op) => [op.id, op.v.name, op.v.dates]), [['id001', 'Textil', ['2027-01-02', '2027-01-03']]]);
    });

    t.test('events (xlsx): columns by their headers, multi-day on the first day, bad rows named, extra columns ignored', () => {
        const rows = rowsOf('akce.xlsx');
        const cols = im.detectEventColumns(rows, Y);
        assert.deepEqual(cols, { header: 0, date: 0, name: 1, club: 2, code: -1 });
        const read = im.readEvents(rows, Y);
        assert.deepEqual(read.items.map((i) => [i.row, i.date, i.name, i.club]), [
            [2, '2027-01-17', 'Tříkrálová sbírka – poděkování', 'Obec Testov'],
            [3, '2027-02-14', 'Hasičský ples', 'SDH Testov'],
            [4, '2027-02-14', 'Hasičský ples', 'SDH Testov'],
            [5, '2027-06-12', 'Pouťové oslavy', 'TJ Sokol Testov'],
            [6, '2027-04-30', 'Pálení čarodějnic', 'SDH'],
            [7, '2027-06-01', 'Dětský den', ''],
        ]);
        assert.deepEqual(read.issues.map((i) => [i.row, i.reason]), [[5, 'multiday'], [8, 'date'], [9, 'name']]);
    });

    t.test('events: organisers matched by code / name, new ones get a short code, duplicates are skipped', () => {
        const content = { clubs: { c1: { code: 'SDH', name: 'Sbor dobrovolných hasičů', pos: 0 } }, events: { e1: { date: '2027-04-30', name: 'Pálení  čarodějnic', club: 'c1', icon: null } } };
        const read = im.readEvents(rowsOf('akce.xlsx'), Y);
        const clubs = im.eventClubs(content, read.items);
        assert.deepEqual(
            clubs.map((c) => [c.key, c.name, c.count, c.suggested, c.proposed]),
            [
                ['obec testov', 'Obec Testov', 1, 'new', 'OT'],
                ['sdh', 'SDH Testov', 3, 'c1', ''],
                ['tj', 'TJ Sokol Testov', 1, 'new', 'TJ'],
            ],
            '„SDH Testov“ and „SDH“ are one organiser, matched to the stored SDH',
        );
        const plan = im.planEvents(content, read, clubs, { tj: { to: 'new', code: 'SOKOL' } }, seq());
        assert.equal(plan.added, 4);
        assert.equal(plan.duplicates, 2);
        assert.deepEqual(plan.issues.map((i) => [i.row, i.reason]), [[4, 'twice'], [6, 'duplicate']]);
        const clubOps = plan.ops.filter((op) => op.c === 'clubs');
        assert.deepEqual(clubOps.map((op) => op.v), [{ code: 'OT', name: 'Obec Testov', pos: 1 }, { code: 'SOKOL', name: 'TJ Sokol Testov', pos: 2 }]);
        const events = plan.ops.filter((op) => op.c === 'events').map((op) => op.v);
        assert.deepEqual(events.map((e) => [e.date, e.name, e.club]), [
            ['2027-01-17', 'Tříkrálová sbírka – poděkování', clubOps[0].id],
            ['2027-02-14', 'Hasičský ples', 'c1'],
            ['2027-06-12', 'Pouťové oslavy', clubOps[1].id],
            ['2027-06-01', 'Dětský den', null],
        ]);
        assert.ok(events.every((e) => e.icon === null));
        // „bez pořadatele“ drops the club; a club no imported event uses is not created
        const none = im.planEvents(content, read, clubs, { 'obec testov': { to: 'none' } }, seq());
        assert.equal(none.ops.find((op) => op.c === 'events' && op.v.name.startsWith('Tříkrálová')).v.club, null);
        assert.ok(!none.ops.some((op) => op.c === 'clubs' && op.v.code === 'OT'));
    });

    t.test('events (CSV, Windows-1250): no header words needed beyond the first row; next-January dates stay in range', () => {
        const read = im.readEvents(rowsOf('akce-1250.csv'), Y);
        assert.deepEqual(read.columns, { header: 0, date: 0, name: 1, club: 2, code: -1 });
        assert.deepEqual(read.items.map((i) => [i.date, i.name, i.club]), [
            ['2027-03-06', 'Maškarní ples; pro děti', 'ZŠ Testov'],
            ['2027-12-24', 'Zpívání u stromu', ''],
            ['2028-01-01', 'Novoroční výšlap', 'KČT'],
        ]);
        const clubs = im.eventClubs({}, read.items);
        assert.deepEqual(clubs.map((c) => [c.name, c.proposed]), [['ZŠ Testov', 'ZŠ'], ['KČT', 'KČT']]);
        // a table without any header row
        const bare = im.readEvents([['6.3.2027', 'Ples', 'SDH'], ['7.3.2027', 'Výlov', 'MS']], Y);
        assert.deepEqual(bare.items.map((i) => [i.name, i.club]), [['Ples', 'SDH'], ['Výlov', 'MS']]);
        assert.equal(im.proposeCode('Spolek přátel obce', '', new Set()), 'SPO');
        assert.equal(im.proposeCode('SDH Lhota', '', new Set(['sdh'])), 'SDH2');
    });
}
