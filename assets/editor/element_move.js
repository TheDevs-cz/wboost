/*
 * "Uživatel může posunout" — the shared core of user-movable elements.
 *
 * JS twin of src/Value/ElementMoveAllowance.php + src/Value/ElementOffsets.php
 * (parity pinned by tests/Value/ElementMoveParityTest.php through
 * tests/Fixtures/element-move/harness.cjs). Deliberately a dependency-free
 * CLASSIC script exposing `window.WBoostElementMove`, like container_layout.js:
 * the admin editor uses it today; the fill overlay, the text echo and (if ever
 * needed) the headless render template are meant to load the very same file.
 *
 * Canvas JSON contract — custom property `userMove` on a Fabric object (text,
 * shape, decorative image) or a `userMove` key on a TOP-LEVEL `containers[]`
 * definition, present only when at least one axis is allowed:
 *
 *   { vertical: bool, horizontal: bool,
 *     maxUp: px|null, maxDown: px|null, maxLeft: px|null, maxRight: px|null }
 *
 * Maxima are canvas px of the variant that stores them, rounded to 1/100;
 * null = unlimited but the element stays on the artwork; 0 = not that way.
 * A disabled axis always carries null maxima. Treat the object as IMMUTABLE
 * (Fabric clones share the reference): always assign a fresh one.
 */
(function (global) {
    'use strict';

    var CANVAS_KEY = 'userMove';

    /** Half-away-from-zero to 1/100 with plain IEEE ops (≡ PHP round2). */
    function round2(value) {
        if (typeof value !== 'number' || !isFinite(value)) return 0;
        var rounded = Math.floor(Math.abs(value) * 100 + 0.5) / 100;
        if (rounded === 0) return 0;
        return value < 0 ? -rounded : rounded;
    }

    function maxOrNull(value) {
        if (typeof value !== 'number' || !isFinite(value) || value < 0) return null;
        return round2(value);
    }

    /** Lenient normalisation → canonical allowance, or null (= not movable). */
    function normalize(raw) {
        if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null;
        var vertical = raw.vertical === true;
        var horizontal = raw.horizontal === true;
        if (!vertical && !horizontal) return null;
        return {
            vertical: vertical,
            horizontal: horizontal,
            maxUp: vertical ? maxOrNull(raw.maxUp) : null,
            maxDown: vertical ? maxOrNull(raw.maxDown) : null,
            maxLeft: horizontal ? maxOrNull(raw.maxLeft) : null,
            maxRight: horizontal ? maxOrNull(raw.maxRight) : null,
        };
    }

    /** Group projection: vertical maxima × ry, horizontal × rx. */
    function scale(raw, rx, ry) {
        var allowance = normalize(raw);
        if (!allowance) return null;
        var by = function (value, ratio) { return value === null ? null : round2(value * ratio); };
        return {
            vertical: allowance.vertical,
            horizontal: allowance.horizontal,
            maxUp: by(allowance.maxUp, ry),
            maxDown: by(allowance.maxDown, ry),
            maxLeft: by(allowance.maxLeft, rx),
            maxRight: by(allowance.maxRight, rx),
        };
    }

    /**
     * Allowed offset range relative to the designed position.
     * frame = the designed axis-aligned bounding box {left, top, width, height}.
     */
    function bounds(raw, frame, canvasWidth, canvasHeight) {
        var allowance = normalize(raw);
        var minX = 0, maxX = 0, minY = 0, maxY = 0;
        if (allowance && allowance.horizontal) {
            minX = -(allowance.maxLeft !== null ? allowance.maxLeft : Math.max(0, frame.left));
            maxX = allowance.maxRight !== null ? allowance.maxRight : Math.max(0, canvasWidth - (frame.left + frame.width));
        }
        if (allowance && allowance.vertical) {
            minY = -(allowance.maxUp !== null ? allowance.maxUp : Math.max(0, frame.top));
            maxY = allowance.maxDown !== null ? allowance.maxDown : Math.max(0, canvasHeight - (frame.top + frame.height));
        }
        return { minX: round2(minX), maxX: round2(maxX), minY: round2(minY), maxY: round2(maxY) };
    }

    function clamp(raw, x, y, frame, canvasWidth, canvasHeight) {
        var b = bounds(raw, frame, canvasWidth, canvasHeight);
        var fx = (typeof x === 'number' && isFinite(x)) ? x : 0;
        var fy = (typeof y === 'number' && isFinite(y)) ? y : 0;
        return {
            x: round2(Math.min(Math.max(fx, b.minX), b.maxX)),
            y: round2(Math.min(Math.max(fy, b.minY), b.maxY)),
        };
    }

    function num(value) {
        return (typeof value === 'number' && isFinite(value)) ? value : 0;
    }

    function deepMemberInputIds(byId, rootId) {
        var result = [];
        var seen = {};
        var visited = {};
        var stack = [rootId];
        while (stack.length) {
            var id = stack.shift();
            if (visited[id] || !Object.prototype.hasOwnProperty.call(byId, id)) continue;
            visited[id] = true;
            var container = byId[id];
            (Array.isArray(container.memberInputIds) ? container.memberInputIds : []).forEach(function (memberId) {
                if (typeof memberId === 'string' && memberId !== '' && !seen[memberId]) {
                    seen[memberId] = true;
                    result.push(memberId);
                }
            });
            (Array.isArray(container.memberContainerIds) ? container.memberContainerIds : []).forEach(function (childId) {
                if (typeof childId === 'string') stack.push(childId);
            });
        }
        return result;
    }

    /**
     * Translate the offset elements of a canvas document (plain JSON, not
     * Fabric objects). Returns a NEW document; the input is not mutated.
     * offsets = { <inputId | containerId>: {x, y} } — already clamped.
     */
    function bake(canvas, offsets) {
        if (!canvas || typeof canvas !== 'object' || !Array.isArray(canvas.objects)) return canvas;

        var byId = {};
        (Array.isArray(canvas.containers) ? canvas.containers : []).forEach(function (container) {
            if (container && typeof container.id === 'string' && container.id !== ''
                && !Object.prototype.hasOwnProperty.call(byId, container.id)) {
                byId[container.id] = container;
            }
        });

        var deltas = {};
        var add = function (inputId, dx, dy) {
            var current = deltas[inputId] || [0, 0];
            deltas[inputId] = [current[0] + dx, current[1] + dy];
        };
        Object.keys(offsets || {}).forEach(function (id) {
            var offset = offsets[id] || {};
            var dx = num(offset.x);
            var dy = num(offset.y);
            if (dx === 0 && dy === 0) return;
            if (Object.prototype.hasOwnProperty.call(byId, id)) {
                deepMemberInputIds(byId, id).forEach(function (memberId) { add(memberId, dx, dy); });
                return;
            }
            add(id, dx, dy);
        });

        var copy = Object.assign({}, canvas);
        copy.objects = canvas.objects.map(function (object) {
            if (!object || typeof object !== 'object' || typeof object.inputId !== 'string') return object;
            var delta = deltas[object.inputId];
            if (!delta || (delta[0] === 0 && delta[1] === 0)) return object;
            var moved = Object.assign({}, object);
            moved.left = num(object.left) + delta[0];
            moved.top = num(object.top) + delta[1];
            if (object.clipPath && typeof object.clipPath === 'object' && object.clipPath.absolutePositioned === true) {
                moved.clipPath = Object.assign({}, object.clipPath);
                moved.clipPath.left = num(object.clipPath.left) + delta[0];
                moved.clipPath.top = num(object.clipPath.top) + delta[1];
            }
            return moved;
        });
        return copy;
    }

    global.WBoostElementMove = {
        CANVAS_KEY: CANVAS_KEY,
        round2: round2,
        normalize: normalize,
        scale: scale,
        bounds: bounds,
        clamp: clamp,
        bake: bake,
    };
})(typeof window !== 'undefined' ? window : globalThis);
