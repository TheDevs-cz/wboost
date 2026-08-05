/*
 * Container layout — the single source of truth for "smart text area" reflow.
 *
 * A container groups members into a vertical document-like flow: when a filled
 * text wraps to more lines than designed, the flow items below it shift down
 * instead of being overlapped; hidden items collapse (take no space); the flow
 * of a TOP-LEVEL container is bounded by its maxHeight (content past it =
 * overflow, reported to the caller — enforcement policy is the caller's
 * business).
 *
 * Member model (v2 — nested containers):
 *  - TEXT placeholders are flow items, exactly as before: each text is its own
 *    item, designed gaps between consecutive items are preserved (negative
 *    gaps included), growth pushes the items below down, a hidden text
 *    collapses.
 *  - CONTAINERS can be members of another container (`memberContainerIds`).
 *    A child container is one flow item in its parent: it is laid out first
 *    (bottom-up), its resulting content height is its item height, and the
 *    parent shifts it as a whole. A nested container's own maxHeight is NOT a
 *    bound — it grows freely with content; only the outermost (root)
 *    container's maxHeight gates overflow.
 *  - DECORATIVE IMAGES (non-placeholder, non-background) can be members too.
 *    An image whose designed vertical interval overlaps a text/child item
 *    becomes that item's ATTACHMENT: it rides along with the item (fixed
 *    offset from the item's designed geometry) and is hidden when the item
 *    collapses — the "checkbox icon next to the checklist line" case. An
 *    image overlapping no item is a STANDALONE flow item (a divider between
 *    sections): it flows like a text but never changes height and never
 *    hides.
 *  - `gap` (nullable, canvas px): when set on a container, it replaces every
 *    designed inter-item gap of THAT container with a uniform spacing —
 *    vertical member positions then only determine flow ORDER. When null,
 *    designed gaps are preserved (the pre-v2 behavior, and the default).
 *  - SIBLING COLLISION-PUSH: top-level containers never overlap. After each
 *    root tree is laid out, roots are walked in designed-top order and a root
 *    whose content would run into a lower, HORIZONTALLY-overlapping root
 *    pushes that root (its whole tree) down by the excess — chained. Designed
 *    whitespace between them absorbs growth first (no movement until they
 *    would actually collide) and there is no pull-up; full document flow
 *    (gap-preserving, pull-up) is what NESTING is for. Side-by-side columns
 *    (disjoint x-ranges) never interact. With opts.canvasHeight set, content
 *    ending below the canvas bottom counts toward the root's overflowPx too —
 *    a pushed section cannot silently fall off the canvas.
 *  - `spaceAfter` (nullable, canvas px): the guaranteed minimum clearance
 *    BELOW a container. A pushing root places the pushed sibling at
 *    contentBottom + its own spaceAfter (and the minimum is enforced even at
 *    designed positions); a nested child's spaceAfter floors the parent-flow
 *    gap after it; against the canvas bottom it acts as the container's page
 *    margin (content must end above canvasHeight − spaceAfter). Null = 0.
 *
 * This file is deliberately a dependency-free classic script (attaches to
 * window/globalThis, no ES module syntax) because it has three consumers that
 * cannot share one loading mechanism:
 *   1. templates/api/template_variant_render.html.twig — inlined verbatim into
 *      the headless Gotenberg render by TemplateVariantImageRenderer,
 *   2. the admin canvas editor (canvas_container_controller.js),
 *   3. the user fill page (variant_fill_overlay_controller.js),
 * the latter two via a plain <script src> tag rendered before the importmap.
 * Keep it pure and side-effect free: docs/api/consumer-prompt.md mirrors the
 * algorithm for external API consumers, so any change here is a contract
 * change.
 *
 * Geometry contract: canvas px, top-left origin, textboxes are originX/originY
 * left/top with rotation and scaling locked (the editor enforces this at
 * creation), so "height" is the only dimension that varies with content. The
 * "objects" the two-phase API operates on can be live Fabric objects OR plain
 * geometry POJOs ({type, inputId, top, left, width, height, scaleX, scaleY,
 * visible}) — the fill overlay feeds POJOs built from designer frames.
 */
(function (global) {
    'use strict';

    /**
     * Designed vertical gaps between consecutive members, in flow order.
     * members: [{ designedTop, designedHeight }]
     * Returns n-1 gaps; gaps[i] sits between member i and member i+1.
     * (Legacy helper — the tree pipeline computes extent-based gaps itself,
     * but the flat helpers stay exported: they ARE the documented consumer
     * contract for mirroring a texts-only container.)
     */
    function computeGaps(members) {
        const gaps = [];
        for (let i = 1; i < members.length; i += 1) {
            const previous = members[i - 1];
            gaps.push(members[i].designedTop - (previous.designedTop + previous.designedHeight));
        }
        return gaps;
    }

    /**
     * Pure flat reflow (legacy helper, texts-only semantics).
     * members: [{ designedTop, actualHeight, hidden }] in flow order.
     * gaps: output of computeGaps for the same members (designed geometry).
     *
     * Rules: the first visible member anchors at the container top (= designed
     * top of the FIRST member, hidden or not); every next visible member sits
     * at previousVisibleBottom + its own designed gap (the gap to its designed
     * predecessor, even when that predecessor is hidden). Hidden members get a
     * null top and occupy no space.
     */
    function computeLayout(members, maxHeight, gaps) {
        const tops = new Array(members.length).fill(null);
        if (members.length === 0) {
            return { tops, containerTop: 0, contentBottom: 0, overflowPx: 0 };
        }

        const containerTop = members[0].designedTop;
        let previousBottom = null;

        for (let i = 0; i < members.length; i += 1) {
            const member = members[i];
            if (member.hidden) {
                continue;
            }
            const top = previousBottom === null
                ? containerTop
                : previousBottom + (gaps[i - 1] !== undefined ? gaps[i - 1] : 0);
            tops[i] = top;
            previousBottom = top + member.actualHeight;
        }

        const contentBottom = previousBottom === null ? containerTop : previousBottom;
        const overflowPx = Math.max(0, contentBottom - (containerTop + maxHeight));

        return { tops, containerTop, contentBottom, overflowPx };
    }

    function isTextboxObject(candidate) {
        return Boolean(candidate)
            && String(candidate.type || '').toLowerCase() === 'textbox';
    }

    /**
     * Decorative canvas image: a member candidate that rides the flow.
     * Fillable image placeholders and the background layer are deliberately
     * NOT container material — their frames are load-bearing elsewhere
     * (fill-page live objects, clipPath rects, API contracts).
     */
    function isImageObject(candidate) {
        return Boolean(candidate)
            && String(candidate.type || '').toLowerCase() === 'image'
            && candidate.imagePlaceholder !== true
            && candidate.isBackground !== true;
    }

    function isMemberCandidate(candidate) {
        return isTextboxObject(candidate) || isImageObject(candidate);
    }

    function displayedHeight(object) {
        return object.height * (object.scaleY || 1);
    }

    function normalizeGap(gap) {
        return (typeof gap === 'number' && isFinite(gap) && gap >= 0) ? gap : null;
    }

    function setObjectProps(object, props) {
        if (typeof object.set === 'function') {
            object.set(props);
        } else {
            Object.keys(props).forEach(function (key) { object[key] = props[key]; });
        }
        if (typeof object.setCoords === 'function') {
            object.setCoords();
        }
    }

    /**
     * Resolve a container's DIRECT input members (texts + decorative images,
     * by inputId) from a flat object list, preserving the persisted order.
     * Members that no longer exist are skipped.
     *
     * DESIGN-hidden members (the editor's per-layer eye toggle → the object is
     * visible:false already at membership-resolution time, i.e. phase A) are
     * treated as non-existent, exactly like deleted ones: they do not anchor
     * the flow, contribute no gap, and the surfaces that never see them (the
     * fill overlay measures only fillable inputs) lay out identically. This is
     * distinct from FILL-time hides, which are applied AFTER phase A and
     * therefore still resolve as members and collapse in phase B.
     */
    function collectMembers(objects, container) {
        const members = [];
        const ids = Array.isArray(container.memberInputIds) ? container.memberInputIds : [];
        for (const inputId of ids) {
            const found = objects.find((o) => isMemberCandidate(o) && o.inputId === inputId && o.visible !== false);
            if (found) {
                members.push(found);
            }
        }
        return members;
    }

    /** Child container definitions of a container, resolved against the full list. */
    function childContainersOf(containers, container) {
        const byId = new Map((containers || []).filter((c) => c && c.id).map((c) => [c.id, c]));
        const ids = Array.isArray(container.memberContainerIds) ? container.memberContainerIds : [];
        const children = [];
        for (const id of ids) {
            if (id !== container.id && byId.has(id)) {
                children.push(byId.get(id));
            }
        }
        return children;
    }

    /** The container (if any) that lists `containerId` among its children. */
    function parentOf(containers, containerId) {
        return (containers || []).find(
            (c) => c && Array.isArray(c.memberContainerIds) && c.id !== containerId
                && c.memberContainerIds.includes(containerId),
        ) || null;
    }

    /** Containers not claimed as a child by any other container. */
    function rootContainers(containers) {
        const defs = (containers || []).filter((c) => c && c.id);
        const byId = new Map(defs.map((c) => [c.id, c]));
        const claimed = new Set();
        defs.forEach((def) => {
            (def.memberContainerIds || []).forEach((id) => {
                if (id !== def.id && byId.has(id)) {
                    claimed.add(id);
                }
            });
        });
        return defs.filter((def) => !claimed.has(def.id));
    }

    /** The root container of the tree `containerId` belongs to. */
    function rootOf(containers, containerId) {
        let current = (containers || []).find((c) => c && c.id === containerId) || null;
        const seen = new Set();
        while (current && !seen.has(current.id)) {
            seen.add(current.id);
            const parent = parentOf(containers, current.id);
            if (!parent) {
                return current;
            }
            current = parent;
        }
        return current;
    }

    /**
     * Direct + descendant member objects of a container — what the editor
     * moves as a unit (zone label drag) and draws the zone around.
     */
    function collectDeepMemberObjects(objects, containers, container, visiting) {
        const seen = visiting || new Set();
        if (!container || seen.has(container.id)) {
            return [];
        }
        seen.add(container.id);
        const result = collectMembers(objects, container);
        childContainersOf(containers, container).forEach((child) => {
            collectDeepMemberObjects(objects, containers, child, seen).forEach((o) => result.push(o));
        });
        return result;
    }

    // --- two-phase tree pipeline ---------------------------------------------

    /**
     * Phase A — run BEFORE text overrides are applied, while every member
     * still holds its designed text: build the container forest and snapshot
     * the designed geometry (item extents + gaps) the reflow is anchored to.
     *
     * opts.getTop(o)/opts.getLeft(o): absolute accessor overrides (the editor
     * needs these while a Fabric ActiveSelection holds members with relative
     * coords). opts.canvasHeight: when set (> 0), a root container's content
     * must end above canvasHeight − spaceAfter or it counts as overflow.
     */
    function prepareFabricContainers(objects, containers, opts) {
        const getTop = (opts && opts.getTop) || function (o) { return o.top; };
        const getLeft = (opts && opts.getLeft) || function (o) { return o.left; };
        const canvasHeight = (opts && typeof opts.canvasHeight === 'number' && opts.canvasHeight > 0)
            ? opts.canvasHeight
            : null;
        const defs = (containers || []).filter((c) => c && c.id);
        const byId = new Map(defs.map((c) => [c.id, c]));
        const consumed = new Set();

        function buildNode(def, visiting) {
            if (visiting.has(def.id) || consumed.has(def.id)) {
                return null; // cycle guard / second parent claiming the same child
            }
            visiting.add(def.id);

            const childNodes = [];
            (Array.isArray(def.memberContainerIds) ? def.memberContainerIds : []).forEach((childId) => {
                if (childId === def.id) return;
                const childDef = byId.get(childId);
                if (!childDef) return;
                const child = buildNode(childDef, visiting);
                if (child) childNodes.push(child);
            });
            visiting.delete(def.id);

            const direct = collectMembers(objects, def);
            const textObjects = direct.filter(isTextboxObject);
            const imageObjects = direct.filter(isImageObject);

            // Flow item candidates: every text + every child container.
            const items = [];
            textObjects.forEach((obj) => {
                const top = getTop(obj);
                items.push({
                    kind: 'text',
                    obj,
                    baseTop: top,
                    baseHeight: displayedHeight(obj),
                    attachments: [],
                });
            });
            childNodes.forEach((node) => {
                items.push({
                    kind: 'container',
                    node,
                    baseTop: node.designedExtTop,
                    baseHeight: node.designedExtBottom - node.designedExtTop,
                    attachments: [],
                });
            });

            // Images: attach to the item whose designed interval they overlap
            // the most; no overlap → standalone flow item.
            imageObjects.forEach((obj) => {
                const top = getTop(obj);
                const height = displayedHeight(obj);
                let best = null;
                let bestOverlap = 0;
                items.forEach((item) => {
                    const overlap = Math.min(top + height, item.baseTop + item.baseHeight)
                        - Math.max(top, item.baseTop);
                    if (overlap > 0 && overlap > bestOverlap) {
                        best = item;
                        bestOverlap = overlap;
                    }
                });
                if (best) {
                    best.attachments.push({ obj, offset: top - best.baseTop, height });
                } else {
                    items.push({
                        kind: 'image',
                        obj,
                        baseTop: top,
                        baseHeight: height,
                        attachments: [],
                    });
                }
            });

            if (items.length === 0) {
                return null;
            }

            // Designed extents (base geometry ∪ attachments; offsets are fixed
            // relative to the base top, only the base HEIGHT varies later).
            items.forEach((item) => {
                let minOffset = 0;
                let attachedBottom = -Infinity;
                item.attachments.forEach((att) => {
                    if (att.offset < minOffset) minOffset = att.offset;
                    const bottom = att.offset + att.height;
                    if (bottom > attachedBottom) attachedBottom = bottom;
                });
                // Offset of the base object below the item's extent top (≥ 0).
                item.baseTopOffset = -minOffset;
                // Bottom of the attachment stack relative to the extent top
                // (the base bottom is computed live in the measure pass).
                item.attachedBottom = attachedBottom === -Infinity
                    ? null
                    : item.baseTopOffset + attachedBottom;
                item.designedExtTop = item.baseTop - item.baseTopOffset;
                item.designedExtBottom = Math.max(
                    item.baseTop + item.baseHeight,
                    item.attachedBottom === null ? -Infinity : item.designedExtTop + item.attachedBottom,
                );
            });

            items.sort((a, b) => a.designedExtTop - b.designedExtTop);

            const gaps = [];
            for (let i = 1; i < items.length; i += 1) {
                gaps.push(items[i].designedExtTop - items[i - 1].designedExtBottom);
            }

            // Horizontal designed extent of the whole tree — the sibling
            // collision-push only couples roots whose x-ranges overlap
            // (side-by-side columns never push each other).
            let extLeft = Infinity;
            let extRight = -Infinity;
            direct.forEach((obj) => {
                const left = getLeft(obj);
                extLeft = Math.min(extLeft, left);
                extRight = Math.max(extRight, left + obj.width * (obj.scaleX || 1));
            });
            childNodes.forEach((child) => {
                extLeft = Math.min(extLeft, child.extLeft);
                extRight = Math.max(extRight, child.extRight);
            });

            consumed.add(def.id);

            return {
                id: def.id,
                maxHeight: def.maxHeight,
                gap: normalizeGap(def.gap),
                spaceAfter: normalizeGap(def.spaceAfter),
                canvasHeight,
                items,
                gaps,
                anchorTop: items[0].designedExtTop,
                designedExtTop: items[0].designedExtTop,
                designedExtBottom: Math.max.apply(null, items.map((i) => i.designedExtBottom)),
                extLeft,
                extRight,
            };
        }

        const prepared = [];
        rootContainers(defs).forEach((def) => {
            if (!(def.maxHeight > 0)) {
                return;
            }
            const node = buildNode(def, new Set());
            if (node) {
                prepared.push(node);
            }
        });
        return prepared;
    }

    /**
     * Measure pass (design space): bottom-up, computes every item's actual
     * height + hidden flag and flows the items of each node from its own
     * designed anchor. Child containers are measured first — their flowed
     * content height IS their item height in the parent.
     *
     * Hidden rules: a text item is hidden when its object is (fill-time hide);
     * a container item is hidden when ALL of its own items are hidden; a
     * standalone image item is only hidden when its object is. Attachments
     * never keep an item alive — they collapse with their host.
     */
    function measureNode(node) {
        let previousBottom = null;

        node.items.forEach((item, i) => {
            let hidden;
            let baseHeightNow;
            if (item.kind === 'container') {
                measureNode(item.node);
                hidden = item.node._hidden;
                baseHeightNow = item.node._height;
            } else {
                hidden = item.obj.visible === false;
                baseHeightNow = displayedHeight(item.obj);
            }

            item._hidden = hidden;
            item._height = Math.max(
                item.baseTopOffset + baseHeightNow,
                item.attachedBottom === null ? -Infinity : item.attachedBottom,
            );

            if (hidden) {
                item._extTop = null;
                return;
            }

            let gapBefore = node.gap !== null ? node.gap : (node.gaps[i - 1] !== undefined ? node.gaps[i - 1] : 0);
            // A nested child's spaceAfter floors the parent-flow gap after it
            // (measured against the DESIGNED predecessor, same as the gap).
            const previousItem = node.items[i - 1];
            if (previousItem && previousItem.kind === 'container' && previousItem.node.spaceAfter !== null) {
                gapBefore = Math.max(gapBefore, previousItem.node.spaceAfter);
            }
            item._extTop = previousBottom === null ? node.anchorTop : previousBottom + gapBefore;
            previousBottom = item._extTop + item._height;
        });

        node._contentBottom = previousBottom === null ? node.anchorTop : previousBottom;
        node._height = node._contentBottom - node.anchorTop;
        node._hidden = node.items.every((item) => item._hidden);
    }

    /**
     * Commit pass: translate the design-space layout by the accumulated parent
     * delta and mutate the member objects. Attachments of a collapsed item are
     * force-hidden (their texts already are — that is what collapsed them).
     */
    function commitNode(node, delta, textFlow) {
        node.items.forEach((item) => {
            if (item._hidden || item._extTop === null) {
                item.attachments.forEach((att) => {
                    if (att.obj.visible !== false) {
                        setObjectProps(att.obj, { visible: false });
                    }
                });
                if (item.kind === 'container') {
                    commitHidden(item.node, textFlow);
                } else if (item.kind === 'text') {
                    textFlow.push({ inputId: item.obj.inputId, top: null });
                }
                return;
            }

            const finalExtTop = item._extTop + delta;
            const finalBaseTop = finalExtTop + item.baseTopOffset;

            if (item.kind === 'container') {
                commitNode(item.node, finalBaseTop - item.node.anchorTop, textFlow);
            } else {
                if (item.obj.top !== finalBaseTop) {
                    setObjectProps(item.obj, { top: finalBaseTop });
                }
                if (item.kind === 'text') {
                    textFlow.push({ inputId: item.obj.inputId, top: finalBaseTop });
                }
            }

            item.attachments.forEach((att) => {
                const attTop = finalBaseTop + att.offset;
                if (att.obj.top !== attTop) {
                    setObjectProps(att.obj, { top: attTop });
                }
            });
        });
        node._finalTop = node.anchorTop + delta;
        node._finalBottom = node._contentBottom + delta;
    }

    /** A fully collapsed subtree still reports its texts (all hidden). */
    function commitHidden(node, textFlow) {
        node.items.forEach((item) => {
            item.attachments.forEach((att) => {
                if (att.obj.visible !== false) {
                    setObjectProps(att.obj, { visible: false });
                }
            });
            if (item.kind === 'container') {
                commitHidden(item.node, textFlow);
            } else if (item.kind === 'text') {
                textFlow.push({ inputId: item.obj.inputId, top: null });
            }
        });
        node._finalTop = null;
        node._finalBottom = null;
    }

    /**
     * Phase B — run AFTER overrides (texts substituted, hides applied, heights
     * re-wrapped): reflow every prepared tree by mutating member tops, then
     * resolve sibling collisions between the roots (designed-top order, only
     * horizontally-overlapping pairs, push-down only — chained; a pushing root
     * keeps its spaceAfter as clearance, and the minimum clearance is enforced
     * even at designed positions).
     *
     * Returns one result per container — roots AND descendants:
     *   { id, maxHeight, containerTop, contentBottom, overflowPx, nested,
     *     textFlow? }
     * Only ROOT containers can report overflowPx > 0 (a nested container grows
     * freely — the outer bound is the contract): the max of the root's own
     * maxHeight excess and, when canvasHeight is known, content ending below
     * canvasHeight − spaceAfter. Roots also carry `textFlow`: the DEEP text
     * members in flow order with their final tops (null = hidden), which is
     * what the fill overlay positions its boxes from.
     */
    function applyFabricLayout(prepared) {
        const roots = (prepared || []).slice();
        roots.forEach((root) => measureNode(root));

        // Sibling collision-push over the measured (design-space) flows.
        const placed = [];
        [...roots].sort((a, b) => a.anchorTop - b.anchorTop).forEach((root) => {
            let delta = 0;
            placed.forEach((other) => {
                const xOverlap = Math.min(other.extRight, root.extRight) - Math.max(other.extLeft, root.extLeft);
                if (!(xOverlap > 0)) return;
                const clearance = other.spaceAfter !== null ? other.spaceAfter : 0;
                delta = Math.max(delta, (other._pushedBottom + clearance) - root.anchorTop);
            });
            delta = Math.max(0, delta);
            root._rootDelta = delta;
            root._pushedBottom = root._contentBottom + delta;
            placed.push(root);
        });

        const results = [];
        roots.forEach((root) => {
            const delta = root._rootDelta || 0;
            const textFlow = [];
            commitNode(root, delta, textFlow);

            const finalTop = root.anchorTop + delta;
            const finalBottom = root._contentBottom + delta;
            let overflowPx = Math.max(0, finalBottom - (finalTop + root.maxHeight));
            if (root.canvasHeight !== null) {
                const clearance = root.spaceAfter !== null ? root.spaceAfter : 0;
                overflowPx = Math.max(overflowPx, finalBottom - (root.canvasHeight - clearance));
            }

            results.push({
                id: root.id,
                maxHeight: root.maxHeight,
                containerTop: finalTop,
                contentBottom: finalBottom,
                overflowPx,
                nested: false,
                textFlow,
            });

            const walkChildren = (node) => {
                node.items.forEach((item) => {
                    if (item.kind !== 'container') return;
                    results.push({
                        id: item.node.id,
                        maxHeight: item.node.maxHeight,
                        containerTop: item.node._finalTop === null ? item.node.anchorTop : item.node._finalTop,
                        contentBottom: item.node._finalBottom === null ? item.node.anchorTop : item.node._finalBottom,
                        overflowPx: 0,
                        nested: true,
                    });
                    walkChildren(item.node);
                });
            };
            walkChildren(root);
        });
        return results;
    }

    /**
     * Flow order = current vertical order. Used by the editor to (re)derive
     * memberInputIds whenever members are created, moved or saved.
     */
    function sortMemberIdsByTop(objects, memberInputIds) {
        const withTops = [];
        for (const inputId of memberInputIds || []) {
            const found = objects.find((o) => isMemberCandidate(o) && o.inputId === inputId);
            if (found) {
                withTops.push({ inputId, top: found.top });
            }
        }
        withTops.sort((a, b) => a.top - b.top);
        return withTops.map((entry) => entry.inputId);
    }

    global.WBoostContainerLayout = {
        computeGaps,
        computeLayout,
        collectMembers,
        collectDeepMemberObjects,
        childContainersOf,
        parentOf,
        rootOf,
        rootContainers,
        isMemberCandidate,
        isTextboxObject,
        isImageObject,
        prepareFabricContainers,
        applyFabricLayout,
        sortMemberIdsByTop,
    };
})(typeof window !== 'undefined' ? window : globalThis);
