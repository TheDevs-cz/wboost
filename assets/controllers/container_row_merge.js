/**
 * "Sloučit do opakovaného řádku" (container-rows plan P6) — turn N
 * hand-numbered copies of a row ("Skóre 1 + Soupeř 1" … "Skóre 5 + Soupeř 5",
 * "D - Střelec 1–4") into ONE repeatable row, WITHOUT orphaning what the old
 * rows' ids carry (stored export versions, drafts, API callers, documents).
 *
 * Pure and dependency-free on purpose: the admin editor runs it on the live
 * Fabric canvas AND on every group-editor shadow, and the PHP suite runs it in
 * node on the prod fixtures (tests/Fixtures/containers/row-merge-harness.cjs)
 * to prove the merged design renders like the original. It reads objects as
 * plain property bags (Fabric objects or POJOs) and never mutates them —
 * `planRowMerge` decides, `applyRowMerge` rewrites the container definitions,
 * the caller removes `plan.removeInputIds` and applies `plan.moves`.
 *
 * Two shapes are recognised from the selection (one element per row is
 * enough):
 *  - `containers`: the rows are sibling NESTED containers of one parent
 *    (Výsledky turnaje: five 2-member rows in a root). Row 1's container
 *    becomes the repeatable row.
 *  - `texts`: the rows are single direct members of one container (the
 *    Výsledek scorer columns). Row 1's element is wrapped into a new nested
 *    1-item row container (id from the caller — a group passes the SAME id to
 *    every size).
 *
 * The result: row 1 (the topmost) stays as the template row with
 * `repeat {max (caller: ≥ N, what fits), min 1, default N, slots}`; rows 2…N
 * (objects, definitions) leave the design. `slots[k−1]` maps every id of the
 * template row's tree (member inputIds + container ids) to the id row k+1
 * HAD, so the server's expansion gives slot k those LEGACY ids instead of
 * derived ones (PHP RepeatableRows::expandCanvas, ContainerRepeat::$slots) —
 * every old value lands in its row. Everything designed below the rows (in
 * the parent, then in each ancestor) moves up by the removed rows' height, so
 * the server's expansion — which pushes them down again by the materialised
 * rows — reproduces the original geometry.
 *
 * What is refused (with the reason the button shows): fewer than 2 rows,
 * rows that are not structurally the same (member count / kinds / order, or a
 * difference that would change pixels or value semantics — font, size,
 * colour, alignment, rich vs plain, hidable, locked, picture source, …), rows
 * that are not consecutive in the parent's flow, a parent without a uniform
 * gap (the pitch of the repeated rows would not be the drawn one), a parent
 * that already has a repeatable row, rows inside / holding a repeatable row.
 * Differences that only move pixels a little (a 1.85 px nudge, a narrower
 * text box) or touch settings with no render effect (max length, font /
 * colour allowlists) are SOFT: listed for the designer to confirm — the
 * merged rows take row 1's.
 */

/** Geometry noise below this is not a difference (px). */
export const MERGE_TOLERANCE = 0.5;

/** Properties whose difference refuses the merge (pixels or value semantics). */
const HARD_PROPS = [
    'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'textAlign', 'lineHeight', 'charSpacing',
    'underline', 'linethrough', 'overline', 'fill', 'stroke', 'strokeWidth', 'strokeDashArray',
    'opacity', 'angle', 'scaleX', 'scaleY', 'flipX', 'flipY', 'skewX', 'skewY', 'originX', 'originY',
    'shadow', 'textBackgroundColor', 'backgroundColor', 'direction', 'paintFirst', 'rx', 'ry', 'radius',
    'shapeKind', 'uppercase', 'richText', 'lists', 'listBullet', 'listBulletImage', 'listIndent',
    'listItemSpacing', 'listBlockSpacing', 'listCheckboxes', 'listCheckboxImage', 'listCheckboxCheckedImage',
    'checklist', 'locked', 'hidable', 'imagePlaceholder', 'allowMove', 'allowResize', 'allowRotate',
    'colorChoice', 'visible', 'isBackground',
];

/** Settings with no render effect: a difference is listed, row 1's wins. */
const SOFT_PROPS = {
    maxLength: 'max. délka',
    allowedFonts: 'nabídka písem',
    fontChoice: 'volba písma',
    allowedColors: 'nabídka barev',
    allowedDirectoryIds: 'složky galerie',
    colorFree: 'volná barva',
    userMove: 'posun uživatelem',
    checklistAdd: 'přidávání položek',
    checklistRemove: 'mazání položek',
    checklistEditText: 'úprava položek',
    checklistToggle: 'odškrtávání',
};

const typeOf = (obj) => String(obj.type || '').toLowerCase();

function kindOf(obj) {
    const type = typeOf(obj);
    if (type === 'textbox') return 'text';
    if (type === 'image') return obj.imagePlaceholder === true ? 'slot' : 'image';
    return `shape:${type}`;
}

const KIND_LABELS = { text: 'text', image: 'obrázek', slot: 'obrázek k vyplnění' };

function labelOf(obj) {
    const name = typeof obj.name === 'string' ? obj.name.trim() : '';
    if (name) return `„${name}“`;
    const kind = kindOf(obj);
    return KIND_LABELS[kind] || 'tvar';
}

/** Absolute designed geometry of a plain object (the default accessor). */
export function plainGeometry(obj) {
    const scaleX = typeof obj.scaleX === 'number' && obj.scaleX !== 0 ? obj.scaleX : 1;
    const scaleY = typeof obj.scaleY === 'number' && obj.scaleY !== 0 ? obj.scaleY : 1;
    return {
        left: Number(obj.left) || 0,
        top: Number(obj.top) || 0,
        width: (Number(obj.width) || 0) * scaleX,
        height: (Number(obj.height) || 0) * scaleY,
    };
}

/** A comparable form of a property value (gradients / shadows as plain data, floats rounded). */
function comparable(value) {
    let plain = value;
    if (plain && typeof plain === 'object' && typeof plain.toObject === 'function') plain = plain.toObject();
    return JSON.stringify(plain === undefined ? null : plain, (key, v) => (typeof v === 'number' ? Math.round(v * 10000) / 10000 : v));
}

function srcOf(obj) {
    if (typeof obj.missingSrc === 'string' && obj.missingSrc !== '') return obj.missingSrc;
    if (typeof obj.getSrc === 'function') return obj.getSrc();
    return typeof obj.src === 'string' ? obj.src : null;
}

function round(value) {
    return Math.round(value * 100) / 100;
}

// --- container helpers (definitions = {id, memberInputIds, memberContainerIds, …}) ---

function byIdMap(containers) {
    return new Map(containers.map((c) => [c.id, c]));
}

function parentIdOf(containers, id) {
    const parent = containers.find((c) => c.id !== id && (c.memberContainerIds || []).includes(id));
    return parent ? parent.id : null;
}

function directContainerIdOf(containers, inputId) {
    const owner = containers.find((c) => (c.memberInputIds || []).includes(inputId));
    return owner ? owner.id : null;
}

/** Container ids from `id` up to its root. */
function chainOf(containers, id) {
    const chain = [];
    const seen = new Set();
    let current = id;
    while (current && !seen.has(current)) {
        seen.add(current);
        chain.push(current);
        current = parentIdOf(containers, current);
    }
    return chain;
}

function treeIds(containers, id, seen = new Set()) {
    if (seen.has(id)) return [];
    seen.add(id);
    const container = byIdMap(containers).get(id);
    if (!container) return [id];
    return [id, ...(container.memberContainerIds || []).flatMap((childId) => treeIds(containers, childId, seen))];
}

function deepMemberIds(containers, id) {
    const map = byIdMap(containers);
    return treeIds(containers, id).flatMap((cid) => ((map.get(cid) || {}).memberInputIds || []));
}

/**
 * Plan the merge of the rows the selection touches.
 *
 * @param {Object} options
 * @param {Array} options.objects canvas objects (Fabric or POJOs, `inputId` set)
 * @param {Array} options.containers container definitions
 * @param {Array<string>} options.selectedIds inputIds of the selected objects
 * @param {Function} [options.geometry] obj → {left, top, width, height}, absolute
 * @returns {{ok: false, reason: string} | {ok: true, mode: string, parentId: string,
 *   count: number, templateRowId: (string|null), templateMemberId: (string|null),
 *   templateHeight: number, rowMemberIds: Array<string>, removeInputIds: Array<string>,
 *   removeContainerIds: Array<string>, slots: Array<Object>, moves: Object, soft: Array<string>}}
 */
export function planRowMerge({ objects, containers, selectedIds, geometry = plainGeometry }) {
    const fail = (reason) => ({ ok: false, reason });
    const defs = Array.isArray(containers) ? containers : [];
    const map = byIdMap(defs);
    const objectById = new Map();
    (objects || []).forEach((obj) => {
        if (obj && obj.inputId && !objectById.has(obj.inputId)) objectById.set(obj.inputId, obj);
    });

    const ids = [...new Set((selectedIds || []).filter(Boolean))];
    if (ids.length < 2) return fail('Vyberte aspoň dva řádky (z řádku o více prvcích stačí jeden prvek).');

    // 1. Which container holds the rows, and what a row is.
    const chains = [];
    for (const id of ids) {
        const direct = directContainerIdOf(defs, id);
        if (!direct) return fail('Všechny vybrané prvky musí být v kontejneru (sloupec nebo seznam řádků).');
        chains.push({ id, chain: chainOf(defs, direct) });
    }
    const parentId = chains[0].chain.find((cid) => chains.every((entry) => entry.chain.includes(cid)));
    if (!parentId) return fail('Vybrané řádky nejsou ve stejném kontejneru.');

    const items = [];
    chains.forEach(({ id, chain }) => {
        const at = chain.indexOf(parentId);
        const item = at === 0 ? { kind: 'text', id } : { kind: 'container', id: chain[at - 1] };
        if (!items.some((existing) => existing.id === item.id)) items.push(item);
    });
    const mode = items[0].kind === 'text' ? 'texts' : 'containers';
    if (!items.every((item) => item.kind === items[0].kind)) {
        return fail('Výběr míchá samostatné prvky a celé řádky — vyberte jen jedno nebo druhé.');
    }
    if (items.length < 2) return fail('Vyberte prvky aspoň ze dvou různých řádků.');

    // 2. Structure rules.
    const parent = map.get(parentId);
    if (chainOf(defs, parentId).some((cid) => (map.get(cid) || {}).repeat)) {
        return fail('Uvnitř opakovaného řádku další opakovaný řádek být nemůže.');
    }
    if ((parent.memberContainerIds || []).some((cid) => (map.get(cid) || {}).repeat)) {
        return fail('Kontejner už má opakovaný řádek — v jednom kontejneru může být jen jeden.');
    }
    if (mode === 'containers' && items.some((item) => treeIds(defs, item.id).some((cid) => (map.get(cid) || {}).repeat))) {
        return fail('Vybrané řádky už obsahují opakovaný řádek.');
    }

    // 3. Geometry of the parent's flow items (designed extents).
    const extentOf = (memberIds) => {
        let top = Infinity;
        let bottom = -Infinity;
        memberIds.forEach((id) => {
            const obj = objectById.get(id);
            if (!obj || obj.visible === false) return;
            const g = geometry(obj);
            top = Math.min(top, g.top);
            bottom = Math.max(bottom, g.top + g.height);
        });
        return top === Infinity ? null : { top, bottom };
    };
    const flowItems = [
        ...(parent.memberInputIds || []).map((id) => ({ id, members: [id] })),
        ...(parent.memberContainerIds || []).map((id) => ({ id, members: deepMemberIds(defs, id) })),
    ].map((item) => ({ ...item, extent: extentOf(item.members) })).filter((item) => item.extent);
    flowItems.sort((a, b) => a.extent.top - b.extent.top);

    const rows = items.map((item) => flowItems.find((flow) => flow.id === item.id)).filter(Boolean);
    if (rows.length !== items.length) return fail('Některý vybraný řádek je skrytý nebo prázdný.');
    rows.sort((a, b) => a.extent.top - b.extent.top);

    if (rows.some((row, k) => k > 0 && row.extent.top < rows[k - 1].extent.bottom - MERGE_TOLERANCE)) {
        return fail('Vybrané prvky nejsou řádky pod sebou — vyberte prvky z různých řádků (stačí jeden z každého).');
    }

    const positions = rows.map((row) => flowItems.indexOf(row));
    if (positions.some((position, k) => k > 0 && position !== positions[k - 1] + 1)) {
        return fail('Mezi vybranými řádky je jiný prvek kontejneru — vyberte řádky, které jdou po sobě.');
    }
    if (typeof parent.gap !== 'number') {
        const pitch = rows.length > 1 ? round(rows[1].extent.top - rows[0].extent.bottom) : 0;
        return fail(`Kontejner nemá jednotnou mezeru — nastavte ji v ⚙ (mezi řádky je teď asi ${pitch} px), jinak by opakované řádky neměly rozestup podle návrhu.`);
    }

    // 4. Structural equivalence, pairing every row's tree with row 1's.
    const soft = [];
    const slots = [];
    const memberObjects = (memberIds) => memberIds
        .map((id) => objectById.get(id))
        .filter(Boolean)
        .sort((a, b) => {
            const ga = geometry(a);
            const gb = geometry(b);
            return Math.abs(ga.top - gb.top) > MERGE_TOLERANCE ? ga.top - gb.top : ga.left - gb.left;
        });

    const compareObjects = (template, other, rowLabel, origin) => {
        if (kindOf(template) !== kindOf(other)) {
            return `${rowLabel}: místo prvku ${labelOf(template)} je ${labelOf(other)}.`;
        }
        for (const prop of HARD_PROPS) {
            if (comparable(template[prop]) !== comparable(other[prop])) {
                return `${rowLabel}: ${labelOf(other)} má jiné „${prop}“ než ${labelOf(template)} — sloučit jde jen stejně vypadající řádky.`;
            }
        }
        if ((kindOf(template) === 'image') && srcOf(template) !== srcOf(other)) {
            return `${rowLabel}: ${labelOf(other)} má jiný obrázek než řádek 1.`;
        }
        Object.keys(SOFT_PROPS).forEach((prop) => {
            if (comparable(template[prop]) !== comparable(other[prop])) {
                soft.push(`${rowLabel}: ${labelOf(other)} — jiné nastavení „${SOFT_PROPS[prop]}“`);
            }
        });
        const gt = geometry(template);
        const go = geometry(other);
        const dx = go.left - gt.left;
        const dy = (go.top - origin.other) - (gt.top - origin.template);
        const dw = go.width - gt.width;
        const dh = kindOf(template) === 'text' ? 0 : go.height - gt.height; // a text's height follows its content
        if (Math.abs(dx) > MERGE_TOLERANCE || Math.abs(dy) > MERGE_TOLERANCE) {
            soft.push(`${rowLabel}: ${labelOf(other)} je posunutý o ${round(dx)} / ${round(dy)} px`);
        }
        if (Math.abs(dw) > MERGE_TOLERANCE || Math.abs(dh) > MERGE_TOLERANCE) {
            soft.push(`${rowLabel}: ${labelOf(other)} má jiný rozměr (${round(dw)} / ${round(dh)} px)`);
        }
        return null;
    };

    // Pairs template-tree id → row-k id; returns a hard reason or null.
    const pairTrees = (templateId, otherId, rowLabel, origin, pairs) => {
        const template = map.get(templateId);
        const other = map.get(otherId);
        pairs[templateId] = otherId;
        if (comparable(template.gap ?? null) !== comparable(other.gap ?? null) || Boolean(template.hidable) !== Boolean(other.hidable)) {
            return `${rowLabel} má jinou mezeru nebo skrývání než řádek 1.`;
        }
        if (Boolean(template.hidable) && (template.name || '') !== (other.name || '')) {
            soft.push(`${rowLabel}: jiný název skupiny pro skrytí`);
        }
        const tm = memberObjects(template.memberInputIds || []);
        const om = memberObjects(other.memberInputIds || []);
        if (tm.length !== om.length) return `${rowLabel} má jiný počet prvků (${om.length}) než řádek 1 (${tm.length}).`;
        for (let i = 0; i < tm.length; i++) {
            const reason = compareObjects(tm[i], om[i], rowLabel, origin);
            if (reason) return reason;
            pairs[tm[i].inputId] = om[i].inputId;
        }
        const childExtent = (cid) => extentOf(deepMemberIds(defs, cid)) || { top: 0 };
        const tc = [...(template.memberContainerIds || [])].sort((a, b) => childExtent(a).top - childExtent(b).top);
        const oc = [...(other.memberContainerIds || [])].sort((a, b) => childExtent(a).top - childExtent(b).top);
        if (tc.length !== oc.length) return `${rowLabel} má jiný počet vnořených skupin než řádek 1.`;
        for (let i = 0; i < tc.length; i++) {
            const reason = pairTrees(tc[i], oc[i], rowLabel, origin, pairs);
            if (reason) return reason;
        }
        return null;
    };

    const template = rows[0];
    for (let k = 1; k < rows.length; k++) {
        const rowLabel = `Řádek ${k + 1}`;
        const origin = { template: template.extent.top, other: rows[k].extent.top };
        const pairs = {};
        if (mode === 'texts') {
            const reason = compareObjects(objectById.get(template.id), objectById.get(rows[k].id), rowLabel, origin);
            if (reason) return fail(reason);
            pairs[template.id] = rows[k].id;
        } else {
            const reason = pairTrees(template.id, rows[k].id, rowLabel, origin, pairs);
            if (reason) return fail(reason);
        }
        slots.push(pairs);
    }

    // 5. What leaves the design, and what moves up.
    const removed = rows.slice(1);
    const removeInputIds = removed.flatMap((row) => row.members);
    const removeContainerIds = mode === 'containers' ? removed.flatMap((row) => treeIds(defs, row.id)) : [];
    const shiftUp = rows[rows.length - 1].extent.top - rows[0].extent.top;

    const moves = {};
    if (shiftUp > 0) {
        const blockTop = rows[0].extent.top;
        // In the parent: the items designed below the rows.
        flowItems.forEach((item) => {
            if (!rows.includes(item) && item.extent.top > blockTop) item.members.forEach((id) => { moves[id] = -shiftUp; });
        });
        // In each ancestor: the items designed below the branch holding them.
        let branchId = parentId;
        let ancestorId = parentIdOf(defs, branchId);
        const seen = new Set([parentId]);
        while (ancestorId && !seen.has(ancestorId)) {
            seen.add(ancestorId);
            const ancestor = map.get(ancestorId);
            const branchExtent = extentOf(deepMemberIds(defs, branchId));
            const branchTop = branchExtent ? branchExtent.top : blockTop;
            [
                ...(ancestor.memberInputIds || []).map((id) => ({ id, members: [id] })),
                ...(ancestor.memberContainerIds || []).filter((cid) => cid !== branchId).map((cid) => ({ id: cid, members: deepMemberIds(defs, cid) })),
            ].forEach((item) => {
                if (item.id === branchId) return;
                const extent = extentOf(item.members);
                if (extent && extent.top > branchTop) item.members.forEach((id) => { moves[id] = -shiftUp; });
            });
            branchId = ancestorId;
            ancestorId = parentIdOf(defs, branchId);
        }
    }

    const templateObject = mode === 'texts' ? objectById.get(template.id) : null;

    return {
        ok: true,
        mode,
        parentId,
        count: rows.length,
        templateRowId: mode === 'containers' ? template.id : null,
        templateMemberId: mode === 'texts' ? template.id : null,
        templateHeight: templateObject ? geometry(templateObject).height : 0,
        rowMemberIds: rows.flatMap((row) => row.members),
        removeInputIds,
        removeContainerIds,
        slots,
        moves,
        soft,
    };
}

/**
 * The container definitions after the merge (a deep copy — the input is not
 * touched): rows 2…N gone, row 1 marked `repeat` with the legacy slots.
 *
 * @param {Array} containers definitions
 * @param {Object} plan a successful planRowMerge() result
 * @param {Object} options
 * @param {string} options.rowId id of the NEW row container (`texts` mode)
 * @param {number} options.max the row's max (≥ plan.count)
 * @returns {{containers: Array, rowId: string}}
 */
export function applyRowMerge(containers, plan, { rowId, max }) {
    const defs = JSON.parse(JSON.stringify(containers || []));
    const count = plan.count;
    const repeat = { max: Math.max(count, Math.min(30, max || count)), min: 1, default: count };
    if (plan.slots.some((slot) => Object.keys(slot).length)) repeat.slots = plan.slots;

    const removeIds = new Set(plan.removeContainerIds);
    const kept = defs.filter((c) => !removeIds.has(c.id));
    kept.forEach((c) => {
        c.memberContainerIds = (c.memberContainerIds || []).filter((id) => !removeIds.has(id));
    });

    if (plan.mode === 'containers') {
        const row = kept.find((c) => c.id === plan.templateRowId);
        row.repeat = repeat;
        return { containers: kept, rowId: row.id };
    }

    const parent = kept.find((c) => c.id === plan.parentId);
    const rowMembers = new Set(plan.rowMemberIds);
    parent.memberInputIds = (parent.memberInputIds || []).filter((id) => !rowMembers.has(id));
    parent.memberContainerIds = [...(parent.memberContainerIds || []), rowId];
    kept.push({
        id: rowId,
        // Inert for a nested container (only the root bounds) but must be
        // positive to survive the sanitizer (≡ makeActiveRow).
        maxHeight: Math.max(20, Math.ceil(plan.templateHeight)),
        memberInputIds: [plan.templateMemberId],
        memberContainerIds: [],
        repeat,
    });
    return { containers: kept, rowId };
}
