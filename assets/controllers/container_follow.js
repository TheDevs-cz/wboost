/**
 * Admin editor plans for the two one-click container actions of 2026-11-24
 * (Jan's „Výsledky turnaje" report — the designer wanted "dynamic rows" and
 * ended up with an outer container, a row and a stray non-repeating copy):
 *
 *  - „Dynamické řádky" (`planDynamicRows`): the selected elements of ONE row
 *    become a repeatable row inside a new list root, in one step. Free
 *    elements → a new row container + a new root holding only it; the members
 *    of ONE flat top-level container → that container is converted IN PLACE
 *    (it becomes the row, a new root wraps it and takes over its top-level
 *    settings — spaceAfter, userMove, its own `follows`, hide toggle — and
 *    every container attached below it is re-attached to the new root).
 *  - „Navázat pod sebe" (`planLink`): the selected units (a member stands
 *    for its whole top-level container, a free element becomes a new
 *    single-member container) are chained top to bottom with `follows`.
 *
 * Pure and dependency-free (no Fabric, no DOM): the caller hands in plain
 * selection entries {inputId, top, bottom, candidate} with ABSOLUTE geometry
 * and the container definitions; `apply()` mutates the definitions array in
 * place and returns the ids it created. The PHP suite runs this very file
 * through tests/Fixtures/containers/container-follow-harness.cjs.
 */

/** The gap a new "Navázat pod" link gets: the target's uniform gap, else 20 px. */
export const DEFAULT_FOLLOW_GAP = 20;

/** Uniform gap between the rows of a NEW list root (editable in its ⚙). */
export const DEFAULT_ROWS_GAP = 20;

export function defaultFollowGap(target) {
    return target && typeof target.gap === 'number' && Number.isFinite(target.gap) && target.gap >= 0
        ? target.gap
        : DEFAULT_FOLLOW_GAP;
}

function directParentMap(containers) {
    const parentOf = new Map();
    containers.forEach((c) => (c.memberContainerIds || []).forEach((id) => {
        if (!parentOf.has(id)) parentOf.set(id, c.id);
    }));
    return parentOf;
}

function memberMap(containers) {
    const memberOf = new Map();
    containers.forEach((c) => (c.memberInputIds || []).forEach((id) => {
        if (!memberOf.has(id)) memberOf.set(id, c);
    }));
    return memberOf;
}

function rootIdOf(parentOf, id) {
    const seen = new Set();
    let current = id;
    while (parentOf.has(current) && !seen.has(current)) {
        seen.add(current);
        current = parentOf.get(current);
    }
    return current;
}

function extent(entries) {
    return {
        top: Math.min(...entries.map((e) => e.top)),
        bottom: Math.max(...entries.map((e) => e.bottom)),
    };
}

/**
 * @param {Object} args
 * @param {Array} args.containers   container definitions
 * @param {Array} args.selection    [{inputId, top, bottom, candidate}] — `candidate` = may be a container member
 * @param {number} args.canvasHeight logical canvas height (the new root's bound runs to it)
 * @param {Function} args.newId     () => fresh id
 * @returns {{ok: true, mode: 'free'|'container', apply: Function}|{ok: false, reason: string}}
 */
export function planDynamicRows({ containers, selection, canvasHeight, newId }) {
    if (!selection.length) return { ok: false, reason: 'Vyberte prvky jednoho řádku.' };
    if (selection.some((s) => !s.inputId || !s.candidate)) {
        return { ok: false, reason: 'Řádek může obsahovat texty, obrázky a tvary — ne pozadí.' };
    }
    const memberOf = memberMap(containers);
    const parentOf = directParentMap(containers);
    const inContainer = selection.filter((s) => memberOf.has(s.inputId));
    const box = extent(selection);
    const rootBound = (top) => Math.max(20, Math.floor((canvasHeight || 0) - top));

    if (inContainer.length === 0) {
        return {
            ok: true,
            mode: 'free',
            apply(defs) {
                const row = {
                    id: newId(),
                    maxHeight: Math.max(20, Math.ceil(box.bottom - box.top)),
                    memberInputIds: [...selection].sort((a, b) => a.top - b.top).map((s) => s.inputId),
                    memberContainerIds: [],
                    repeat: { max: 1, min: 1, default: 1 },
                };
                const root = {
                    id: newId(),
                    maxHeight: rootBound(box.top),
                    memberInputIds: [],
                    memberContainerIds: [row.id],
                    gap: DEFAULT_ROWS_GAP,
                };
                defs.push(root, row);
                return { rowId: row.id, rootId: root.id };
            },
        };
    }

    if (inContainer.length !== selection.length) {
        return { ok: false, reason: 'Vyberte buď volné prvky, nebo prvky jednoho kontejneru — ne obojí.' };
    }
    const direct = new Set(inContainer.map((s) => memberOf.get(s.inputId).id));
    if (direct.size !== 1) {
        return { ok: false, reason: 'Prvky leží v různých kontejnerech — vyberte prvky jednoho řádku.' };
    }
    const container = memberOf.get(inContainer[0].inputId);
    if (parentOf.has(container.id)) {
        return {
            ok: false,
            reason: container.repeat
                ? 'Tento kontejner už je opakovatelný řádek.'
                : 'Prvky jsou ve vnořeném kontejneru — ten označíte jako „Opakovatelný řádek" v jeho ⚙.',
        };
    }
    if ((container.memberContainerIds || []).length) {
        return { ok: false, reason: 'Kontejner obsahuje vnořené kontejnery — řádek musí být jednoduchý (jen prvky).' };
    }

    return {
        ok: true,
        mode: 'container',
        apply(defs) {
            const row = defs.find((c) => c.id === container.id);
            if (!row) return null;
            const memberTops = selection.map((s) => s.top);
            const top = Math.min(...memberTops);
            const root = {
                id: newId(),
                maxHeight: Math.max(row.maxHeight || 0, rootBound(top)),
                memberInputIds: [],
                memberContainerIds: [row.id],
                gap: DEFAULT_ROWS_GAP,
            };
            // Top-level settings move to the new root (inert on a nested row).
            ['spaceAfter', 'userMove', 'follows', 'hidable', 'name'].forEach((key) => {
                if (row[key] !== undefined) {
                    root[key] = row[key];
                    delete row[key];
                }
            });
            // Containers attached below the old top-level container follow
            // the new root (the row itself can no longer be a link target).
            defs.forEach((c) => {
                if (c.follows && c.follows.containerId === row.id) {
                    c.follows = { ...c.follows, containerId: root.id };
                }
            });
            row.repeat = { max: 1, min: 1, default: 1 };
            defs.splice(defs.indexOf(row), 0, root);
            return { rowId: row.id, rootId: root.id };
        },
    };
}

/**
 * @param {Object} args
 * @param {Array} args.containers
 * @param {Array} args.selection [{inputId, top, bottom, candidate}]
 * @param {Function} args.newId
 * @returns {{ok: true, count: number, apply: Function}|{ok: false, reason: string}}
 */
export function planLink({ containers, selection, newId }) {
    const memberOf = memberMap(containers);
    const parentOf = directParentMap(containers);
    const units = new Map();
    for (const entry of selection) {
        const owner = entry.inputId ? memberOf.get(entry.inputId) : null;
        if (owner) {
            const rootId = rootIdOf(parentOf, owner.id);
            const unit = units.get(rootId) || { kind: 'container', id: rootId, top: Infinity, bottom: -Infinity };
            unit.top = Math.min(unit.top, entry.top);
            unit.bottom = Math.max(unit.bottom, entry.bottom);
            units.set(rootId, unit);
            continue;
        }
        if (!entry.inputId || !entry.candidate) {
            return { ok: false, reason: 'Navázat lze kontejnery a texty, obrázky či tvary — ne pozadí.' };
        }
        units.set(`free:${entry.inputId}`, { kind: 'free', inputId: entry.inputId, top: entry.top, bottom: entry.bottom });
    }
    if (units.size < 2) {
        return { ok: false, reason: 'Vyberte aspoň dva bloky — prvky dvou kontejnerů, nebo kontejner a volný text pod ním.' };
    }
    const ordered = [...units.values()].sort((a, b) => a.top - b.top);

    return {
        ok: true,
        count: ordered.length,
        apply(defs) {
            const ids = ordered.map((unit) => {
                if (unit.kind === 'container') return unit.id;
                const single = {
                    id: newId(),
                    maxHeight: Math.max(20, Math.ceil((unit.bottom - unit.top) * 1.5)),
                    memberInputIds: [unit.inputId],
                    memberContainerIds: [],
                };
                defs.push(single);
                return single.id;
            });
            const byId = new Map(defs.map((c) => [c.id, c]));
            for (let i = 1; i < ids.length; i += 1) {
                const follower = byId.get(ids[i]);
                const target = byId.get(ids[i - 1]);
                if (!follower || !target) continue;
                // Never close a loop: a target (transitively) attached below
                // its new follower loses that link.
                let cursor = target;
                const seen = new Set();
                while (cursor && cursor.follows && !seen.has(cursor.id)) {
                    seen.add(cursor.id);
                    if (cursor.follows.containerId === follower.id) {
                        delete cursor.follows;
                        break;
                    }
                    cursor = byId.get(cursor.follows.containerId);
                }
                follower.follows = { containerId: target.id, gap: defaultFollowGap(target) };
            }
            return { containerIds: ids };
        },
    };
}

/**
 * The toggled-on state of „Navázat pod sebe" (Jan, 2026-11-25: the ⛓ button
 * never showed that the selected block already IS attached, and offered no
 * way out). The selection stands for already-linked blocks when it is
 *  - ONE top-level container (its members) that follows another one, or
 *  - several top-level containers chained exactly in their top-to-bottom
 *    order (each lower one follows the one right above it).
 * Then `apply()` detaches those links (only the ones the selection shows —
 * the single block's own link, or the links inside the chain). Anything
 * else — a free element in the selection, a gap in the chain — is null: the
 * button keeps its "link" meaning (`planLink`).
 *
 * @param {Object} args
 * @param {Array} args.containers
 * @param {Array} args.selection [{inputId, top, bottom, candidate}]
 * @returns {{count: number, followerIds: string[], apply: Function}|null}
 */
export function planUnlink({ containers, selection }) {
    if (!selection.length) return null;
    const memberOf = memberMap(containers);
    const parentOf = directParentMap(containers);
    const units = new Map();
    for (const entry of selection) {
        const owner = entry.inputId ? memberOf.get(entry.inputId) : null;
        if (!owner) return null;
        const rootId = rootIdOf(parentOf, owner.id);
        units.set(rootId, Math.min(units.has(rootId) ? units.get(rootId) : Infinity, entry.top));
    }
    const byId = new Map(containers.map((c) => [c.id, c]));
    const ordered = [...units.entries()].sort((a, b) => a[1] - b[1]).map(([id]) => id);
    let followerIds;
    if (ordered.length === 1) {
        const root = byId.get(ordered[0]);
        if (!root || !root.follows || !byId.has(root.follows.containerId)) return null;
        followerIds = [root.id];
    } else {
        for (let i = 1; i < ordered.length; i += 1) {
            const follower = byId.get(ordered[i]);
            if (!follower || !follower.follows || follower.follows.containerId !== ordered[i - 1]) return null;
        }
        followerIds = ordered.slice(1);
    }

    return {
        count: followerIds.length,
        followerIds,
        apply(defs) {
            defs.forEach((c) => {
                if (followerIds.includes(c.id)) delete c.follows;
            });
            return { containerIds: followerIds };
        },
    };
}

/**
 * Top-level containers a container may be attached below (⚙ "Navázat pod"):
 * every OTHER top-level container whose own chain does not lead back to it.
 *
 * @returns {Array} definitions
 */
export function followTargets(containers, container) {
    const parentOf = directParentMap(containers);
    if (parentOf.has(container.id)) return [];
    const byId = new Map(containers.map((c) => [c.id, c]));
    const leadsBack = (id) => {
        const seen = new Set();
        let current = byId.get(id);
        while (current && !seen.has(current.id)) {
            if (current.id === container.id) return true;
            seen.add(current.id);
            current = current.follows ? byId.get(current.follows.containerId) : null;
        }
        return false;
    };
    return containers.filter((c) => c.id !== container.id && !parentOf.has(c.id) && !leadsBack(c.id));
}
