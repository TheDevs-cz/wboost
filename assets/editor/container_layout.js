/*
 * Container layout — the single source of truth for "smart text area" reflow.
 *
 * A container groups 2+ text placeholders into a vertical flow: when a filled
 * text wraps to more lines than designed, the members below it shift down
 * instead of being overlapped; hidden members collapse (take no space); the
 * flow is bounded by the container's maxHeight (content past it = overflow,
 * reported to the caller — enforcement policy is the caller's business).
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
 * creation), so "height" is the only dimension that varies with content.
 */
(function (global) {
    'use strict';

    /**
     * Designed vertical gaps between consecutive members, in flow order.
     * members: [{ designedTop, designedHeight }]
     * Returns n-1 gaps; gaps[i] sits between member i and member i+1.
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
     * Pure reflow.
     * members: [{ designedTop, actualHeight, hidden }] in flow order.
     * gaps: output of computeGaps for the same members (designed geometry).
     *
     * Rules: the first visible member anchors at the container top (= designed
     * top of the FIRST member, hidden or not); every next visible member sits
     * at previousVisibleBottom + its own designed gap (the gap to its designed
     * predecessor, even when that predecessor is hidden). Hidden members get a
     * null top and occupy no space.
     *
     * Returns { tops, containerTop, contentBottom, overflowPx } where tops[i]
     * is the new top for member i (null = hidden) and overflowPx > 0 means the
     * content does not fit within maxHeight.
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
     * Resolve a container's member objects (by inputId, textboxes only) from a
     * flat object list, preserving the persisted flow order. Members that no
     * longer exist are skipped — a container that resolves to fewer than 2
     * members is inert.
     */
    function collectMembers(objects, container) {
        const members = [];
        const ids = Array.isArray(container.memberInputIds) ? container.memberInputIds : [];
        for (const inputId of ids) {
            const found = objects.find((o) => isTextboxObject(o) && o.inputId === inputId);
            if (found) {
                members.push(found);
            }
        }
        return members;
    }

    function displayedHeight(object) {
        return object.height * (object.scaleY || 1);
    }

    /**
     * Phase A — run BEFORE text overrides are applied, while every member
     * still holds its designed text: snapshot the designed geometry (tops +
     * gaps) each container's reflow is anchored to.
     */
    function prepareFabricContainers(objects, containers) {
        const prepared = [];
        for (const container of containers || []) {
            if (!container || !(container.maxHeight > 0)) {
                continue;
            }
            const memberObjects = collectMembers(objects, container);
            if (memberObjects.length < 2) {
                continue;
            }
            const designed = memberObjects.map((o) => ({
                designedTop: o.top,
                designedHeight: displayedHeight(o),
            }));
            prepared.push({
                id: container.id,
                maxHeight: container.maxHeight,
                memberObjects,
                designedTops: designed.map((d) => d.designedTop),
                gaps: computeGaps(designed),
            });
        }
        return prepared;
    }

    /**
     * Phase B — run AFTER overrides (texts substituted, hides applied, heights
     * re-wrapped): reflow every prepared container by mutating member tops.
     * Uses obj.set() when available so Fabric invalidates its caches, plain
     * assignment otherwise. Returns per-container results incl. overflowPx.
     */
    function applyFabricLayout(prepared) {
        return prepared.map((p) => {
            const members = p.memberObjects.map((o, i) => ({
                designedTop: p.designedTops[i],
                actualHeight: displayedHeight(o),
                hidden: o.visible === false,
            }));
            const layout = computeLayout(members, p.maxHeight, p.gaps);
            p.memberObjects.forEach((o, i) => {
                const top = layout.tops[i];
                if (top === null || o.top === top) {
                    return;
                }
                if (typeof o.set === 'function') {
                    o.set({ top });
                } else {
                    o.top = top;
                }
                if (typeof o.setCoords === 'function') {
                    o.setCoords();
                }
            });
            return {
                id: p.id,
                maxHeight: p.maxHeight,
                containerTop: layout.containerTop,
                contentBottom: layout.contentBottom,
                overflowPx: layout.overflowPx,
            };
        });
    }

    /**
     * Flow order = current vertical order. Used by the editor to (re)derive
     * memberInputIds whenever members are created, moved or saved.
     */
    function sortMemberIdsByTop(objects, memberInputIds) {
        const withTops = [];
        for (const inputId of memberInputIds || []) {
            const found = objects.find((o) => isTextboxObject(o) && o.inputId === inputId);
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
        prepareFabricContainers,
        applyFabricLayout,
        sortMemberIdsByTop,
    };
})(typeof window !== 'undefined' ? window : globalThis);
