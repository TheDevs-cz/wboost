// Moon phases: accurate to the day for 2026–2028 (Czech civil date), checked against published
// instants and against an independent low-precision solar / lunar longitude formula.
import assert from 'node:assert/strict';
import { makeDoc } from '../helpers.mjs';

export default async function (t, load) {
    const { computeMoonPhases, effectivePhases, moonPhaseInstants } = await load('generate/moon.js');
    const { dayInfo } = await load('generate/day.js');
    const all = moonPhaseInstants(2026, 2028);

    // published instants (UTC) — eclipses and well-known phases; [date, time, phase, Czech civil date]
    const KNOWN = [
        ['2026-01-03', '10:03', 2, '2026-01-03'],
        ['2026-02-17', '12:01', 0, '2026-02-17'],
        ['2026-03-03', '11:38', 2, '2026-03-03'],
        ['2026-08-12', '17:37', 0, '2026-08-12'],
        ['2026-08-28', '04:18', 2, '2026-08-28'],
        ['2027-01-22', '12:17', 2, '2027-01-22'],
        ['2027-02-06', '15:56', 0, '2027-02-06'],
        ['2027-02-20', '23:23', 2, '2027-02-21'], // 00:23 CET the next day
        ['2027-03-22', '10:44', 2, '2027-03-22'],
        ['2027-08-02', '10:05', 0, '2027-08-02'],
        ['2028-01-12', '04:03', 2, '2028-01-12'],
        ['2028-01-26', '15:12', 0, '2028-01-26'],
        ['2028-07-06', '18:10', 2, '2028-07-06'],
        ['2028-07-22', '03:01', 0, '2028-07-22'],
        ['2028-12-31', '16:48', 2, '2028-12-31'],
    ];

    t.test('phase instants match published times within two minutes', () => {
        for (const [date, time, phase, local] of KNOWN) {
            const want = Date.parse(`${date}T${time}:00Z`);
            const hit = all.find((p) => p.phase === phase && Math.abs(p.utcMs - want) < 2 * 86400000);
            assert.ok(hit, `${date} phase ${phase} not found`);
            assert.ok(Math.abs(hit.utcMs - want) <= 120000, `${date} ${time}: got ${new Date(hit.utcMs).toISOString()}`);
            assert.equal(hit.date, local);
        }
    });

    t.test('full moons of early 2027 fall on 22 January, 21 February (Czech time) and 22 March', () => {
        const full = computeMoonPhases(2027).filter((p) => p.phase === 2).map((p) => p.date);
        assert.deepEqual(full.slice(0, 4), ['2026-12-24', '2027-01-22', '2027-02-21', '2027-03-22']);
    });

    t.test('every phase agrees with an independent elongation formula (Sun–Moon angle within 0.5°)', () => {
        const R = Math.PI / 180;
        const elongation = (ms) => {
            const d = ms / 86400000 - 10957.5; // days from J2000.0
            const T = d / 36525;
            const g = (357.528 + 0.9856003 * d) * R;
            const sun = 280.46 + 0.9856474 * d + 1.915 * Math.sin(g) + 0.02 * Math.sin(2 * g);
            const moon = 218.32 + 481267.881 * T + 6.29 * Math.sin((135.0 + 477198.87 * T) * R) - 1.27 * Math.sin((259.3 - 413335.36 * T) * R)
                + 0.66 * Math.sin((235.7 + 890534.22 * T) * R) + 0.21 * Math.sin((269.9 + 954397.74 * T) * R)
                - 0.19 * Math.sin((357.5 + 35999.05 * T) * R) - 0.11 * Math.sin((186.5 + 966404.03 * T) * R);
            return (((moon - sun) % 360) + 360) % 360;
        };
        for (const p of all) {
            const error = ((elongation(p.utcMs) - p.phase * 90 + 540) % 360) - 180;
            assert.ok(Math.abs(error) < 0.5, `${p.date} phase ${p.phase}: elongation off by ${error.toFixed(3)}°`);
            // 0.5° of elongation is under an hour: an instant that close to local midnight would be ambiguous
        }
    });

    t.test('phases cycle 0 → 1 → 2 → 3 with 6–9 days between them', () => {
        for (let i = 1; i < all.length; i++) {
            assert.equal(all[i].phase, (all[i - 1].phase + 1) % 4);
            const gap = (all[i].utcMs - all[i - 1].utcMs) / 86400000;
            assert.ok(gap > 6 && gap < 9, `gap ${gap}`);
        }
    });

    t.test('computeMoonPhases covers 20 December … 12 January and is sorted', () => {
        for (const year of [2026, 2027, 2028]) {
            const list = computeMoonPhases(year);
            assert.ok(list.length >= 51 && list.length <= 54, `${year}: ${list.length}`);
            assert.ok(list[0].date >= `${year - 1}-12-20` && list[list.length - 1].date <= `${year + 1}-01-12`);
            assert.deepEqual(list.map((p) => p.date), list.map((p) => p.date).slice().sort());
            assert.equal(new Set(list.map((p) => p.date)).size, list.length);
        }
    });

    t.test('moon edits: remove needs the same date and phase, a later add wins', async () => {
        const content = { moonEdits: {
            a: { op: 'remove', date: '2027-01-22', phase: 2 },
            b: { op: 'add', date: '2027-01-23', phase: 2 },
            c: { op: 'remove', date: '2027-01-07', phase: 3 }, // wrong phase: nothing happens
            d: { op: 'add', date: '2027-03-01', phase: 1 },
            e: { op: 'add', date: '2027-03-01', phase: 3 },
        } };
        const doc = await makeDoc(load, { content });
        const map = effectivePhases(doc);
        assert.equal(map.has('2027-01-22'), false);
        assert.equal(map.get('2027-01-23'), 2);
        assert.equal(map.get('2027-01-07'), 0);
        assert.equal(map.get('2027-03-01'), 3);
        const plain = effectivePhases(await makeDoc(load));
        assert.equal(plain.get('2027-01-22'), 2);
        assert.equal(plain.size, computeMoonPhases(2027).length);
    });

    t.test('the moon icon shows only for enabled phases and when the feature is on', async () => {
        const doc = await makeDoc(load);
        assert.deepEqual(dayInfo(doc, '2027-01-22').icons.map((i) => [i.category, i.phase, i.title]), [['moon', 2, 'Úplněk']]);
        const some = await makeDoc(load, { styles: (l) => { l.styles.moon.phases = [true, false, false, false]; } });
        assert.deepEqual(dayInfo(some, '2027-01-22').icons, []);
        assert.equal(dayInfo(some, '2027-01-07').icons.length, 1);
        const off = await makeDoc(load, { features: { moon: false } });
        assert.deepEqual(dayInfo(off, '2027-01-22').icons, []);
    });
}
