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
    'allowedDirectoryIds',
];

function isTextboxObject(obj) {
    return (obj.type || '').toLowerCase() === 'textbox';
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
            if (!obj.inputId) {
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
            if (!obj.inputId) {
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
        const activeDims = this.activeDims();
        const targets = this.targets();
        const touched = new Set();

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
            if (!obj.inputId) {
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
                    memberInputIds: (container.memberInputIds || []).slice(),
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
        const currentOrder = canvas.getObjects()
            .filter((o) => o.inputId)
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

        targets.forEach((target) => {
            const { ry } = ratios(activeDims, target);
            const shadowContainers = Array.isArray(target.shadow.wboostContainers)
                ? target.shadow.wboostContainers
                : [];
            const shadowById = new Map(shadowContainers.map((c) => [c.id, c]));
            const targetTextboxIds = new Set(
                target.shadow.getObjects()
                    .filter((o) => isTextboxObject(o) && o.inputId)
                    .map((o) => o.inputId),
            );

            const next = [];

            current.forEach((container) => {
                const base = baselineById.get(container.id);
                const existing = shadowById.get(container.id);
                const memberIds = (container.memberInputIds || [])
                    .filter((id) => targetTextboxIds.has(id));

                if (!existing) {
                    // New container → absolute projection of maxHeight.
                    next.push({
                        id: container.id,
                        maxHeight: container.maxHeight * ry,
                        memberInputIds: memberIds,
                    });
                    return;
                }

                let maxHeight = existing.maxHeight;
                if (base && base.maxHeight && container.maxHeight !== base.maxHeight) {
                    maxHeight = existing.maxHeight * (container.maxHeight / base.maxHeight);
                } else if (!base) {
                    maxHeight = container.maxHeight * ry;
                }

                next.push({
                    id: container.id,
                    maxHeight,
                    memberInputIds: memberIds,
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
