/*
 * Dotted / dashed LINES for the "Čára" shape.
 *
 * A čára is a thin filled Rect (see canvas_shapes.js — a real Fabric Line is
 * un-resizable on one axis). Fabric dashes a Rect's BORDER, i.e. the outline
 * traced around the bar: on a 4 px bar with a 4 px dotted border that is two
 * rows of overlapping dots on top of a solid bar — the "caterpillar" a
 * dotted rule imported from InDesign rendered as (2026-11). A designer who
 * styles a LINE means the line itself, so this patch draws a styled line's
 * body instead:
 *
 * - trigger: `shapeKind === 'line'` + a non-empty `strokeDashArray`; the style
 *   is read off the array exactly like `dashStyleOf` / CanvasShapeStroke
 *   (first entry 0 = dotted, else dashed). The NUMBERS are ignored — the
 *   pattern always follows the line's CURRENT displayed thickness, so a line
 *   saved with a border-width pattern (every pre-fix one) or resized since,
 *   or projected to another dimension by the group engine, still draws right.
 * - dots: round, diameter = the bar's short side, centre-to-centre ≈ 3× that
 *   (a two-dot gap), distributed so the first and last dot sit flush with
 *   the line's ends (InDesign's "adjust gaps"). Dashes: 3 : 2 dash : gap,
 *   stretched to start and end on a dash.
 * - paint: the line's fill (solid or gradient, via Fabric's own fill path);
 *   a fill-less line falls back to its border colour. The border itself is
 *   NOT drawn on a styled line.
 * - geometry is computed in DISPLAYED units (scale undone), so dots stay
 *   round however the bar was stretched.
 *
 * Classic script on purpose (no ES module syntax): inlined verbatim into the
 * headless Gotenberg render template AND loaded via <script src> by the admin
 * editor (single + group), so the editor and every export (PNG, WebP, print
 * PDF raster, MCP preview) draw the identical line. Call
 * `WBoostFabricLineStyle.enable(Rect)` once per page with whichever Rect class
 * that surface uses (UMD global or ESM import — same prototype).
 */
(function (global) {
    'use strict';

    var DOT_SPACING = 3; // centre-to-centre, in thicknesses
    var DASH = 3;
    var GAP = 2;

    function lineStyleOf(object) {
        if (!object || object.shapeKind !== 'line') return null;
        var dash = object.strokeDashArray;
        if (!Array.isArray(dash) || dash.length === 0) return null;

        return Number(dash[0]) === 0 ? 'dotted' : 'dashed';
    }

    /** Sub-paths along the long axis, in displayed units around the centre. */
    function tracePath(ctx, style, length, thickness, horizontal) {
        var half = length / 2;

        function point(along, across) {
            return horizontal ? [along, across] : [across, along];
        }

        if (style === 'dotted') {
            var r = thickness / 2;
            var run = length - thickness; // centre of the first → last dot
            var count = run > 0 ? Math.max(1, Math.round(run / (thickness * DOT_SPACING))) : 0;
            var step = count > 0 ? run / count : 0;
            for (var i = 0; i <= count; i++) {
                var c = point(-half + r + i * step, 0);
                ctx.moveTo(c[0] + r, c[1]);
                ctx.arc(c[0], c[1], r, 0, Math.PI * 2);
            }

            return;
        }

        var dash = thickness * DASH;
        var gap = thickness * GAP;
        var n = Math.max(1, Math.round((length + gap) / (dash + gap)));
        var fit = length / (n * dash + (n - 1) * gap);
        var d = dash * fit;
        var g = gap * fit;
        for (var k = 0; k < n; k++) {
            var start = -half + k * (d + g);
            var a = point(start, -thickness / 2);
            if (horizontal) ctx.rect(a[0], a[1], d, thickness);
            else ctx.rect(a[0], a[1], thickness, d);
        }
    }

    function renderStyledLine(object, ctx, style) {
        var sx = object.scaleX || 1;
        var sy = object.scaleY || 1;
        var w = Math.abs((object.width || 0) * sx);
        var h = Math.abs((object.height || 0) * sy);
        if (!(w > 0 && h > 0)) return;

        var horizontal = w >= h;

        // Build the path in displayed units, then paint under the object's
        // own transform: a canvas path is fixed in device space when it is
        // traced, while Fabric's fill styles (gradients in percentage units)
        // expect the unscaled object box.
        ctx.save();
        ctx.scale(1 / sx, 1 / sy);
        ctx.beginPath();
        tracePath(ctx, style, horizontal ? w : h, horizontal ? h : w, horizontal);
        ctx.restore();

        if (object.fill) {
            object._renderFill(ctx);
        } else if (object.stroke) {
            ctx.save();
            ctx.fillStyle = object.stroke;
            ctx.fill();
            ctx.restore();
        }
    }

    function enable(Rect) {
        if (!Rect || typeof Rect.prototype._render !== 'function') return;
        if (Rect.prototype.__wboostLineStyleEnabled) return;
        Rect.prototype.__wboostLineStyleEnabled = true;

        var original = Rect.prototype._render;
        Rect.prototype._render = function (ctx) {
            var style = lineStyleOf(this);
            if (style === null) {
                return original.call(this, ctx);
            }
            renderStyledLine(this, ctx, style);
        };
    }

    global.WBoostFabricLineStyle = { enable: enable, lineStyleOf: lineStyleOf };
})(typeof window !== 'undefined' ? window : globalThis);
