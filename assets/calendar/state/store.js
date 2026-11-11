// The calendar state store (architecture §D.4). Pure: no DOM, no timers, no fetch — the HTTP
// client and the text measurer are injected, so the whole store runs in node.
//
// Rules every UI module can rely on:
// - `getState()` returns the live state object; treat it as READ-ONLY. Every change replaces the
//   touched objects (`doc`, `doc.design`, `doc.design.layout`, `doc.content[collection]`, `ui`), so
//   identity comparison is a valid "did it change" test.
// - Mutations emit synchronously, after the state is consistent. Event types:
//     layout  { live: boolean }           the layout changed (`live` = mid-drag / mid-slide, not committed)
//     design  { keys: string[] }          top-level design keys other than `layout` changed
//     content { collection, id }          one content item was put / removed
//     ui      { keys: string[] }          view state changed (never saved)
//     status  { status }                  'clean' | 'dirty' | 'saving' | 'error'
//     undo    { direction }               a snapshot was restored by undo() / redo(); always follows
//                                         the `layout { live: false }` event of that restore
//     replace {}                          the document was replaced by a save response
// - Layout and design changes are designer-only; in client mode they throw (the server refuses
//   them too). Content changes of collections outside the client whitelist throw in client mode.

import { DEFAULT_PRINT, K, bleedOf, clampLimit } from '../model/defaults.js';
import { COLLECTIONS, shiftContentYear } from '../model/content.js';
import { availableParts, isElementVisible } from '../model/elements.js';
import { defaultLayout, mirrorLayout, presetsForType, rebaseLayoutForType } from '../model/layouts.js';
import { normalizeLayout } from '../model/normalize.js';
import { buildPages } from '../generate/pages.js';
import { createDayContext } from '../generate/day.js';
import { layoutWeekPage } from '../render/week-layout.js';
import { allWarnings } from '../render/warnings.js';
import { autoGrow, maxTextWidths } from '../editor/autogrow.js';
import { adaptBoxesToBleed, clampBoxesToBounds } from '../editor/bleed.js';
import { createUndo } from './undo.js';
import { applyResponse, buildPayload, contentKey, same } from './payload.js';

const DEFAULT_UI = Object.freeze({
    selection: null,
    zoom: 1,
    guides: [],
    panelCollapsed: false,
    partsOpen: false,
    layoutMenuOpen: false,
    contentView: 'style',
    previewPage: 0,
    tool: null,
    toolState: {},
    iconCell: null,
});

const clone = (v) => JSON.parse(JSON.stringify(v));

function memo() {
    let deps = null;
    let value;
    return (next, compute) => {
        if (!deps || deps.length !== next.length || next.some((d, i) => d !== deps[i])) {
            value = compute();
            deps = next;
        }
        return value;
    };
}

/**
 * @param o.doc       normalised `Doc`
 * @param o.env       `Env` (`mode`, `fonts`, `accent`, `assets`, `currentYear`, …); `env.assets` is
 *                    extended in place by `addAsset` / `replaceLayout` / save responses
 * @param o.api       `{ save(payload): Promise<SaveResponse> }`
 * @param o.measurer  `Measurer`
 */
export function createStore({ doc, env, api, measurer }) {
    const designer = env.mode === 'designer';
    const undoStack = createUndo(K.UNDO_LIMIT);
    const listeners = new Set();

    const state = {
        doc,
        server: { design: doc.design, content: doc.content },
        dirtyDesign: new Set(),
        dirtyContent: new Map(),
        ui: { ...DEFAULT_UI, toolState: {} },
        status: 'clean',
    };

    let seq = 0;
    let saving = false;
    let failed = false;
    let liveSession = null;
    let assetsVersion = 0;

    // ---- events ---------------------------------------------------------------------------

    function emit(event) {
        for (const fn of [...listeners]) fn(event);
    }

    function isDirty() {
        if (state.dirtyContent.size > 0) return true;
        for (const key of state.dirtyDesign) if (key !== 'step') return true;
        return false;
    }

    function refreshStatus() {
        const next = saving ? 'saving' : failed ? 'error' : isDirty() ? 'dirty' : 'clean';
        if (next === state.status) return;
        state.status = next;
        emit({ type: 'status', status: next });
    }

    /** Called by every mutation: a new edit replaces the "save failed" state by "dirty". */
    function touched() {
        failed = false;
        refreshStatus();
        // nothing but the step is unsaved (e.g. an undo brought the rest back): store it silently
        if (designer && state.status === 'clean' && state.dirtyDesign.has('step')) save();
    }

    function assertDesigner() {
        if (!designer) throw new Error('Tuto změnu může udělat jen grafik.');
    }

    // ---- derivations ----------------------------------------------------------------------

    const pagesMemo = memo();
    const contextMemo = memo();
    const warningsMemo = memo();
    let geometryDeps = [];
    let geometryCache = new Map();

    const wkForm = (design) => (design.layout ? design.layout.styles.wk.form : 'a');

    function pages() {
        const { design, content } = state.doc;
        return pagesMemo([design.year, design.type, design.features.specialPages, content.specialPages, wkForm(design)], () => buildPages(state.doc));
    }

    function dayContext() {
        const { design, content } = state.doc;
        const styles = design.layout ? design.layout.styles : null;
        return contextMemo([content, design.features, styles, design.year, design.type], () => createDayContext(state.doc));
    }

    function layoutOpts(context) {
        return { env: { assets: env.assets, fonts: env.fonts || [] }, measurer, dayContext: context || dayContext() };
    }

    function geometry(page) {
        const { design } = state.doc;
        if (!page || page.kind !== 'week' || !design.layout) return null;
        const context = dayContext();
        const deps = [design.layout, design.features, context, pages()];
        if (deps.some((d, i) => d !== geometryDeps[i])) {
            geometryDeps = deps;
            geometryCache = new Map();
        }
        if (!geometryCache.has(page.key)) geometryCache.set(page.key, layoutWeekPage(state.doc, page, layoutOpts(context)));
        return geometryCache.get(page.key);
    }

    function warnings() {
        const { design } = state.doc;
        const context = dayContext();
        const list = pages();
        return warningsMemo([design.layout, design.features, context, list, assetsVersion], () => allWarnings(state.doc, list, layoutOpts(context)));
    }

    // ---- low-level writes -----------------------------------------------------------------

    function markDesign(...keys) {
        for (const key of keys) {
            if (same(state.doc.design[key], state.server.design[key])) state.dirtyDesign.delete(key);
            else state.dirtyDesign.add(key);
        }
    }

    function writeDesign(patch) {
        state.doc = { ...state.doc, design: { ...state.doc.design, ...patch } };
    }

    function snapshot() {
        const { design } = state.doc;
        return JSON.stringify({ layout: design.layout, captions: design.features.captions, preset: design.preset });
    }

    /** Auto-grow of Měsíc / Číslo týdne (§E.5) for a layout that is about to become current. */
    function grown(layout, design = state.doc.design) {
        if (!layout) return layout;
        const probe = { ...state.doc, design: { ...design, layout } };
        const widths = maxTextWidths(probe, buildPages(probe), { env: { assets: env.assets, fonts: env.fonts || [] }, measurer });
        return autoGrow(layout, widths, probe.design) || layout;
    }

    /** Clears a selection that is no longer drawn; a part whose feature went away falls back to the block. */
    function fixSelection() {
        const selection = state.ui.selection;
        if (!selection) return false;
        const { design } = state.doc;
        let next = selection;
        if (!design.layout || !isElementVisible(design, selection.el) || (selection.frame === 1 && design.type !== 'fortnight')) {
            next = null;
        } else if (selection.part && !availableParts(design).includes(selection.part)) {
            next = { el: selection.el };
            if (selection.frame !== undefined) next.frame = selection.frame;
        }
        if (next === selection) return false;
        state.ui = { ...state.ui, selection: next, iconCell: null };
        return true;
    }

    function clampView() {
        const count = pages().length;
        const patch = {};
        if (state.ui.previewPage > count - 1) patch.previewPage = Math.max(0, count - 1);
        if (typeof state.ui.contentView === 'number' && state.ui.contentView > count - 1) patch.contentView = Math.max(0, count - 1);
        const keys = Object.keys(patch);
        if (keys.length) state.ui = { ...state.ui, ...patch };
        return keys;
    }

    function setUi(patch) {
        const keys = Object.keys(patch).filter((key) => patch[key] !== state.ui[key]);
        if (!keys.length) return;
        state.ui = { ...state.ui, ...patch };
        emit({ type: 'ui', keys });
    }

    function emitUiIf(changed, keys) {
        if (changed) emit({ type: 'ui', keys });
    }

    // ---- design ---------------------------------------------------------------------------

    function setDesign(patch) {
        assertDesigner();
        const next = {};
        if (typeof patch.name === 'string') next.name = patch.name.slice(0, 60);
        if (typeof patch.linkElements === 'boolean') next.linkElements = patch.linkElements;
        if (typeof patch.finished === 'boolean') next.finished = patch.finished;
        const keys = Object.keys(next).filter((key) => next[key] !== state.doc.design[key]);
        if (!keys.length) return;
        writeDesign(next);
        markDesign(...keys);
        emit({ type: 'design', keys });
        touched();
    }

    function setStep(step) {
        if (!designer) return;
        const n = Number(step);
        if (![1, 2, 3, 4, 5].includes(n) || n === state.doc.design.step) return;
        if (n >= 3 && !state.doc.design.layout) return;
        const wasClean = state.status === 'clean';
        writeDesign({ step: n });
        markDesign('step');
        emit({ type: 'design', keys: ['step'] });
        // the step is stored silently when nothing else is unsaved, so reopening returns here
        if (wasClean && state.dirtyDesign.has('step')) save();
    }

    function applyFeatures(features) {
        assertDesigner();
        const { design } = state.doc;
        const keys = Object.keys(features).filter((key) => key in design.features && features[key] !== design.features[key]);
        if (!keys.length) return;
        const before = snapshot();
        const next = { ...design.features };
        for (const key of keys) next[key] = features[key] === true;
        const layout = grown(design.layout, { ...design, features: next });
        if (keys.includes('captions') && design.layout) undoStack.push(before);
        writeDesign({ features: next, layout });
        markDesign('features', 'layout');
        const selectionChanged = fixSelection();
        if (layout !== design.layout) emit({ type: 'layout', live: false });
        emit({ type: 'design', keys: ['features'] });
        emitUiIf(selectionChanged, ['selection']);
        touched();
    }

    function setFeature(key, on) {
        applyFeatures({ [key]: on });
    }

    function setAllFeatures(on) {
        const all = {};
        for (const key of Object.keys(state.doc.design.features)) all[key] = on === true;
        applyFeatures(all);
    }

    function setPrint(patch) {
        assertDesigner();
        const { design } = state.doc;
        const next = { ...design.print };
        if (typeof patch.marks === 'boolean') next.marks = patch.marks;
        if (patch.bleedMm !== undefined) next.bleedMm = clampLimit('bleedMm', patch.bleedMm);
        if (typeof patch.bleed === 'boolean' && patch.bleed !== next.bleed) {
            next.bleed = patch.bleed;
            if (patch.bleed) next.bleedMm = DEFAULT_PRINT.bleedMm;
        }
        if (same(next, design.print)) return;
        const oldBleed = bleedOf(design.print);
        const newBleed = bleedOf(next);
        let layout = design.layout;
        if (layout && oldBleed !== newBleed) {
            layout = { ...layout, boxes: adaptBoxesToBleed(layout.boxes, design.page, oldBleed, newBleed) };
        }
        writeDesign({ print: next, layout });
        markDesign('print', 'layout');
        if (layout !== design.layout) emit({ type: 'layout', live: false });
        emit({ type: 'design', keys: ['print'] });
        touched();
    }

    function writeItem(collection, id, value, label) {
        const current = state.doc.content[collection] || {};
        const next = { ...current };
        if (value === null || value === undefined) delete next[id];
        else next[id] = clone(value);
        state.doc = { ...state.doc, content: { ...state.doc.content, [collection]: next } };
        const key = contentKey(collection, id);
        const stored = state.server.content[collection] ? state.server.content[collection][id] : undefined;
        if (same(next[id] === undefined ? null : next[id], stored === undefined ? null : stored)) state.dirtyContent.delete(key);
        else state.dirtyContent.set(key, { seq: ++seq, label: label ? String(label) : '' });
    }

    function changeYear(year) {
        assertDesigner();
        const { design, content } = state.doc;
        const to = Number(year);
        if (!Number.isInteger(to) || to === design.year) return { dropped: 0 };
        const from = design.year;
        const patch = { year: to };
        const keys = ['year'];
        if (design.name === `Stolní kalendář ${from}`) {
            patch.name = `Stolní kalendář ${to}`;
            keys.push('name');
        }
        const { ops, dropped } = shiftContentYear(content, from, to);
        writeDesign(patch);
        const changed = [];
        for (const op of ops) {
            const current = content[op.c] ? content[op.c][op.id] : undefined;
            if (same(current === undefined ? null : current, op.v)) continue;
            writeItem(op.c, op.id, op.v, '');
            changed.push(op);
        }
        const layout = grown(state.doc.design.layout);
        if (layout !== state.doc.design.layout) writeDesign({ layout });
        markDesign(...keys, 'layout');
        const uiKeys = clampView();
        if (layout !== design.layout) emit({ type: 'layout', live: false });
        emit({ type: 'design', keys });
        for (const op of changed) emit({ type: 'content', collection: op.c, id: op.id });
        emitUiIf(uiKeys.length > 0, uiKeys);
        touched();
        return { dropped };
    }

    function changeType(type) {
        assertDesigner();
        const { design } = state.doc;
        const next = type === 'fortnight' ? 'fortnight' : 'week';
        if (next === design.type) return;
        const patch = { type: next };
        const keys = ['type'];
        if (design.layout) {
            const rebased = rebaseLayoutForType(design.layout, next, design.page, bleedOf(design.print));
            const normalised = normalizeLayout(rebased.layout, next, design.page, env);
            patch.preset = rebased.preset;
            patch.layout = grown(normalised, { ...design, type: next, preset: rebased.preset });
            keys.push('preset');
        }
        writeDesign(patch);
        markDesign(...keys, 'layout');
        undoStack.clear();
        liveSession = null;
        const hadSelection = state.ui.selection !== null;
        state.ui = { ...state.ui, selection: null, iconCell: null };
        const uiKeys = clampView();
        if (design.layout) emit({ type: 'layout', live: false });
        emit({ type: 'design', keys });
        emitUiIf(hadSelection || uiKeys.length > 0, ['selection', ...uiKeys]);
        touched();
    }

    /**
     * Step 2 (§E.8). `opts.advance === false` keeps the current step (the automatic application
     * of the single 14-day layout); by default the calendar moves to step 3.
     */
    function choosePreset(preset, opts = {}) {
        assertDesigner();
        const { design } = state.doc;
        if (!presetsForType(design.type).includes(preset)) return;
        const keys = [];
        let layoutChanged = false;
        if (!design.layout) {
            const layout = grown(normalizeLayout(defaultLayout(preset, design.page, bleedOf(design.print), env), design.type, design.page, env), { ...design, preset });
            writeDesign({ preset, layout });
            keys.push('preset');
            layoutChanged = true;
        } else if (preset !== design.preset) {
            const before = snapshot();
            const layout = grown(mirrorLayout(design.layout, design.page), { ...design, preset });
            undoStack.push(before);
            writeDesign({ preset, layout });
            keys.push('preset');
            layoutChanged = true;
        }
        const advance = opts.advance !== false && state.doc.design.step !== 3;
        if (advance) {
            writeDesign({ step: 3 });
            keys.push('step');
        }
        markDesign('preset', 'layout', 'step');
        const hadSelection = state.ui.selection !== null;
        if (hadSelection) state.ui = { ...state.ui, selection: null, iconCell: null };
        if (layoutChanged) emit({ type: 'layout', live: false });
        if (keys.length) emit({ type: 'design', keys });
        emitUiIf(hadSelection, ['selection']);
        touched();
    }

    // ---- layout ---------------------------------------------------------------------------

    function change(fn, opts = {}) {
        assertDesigner();
        const { design } = state.doc;
        if (!design.layout) return;
        const before = snapshot();
        const produced = fn(design.layout) || design.layout;
        const captions = typeof opts.captions === 'boolean' ? opts.captions : design.features.captions;
        const features = captions === design.features.captions ? design.features : { ...design.features, captions };
        const layout = grown(produced, { ...design, features });
        writeDesign({ layout, features });
        if (snapshot() === before) {
            writeDesign({ layout: design.layout, features: design.features });
            return;
        }
        undoStack.push(before);
        markDesign('layout', 'features');
        const selectionChanged = fixSelection();
        emit({ type: 'layout', live: false });
        if (features !== design.features) emit({ type: 'design', keys: ['features'] });
        emitUiIf(selectionChanged, ['selection']);
        touched();
    }

    function beginChange() {
        assertDesigner();
        if (!state.doc.design.layout) return;
        liveSession = { snapshot: snapshot(), layout: state.doc.design.layout };
    }

    function updateLive(fn) {
        assertDesigner();
        if (!state.doc.design.layout) return;
        if (!liveSession) beginChange();
        const layout = fn(state.doc.design.layout) || state.doc.design.layout;
        if (layout === state.doc.design.layout) return;
        writeDesign({ layout });
        emit({ type: 'layout', live: true });
    }

    function commitChange() {
        if (!liveSession) return;
        const session = liveSession;
        liveSession = null;
        const layout = grown(state.doc.design.layout);
        if (layout !== state.doc.design.layout) writeDesign({ layout });
        if (snapshot() !== session.snapshot) {
            undoStack.push(session.snapshot);
            markDesign('layout');
        }
        emit({ type: 'layout', live: false });
        touched();
    }

    function cancelChange() {
        if (!liveSession) return;
        const session = liveSession;
        liveSession = null;
        if (state.doc.design.layout !== session.layout) writeDesign({ layout: session.layout });
        emit({ type: 'layout', live: false });
    }

    function mergeAssets(assets) {
        if (!assets || typeof assets !== 'object') return;
        for (const [id, info] of Object.entries(assets)) env.assets[id] = info;
        assetsVersion++;
    }

    function replaceLayout(layout, assets) {
        assertDesigner();
        const { design } = state.doc;
        mergeAssets(assets);
        const before = snapshot();
        const hadLayout = Boolean(design.layout);
        const preset = presetsForType(design.type).includes(design.preset) ? design.preset : presetsForType(design.type)[0];
        const normalised = normalizeLayout(layout, design.type, design.page, env);
        const clamped = { ...normalised, boxes: clampBoxesToBounds(normalised.boxes, design.page, bleedOf(design.print)) };
        writeDesign({ layout: grown(clamped, { ...design, preset }), preset });
        if (hadLayout) undoStack.push(before);
        markDesign('layout', 'preset');
        liveSession = null;
        const hadSelection = state.ui.selection !== null;
        state.ui = { ...state.ui, selection: null, iconCell: null };
        emit({ type: 'layout', live: false });
        emitUiIf(hadSelection, ['selection']);
        touched();
    }

    function restore(text, direction) {
        const { design } = state.doc;
        const parsed = JSON.parse(text);
        let layout = parsed.layout;
        if (layout) layout = { ...layout, boxes: clampBoxesToBounds(layout.boxes, design.page, bleedOf(design.print)) };
        const features = parsed.captions === design.features.captions ? design.features : { ...design.features, captions: parsed.captions === true };
        const preset = presetsForType(design.type).includes(parsed.preset) ? parsed.preset : design.preset;
        writeDesign({ layout, features, preset });
        markDesign('layout', 'features', 'preset');
        liveSession = null;
        const selectionChanged = fixSelection();
        emit({ type: 'layout', live: false });
        const keys = [];
        if (features !== design.features) keys.push('features');
        if (preset !== design.preset) keys.push('preset');
        if (keys.length) emit({ type: 'design', keys });
        emitUiIf(selectionChanged, ['selection']);
        emit({ type: 'undo', direction });
        touched();
    }

    function undo() {
        if (!designer || !undoStack.canUndo()) return false;
        if (liveSession) cancelChange();
        restore(undoStack.undo(snapshot()), 'undo');
        return true;
    }

    function redo() {
        if (!designer || !undoStack.canRedo()) return false;
        if (liveSession) cancelChange();
        restore(undoStack.redo(snapshot()), 'redo');
        return true;
    }

    // ---- content --------------------------------------------------------------------------

    function assertCollection(collection) {
        const spec = COLLECTIONS[collection];
        if (!spec) throw new Error(`Unknown content collection "${collection}"`);
        if (!designer && !spec.clientWritable) throw new Error('Tuto změnu může udělat jen grafik.');
    }

    function putItem(collection, id, value, label) {
        assertCollection(collection);
        writeItem(collection, String(id), value, label);
        const uiKeys = clampView();
        emit({ type: 'content', collection, id: String(id) });
        emitUiIf(uiKeys.length > 0, uiKeys);
        touched();
    }

    function removeItem(collection, id, label) {
        putItem(collection, id, null, label);
    }

    /** `ops`: `[{ c, id, v, label? }]`, applied in order (`v: null` removes). */
    function applyOps(ops) {
        const list = Array.isArray(ops) ? ops : [];
        for (const op of list) assertCollection(op.c);
        for (const op of list) writeItem(op.c, String(op.id), op.v, op.label);
        const uiKeys = clampView();
        for (const op of list) emit({ type: 'content', collection: op.c, id: String(op.id) });
        emitUiIf(uiKeys.length > 0, uiKeys);
        if (list.length) touched();
    }

    /** Registers an uploaded asset (`api.upload` answer: `{ id, url, previewUrl, width, height, … }`). */
    function addAsset(info) {
        if (!info || typeof info.id !== 'string') return;
        const { id, ...rest } = info;
        env.assets[id] = rest;
        assetsVersion++;
    }

    // ---- saving ---------------------------------------------------------------------------

    let inFlight = null;
    let followUp = null;
    let followUpFinish = false;

    async function runSave(opts) {
        const finish = opts.finish === true;
        const nothing = state.dirtyDesign.size === 0 && state.dirtyContent.size === 0;
        if (nothing && !finish) return { ok: true, saved: false };
        // a save that only stores the step is silent: no status change, no error state
        const quiet = !finish && !isDirty();
        const { payload, sent } = buildPayload(state, { finish });
        if (!quiet) {
            saving = true;
            refreshStatus();
        }
        try {
            const response = await api.save(payload);
            mergeAssets(response.assets);
            const next = applyResponse(state, response, sent, env);
            state.doc = next.doc;
            state.server = next.server;
            state.dirtyDesign = next.dirtyDesign;
            state.dirtyContent = next.dirtyContent;
            saving = false;
            failed = false;
            const selectionChanged = fixSelection();
            const uiKeys = clampView();
            emit({ type: 'replace' });
            emitUiIf(selectionChanged || uiKeys.length > 0, [...(selectionChanged ? ['selection'] : []), ...uiKeys]);
            refreshStatus();
            return { ok: true, saved: true, savedAt: response.savedAt || null };
        } catch (error) {
            saving = false;
            if (!quiet) failed = true;
            refreshStatus();
            return { ok: false, error: (error && error.message) || 'Uložení se nepovedlo' };
        }
    }

    /**
     * One request at a time; a `save()` during a request resolves after one follow-up request that
     * carries everything changed meanwhile.
     */
    function save(opts = {}) {
        if (inFlight) {
            followUpFinish = followUpFinish || opts.finish === true;
            if (!followUp) {
                followUp = inFlight.then(() => {
                    const finish = followUpFinish;
                    followUp = null;
                    followUpFinish = false;
                    return save({ finish });
                });
            }
            return followUp;
        }
        const request = runSave(opts).then((result) => {
            if (inFlight === request) inFlight = null;
            // the step moved while this request was on its way (5 → 1 → 5 faster than the round
            // trip): the answer kept the local step, and nothing but the step is unsaved — store it
            // now, silently, so the last step chosen is the one a reload opens
            if (result.ok && designer && !followUp && state.dirtyDesign.has('step') && !isDirty()) save();
            return result;
        });
        inFlight = request;
        return request;
    }

    return {
        getState: () => state,
        subscribe(fn) {
            listeners.add(fn);
            return () => listeners.delete(fn);
        },
        pages,
        dayContext,
        geometry,
        warnings,
        /** `LayoutOpts` of the current document, for `renderPage` / `layoutWeekPage` callers. */
        layoutOpts: () => layoutOpts(),
        setDesign,
        setStep,
        setFeature,
        setAllFeatures,
        setPrint,
        changeYear,
        changeType,
        choosePreset,
        change,
        beginChange,
        updateLive,
        commitChange,
        cancelChange,
        replaceLayout,
        undo,
        redo,
        canUndo: () => designer && undoStack.canUndo(),
        canRedo: () => designer && undoStack.canRedo(),
        putItem,
        removeItem,
        applyOps,
        addAsset,
        setUi,
        isDirty,
        save,
    };
}
