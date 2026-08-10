import { CANVAS_CUSTOM_PROPERTIES, applyEditorLock } from './canvas_custom_properties.js';
import { coverForDimensions } from './canvas_payload.js';
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
    'checklist', 'checklistAdd', 'checklistRemove', 'checklistEditText', 'checklistToggle',
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
     * Two target sets, because two kinds of change travel differently.
     *
     * `targets` — the variants the user opted into with "Úprava více variant"
     * + the per-variant switches. EDITS (moves, resizes, styles, metadata,
     * z-order, containers) go here, so an un-toggled dimension keeps its own
     * fine-tunes.
     *
     * `allTargets` — every non-active variant, no opt-out. STRUCTURE goes
     * here: adding an object, deleting one, picking a background, and the
     * explicit per-element "Srovnat podle skupiny". The object set must stay
     * identical across dimensions — a dimension silently missing an element
     * (or a background) renders as a scrambled stack, which is precisely the
     * failure the group model exists to prevent.
     *
     * @param {Object} options
     * @param {Function} options.activeCanvas  () => the interactive Fabric canvas
     * @param {Function} options.activeDims    () => {width, height} of the active variant
     * @param {Function} options.targets       () => [{id, shadow, width, height}] for
     *                                         the opted-in, non-active variants
     * @param {Function} options.allTargets    () => the same shape for EVERY
     *                                         non-active variant
     */
    constructor({ activeCanvas, activeDims, targets, allTargets }) {
        this.activeCanvas = activeCanvas;
        this.activeDims = activeDims;
        this.targets = targets;
        this.allTargets = allTargets;
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
     * Project a freshly added active-canvas object into EVERY target with the
     * SAME inputId (absolute projection — there is nothing to be relative to
     * yet). Structural: never gated by the per-variant switches.
     *
     * @param {Object} obj  the active-canvas object to fan out
     * @param {Object|null} sourceDims  {width, height} of the variant the
     *        object was added on — pass the dims captured at event time when
     *        the call is deferred (the active variant may have changed since).
     */
    async projectNewObject(obj, sourceDims = null) {
        const touched = new Set();

        // Background layers never fan out HERE — every dimension needs its own
        // cover fit, which projectBackgroundLayer computes. Belt to the
        // caller's event-gate suspenders.
        if (obj.isBackground === true) {
            return touched;
        }

        const activeDims = sourceDims || this.activeDims();
        const targets = this.allTargets();

        for (const target of targets) {
            if (target.shadow.getObjects().some((o) => o.inputId === obj.inputId)) {
                continue; // already there (double event guard)
            }

            // Per-target try/catch: cloning an image re-fetches its src, and
            // one failed fetch must not strand the REMAINING variants without
            // the object — a partial fan-out is the exact divergence the
            // structural rule exists to prevent. The skipped variant is healed
            // by the next reconcileStructure pass (tab switch / save).
            try {
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
            } catch (err) {
                console.error(`Propagace nového prvku do varianty ${target.id} selhala:`, err);
            }
        }

        return touched;
    }

    /**
     * Structural healing: make sure every syncable active-canvas object exists
     * (by inputId) on every sibling shadow — the "adds always fan out" rule
     * enforced after the fact. Catches whatever slipped through live
     * propagation (pre-2026-08-07 include-gated adds persisted in the DB, a
     * failed clone, an add during shadow hydration) the next time the group
     * is opened, a tab is switched, or a save runs.
     *
     * ADD-ONLY on purpose: an object present on a sibling but missing here is
     * the same divergence seen from the other side, and deleting it would
     * destroy the designer's work instead of healing it — it gets projected
     * out when THAT variant becomes active. Deletions stay an explicit user
     * action (which removeObject already fans out).
     */
    async reconcileStructure() {
        const touched = new Set();

        for (const obj of this.activeCanvas().getObjects()) {
            if (!isSyncable(obj)) {
                continue;
            }
            (await this.projectNewObject(obj)).forEach((id) => touched.add(id));
        }

        return touched;
    }

    /**
     * Fan the background PICTURE out to every target — the one thing about a
     * background that IS shared across dimensions.
     *
     * Everything else about it stays per-dimension: the layer is excluded from
     * the diffing engine (baseline, projectNewObject, resync, z-order) because
     * cover fit is an absolute function of (image, canvas size) and relative
     * propagation would compound drift. But excluding the picture too left the
     * designer with no way to give the other dimensions a background at all —
     * they rendered transparent, and whatever full-canvas artwork sat lowest
     * read as the background. So the picture travels and the FIT is recomputed
     * from scratch for each target's own size (never scaled from the source),
     * landing at the slot the target's own background occupied — index 0 when
     * it had none.
     *
     * Metadata (inputId, placeholder flags, name) is copied from the active
     * layer rather than preserved per target: a group-level pick is a
     * group-level decision, and the shared inputId is the same join key
     * CanvasDesignProjector stamps when it seeds a dimension.
     */
    async projectBackgroundLayer(source) {
        const touched = new Set();

        if (!source || source.isBackground !== true) {
            return touched;
        }

        for (const target of this.allTargets()) {
            // Per-target try/catch, same reason as projectNewObject: one
            // failed image fetch must not leave the remaining dimensions
            // without the background. The existing layer is only removed
            // AFTER the clone succeeded, so a failure never strips a
            // dimension's current background either.
            try {
                const clone = await source.clone(CANVAS_CUSTOM_PROPERTIES);
                CANVAS_CUSTOM_PROPERTIES.forEach((prop) => {
                    if (source[prop] !== undefined) {
                        clone[prop] = source[prop];
                    }
                });

                coverForDimensions(clone, target.width, target.height, 'top-left');
                // Backgrounds are click-through on the canvas surface — the shadow
                // is static, but the flags ride the save into the editor.
                applyEditorLock(clone);

                const existing = target.shadow.getObjects().find((o) => o.isBackground === true);
                let index = 0;
                if (existing) {
                    index = target.shadow.getObjects().indexOf(existing);
                    target.shadow.remove(existing);
                }

                target.shadow.add(clone);
                target.shadow.moveObjectTo(clone, index);
                touched.add(target.id);
            } catch (err) {
                console.error(`Propagace pozadí do varianty ${target.id} selhala:`, err);
            }
        }

        return touched;
    }

    /**
     * Propagate a deletion (matched by inputId) to EVERY target. Structural,
     * like the add it mirrors: an object that always fans out must always be
     * removable in one go. Per-dimension "not shown here" is the layers
     * panel's visibility eye, which travels as an ordinary (gated) edit.
     */
    removeObject(inputId) {
        const targets = this.allTargets();
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
     * Explicit re-sync of ONE active object: overwrite its match in every
     * target with the absolute projection of the active geometry (clobbers
     * per-variant fine-tunes — this is the user-invoked "Srovnat podle
     * skupiny" on the mini-toolbar).
     *
     * Ungated on purpose: the button's whole point is "push THIS element
     * everywhere", so it obeys the explicit click over the standing mode.
     *
     * @param {Object} obj the active object to project
     */
    resync(obj) {
        const activeDims = this.activeDims();
        const touched = new Set();

        if (!isSyncable(obj)) {
            return touched;
        }

        this.allTargets().forEach((target) => {
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
