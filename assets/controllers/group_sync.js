import { CANVAS_CUSTOM_PROPERTIES } from './canvas_custom_properties.js';
import { applyGeometryDelta, projectGeometry, ratios } from './group_projection.js';

/**
 * Propagation engine for the template-group editor.
 *
 * Design: BASELINE-SNAPSHOT DIFFING. The engine keeps a snapshot of every
 * active-canvas object (geometry + propagatable props + stack order, keyed by
 * inputId) and, on each pass, diffs the live objects against it. Whatever
 * changed is applied to every included target variant's shadow canvas —
 * geometry as relative deltas (per-variant fine-tunes survive), everything
 * else as absolute copies of ONLY the changed keys.
 *
 * Why not Fabric's transform payloads: several codebase call sites fire
 * `object:modified` with no target/transform (alignment restack, container
 * drags, arrow-key moves), and the property panels fire no Fabric event at
 * all (they funnel through markUnsaved → canvas-editor:dirty). Diffing
 * handles every mutation path uniformly and is idempotent.
 */

// Non-geometry keys that propagate as absolute copies when they change.
const STYLE_KEYS = [
    'text', 'fontFamily', 'fill', 'textAlign', 'underline', 'linethrough',
    'overline', 'lineHeight', 'charSpacing',
];
const META_KEYS = [
    'name', 'maxLength', 'locked', 'uppercase', 'description', 'hidable',
    'richText', 'imagePlaceholder', 'allowMove', 'allowResize', 'allowRotate',
    'allowedDirectoryIds', 'visible',
    // List config propagates as exact copies. The px values (indent /
    // spacings) do NOT rescale per dimension — admins of grouped templates
    // should keep them blank (null = derived from the font size, which the
    // projector DOES scale, so the defaults track each dimension correctly).
    'lists', 'listBullet', 'listBulletImage', 'listIndent', 'listItemSpacing', 'listBlockSpacing',
    'listCheckboxes', 'listCheckboxImage', 'listCheckboxCheckedImage',
    'sampleValue',
];

function isTextboxObject(obj) {
    return (obj.type || '').toLowerCase() === 'textbox';
}

/**
 * Objects that can be CONTAINER members: texts + decorative images (mirrors
 * the shared layout engine's isMemberCandidate — fillable placeholders and
 * background layers never join a flow).
 */
function isContainerMemberObject(obj) {
    const layout = window.WBoostContainerLayout;
    if (layout) {
        return layout.isMemberCandidate(obj);
    }
    return isTextboxObject(obj);
}

/** Vertical-spacing projection (gap / spaceAfter): scale when set, keep the
 *  key absent when not. */
function projectedSpacing(value, ry) {
    return typeof value === 'number' && isFinite(value)
        ? value * ry
        : undefined;
}

/**
 * Objects the propagation engine is allowed to touch. Background layers are
 * NEVER syncable — backgrounds are strictly per-dimension: their cover fit is
 * an absolute function of (image, canvas size), so relative deltas would
 * compound into drift across variants, and group-seeded siblings share the
 * background's inputId, so a resync would clobber every sibling's own cover.
 */
function isSyncable(obj) {
    return !!obj.inputId && obj.isBackground !== true;
}

/**
 * Absolute left/top even while the object sits inside an ActiveSelection —
 * mirrors canvas_container_controller._absTop/_absLeft (the transform
 * matrix's translation is the object's absolute centre).
 */
function absLeft(obj) {
    if (obj.group) {
        const m = obj.calcTransformMatrix();
        return m[4] - (obj.width * (obj.scaleX || 1)) / 2;
    }
    return obj.left;
}

function absTop(obj) {
    if (obj.group) {
        const m = obj.calcTransformMatrix();
        return m[5] - (obj.height * (obj.scaleY || 1)) / 2;
    }
    return obj.top;
}

function snapshotGeometry(obj) {
    const geom = {
        left: absLeft(obj),
        top: absTop(obj),
        scaleX: obj.scaleX || 1,
        scaleY: obj.scaleY || 1,
        angle: obj.angle || 0,
        width: obj.width,
    };

    if (isTextboxObject(obj)) {
        geom.fontSize = obj.fontSize;
    }

    return geom;
}

function snapshotProps(obj) {
    const props = {};

    [...STYLE_KEYS, ...META_KEYS].forEach((key) => {
        const value = obj[key];
        props[key] = Array.isArray(value) ? value.slice() : value;
    });

    return props;
}

function propsEqual(a, b) {
    if (Array.isArray(a) || Array.isArray(b)) {
        return JSON.stringify(a || []) === JSON.stringify(b || []);
    }
    return a === b;
}

export class GroupSync {
    /**
     * @param {Object} options
     * @param {Function} options.activeCanvas  () => the interactive Fabric canvas
     * @param {Function} options.activeDims    () => {width, height} of the active variant
     * @param {Function} options.targets       () => [{id, shadow, width, height}] for
     *                                         INCLUDED, non-active variants
     */
    constructor({ activeCanvas, activeDims, targets }) {
        this.activeCanvas = activeCanvas;
        this.activeDims = activeDims;
        this.targets = targets;
        this.baseline = new Map();
        this.baselineOrder = [];
        this.baselineContainers = [];
    }

    rebaseline() {
        const canvas = this.activeCanvas();
        this.baseline = new Map();
        this.baselineOrder = [];

        canvas.getObjects().forEach((obj) => {
            if (!isSyncable(obj)) {
                return;
            }
            this.baseline.set(obj.inputId, {
                geom: snapshotGeometry(obj),
                props: snapshotProps(obj),
            });
            this.baselineOrder.push(obj.inputId);
        });

        this.baselineContainers = Array.isArray(canvas.wboostContainers)
            ? JSON.parse(JSON.stringify(canvas.wboostContainers))
            : [];
    }

    /**
     * Diff active canvas against the baseline and propagate every change to
     * all included targets. Returns the Set of touched target variant ids.
     */
    syncPass() {
        const canvas = this.activeCanvas();
        const activeDims = this.activeDims();
        const targets = this.targets();
        const touched = new Set();

        canvas.getObjects().forEach((obj) => {
            if (!isSyncable(obj)) {
                return;
            }

            const base = this.baseline.get(obj.inputId);
            if (!base) {
                // Object appeared without going through onObjectAdded (e.g.
                // during a restore) — nothing to diff against; the baseline
                // rebuild below picks it up.
                return;
            }

            const curGeom = snapshotGeometry(obj);
            const curProps = snapshotProps(obj);

            const changedProps = [...STYLE_KEYS, ...META_KEYS].filter(
                (key) => !propsEqual(base.props[key], curProps[key]),
            );
            const geomChanged = Object.keys(base.geom).some(
                (key) => base.geom[key] !== curGeom[key],
            );

            if (!geomChanged && changedProps.length === 0) {
                return;
            }

            targets.forEach((target) => {
                const match = target.shadow.getObjects().find((o) => o.inputId === obj.inputId);
                if (!match) {
                    return; // element missing in this variant — silently skip
                }

                const { rx, ry } = ratios(activeDims, target);

                if (geomChanged) {
                    const targetGeom = snapshotGeometry(match);
                    const changes = applyGeometryDelta(base.geom, curGeom, targetGeom, rx, ry);
                    if (Object.keys(changes).length > 0) {
                        match.set(changes);
                    }
                }

                changedProps.forEach((key) => {
                    const value = curProps[key];
                    match.set(key, Array.isArray(value) ? value.slice() : value);
                });

                if (isTextboxObject(match) && typeof match.initDimensions === 'function') {
                    match.initDimensions();
                }
                match.setCoords();

                touched.add(target.id);
            });
        });

        this._syncZOrder(targets, touched);
        this._syncContainers(activeDims, targets, touched);

        this.rebaseline();

        return touched;
    }

    /**
     * Project a freshly added active-canvas object into every included target
     * with the SAME inputId (absolute projection — there is nothing to be
     * relative to yet).
     */
    async projectNewObject(obj) {
        const touched = new Set();

        // Background layers never fan out — every dimension keeps (or lacks)
        // its own. Belt to the caller's event-gate suspenders.
        if (obj.isBackground === true) {
            return touched;
        }

        const activeDims = this.activeDims();
        const targets = this.targets();

        for (const target of targets) {
            if (target.shadow.getObjects().some((o) => o.inputId === obj.inputId)) {
                continue; // already there (double event guard)
            }

            const clone = await obj.clone(CANVAS_CUSTOM_PROPERTIES);
            // Fabric's clone() suffers the same custom-property stripping as
            // toJSON — re-stamp from the source object.
            CANVAS_CUSTOM_PROPERTIES.forEach((prop) => {
                if (obj[prop] !== undefined) {
                    clone[prop] = obj[prop];
                }
            });

            const { rx, ry } = ratios(activeDims, target);
            const projected = projectGeometry(snapshotGeometry(obj), rx, ry, isTextboxObject(obj));
            clone.set(projected);

            if (isTextboxObject(clone) && typeof clone.initDimensions === 'function') {
                clone.initDimensions();
            }
            clone.setCoords();

            target.shadow.add(clone);
            touched.add(target.id);
        }

        return touched;
    }

    /** Propagate a deletion (matched by inputId) to every included target. */
    removeObject(inputId) {
        const targets = this.targets();
        const touched = new Set();

        targets.forEach((target) => {
            const match = target.shadow.getObjects().find((o) => o.inputId === inputId);
            if (!match) {
                return;
            }
            target.shadow.remove(match);

            // Prune the removed member from the target's own container
            // definitions (mirrors the active canvas' _pruneRemoved).
            const containers = Array.isArray(target.shadow.wboostContainers)
                ? target.shadow.wboostContainers
                : [];
            containers.forEach((container) => {
                if (Array.isArray(container.memberInputIds)) {
                    container.memberInputIds = container.memberInputIds.filter((id) => id !== inputId);
                }
            });

            touched.add(target.id);
        });

        return touched;
    }

    /**
     * Explicit re-sync: overwrite matched targets' geometry with the absolute
     * projection of the active object (clobbers per-variant fine-tunes — this
     * is the user-invoked "Srovnat podle skupiny").
     *
     * @param {Object|null} onlyObj  limit to one active object (per-element
     *                               re-sync); null = every matched element
     * @param {string|null} onlyTargetId limit to one variant (per-variant re-sync)
     */
    resync(onlyObj = null, onlyTargetId = null) {
        const canvas = this.activeCanvas();
        const activeDims = this.activeDims();
        const targets = this.targets().filter(
            (target) => onlyTargetId === null || target.id === onlyTargetId,
        );
        const objects = onlyObj ? [onlyObj] : canvas.getObjects();
        const touched = new Set();

        objects.forEach((obj) => {
            if (!isSyncable(obj)) {
                return;
            }

            targets.forEach((target) => {
                const match = target.shadow.getObjects().find((o) => o.inputId === obj.inputId);
                if (!match) {
                    return;
                }

                const { rx, ry } = ratios(activeDims, target);
                match.set(projectGeometry(snapshotGeometry(obj), rx, ry, isTextboxObject(obj)));

                // Styles + metadata follow absolutely on an explicit re-sync.
                const props = snapshotProps(obj);
                [...STYLE_KEYS, ...META_KEYS].forEach((key) => {
                    const value = props[key];
                    if (value !== undefined) {
                        match.set(key, Array.isArray(value) ? value.slice() : value);
                    }
                });

                if (isTextboxObject(match) && typeof match.initDimensions === 'function') {
                    match.initDimensions();
                }
                match.setCoords();

                touched.add(target.id);
            });

            // Container maxHeight follows on a full-variant re-sync only
            // (handled below, outside the per-object loop).
        });

        if (!onlyObj) {
            const containers = Array.isArray(canvas.wboostContainers) ? canvas.wboostContainers : [];
            targets.forEach((target) => {
                const { ry } = ratios(activeDims, target);
                target.shadow.wboostContainers = containers.map((container) => ({
                    ...container,
                    maxHeight: container.maxHeight * ry,
                    gap: projectedSpacing(container.gap, ry),
                    spaceAfter: projectedSpacing(container.spaceAfter, ry),
                    memberInputIds: (container.memberInputIds || []).slice(),
                    memberContainerIds: (container.memberContainerIds || []).slice(),
                }));
                touched.add(target.id);
            });
        }

        return touched;
    }

    /**
     * Reflow a target shadow's containers with the shared layout module and
     * return the max overflow across them (px, 0 = fits).
     */
    static reflowShadow(shadow) {
        const layoutModule = window.WBoostContainerLayout;
        if (!layoutModule) {
            return 0;
        }

        const containers = Array.isArray(shadow.wboostContainers) ? shadow.wboostContainers : [];
        if (containers.length === 0) {
            return 0;
        }

        const prepared = layoutModule.prepareFabricContainers(shadow.getObjects(), containers);
        const results = layoutModule.applyFabricLayout(prepared);

        return results.reduce((max, r) => Math.max(max, r.overflowPx || 0), 0);
    }

    _syncZOrder(targets, touched) {
        const canvas = this.activeCanvas();
        // Backgrounds are excluded on both sides: the active background's id
        // never enters currentOrder, so a target's background fails the
        // currentOrder.includes() check below and keeps its own absolute
        // stack slot while the shared objects reorder through the rest.
        const currentOrder = canvas.getObjects()
            .filter((o) => isSyncable(o))
            .map((o) => o.inputId);

        const baselineShared = this.baselineOrder.filter((id) => currentOrder.includes(id));
        const currentShared = currentOrder.filter((id) => baselineShared.includes(id));

        if (baselineShared.join('\n') === currentShared.join('\n')) {
            return; // relative order unchanged
        }

        targets.forEach((target) => {
            const objects = target.shadow.getObjects();
            const slots = [];
            const shared = [];

            objects.forEach((o, index) => {
                if (o.inputId && currentOrder.includes(o.inputId)) {
                    slots.push(index);
                    shared.push(o);
                }
            });

            if (shared.length < 2) {
                return;
            }

            // Re-order the shared objects per the active stack, INTO the stack
            // slots they already occupy — variant-only objects keep their
            // absolute positions.
            shared.sort((a, b) => currentOrder.indexOf(a.inputId) - currentOrder.indexOf(b.inputId));

            const desired = objects.slice();
            slots.forEach((slot, k) => {
                desired[slot] = shared[k];
            });

            desired.forEach((o, index) => {
                target.shadow.moveObjectTo(o, index);
            });

            touched.add(target.id);
        });
    }

    _syncContainers(activeDims, targets, touched) {
        const canvas = this.activeCanvas();
        const current = Array.isArray(canvas.wboostContainers) ? canvas.wboostContainers : [];
        const baseline = this.baselineContainers;

        if (JSON.stringify(current) === JSON.stringify(baseline)) {
            return;
        }

        const baselineById = new Map(baseline.map((c) => [c.id, c]));

        const currentIds = new Set(current.map((c) => c.id));

        targets.forEach((target) => {
            const { ry } = ratios(activeDims, target);
            const shadowContainers = Array.isArray(target.shadow.wboostContainers)
                ? target.shadow.wboostContainers
                : [];
            const shadowById = new Map(shadowContainers.map((c) => [c.id, c]));
            const targetMemberIds = new Set(
                target.shadow.getObjects()
                    .filter((o) => isContainerMemberObject(o) && o.inputId)
                    .map((o) => o.inputId),
            );

            const next = [];

            current.forEach((container) => {
                const base = baselineById.get(container.id);
                const existing = shadowById.get(container.id);
                const memberIds = (container.memberInputIds || [])
                    .filter((id) => targetMemberIds.has(id));
                // Nesting structure follows the active canvas absolutely — it
                // is topology, not geometry (child ids are shared across the
                // group the same way member inputIds are).
                const childIds = (container.memberContainerIds || [])
                    .filter((id) => currentIds.has(id) && id !== container.id);

                if (!existing) {
                    // New container → absolute projection of maxHeight/spacing.
                    next.push({
                        id: container.id,
                        maxHeight: container.maxHeight * ry,
                        gap: projectedSpacing(container.gap, ry),
                        spaceAfter: projectedSpacing(container.spaceAfter, ry),
                        memberInputIds: memberIds,
                        memberContainerIds: childIds,
                    });
                    return;
                }

                let maxHeight = existing.maxHeight;
                if (base && base.maxHeight && container.maxHeight !== base.maxHeight) {
                    maxHeight = existing.maxHeight * (container.maxHeight / base.maxHeight);
                } else if (!base) {
                    maxHeight = container.maxHeight * ry;
                }

                // Spacing (gap / spaceAfter): keep the target's own value
                // until the ACTIVE canvas changes it vs the baseline — then
                // project absolutely.
                const followSpacing = (key) => {
                    let value = typeof existing[key] === 'number' ? existing[key] : undefined;
                    const baseValue = base && typeof base[key] === 'number' ? base[key] : null;
                    const currentValue = typeof container[key] === 'number' ? container[key] : null;
                    if (!base || currentValue !== baseValue) {
                        value = projectedSpacing(container[key], ry);
                    }
                    return value;
                };

                next.push({
                    id: container.id,
                    maxHeight,
                    gap: followSpacing('gap'),
                    spaceAfter: followSpacing('spaceAfter'),
                    memberInputIds: memberIds,
                    memberContainerIds: childIds,
                });
            });

            // Containers dissolved on the active canvas disappear from targets
            // too (they are simply absent from `current`/`next`).
            const changed = JSON.stringify(next) !== JSON.stringify(shadowContainers);

            target.shadow.wboostContainers = next;

            if (changed) {
                touched.add(target.id);
            }
        });
    }
}
