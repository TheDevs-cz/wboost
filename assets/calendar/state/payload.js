// Save payload and response handling (architecture §C.5, §D.4). Pure, node-tested.
//
// The store keeps `{ doc, server, dirtyDesign: Set<key>, dirtyContent: Map<'collection/id', { seq, label }> }`.
// `buildPayload` turns the dirty marks into the request, `applyResponse` folds the server's answer
// back in while keeping every edit made after the request was built.

import { normalizeDocument } from '../model/normalize.js';

export const DESIGN_KEYS = Object.freeze(['name', 'year', 'type', 'page', 'print', 'features', 'preset', 'layout', 'linkElements', 'step', 'finished']);

/** Canonical JSON (object keys sorted) — equality that ignores key order. */
export function stable(value) {
    if (Array.isArray(value)) return `[${value.map(stable).join(',')}]`;
    if (value && typeof value === 'object') {
        return `{${Object.keys(value)
            .sort()
            .filter((k) => value[k] !== undefined)
            .map((k) => `${JSON.stringify(k)}:${stable(value[k])}`)
            .join(',')}}`;
    }
    return JSON.stringify(value === undefined ? null : value);
}

export const same = (a, b) => stable(a) === stable(b);

export const contentKey = (collection, id) => `${collection}/${id}`;

export function splitContentKey(key) {
    const at = key.indexOf('/');
    return { c: key.slice(0, at), id: key.slice(at + 1) };
}

const clone = (v) => (v === undefined ? undefined : JSON.parse(JSON.stringify(v)));

/**
 * @param state  `{ doc, dirtyDesign, dirtyContent }`
 * @param opts   `{ finish?: boolean }`
 * @returns `{ payload, sent }` — `payload` is the JSON of §C.5; `sent` remembers what went out:
 *          `{ design: { [key]: canonical JSON }, content: [{ key, seq }], finish }`
 */
export function buildPayload(state, opts = {}) {
    const payload = {};
    const sent = { design: {}, content: [], finish: opts.finish === true };

    const design = {};
    for (const key of DESIGN_KEYS) {
        if (!state.dirtyDesign.has(key)) continue;
        design[key] = clone(state.doc.design[key]);
        sent.design[key] = stable(state.doc.design[key]);
    }
    if (Object.keys(design).length) payload.design = design;

    const ops = [];
    const entries = [...state.dirtyContent.entries()].sort((a, b) => a[1].seq - b[1].seq);
    for (const [key, mark] of entries) {
        const { c, id } = splitContentKey(key);
        const item = state.doc.content[c] ? state.doc.content[c][id] : undefined;
        const op = { c, id, v: item === undefined ? null : clone(item) };
        if (mark.label) op.label = String(mark.label).slice(0, 120);
        ops.push(op);
        sent.content.push({ key, seq: mark.seq });
    }
    if (ops.length) payload.content = ops;

    if (sent.finish) payload.finish = true;
    return { payload, sent };
}

/**
 * Folds a save response into the state. Edits made while the request was in flight survive:
 * a design key whose value changed since it was sent stays dirty and keeps its local value;
 * a content item whose `seq` moved stays dirty and keeps its local value (or its local absence).
 *
 * @param state     `{ doc, server, dirtyDesign, dirtyContent }`
 * @param response  `{ calendar, assets?, savedAt? }` (§C.5)
 * @param sent      the marker of `buildPayload`
 * @param env       `{ fonts, accent, currentYear }` for `normalizeDocument`
 * @returns `{ doc, server, dirtyDesign, dirtyContent }` (new objects; the input is not mutated)
 */
export function applyResponse(state, response, sent, env) {
    const fresh = normalizeDocument(response.calendar, env);

    const dirtyDesign = new Set();
    const design = { ...fresh.design };
    // also the keys that were sent: one that went back to its previously saved value while the
    // request was in flight (step 3 → 1 → 3, an undo) carries no dirty mark, yet differs from what
    // the server has just stored — the local value must win and be saved again
    for (const key of new Set([...state.dirtyDesign, ...Object.keys(sent.design)])) {
        const wasSent = Object.prototype.hasOwnProperty.call(sent.design, key);
        const unchangedSinceSent = wasSent && sent.design[key] === stable(state.doc.design[key]);
        if (unchangedSinceSent) continue;
        design[key] = clone(state.doc.design[key]);
        if (!same(design[key], fresh.design[key])) dirtyDesign.add(key);
    }

    const sentSeq = new Map(sent.content.map((s) => [s.key, s.seq]));
    const dirtyContent = new Map();
    const content = { ...fresh.content };
    for (const [key, mark] of state.dirtyContent) {
        if (sentSeq.get(key) === mark.seq) continue;
        const { c, id } = splitContentKey(key);
        const local = state.doc.content[c] ? state.doc.content[c][id] : undefined;
        content[c] = { ...(content[c] || {}) };
        if (local === undefined) delete content[c][id];
        else content[c][id] = clone(local);
        dirtyContent.set(key, mark);
    }

    // normalise once more: a locally kept `type` / `layout` pair must stay consistent
    const doc = normalizeDocument({ id: fresh.id, revision: fresh.revision, design, content }, env);
    return {
        doc,
        server: { design: fresh.design, content: fresh.content },
        dirtyDesign,
        dirtyContent,
    };
}
