// The workspace address (K9): `?krok=` / `&strana=` read and written by state/url.js, and the
// store's `openStep` — a step opened by a link is navigation, never an edit.
import assert from 'node:assert/strict';
import { ENV, copy, makeDoc } from '../helpers.mjs';

export default async function (t, load) {
    const { readAddress, writeAddress, addressOf, pageIndexOf } = await load('state/url.js');
    const { createStore } = await load('state/store.js');
    const { createEstimatorMeasurer } = await load('render/measure.js');

    async function makeStore(o = {}, mode = 'designer') {
        const doc = await makeDoc(load, o);
        const server = { doc: copy(doc), requests: [] };
        const api = {
            async save(payload) {
                server.requests.push(copy(payload));
                const design = { ...server.doc.design, ...(payload.design || {}) };
                if (payload.finish) {
                    design.finished = true;
                    design.step = 5;
                }
                server.doc = { id: server.doc.id, revision: server.doc.revision + 1, design, content: copy(server.doc.content) };
                return { calendar: { id: server.doc.id, revision: server.doc.revision, ...copy(design), content: copy(server.doc.content) }, assets: {} };
            },
        };
        const store = createStore({ doc, env: { ...ENV, assets: {}, mode }, api, measurer: createEstimatorMeasurer() });
        return { store, server };
    }
    const tick = () => new Promise((r) => setTimeout(r, 5));

    t.test('readAddress: krok 1–5 and a page key; anything else is ignored', () => {
        assert.deepEqual(readAddress('?krok=4&strana=w05'), { step: 4, page: 'w05', scan: false });
        assert.deepEqual(readAddress('krok=5&strana=sp%3AabcDEF123'), { step: 5, page: 'sp:abcDEF123', scan: false });
        for (const page of ['cover', 'pre', 'post', 'back', 'w53']) assert.equal(readAddress(`?strana=${page}`).page, page);
        for (const bad of ['?krok=0', '?krok=6', '?krok=2x', '?krok=', '?krok=-1', '?krok=1.5', '']) assert.equal(readAddress(bad).step, null, bad);
        for (const bad of ['?strana=w5', '?strana=week', '?strana=sp:', '?strana=%3Cb%3E', '?strana=w05x']) assert.equal(readAddress(bad).page, null, bad);
        assert.equal(readAddress('?sken=1&krok=4').scan, true);
        assert.equal(readAddress(null).step, null);
    });

    t.test('writeAddress keeps other parameters, sets / drops krok and strana', () => {
        assert.equal(writeAddress('', { step: 3, page: null }), '?krok=3');
        assert.equal(writeAddress('?krok=3', { step: 5, page: 'sp:abc' }), '?krok=5&strana=sp%3Aabc');
        assert.equal(writeAddress('?sken=1&krok=4&strana=w05', { step: 2, page: null }), '?sken=1&krok=2');
        assert.equal(writeAddress('?krok=4', { step: null, page: null }), '');
        assert.equal(writeAddress('?x=1', { step: null, page: 'w02' }), '?x=1&strana=w02');
        // round trip
        const back = readAddress(writeAddress('', { step: 5, page: 'sp:Ab12' }));
        assert.deepEqual(back, { step: 5, page: 'sp:Ab12', scan: false });
    });

    t.test('addressOf: the page only in steps 4 (a page view) and 5; a client has no krok', () => {
        const pages = [{ key: 'cover' }, { key: 'w01' }, { key: 'back' }];
        const ui = { contentView: 1, previewPage: 2 };
        assert.deepEqual(addressOf({ mode: 'designer', step: 3, ui, pages }), { step: 3, page: null });
        assert.deepEqual(addressOf({ mode: 'designer', step: 4, ui, pages }), { step: 4, page: 'w01' });
        assert.deepEqual(addressOf({ mode: 'designer', step: 4, ui: { ...ui, contentView: 'style' }, pages }), { step: 4, page: null });
        assert.deepEqual(addressOf({ mode: 'designer', step: 4, ui: { ...ui, contentView: 'special' }, pages }), { step: 4, page: null });
        assert.deepEqual(addressOf({ mode: 'designer', step: 5, ui, pages }), { step: 5, page: 'back' });
        assert.deepEqual(addressOf({ mode: 'client', step: 5, ui, pages }), { step: null, page: 'back' });
        assert.deepEqual(addressOf({ mode: 'designer', step: 5, ui: { ...ui, previewPage: 9 }, pages }), { step: 5, page: null });
        // step 3 (K20 P2): the page on the stage, while one is chosen and exists
        assert.deepEqual(addressOf({ mode: 'designer', step: 3, ui: { ...ui, stagePage: 'cover' }, pages }), { step: 3, page: 'cover' });
        assert.deepEqual(addressOf({ mode: 'designer', step: 3, ui: { ...ui, stagePage: 'sp:gone' }, pages }), { step: 3, page: null });
        assert.equal(pageIndexOf(pages, 'w01'), 1);
        assert.equal(pageIndexOf(pages, 'w02'), null);
        assert.equal(pageIndexOf(pages, null), null);
    });

    t.test('store.openStep: shown, never dirty, never saved, not moved back by a save answer', async () => {
        const { store, server } = await makeStore({});
        const stored = store.getState().doc.design.step;
        const target = stored === 4 ? 5 : 4;
        const events = [];
        store.subscribe((e) => events.push(e));
        store.openStep(target);
        assert.equal(store.getState().doc.design.step, target);
        assert.deepEqual(events, [{ type: 'design', keys: ['step'] }]);
        assert.equal(store.getState().status, 'clean');
        assert.equal(store.getState().dirtyDesign.size, 0);
        await tick();
        assert.equal(server.requests.length, 0, 'opening a step by the address writes nothing');
        // an edit is saved without the step, and its answer (the stored step) does not move the view
        store.setDesign({ name: 'Jiný název' });
        await store.save();
        assert.deepEqual(server.requests[0], { design: { name: 'Jiný název' } });
        assert.equal(server.doc.design.step, stored);
        assert.equal(store.getState().doc.design.step, target, 'the view stays on the opened step');
        assert.equal(store.getState().status, 'clean');
        // the user moves: the usual rule stores the step silently
        const next = target === 4 ? 5 : 4;
        store.setStep(next);
        await tick();
        assert.deepEqual(server.requests[1], { design: { step: next } });
        assert.equal(server.doc.design.step, next);
    });

    t.test('store.openStep: invalid steps, steps 3–5 without a layout and a client are ignored', async () => {
        const { store } = await makeStore({ preset: null });
        const stored = store.getState().doc.design.step;
        for (const bad of [0, 6, 'x', 3, 4, 5]) store.openStep(bad);
        assert.equal(store.getState().doc.design.step, stored);
        const client = (await makeStore({}, 'client')).store;
        const before = client.getState().doc.design.step;
        client.openStep(before === 2 ? 1 : 2);
        assert.equal(client.getState().doc.design.step, before);
    });
}
