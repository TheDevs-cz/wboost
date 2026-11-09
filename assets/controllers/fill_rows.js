/**
 * Repeatable rows on the fill pages (container-rows plan P4) — the PURE part:
 * what adding / removing / reordering a row does to the fill state.
 *
 * The server materialises every row slot (`max` of them) with its own ids
 * and ships, per row, the id each ROLE (an id of the designed row's tree:
 * the row container, nested containers, members) carries in every slot —
 * `FillRepeatableRows` (`data-fill-rows` on the `.fill-popovers` panel).
 * A row action is therefore a PERMUTATION of slots: every named fill field
 * whose name holds a slot id (`textValues[…]`, `fontValues[…]`,
 * `hiddenValues[…]`, `shapeColorValues[…]`, …) takes the value of the same
 * role in its source slot, and so does every picture-state entry keyed by a
 * slot id (`images` of the fill-history state — the single page's per-slot
 * fields, the group page's pictures + per-dimension placements). Nothing
 * here knows a field family by name except for the pristine values.
 *
 * The overlay feeds the result to FillHistory#transact, which applies it
 * through the user-edit path (each changed field gets its `input` /
 * `change`, editors re-sync) and records ONE undo step for the action.
 *
 * Semantics (Jan, 2026-11-09):
 *  - add: one more active row; the new row shows what its slot holds — the
 *    pristine template-row sample, since removing always resets the slot it
 *    frees (see below);
 *  - remove row i: rows i+1… shift UP one slot with all their values, the
 *    freed last slot resets to pristine (sample text, designed font, not
 *    hidden, no picture);
 *  - up / down: swap the two slots' values.
 */

/** The named field carrying a row's count. */
export function countField(rowId) {
    return `rowCounts[${rowId}]`;
}

/** `data-fill-rows` JSON → row id → row (an object; `{}` when absent). */
export function parseRows(json) {
    if (!json) return {};
    try {
        const rows = JSON.parse(json);
        return rows && typeof rows === "object" && !Array.isArray(rows) ? rows : {};
    } catch (err) {
        return {};
    }
}

/** Every slot id → { row, role, slot } (slot 0-based). */
export function slotIndex(rows) {
    const index = new Map();
    Object.values(rows || {}).forEach((row) => {
        Object.entries(row.roles || {}).forEach(([role, ids]) => {
            (ids || []).forEach((id, slot) => {
                if (!index.has(id)) index.set(id, { row: row.id, role, slot });
            });
        });
    });
    return index;
}

/** A requested count clamped to the row's [min, max] (the server's rule). */
export function clampCount(row, value) {
    const parsed = parseInt(value, 10);
    const count = Number.isFinite(parsed) ? parsed : row.default;
    return Math.max(row.min || 1, Math.min(row.max || 1, count || 1));
}

/** Every id of the slots at or beyond `count` (the hidden, unused rows). */
export function inactiveIds(row, count) {
    const ids = new Set();
    Object.values(row.roles || {}).forEach((slots) => {
        (slots || []).slice(count).forEach((id) => ids.add(id));
    });
    return ids;
}

/**
 * Is `action` allowed on `slot` of a row showing `count` rows? Adding is
 * checked against `max` only — the height gate is the caller's.
 */
export function canApply(row, count, action, slot = null) {
    switch (action) {
        case "add":
            return count < row.max;
        case "remove":
            return count > row.min && Number.isInteger(slot) && slot >= 0 && slot < count;
        case "up":
            return Number.isInteger(slot) && slot > 0 && slot < count;
        case "down":
            return Number.isInteger(slot) && slot >= 0 && slot < count - 1;
        default:
            return false;
    }
}

/**
 * The plan of an action: the new count + target slot → source slot (null
 * = reset to pristine). Slots not in `sources` keep their values. Null when
 * the action is not allowed.
 */
export function plan(row, count, action, slot = null) {
    if (!canApply(row, count, action, slot)) return null;
    const sources = new Map();
    if (action === "add") {
        return { count: count + 1, sources };
    }
    if (action === "remove") {
        for (let target = slot; target < count - 1; target++) sources.set(target, target + 1);
        sources.set(count - 1, null);
        return { count: count - 1, sources };
    }
    const other = action === "up" ? slot - 1 : slot + 1;
    sources.set(slot, other);
    sources.set(other, slot);
    return { count, sources };
}

const SEGMENT = /\[([^\]]*)\]/g;

/** The first bracket segment of a field name that is a slot id of `row`. */
function slotSegment(name, roleOf) {
    SEGMENT.lastIndex = 0;
    let match;
    while ((match = SEGMENT.exec(name)) !== null) {
        const hit = roleOf.get(match[1]);
        if (hit) return { ...hit, start: match.index + 1, end: match.index + 1 + match[1].length };
    }
    return null;
}

function roleIndexOf(row) {
    const roleOf = new Map();
    Object.entries(row.roles || {}).forEach(([role, ids]) => {
        (ids || []).forEach((id, slot) => roleOf.set(id, { role, slot }));
    });
    return roleOf;
}

function clone(value) {
    return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
}

/**
 * The pristine value of a fill FIELD of a row slot: the template row's
 * sample for a text, unchecked for a hide, "" (= designed) for the rest.
 */
export function pristineField(row, name, role, value) {
    if (name.startsWith("textValues[")) {
        const pristine = row.pristine || {};
        return Object.prototype.hasOwnProperty.call(pristine, role) ? pristine[role] : "";
    }
    if (value === null || name.startsWith("hiddenValues[")) return null;
    return "";
}

/**
 * The pristine value of a picture-state entry of an emptied slot: no
 * picture, not hidden (string fields "" / booleans false — the single
 * page's per-slot fields, the group page's pictures) and the NEUTRAL
 * placement under the group page's `placement` map.
 */
export function pristineImageState(path, value, neutralPlacement) {
    if (path[0] === "placement") return { ...(neutralPlacement || {}) };
    if (!value || typeof value !== "object") return value;
    const pristine = {};
    Object.keys(value).forEach((key) => {
        pristine[key] = typeof value[key] === "boolean" ? false : "";
    });
    return pristine;
}

/**
 * Apply a plan to a fill-history state `{ fields, images }` (see
 * variant_fill_overlay_controller#_historyState) — returns a NEW state.
 *
 * @param {object} state
 * @param {object} row          one row of the `data-fill-rows` payload
 * @param {{count: number, sources: Map<number, number|null>}} rowPlan
 * @param {object} [options]
 * @param {(path: string[], role: string, value: *) => *} [options.pristineImage]
 *        pristine value of a picture-state entry keyed by a slot id (`path`
 *        = the keys leading to its parent object)
 */
export function applyPlan(state, row, rowPlan, { pristineImage = null } = {}) {
    const roleOf = roleIndexOf(row);
    const fields = { ...((state && state.fields) || {}) };
    const before = (state && state.fields) || {};

    Object.keys(before).forEach((name) => {
        const hit = slotSegment(name, roleOf);
        if (!hit || !rowPlan.sources.has(hit.slot)) return;
        const source = rowPlan.sources.get(hit.slot);
        if (source === null) {
            fields[name] = pristineField(row, name, hit.role, before[name]);
            return;
        }
        const sourceId = row.roles[hit.role][source];
        const sourceName = name.slice(0, hit.start) + sourceId + name.slice(hit.end);
        fields[name] = Object.prototype.hasOwnProperty.call(before, sourceName)
            ? before[sourceName]
            : pristineField(row, name, hit.role, before[name]);
    });
    fields[countField(row.id)] = String(rowPlan.count);

    const images = state && state.images !== undefined ? permuteImages(state.images, row, rowPlan, roleOf, pristineImage, []) : null;

    return { ...(state || {}), fields, images };
}

function permuteImages(node, row, rowPlan, roleOf, pristineImage, path) {
    if (!node || typeof node !== "object" || Array.isArray(node)) return clone(node);
    const out = {};
    Object.keys(node).forEach((key) => {
        const hit = roleOf.get(key);
        if (!hit || !rowPlan.sources.has(hit.slot)) {
            out[key] = hit ? clone(node[key]) : permuteImages(node[key], row, rowPlan, roleOf, pristineImage, [...path, key]);
            return;
        }
        const source = rowPlan.sources.get(hit.slot);
        const sourceId = source === null ? null : row.roles[hit.role][source];
        if (sourceId !== null && Object.prototype.hasOwnProperty.call(node, sourceId)) {
            out[key] = clone(node[sourceId]);
        } else {
            out[key] = pristineImage ? pristineImage(path, hit.role, clone(node[key])) : clone(node[key]);
        }
    });
    return out;
}

/**
 * The echo painters' view of the unused rows: the deep member ids of every
 * slot beyond its row's `rowCounts[<row>]` mirror — `repeatRows` is the
 * echo payload's map (row id → {min, max, default, slots}).
 */
export function unusedRowMemberIds(root, repeatRows) {
    const ids = new Set();
    Object.keys(repeatRows || {}).forEach((rowId) => {
        const row = repeatRows[rowId] || {};
        const mirror = root.querySelector(`[data-row-count-mirror="${rowId}"]`);
        const count = clampCount(row, mirror ? mirror.value : null);
        (Array.isArray(row.slots) ? row.slots : []).slice(count).forEach((members) => {
            (members || []).forEach((id) => ids.add(id));
        });
    });
    return ids;
}
