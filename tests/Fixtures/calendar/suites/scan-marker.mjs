// The marker of a scanned layout (`layoutOrigin`, AI scan P5 — docs/architecture/calendars.md
// § "Calendar scan — UI"): it lives beside the document, a preset card over a scanned layout
// replaces the layout with the preset's default and clears it, the payload may only CLEAR it, and
// `store.load()` takes a document saved through another door.
import assert from 'node:assert/strict';
import { ENV, copy, makeDoc } from '../helpers.mjs';

export default async function (t, load) {
    const { buildPayload } = await load('state/payload.js');
    const { createStore } = await load('state/store.js');
    const { createEstimatorMeasurer } = await load('render/measure.js');
    const { defaultLayout, mirrorLayout } = await load('model/layouts.js');
    const { normalizeLayout } = await load('model/normalize.js');
    const { bleedOf } = await load('model/defaults.js');

    /** A store whose fake server keeps the marker the way SaveCalendarHandler does. */
    async function makeStore(o = {}, origin = 'scan') {
        const doc = await makeDoc(load, o);
        const server = { doc: copy(doc), origin, requests: [] };
        const api = {
            async save(payload) {
                server.requests.push(copy(payload));
                const design = { ...server.doc.design, ...(payload.design || {}) };
                if ('layoutOrigin' in payload) {
                    assert.equal(payload.layoutOrigin, null, 'the browser may only clear the marker');
                    server.origin = null;
                } else if (payload.design && 'preset' in payload.design && payload.design.preset !== server.doc.design.preset) {
                    server.origin = null;
                }
                server.doc = { id: server.doc.id, revision: server.doc.revision + 1, design, content: server.doc.content };
                return { calendar: { id: server.doc.id, revision: server.doc.revision, ...copy(design), content: copy(server.doc.content) }, assets: {}, savedAt: '2026-11-13T10:00:00+00:00', layoutOrigin: server.origin };
            },
        };
        const env = { ...ENV, assets: {}, mode: 'designer', layoutOrigin: origin };
        const store = createStore({ doc, env, api, measurer: createEstimatorMeasurer() });
        return { store, server, env };
    }

    /** A layout that is visibly not a preset's default: the scan's. */
    const scanned = (layout) => ({ ...layout, styles: { ...layout.styles, head: { ...layout.styles.head, bg: { ...layout.styles.head.bg, color: '#1F3A5F' } } } });

    t.test('the marker comes from env, never sits in the design, and an ordinary payload does not mention it', async () => {
        const { store } = await makeStore({ styles: scanned });
        const state = store.getState();
        assert.equal(state.layoutOrigin, 'scan');
        assert.equal('layoutOrigin' in state.doc.design, false);
        store.change((layout) => ({ ...layout, boxes: { ...layout.boxes, logo: { ...layout.boxes.logo, x: 12 } } }));
        const { payload } = buildPayload(store.getState());
        assert.deepEqual(Object.keys(payload), ['design']);
        assert.equal(store.getState().layoutOrigin, 'scan', 'editing a scanned layout keeps it scanned');
        // a calendar without the marker: nothing changes for it
        const plain = await makeStore({}, null);
        plain.store.choosePreset('photoRight');
        assert.equal('layoutOrigin' in buildPayload(plain.store.getState()).payload, false);
    });

    t.test('a preset card over a scanned layout gives the DEFAULT of that preset (no mirror), clears the marker, one undo brings both back', async () => {
        const { store, env } = await makeStore({ preset: 'photoLeft', styles: scanned });
        const before = copy(store.getState().doc.design.layout);
        const d = store.getState().doc.design;

        // the SAME layout choice the scanned layout carries
        store.choosePreset('photoLeft');
        let s = store.getState();
        assert.equal(s.layoutOrigin, null);
        assert.equal(s.doc.design.preset, 'photoLeft');
        assert.equal(s.doc.design.layout.styles.head.bg.color === '#1F3A5F', false);
        const fresh = normalizeLayout(defaultLayout('photoLeft', d.page, bleedOf(d.print), env), d.type, d.page, env);
        assert.deepEqual(s.doc.design.layout.boxes.photo, fresh.boxes.photo);
        assert.equal(s.status, 'dirty');
        const { payload } = buildPayload(s);
        assert.equal(payload.layoutOrigin, null);
        assert.equal('layout' in payload.design, true);

        assert.equal(store.undo(), true);
        s = store.getState();
        assert.equal(s.layoutOrigin, 'scan');
        assert.deepEqual(s.doc.design.layout, before);
        assert.equal(s.status, 'clean');
        assert.equal('layoutOrigin' in buildPayload(s).payload, false);

        assert.equal(store.redo(), true);
        assert.equal(store.getState().layoutOrigin, null);

        // the OTHER card: the default of photoRight, not the mirrored scan
        store.undo();
        store.choosePreset('photoRight');
        s = store.getState();
        assert.equal(s.doc.design.preset, 'photoRight');
        assert.notDeepEqual(s.doc.design.layout.styles.head.bg, mirrorLayout(before, d.page).styles.head.bg);
        assert.equal(s.layoutOrigin, null);
    });

    t.test('saving the cleared marker: the server drops it, and an undo afterwards cannot bring it back', async () => {
        const { store, server } = await makeStore({ preset: 'photoLeft', styles: scanned });
        store.choosePreset('photoLeft');
        const result = await store.save();
        assert.equal(result.ok, true);
        assert.equal(server.requests.at(-1).layoutOrigin, null);
        assert.equal(server.origin, null);
        assert.equal(store.getState().layoutOrigin, null);
        assert.equal(store.getState().serverLayoutOrigin, null);
        store.undo(); // the scanned layout is back in the editor, the marker is gone for good
        assert.equal(store.getState().layoutOrigin, null);
        assert.equal('layoutOrigin' in buildPayload(store.getState()).payload, false);
    });

    t.test('load(): a document saved through another door replaces everything, the undo stack starts again', async () => {
        const { store } = await makeStore({ preset: 'photoLeft' }, null);
        store.change((layout) => ({ ...layout, boxes: { ...layout.boxes, logo: { ...layout.boxes.logo, x: 20 } } }));
        await store.save();
        assert.equal(store.canUndo(), true);
        const events = [];
        store.subscribe((e) => events.push(e.type));

        const applied = await makeDoc(load, { preset: 'photoRight', styles: scanned });
        store.load({ calendar: { id: 'cal-1', revision: 9, ...copy(applied.design), step: 3, content: copy(applied.content) }, assets: { a1: { url: '/a1.png' } }, layoutOrigin: 'scan' });

        const s = store.getState();
        assert.equal(s.layoutOrigin, 'scan');
        assert.equal(s.serverLayoutOrigin, 'scan');
        assert.equal(s.doc.revision, 9);
        assert.equal(s.doc.design.preset, 'photoRight');
        assert.equal(s.doc.design.layout.styles.head.bg.color, '#1F3A5F');
        assert.equal(s.status, 'clean');
        assert.equal(store.isDirty(), false);
        assert.equal(store.canUndo(), false);
        assert.ok(events.includes('replace'));

        // „Vrátit původní“: the same door, the marker goes
        const restored = await makeDoc(load, { preset: 'photoLeft' });
        store.load({ calendar: { id: 'cal-1', revision: 10, ...copy(restored.design), content: copy(restored.content) }, assets: {}, layoutOrigin: null });
        assert.equal(store.getState().layoutOrigin, null);
        assert.equal(store.getState().doc.design.preset, 'photoLeft');
    });
    t.test('14 days: the one layout card over a scanned two-sided layout puts the default back and clears the marker; undo returns the scan', async () => {
        const moved = (layout) => { const l = scanned(layout); return { ...l, boxes: { ...l.boxes, cal: { ...l.boxes.cal, w: 79 }, cal2: { ...l.boxes.cal2, x: 236, w: 79 } } }; };
        const { store, env } = await makeStore({ type: 'fortnight', styles: moved });
        const d = store.getState().doc.design;
        assert.equal(d.preset, 'twoSided');
        assert.equal(store.getState().layoutOrigin, 'scan');
        assert.equal(d.layout.boxes.cal2.x, 236);

        store.choosePreset('twoSided');
        let s = store.getState();
        assert.equal(s.layoutOrigin, null);
        const fresh = normalizeLayout(defaultLayout('twoSided', d.page, bleedOf(d.print), env), d.type, d.page, env);
        assert.deepEqual(s.doc.design.layout.boxes.cal2, fresh.boxes.cal2);
        assert.equal(s.doc.design.layout.styles.head.bg.color === '#1F3A5F', false);
        // the preset key did not change: the payload itself has to clear the marker
        assert.equal(buildPayload(s).payload.layoutOrigin, null);

        assert.equal(store.undo(), true);
        s = store.getState();
        assert.equal(s.layoutOrigin, 'scan');
        assert.equal(s.doc.design.layout.boxes.cal2.x, 236);
        // a preset of the other type is still ignored
        store.choosePreset('photoLeft');
        assert.equal(store.getState().doc.design.preset, 'twoSided');
        assert.equal(store.getState().layoutOrigin, 'scan');
    });

    t.test('a listener that unsubscribes while an event is delivered is not called for that event', async () => {
        // „Použít rozvržení“: the accepted document comes back at step 3 — the workspace (subscribed
        // first) destroys step 2's module on `replace`; that module must not answer the same event
        // (it redrew step 2 over the step-3 editor).
        const { store } = await makeStore({ preset: 'photoLeft', styles: scanned }, null);
        const calls = [];
        let stopSecond = null;
        store.subscribe((event) => { if (event.type === 'replace') { calls.push('workspace'); stopSecond(); } });
        stopSecond = store.subscribe((event) => { if (event.type === 'replace') calls.push('step2'); });
        store.subscribe((event) => { if (event.type === 'replace') calls.push('topbar'); });
        const applied = await makeDoc(load, { preset: 'photoRight', styles: scanned });
        store.load({ calendar: { id: 'cal-1', revision: 9, ...copy(applied.design), step: 3, content: copy(applied.content) }, assets: {}, layoutOrigin: 'scan' });
        assert.deepEqual(calls, ['workspace', 'topbar']);
        assert.equal(store.getState().doc.design.step, 3);
    });
}
