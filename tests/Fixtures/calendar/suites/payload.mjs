// Save flow: dirty tracking, payload, response application, undo model, store rules
// (architecture §C.5, §D.4, §E.7, §E.8).
import assert from 'node:assert/strict';
import { ENV, copy, makeDoc } from '../helpers.mjs';

/** The flat `calendar` object a save answers with, from a Doc. */
const flat = (doc, extra = {}) => ({ id: doc.id, revision: doc.revision + 1, ...copy(doc.design), content: copy(doc.content), ...extra });

export default async function (t, load) {
    const { createUndo } = await load('state/undo.js');
    const { buildPayload, applyResponse, stable, same } = await load('state/payload.js');
    const { createStore } = await load('state/store.js');
    const { createEstimatorMeasurer } = await load('render/measure.js');
    const { mirrorLayout } = await load('model/layouts.js');

    /** A store over a fake server that applies the payload like the real one. */
    async function makeStore(o = {}, mode = 'designer') {
        const doc = await makeDoc(load, o);
        const server = { doc: copy(doc), requests: [], fail: null, gate: null };
        const api = {
            async save(payload) {
                server.requests.push(copy(payload));
                if (server.gate) await server.gate;
                if (server.fail) throw new Error(server.fail);
                const design = { ...server.doc.design, ...(payload.design || {}) };
                if (payload.finish) {
                    design.finished = true;
                    design.step = 5;
                }
                const content = copy(server.doc.content);
                for (const op of payload.content || []) {
                    if (op.v === null) delete content[op.c][op.id];
                    else content[op.c][op.id] = op.v;
                }
                server.doc = { id: server.doc.id, revision: server.doc.revision + 1, design, content };
                return { calendar: { id: server.doc.id, revision: server.doc.revision, ...copy(design), content: copy(content) }, assets: {}, savedAt: '2026-11-11T10:00:00+00:00' };
            },
        };
        const env = { ...ENV, assets: {}, mode };
        const store = createStore({ doc, env, api, measurer: createEstimatorMeasurer() });
        const events = [];
        store.subscribe((e) => events.push(e));
        return { store, server, events, env };
    }

    t.test('undo stack: push / undo / redo, a new change clears redo, the limit drops the oldest', () => {
        const u = createUndo(3);
        assert.equal(u.canUndo(), false);
        assert.equal(u.undo('x'), null);
        u.push('a');
        u.push('b');
        assert.equal(u.undo('c'), 'b');
        assert.equal(u.canRedo(), true);
        assert.equal(u.redo('b'), 'c');
        assert.equal(u.undo('c'), 'b');
        u.push('b2');
        assert.equal(u.canRedo(), false);
        for (const s of ['1', '2', '3', '4']) u.push(s);
        assert.equal(u.undo('now'), '4');
        assert.equal(u.undo('4'), '3');
        assert.equal(u.undo('3'), '2');
        assert.equal(u.undo('2'), null);
        u.clear();
        assert.equal(u.canUndo() || u.canRedo(), false);
    });

    t.test('stable JSON ignores key order', () => {
        assert.equal(stable({ b: 1, a: [{ d: 1, c: 2 }] }), stable({ a: [{ c: 2, d: 1 }], b: 1 }));
        assert.ok(same({ x: 5 }, { x: 5.0 }));
        assert.ok(!same({ x: 5 }, { x: 6 }));
    });

    t.test('payload carries only dirty design keys and one op per dirty item, in edit order', async () => {
        const doc = await makeDoc(load, { content: { events: { e1: { date: '2027-06-01', name: 'Den dětí', club: null, icon: null } } } });
        const state = {
            doc: { ...doc, design: { ...doc.design, name: 'Nový název', step: 2 }, content: { ...doc.content, events: {}, names: { n1: { date: '2027-01-05', name: 'Vasil' } } } },
            dirtyDesign: new Set(['step', 'name']),
            dirtyContent: new Map([
                ['names/n1', { seq: 2, label: '5. 1.' }],
                ['events/e1', { seq: 1, label: '' }],
            ]),
        };
        const { payload, sent } = buildPayload(state, {});
        assert.deepEqual(payload, {
            design: { name: 'Nový název', step: 2 },
            content: [
                { c: 'events', id: 'e1', v: null },
                { c: 'names', id: 'n1', v: { date: '2027-01-05', name: 'Vasil' }, label: '5. 1.' },
            ],
        });
        assert.deepEqual(sent.content, [{ key: 'events/e1', seq: 1 }, { key: 'names/n1', seq: 2 }]);
        assert.equal(sent.finish, false);
        assert.deepEqual(buildPayload({ ...state, dirtyDesign: new Set(), dirtyContent: new Map() }, { finish: true }).payload, { finish: true });
        assert.deepEqual(buildPayload({ ...state, dirtyDesign: new Set(), dirtyContent: new Map() }, {}).payload, {});
    });

    t.test('response: sent marks are cleared, edits made during the request are overlaid and stay dirty', async () => {
        const doc = await makeDoc(load, {});
        const local = { ...doc, design: { ...doc.design, name: 'A' }, content: { ...doc.content, names: { n1: { date: '2027-01-05', name: 'Vasil' } } } };
        const state = { doc: local, server: { design: doc.design, content: doc.content }, dirtyDesign: new Set(['name']), dirtyContent: new Map([['names/n1', { seq: 1, label: '' }]]) };
        const { sent } = buildPayload(state, {});
        // while the request flies: the name changes again, the item is edited, another one is added and one is removed locally
        const during = {
            ...state,
            doc: { ...local, design: { ...local.design, name: 'B', linkElements: false }, content: { ...local.content, names: { n1: { date: '2027-01-05', name: 'Vasilij' }, n2: { date: '2027-02-01', name: 'X' } } } },
            dirtyDesign: new Set(['name', 'linkElements']),
            dirtyContent: new Map([
                ['names/n1', { seq: 3, label: '' }],
                ['names/n2', { seq: 4, label: '' }],
            ]),
        };
        const response = { calendar: flat(local, { content: { ...copy(local.content), events: { other: { date: '2027-03-03', name: 'Od klienta', club: null, icon: null } } } }) };
        const next = applyResponse(during, response, sent, ENV);
        assert.equal(next.doc.revision, doc.revision + 1);
        assert.equal(next.doc.design.name, 'B');
        assert.equal(next.server.design.name, 'A');
        assert.deepEqual([...next.dirtyDesign].sort(), ['linkElements', 'name']);
        assert.deepEqual(next.doc.content.names, { n1: { date: '2027-01-05', name: 'Vasilij' }, n2: { date: '2027-02-01', name: 'X' } });
        assert.deepEqual([...next.dirtyContent.keys()].sort(), ['names/n1', 'names/n2']);
        // somebody else's item arrives with the response
        assert.equal(next.doc.content.events.other.name, 'Od klienta');
        // nothing changed during the request → everything clean
        const clean = applyResponse(state, response, sent, ENV);
        assert.equal(clean.dirtyDesign.size, 0);
        assert.equal(clean.dirtyContent.size, 0);
        assert.equal(clean.doc.design.name, 'A');
    });

    t.test('response: an item removed locally during the request stays removed and dirty', async () => {
        const doc = await makeDoc(load, { content: { names: { n1: { date: '2027-01-05', name: 'Vasil' } } } });
        const state = { doc, server: { design: doc.design, content: doc.content }, dirtyDesign: new Set(), dirtyContent: new Map() };
        const { sent } = buildPayload(state, {});
        const during = { ...state, doc: { ...doc, content: { ...doc.content, names: {} } }, dirtyContent: new Map([['names/n1', { seq: 1, label: '' }]]) };
        const next = applyResponse(during, { calendar: flat(doc) }, sent, ENV);
        assert.deepEqual(next.doc.content.names, {});
        assert.ok(next.dirtyContent.has('names/n1'));
    });

    t.test('store: status, explicit save, reload-equal state', async () => {
        const { store, server, events } = await makeStore({ preset: null });
        assert.equal(store.getState().status, 'clean');
        assert.equal(store.isDirty(), false);
        store.setDesign({ name: 'Kalendář obce' });
        assert.equal(store.getState().status, 'dirty');
        store.setDesign({ name: 'Stolní kalendář 2027' });
        assert.equal(store.getState().status, 'clean', 'back at the stored value is clean again');
        store.setDesign({ name: 'Kalendář obce' });
        const result = await store.save();
        assert.equal(result.ok, true);
        assert.deepEqual(server.requests, [{ design: { name: 'Kalendář obce' } }]);
        assert.equal(store.getState().status, 'clean');
        assert.equal(store.getState().doc.revision, 2);
        assert.ok(events.some((e) => e.type === 'replace'));
        // nothing dirty → no request
        assert.deepEqual(await store.save(), { ok: true, saved: false });
        assert.equal(server.requests.length, 1);
    });

    t.test('store: a failed save keeps the dirty marks and reports the server message', async () => {
        const { store, server } = await makeStore({ preset: null });
        store.setDesign({ name: 'X' });
        server.fail = 'Název je povinný.';
        const result = await store.save();
        assert.deepEqual(result, { ok: false, error: 'Název je povinný.' });
        assert.equal(store.getState().status, 'error');
        assert.equal(store.isDirty(), true);
        store.setDesign({ name: 'Y' });
        assert.equal(store.getState().status, 'dirty');
        server.fail = null;
        assert.equal((await store.save()).ok, true);
        assert.equal(server.doc.design.name, 'Y');
    });

    t.test('store: a save during a request resolves after one follow-up request', async () => {
        const { store, server } = await makeStore({ preset: null });
        let open;
        server.gate = new Promise((resolve) => (open = resolve));
        store.setDesign({ name: 'První' });
        const first = store.save();
        assert.equal(store.getState().status, 'saving');
        store.setDesign({ name: 'Druhý' });
        const second = store.save();
        const third = store.save();
        assert.equal(second, third);
        server.gate = null;
        open();
        await first;
        assert.equal(store.isDirty(), true, 'the edit made during the request is still dirty');
        await second;
        assert.equal(server.requests.length, 2);
        assert.equal(server.doc.design.name, 'Druhý');
        assert.equal(store.getState().status, 'clean');
    });

    t.test('store: the step is stored silently when nothing else is unsaved', async () => {
        const { store, server, events } = await makeStore({});
        store.setStep(2);
        assert.equal(store.getState().status, 'clean');
        assert.equal(store.isDirty(), false);
        await new Promise((r) => setTimeout(r, 5));
        assert.deepEqual(server.requests, [{ design: { step: 2 } }]);
        assert.equal(events.filter((e) => e.type === 'status').length, 0);
        // with other unsaved work the step waits for the explicit save
        store.setDesign({ name: 'Jiný' });
        store.setStep(3);
        await new Promise((r) => setTimeout(r, 5));
        assert.equal(server.requests.length, 1);
        await store.save();
        assert.deepEqual(server.requests[1], { design: { name: 'Jiný', step: 3 } });
    });

    t.test('store: steps 3–5 need a layout; finish marks the calendar finished', async () => {
        const { store, server } = await makeStore({ preset: null });
        store.setStep(3);
        assert.equal(store.getState().doc.design.step, 1);
        store.choosePreset('photoRight');
        assert.equal(store.getState().doc.design.step, 3);
        assert.equal(store.getState().doc.design.preset, 'photoRight');
        const result = await store.save({ finish: true });
        assert.equal(result.ok, true);
        assert.equal(server.requests[0].finish, true);
        assert.equal(store.getState().doc.design.finished, true);
        assert.equal(store.getState().doc.design.step, 5);
    });

    t.test('store: choosing the other preset mirrors the customised layout as ONE undo step', async () => {
        const { store } = await makeStore({});
        store.change((l) => ({ ...l, boxes: { ...l.boxes, logo: { ...l.boxes.logo, x: l.boxes.logo.x + 3 } } }));
        const customised = copy(store.getState().doc.design.layout);
        store.setStep(2);
        store.choosePreset('photoRight');
        const s = store.getState();
        assert.equal(s.doc.design.preset, 'photoRight');
        assert.equal(s.doc.design.step, 3);
        assert.deepEqual(s.doc.design.layout.boxes, mirrorLayout(customised, s.doc.design.page).boxes);
        assert.equal(store.undo(), true);
        assert.deepEqual(store.getState().doc.design.layout, customised);
        assert.equal(store.getState().doc.design.preset, 'photoLeft');
        assert.equal(store.redo(), true);
        assert.equal(store.getState().doc.design.preset, 'photoRight');
        // the active card again: nothing changes, no undo step
        const before = store.getState().doc.design.layout;
        store.setStep(2);
        store.choosePreset('photoRight');
        assert.equal(store.getState().doc.design.layout, before);
        assert.equal(store.getState().doc.design.step, 3);
    });

    t.test('store: fortnight — the single layout is applied without leaving the step', async () => {
        const { store } = await makeStore({ type: 'fortnight', preset: null });
        store.setStep(2);
        store.choosePreset('twoSided', { advance: false });
        const { design } = store.getState().doc;
        assert.equal(design.step, 2);
        assert.equal(design.preset, 'twoSided');
        assert.ok(design.layout.boxes.cal2);
        assert.equal(store.canUndo(), false);
        store.choosePreset('photoLeft');
        assert.equal(store.getState().doc.design.preset, 'twoSided', 'a preset of another type is ignored');
    });

    t.test('store: change / live change are one undo step each; no-op changes add none', async () => {
        const { store, events } = await makeStore({});
        store.change((l) => l);
        assert.equal(store.canUndo(), false);
        const start = copy(store.getState().doc.design.layout.boxes.photo);
        store.beginChange();
        for (let i = 1; i <= 5; i++) store.updateLive((l) => ({ ...l, boxes: { ...l.boxes, photo: { ...start, w: start.w - i } } }));
        assert.equal(events.filter((e) => e.type === 'layout' && e.live).length, 5);
        assert.equal(store.canUndo(), false);
        store.commitChange();
        assert.equal(store.canUndo(), true);
        assert.equal(store.getState().status, 'dirty');
        store.undo();
        assert.deepEqual(store.getState().doc.design.layout.boxes.photo, start);
        assert.equal(store.canUndo(), false);
        assert.equal(store.getState().status, 'clean');
        // cancel restores without a step
        store.beginChange();
        store.updateLive((l) => ({ ...l, boxes: { ...l.boxes, photo: { ...start, w: 20 } } }));
        store.cancelChange();
        assert.deepEqual(store.getState().doc.design.layout.boxes.photo, start);
        assert.equal(store.canRedo(), true);
        const order = events.map((e) => e.type);
        assert.ok(order.indexOf('undo') > order.indexOf('layout'));
    });

    t.test('store: the caption switch is undoable with a layout and clears a hidden selection', async () => {
        const { store } = await makeStore({});
        store.setUi({ selection: { el: 'cap' } });
        store.setFeature('captions', false);
        assert.equal(store.getState().ui.selection, null);
        assert.equal(store.canUndo(), true);
        store.undo();
        assert.equal(store.getState().doc.design.features.captions, true);
        // a part whose feature goes away falls back to the whole block
        store.setFeature('events', true);
        store.setUi({ selection: { el: 'cal', part: 'ev' } });
        store.setFeature('events', false);
        assert.deepEqual(store.getState().ui.selection, { el: 'cal' });
        // QR element
        store.setFeature('qr', true);
        store.setUi({ selection: { el: 'qr' } });
        store.setFeature('qr', false);
        assert.equal(store.getState().ui.selection, null);
    });

    t.test('store: Označit vše switches everything on, then off', async () => {
        const { store } = await makeStore({ preset: null });
        store.setAllFeatures(true);
        assert.ok(Object.values(store.getState().doc.design.features).every((v) => v === true));
        store.setAllFeatures(false);
        assert.ok(Object.values(store.getState().doc.design.features).every((v) => v === false));
    });

    t.test('store: bleed limits and adaptation of the layout', async () => {
        const { store } = await makeStore({});
        const page = store.getState().doc.design.page;
        store.setPrint({ bleedMm: 99 });
        assert.equal(store.getState().doc.design.print.bleedMm, 10);
        assert.equal(store.getState().doc.design.layout.boxes.head.x, -10);
        store.setPrint({ bleedMm: -4 });
        assert.equal(store.getState().doc.design.print.bleedMm, 0);
        store.setPrint({ bleedMm: 7 });
        store.setPrint({ bleed: false });
        let head = store.getState().doc.design.layout.boxes.head;
        assert.equal(head.x, 0);
        assert.equal(head.x + head.w, page.width);
        store.setPrint({ bleed: true });
        assert.equal(store.getState().doc.design.print.bleedMm, 5, 'off → on resets to 5 mm');
        head = store.getState().doc.design.layout.boxes.head;
        assert.equal(head.x, -5);
        assert.equal(head.x + head.w, page.width + 5);
        assert.equal(store.canUndo(), false, 'bleed changes are not undo steps');
    });

    t.test('store: year change renames a default name, shifts dated content, reports 29 February', async () => {
        const { store } = await makeStore({
            year: 2028,
            name: 'Stolní kalendář 2028',
            preset: null,
            content: {
                events: { a: { date: '2028-02-29', name: 'Přestupný', club: null, icon: null }, b: { date: '2028-06-01', name: 'Den dětí', club: null, icon: null } },
                moonEdits: { m: { op: 'add', date: '2028-01-05', phase: 0 } },
                pagePhotos: { w05: { asset: null, zoom: 1, px: 0.5, py: 0.5 } },
            },
        });
        const result = store.changeYear(2027);
        const { doc } = store.getState();
        assert.deepEqual(result, { dropped: 1 });
        assert.equal(doc.design.year, 2027);
        assert.equal(doc.design.name, 'Stolní kalendář 2027');
        assert.deepEqual(Object.keys(doc.content.events), ['b']);
        assert.equal(doc.content.events.b.date, '2027-06-01');
        assert.deepEqual(doc.content.moonEdits, {});
        assert.ok(doc.content.pagePhotos.w05, 'page overrides stay by week key');
        assert.equal(store.pages().length, 55);
        // a custom name is kept
        store.setDesign({ name: 'Bruzovice' });
        store.changeYear(2029);
        assert.equal(store.getState().doc.design.name, 'Bruzovice');
    });

    t.test('store: type change rebases the layout, keeps styles, clears undo', async () => {
        const { store } = await makeStore({});
        store.change((l) => ({ ...l, styles: { ...l.styles, month: { ...l.styles.month, size: 22 } } }));
        assert.equal(store.canUndo(), true);
        store.changeType('fortnight');
        const { design } = store.getState().doc;
        assert.equal(design.type, 'fortnight');
        assert.equal(design.preset, 'twoSided');
        assert.ok(design.layout.boxes.cal2);
        assert.equal(design.layout.styles.month.size, 22);
        assert.equal(store.canUndo(), false);
        assert.equal(store.pages().length, 29);
        // without a layout only the type changes
        const bare = await makeStore({ preset: null });
        bare.store.changeType('fortnight');
        assert.equal(bare.store.getState().doc.design.preset, null);
        assert.equal(bare.store.getState().doc.design.layout, null);
    });

    t.test('store: content items are dirty per item, never on the undo stack, and saved as ops with labels', async () => {
        const { store, server } = await makeStore({});
        store.putItem('events', 'e1', { date: '2027-06-01', name: 'Den dětí', club: null, icon: null }, '1. 6.');
        store.putItem('names', 'n1', { date: '2027-01-05', name: 'Vasil' });
        store.removeItem('names', 'n1');
        assert.equal(store.canUndo(), false);
        assert.equal(store.getState().dirtyContent.size, 1, 'an item put and removed again equals the server state');
        await store.save();
        assert.deepEqual(server.requests[0], { content: [{ c: 'events', id: 'e1', v: { date: '2027-06-01', name: 'Den dětí', club: null, icon: null }, label: '1. 6.' }] });
        assert.equal(store.getState().status, 'clean');
        assert.equal(store.dayContext().eventsByDate.get('2027-06-01').length, 0 + 1);
    });

    t.test('store: client mode cannot touch the design, the layout or designer-only collections', async () => {
        const { store, server } = await makeStore({}, 'client');
        assert.throws(() => store.setDesign({ name: 'X' }));
        assert.throws(() => store.change((l) => l));
        assert.throws(() => store.setFeature('qr', true));
        assert.throws(() => store.setPrint({ bleed: false }));
        assert.throws(() => store.changeYear(2030));
        assert.throws(() => store.putItem('shiftOps', 's1', { name: 'Provoz', pos: 0 }));
        store.setStep(2);
        assert.equal(store.getState().doc.design.step, 1);
        assert.equal(store.undo(), false);
        store.putItem('pageCaptions', 'w05', { text: 'Rybník' }, '5. týden');
        await store.save();
        assert.deepEqual(Object.keys(server.requests[0]), ['content']);
    });

    t.test('store: derivations are memoised and follow the document', async () => {
        const { store } = await makeStore({});
        const pages = store.pages();
        assert.equal(store.pages(), pages);
        const week = pages.find((p) => p.kind === 'week');
        const g = store.geometry(week);
        assert.equal(store.geometry(week), g);
        assert.equal(store.geometry(pages[0]), null);
        store.change((l) => ({ ...l, boxes: { ...l.boxes, cal: { ...l.boxes.cal, h: l.boxes.cal.h - 10 } } }));
        assert.notEqual(store.geometry(week), g);
        assert.ok(store.warnings() instanceof Map);
        store.setUi({ zoom: 2 });
        assert.equal(store.getState().ui.zoom, 2);
        assert.equal(store.getState().status, 'dirty');
    });
}
