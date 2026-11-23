// K13: adding and reordering special pages from the page list — slots, placement, drop order.
import assert from 'node:assert/strict';
import { makeDoc } from '../helpers.mjs';

export default async function (t, load) {
    const { buildPages } = await load('generate/pages.js');
    const { placeSpecial, slotInSequence, specialSections, specialSlots } = await load('state/special-slots.js');

    const custom = (name, pos, extra = {}) => ({ kind: 'image', on: true, layout: 0, position: 'front', nextYear: false, name, asset: 'a1', text: null, fileName: `${name}.pdf`, pos, ...extra });
    const intro = (extra = {}) => ({ kind: 'intro', on: true, layout: 0, position: 'front', nextYear: false, name: '', asset: null, text: null, fileName: null, pos: 0, ...extra });

    /** The doc after writing `changed` into its special pages. */
    const apply = (doc, changed) => ({ ...doc, content: { ...doc.content, specialPages: { ...(doc.content.specialPages || {}), ...changed } } });
    const order = (doc) => buildPages(doc).filter((p) => p.kind !== 'week').map((p) => p.key);

    const plain = await makeDoc(load);
    const withPages = await makeDoc(load, {
        features: { specialPages: true },
        content: {
            specialPages: {
                intro: intro(),
                mapa: custom('Mapa', 5),
                notes: { kind: 'notes', on: true, layout: 0, position: 'back', nextYear: false, name: '', asset: null, text: null, fileName: null, pos: 4 },
                cenik: custom('Ceník', 6, { position: 'back' }),
            },
        },
    });

    t.test('sections list the shown special pages in page order; nothing while the feature is off', () => {
        assert.deepEqual(specialSections(withPages), { front: ['intro', 'mapa'], back: ['notes', 'cenik'] });
        const off = { ...withPages, design: { ...withPages.design, features: { ...withPages.design.features, specialPages: false } } };
        assert.deepEqual(specialSections(off), { front: [], back: [] });
    });

    t.test('slots: after the cover and every front page, after the weeks and every back page', () => {
        assert.deepEqual(
            specialSlots(withPages).map((s) => `${s.position}${s.index}@${s.after}`),
            ['front0@cover', 'front1@sp:intro', 'front2@sp:mapa', 'back0@weeks', 'back1@sp:notes', 'back2@sp:cenik'],
        );
        assert.deepEqual(specialSlots(plain).map((s) => `${s.position}${s.index}`), ['front0', 'back0']);
    });

    t.test('a generated page added right after the cover on a fresh calendar', () => {
        const doc = { ...plain, design: { ...plain.design, features: { ...plain.design.features, specialPages: true } } };
        const changed = placeSpecial(doc, 'contacts', { position: 'front', index: 0 });
        assert.equal(changed.contacts.on, true);
        assert.equal(changed.contacts.position, 'front');
        assert.deepEqual(order(apply(doc, changed)), ['cover', 'sp:contacts', 'back']);
    });

    t.test('a generated page added to the empty back section goes before the back cover', () => {
        const doc = { ...plain, design: { ...plain.design, features: { ...plain.design.features, specialPages: true } } };
        const changed = placeSpecial(doc, 'intro', { position: 'back', index: 0 });
        assert.equal(changed.intro.position, 'back');
        const pages = buildPages(apply(doc, changed));
        assert.equal(pages[pages.length - 2].key, 'sp:intro');
        assert.equal(pages[pages.length - 3].kind, 'week');
    });

    t.test('insert between two front pages and at the end of the back section', () => {
        const between = placeSpecial(withPages, 'year', { position: 'front', index: 1 });
        assert.deepEqual(order(apply(withPages, between)), ['cover', 'sp:intro', 'sp:year', 'sp:mapa', 'sp:notes', 'sp:cenik', 'back']);
        const last = placeSpecial(withPages, 'waste', { position: 'back', index: 2 });
        assert.deepEqual(order(apply(withPages, last)), ['cover', 'sp:intro', 'sp:mapa', 'sp:notes', 'sp:cenik', 'sp:waste', 'back']);
        const first = placeSpecial(withPages, 'contacts', { position: 'back', index: 0 });
        assert.deepEqual(order(apply(withPages, first)), ['cover', 'sp:intro', 'sp:mapa', 'sp:contacts', 'sp:notes', 'sp:cenik', 'back']);
    });

    t.test('a new uploaded page is created at the slot with its own fields', () => {
        const fresh = custom('Plakát', 0, { pos: 99 });
        const changed = placeSpecial(withPages, 'NEWPAGE000000001', { position: 'front', index: 0 }, fresh);
        assert.equal(changed.NEWPAGE000000001.name, 'Plakát');
        assert.equal(changed.NEWPAGE000000001.asset, 'a1');
        assert.deepEqual(order(apply(withPages, changed)).slice(0, 3), ['cover', 'sp:NEWPAGE000000001', 'sp:intro']);
    });

    t.test('moving a front page into the back section switches its position', () => {
        const changed = placeSpecial(withPages, 'intro', { position: 'back', index: 1 });
        assert.equal(changed.intro.position, 'back');
        assert.deepEqual(order(apply(withPages, changed)), ['cover', 'sp:mapa', 'sp:notes', 'sp:intro', 'sp:cenik', 'back']);
    });

    t.test('placing a page where it already is writes nothing', () => {
        assert.deepEqual(placeSpecial(withPages, 'mapa', { position: 'front', index: 1 }), {});
        assert.deepEqual(placeSpecial(withPages, 'cenik', { position: 'back', index: 2 }), {});
    });

    t.test('pages that are off keep their relative order and stay off', () => {
        const changed = placeSpecial(withPages, 'cenik', { position: 'front', index: 0 });
        for (const [id, item] of Object.entries(changed)) {
            if (id !== 'cenik') assert.equal(item.on, id === 'intro' || id === 'mapa' || id === 'notes', id);
        }
        const doc = apply(withPages, changed);
        assert.deepEqual(order(doc), ['cover', 'sp:cenik', 'sp:intro', 'sp:mapa', 'sp:notes', 'back']);
    });

    t.test('an index past the end is clamped to the end of the section', () => {
        const changed = placeSpecial(withPages, 'year', { position: 'front', index: 40 });
        assert.deepEqual(order(apply(withPages, changed)).slice(0, 4), ['cover', 'sp:intro', 'sp:mapa', 'sp:year']);
    });

    t.test('nothing is written for an unknown id', () => {
        assert.deepEqual(placeSpecial(withPages, 'nope', { position: 'front', index: 0 }), {});
    });

    t.test('drop order → slot: before the weeks = front, after them = back', () => {
        const seq = ['cover', 'intro', null, 'mapa', 'week', 'week', 'notes', 'cenik', 'back'];
        assert.deepEqual(slotInSequence(seq, 'intro'), { position: 'front', index: 0 });
        assert.deepEqual(slotInSequence(seq, 'mapa'), { position: 'front', index: 1 });
        assert.deepEqual(slotInSequence(seq, 'notes'), { position: 'back', index: 0 });
        assert.deepEqual(slotInSequence(seq, 'cenik'), { position: 'back', index: 1 });
        assert.equal(slotInSequence(seq, 'year'), null);
        // a front page dropped between the last week and the back cover
        const dropped = ['cover', 'mapa', 'week', 'week', 'intro', 'notes', 'back'];
        const slot = slotInSequence(dropped, 'intro');
        assert.deepEqual(slot, { position: 'back', index: 0 });
        assert.deepEqual(order(apply(withPages, placeSpecial(withPages, 'intro', slot))), ['cover', 'sp:mapa', 'sp:intro', 'sp:notes', 'sp:cenik', 'back']);
    });
}
