/**
 * Repeatable rows ("Opakovatelný řádek", container-rows P3) — the admin
 * editor's geometry of a row: its PITCH (how far apart the rows stack) and
 * how many rows FIT the root container's bound. The editor shows only the
 * template row; these numbers drive the ⚙ prefill ("vejde se N"), the dashed
 * ghost outlines of rows 2…max and the overflow warning.
 *
 * The rules mirror the server's expansion (PHP RepeatableRows::expand +
 * designedCapacity): the gap after a row is the parent's uniform `gap`, else
 * the DESIGNED gap from the row's extent to the nearest item below it in its
 * parent, else 0; the engine stacks the rows at the row's FLOW height + that
 * gap. That distance is the PITCH, and each extra row grows the root's flow
 * by exactly one pitch
 * (hidden rows ≡ absent rows, P0), so with designed geometry
 *
 *     rows fit  ⇔  contentBottom + extra + (n − 1) × pitch ≤ limit
 *
 * where `limit` = min(root top + maxHeight, canvasHeight − spaceAfter) and
 * `extra` = the OTHER repeatable rows of the same root at their default.
 * Parity with RepeatableRows::designedCapacity is pinned by
 * tests/Golden/ContainerRowsEditorGeometryTest (node, both prod fixtures).
 *
 * Pure and dependency-free: the engine (window.WBoostContainerLayout) and
 * the root results of its `applyFabricLayout` are passed in, so the same
 * code runs on live Fabric objects (editor) and on geometry POJOs (node).
 */

/** Hard cap — ContainerRepeat::MAX_ROWS. */
export const MAX_ROWS = 30;

/** Float noise of designed geometry must not flip a fit (≡ PHP FIT_TOLERANCE_PX). */
const FIT_TOLERANCE = 0.5;

function extentOf(objects, getTop) {
    let top = Infinity;
    let bottom = -Infinity;
    let left = Infinity;
    let right = -Infinity;
    objects.forEach((o) => {
        if (o.visible === false) return;
        const oTop = getTop(o);
        const oLeft = typeof o.left === 'number' ? o.left : 0;
        top = Math.min(top, oTop);
        bottom = Math.max(bottom, oTop + o.height * (o.scaleY || 1));
        left = Math.min(left, oLeft);
        right = Math.max(right, oLeft + o.width * (o.scaleX || 1));
    });
    return top === Infinity ? null : { top, bottom, left, right };
}

/**
 * The geometry of one repeatable row, or null (not repeatable / not nested /
 * nothing visible).
 *
 * @param {Array} objects   flat canvas objects (live Fabric or POJOs)
 * @param {Array} containers container definitions
 * @param {string} rowId
 * @param {Object} opts { layout, results? (applyFabricLayout output — the row's
 *                        flow height), getTop? }
 * @returns {null|{row, parent, root, extent, gap, pitch}}
 */
export function rowGeometry(objects, containers, rowId, opts) {
    const layout = opts.layout;
    const getTop = opts.getTop || ((o) => o.top);
    const row = containers.find((c) => c.id === rowId);
    if (!layout || !row || !row.repeat) return null;
    const parent = layout.parentOf(containers, rowId);
    if (!parent) return null;

    const extent = extentOf(layout.collectDeepMemberObjects(objects, containers, row), getTop);
    if (!extent) return null;

    let gap = (typeof parent.gap === 'number' && isFinite(parent.gap) && parent.gap >= 0) ? parent.gap : null;
    if (gap === null) {
        // The designed gap to the nearest item BELOW the row in its parent:
        // direct members one by one, child containers as their whole tree.
        let nextTop = null;
        const consider = (itemExtent) => {
            if (itemExtent && itemExtent.top > extent.top) {
                nextTop = nextTop === null ? itemExtent.top : Math.min(nextTop, itemExtent.top);
            }
        };
        layout.collectMembers(objects, parent).forEach((o) => consider(extentOf([o], getTop)));
        layout.childContainersOf(containers, parent).forEach((child) => {
            if (child.id === row.id) return;
            consider(extentOf(layout.collectDeepMemberObjects(objects, containers, child), getTop));
        });
        gap = nextTop === null ? 0 : nextTop - extent.bottom;
    }

    // How far apart the rows land: the server materialises slot k at
    // k × (extent + gap) DESIGNED, and the engine then stacks each row at the
    // row's FLOW height (it ends at the row's last item — in Turnaj "Soupeř"
    // ends above the taller "Skóre") + the gap before the next one (the
    // uniform gap, or the designed gap measured from the extent's bottom).
    const rowResult = (Array.isArray(opts.results) ? opts.results : []).find((r) => r && r.id === row.id);
    const flowHeight = rowResult && typeof rowResult.contentBottom === 'number'
        ? rowResult.contentBottom - rowResult.containerTop
        : extent.bottom - extent.top;
    const pitch = flowHeight + gap;

    return {
        row,
        parent,
        root: layout.rootOf(containers, rowId) || parent,
        extent,
        gap,
        pitch: Math.max(1, pitch),
    };
}

/**
 * How many rows of `rowId` fit (0…MAX_ROWS — NOT clamped to the row's own
 * max/min: the ⚙ popover prefills max with it and warns when max exceeds it;
 * 0 = even the designed single row overflows).
 *
 * @param {Array} objects
 * @param {Array} containers
 * @param {string} rowId
 * @param {Object} opts { layout, results (applyFabricLayout output),
 *                        canvasHeight, getTop?, otherCounts? }
 * @returns {null|{fits, geometry, limitBottom, limitKind, contentBottom}}
 */
export function rowCapacity(objects, containers, rowId, opts) {
    const geometry = rowGeometry(objects, containers, rowId, opts);
    if (!geometry) return null;
    const results = Array.isArray(opts.results) ? opts.results : [];
    const rootResult = results.find((r) => r && r.id === geometry.root.id && !r.nested);
    if (!rootResult) return null;

    // The OTHER repeatable rows of this root at their default (or the
    // count asked for) — the server's `$otherCounts`.
    let extra = 0;
    const otherCounts = opts.otherCounts || {};
    const layout = opts.layout;
    const walk = (container, seen) => {
        layout.childContainersOf(containers, container).forEach((child) => {
            if (seen.has(child.id)) return;
            seen.add(child.id);
            if (child.repeat && child.id !== rowId) {
                const other = rowGeometry(objects, containers, child.id, opts);
                const count = Number.isInteger(otherCounts[child.id]) ? otherCounts[child.id] : child.repeat.default;
                if (other && count > 1) extra += (count - 1) * other.pitch;
            }
            walk(child, seen);
        });
    };
    walk(geometry.root, new Set([geometry.root.id]));

    const root = geometry.root;
    let limitBottom = rootResult.containerTop + root.maxHeight;
    let limitKind = 'maxHeight';
    const canvasHeight = opts.canvasHeight;
    if (typeof canvasHeight === 'number' && canvasHeight > 0) {
        const spaceAfter = (typeof root.spaceAfter === 'number' && isFinite(root.spaceAfter)) ? root.spaceAfter : 0;
        if (canvasHeight - spaceAfter < limitBottom) {
            limitBottom = canvasHeight - spaceAfter;
            limitKind = 'page';
        }
    }

    const contentBottom = rootResult.contentBottom + extra;
    const room = limitBottom + FIT_TOLERANCE - contentBottom;
    const fits = room < 0
        ? 0
        : Math.min(MAX_ROWS, 1 + Math.floor(room / geometry.pitch));

    return { fits, geometry, limitBottom, limitKind, contentBottom };
}

/**
 * The server's designedCapacity() from `fits`: the largest count in
 * [min, max] that fits, `min` when not even that does.
 */
export function clampedCapacity(fits, repeat) {
    return Math.max(repeat.min, Math.min(repeat.max, fits));
}
