/*
 * "Styl vrstvy" › Zaoblení rohů + Tah for IMAGES (2026-11-24).
 *
 * Two custom properties on a Fabric image, both in canvas px:
 *   frameRadius  number            rounds the corners of the visible frame
 *   frameBorder  {color, width}    an INSIDE border along that frame
 *                                  (Photoshop's Tah › Uvnitř — fully inside
 *                                  the picture, never clipped, never grows it)
 *
 * The "visible frame" is the image's own box — or, when the image carries an
 * ABSOLUTE clipPath (a filled / stand-in placeholder: the client's picture is
 * panned and zoomed inside a fixed frame, ImagePlacement / _frameClip), that
 * frame. So the border and the rounding follow the slot, not the picture.
 * Fabric's own stroke could not do this: it traces the picture's bounds and
 * is then cut by the clip.
 *
 * How: `drawObject` is wrapped — the rounding clips the whole original draw
 * (picture + Fabric's frame clip), the border is stroked AFTER the original,
 * so the frame clip cannot cut it. Drawing happens in the object's local
 * space (the object cache or the canvas), so the viewport (editor zoom,
 * export pixel ratio, print passes) scales it like everything else; the
 * object's own scale is undone, so the px stay px however the picture is
 * scaled. Both props are cache properties (a change re-renders the cache).
 *
 * Classic script on purpose (no ES module syntax): inlined verbatim into the
 * headless Gotenberg render template and loaded via <script src> by the
 * admin editor and the fill page — the three surfaces draw the identical
 * frame. Call `WBoostFabricImageFrame.enable(FabricImage)` once per page.
 */
(function (global) {
    'use strict';

    function radiusOf(object) {
        var r = Number(object.frameRadius);
        return isFinite(r) && r > 0 ? r : 0;
    }

    function borderOf(object) {
        var b = object.frameBorder;
        if (!b || typeof b !== 'object') return null;
        var width = Number(b.width);
        if (!isFinite(width) || width <= 0 || typeof b.color !== 'string' || b.color === '') return null;
        return { color: b.color, width: width };
    }

    // --- 2×3 affine helpers ([a, b, c, d, e, f], canvas convention) -------
    function multiply(m, n) {
        return [
            m[0] * n[0] + m[2] * n[1],
            m[1] * n[0] + m[3] * n[1],
            m[0] * n[2] + m[2] * n[3],
            m[1] * n[2] + m[3] * n[3],
            m[0] * n[4] + m[2] * n[5] + m[4],
            m[1] * n[4] + m[3] * n[5] + m[5],
        ];
    }

    function invert(m) {
        var det = m[0] * m[3] - m[1] * m[2];
        if (!det) return [1, 0, 0, 1, 0, 0];
        return [
            m[3] / det, -m[1] / det, -m[2] / det, m[0] / det,
            (m[2] * m[5] - m[3] * m[4]) / det,
            (m[1] * m[4] - m[0] * m[5]) / det,
        ];
    }

    /**
     * Put the context into FRAME space — origin at the frame's centre, one
     * unit = one canvas px — and return the frame's size there.
     */
    function enterFrameSpace(ctx, object) {
        var clip = object.clipPath;
        if (clip && clip.absolutePositioned === true && typeof clip.calcTransformMatrix === 'function') {
            var local = multiply(invert(object.calcTransformMatrix()), clip.calcTransformMatrix());
            ctx.transform(local[0], local[1], local[2], local[3], local[4], local[5]);
            var csx = Math.abs(clip.scaleX || 1);
            var csy = Math.abs(clip.scaleY || 1);
            ctx.scale(1 / csx, 1 / csy);
            return { width: (clip.width || 0) * csx, height: (clip.height || 0) * csy };
        }
        var sx = Math.abs(object.scaleX || 1);
        var sy = Math.abs(object.scaleY || 1);
        ctx.scale(1 / sx, 1 / sy);
        return { width: (object.width || 0) * sx, height: (object.height || 0) * sy };
    }

    function roundedRect(ctx, x, y, width, height, radius) {
        var r = Math.max(0, Math.min(radius, width / 2, height / 2));
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + width - r, y);
        ctx.arcTo(x + width, y, x + width, y + r, r);
        ctx.lineTo(x + width, y + height - r);
        ctx.arcTo(x + width, y + height, x + width - r, y + height, r);
        ctx.lineTo(x + r, y + height);
        ctx.arcTo(x, y + height, x, y + height - r, r);
        ctx.lineTo(x, y + r);
        ctx.arcTo(x, y, x + r, y, r);
        ctx.closePath();
    }

    function enable(ImageClass) {
        if (!ImageClass || !ImageClass.prototype || ImageClass.prototype.__wboostImageFrame) return;
        var proto = ImageClass.prototype;
        var original = proto.drawObject;
        proto.__wboostImageFrame = true;

        if (Array.isArray(ImageClass.cacheProperties)) {
            ImageClass.cacheProperties = ImageClass.cacheProperties.concat(['frameRadius', 'frameBorder']);
        }

        proto.drawObject = function (ctx, forClipping, context) {
            var radius = forClipping ? 0 : radiusOf(this);
            var border = forClipping ? null : borderOf(this);
            if (!radius && !border) {
                return original.call(this, ctx, forClipping, context);
            }

            var frame = null;
            if (radius) {
                ctx.save();
                ctx.save();
                frame = enterFrameSpace(ctx, this);
                roundedRect(ctx, -frame.width / 2, -frame.height / 2, frame.width, frame.height, radius);
                ctx.restore();
                ctx.clip();
            }

            original.call(this, ctx, forClipping, context);

            if (radius) ctx.restore();

            if (border) {
                ctx.save();
                frame = enterFrameSpace(ctx, this);
                var inset = border.width / 2;
                roundedRect(
                    ctx,
                    -frame.width / 2 + inset,
                    -frame.height / 2 + inset,
                    Math.max(0, frame.width - border.width),
                    Math.max(0, frame.height - border.width),
                    Math.max(0, radius - inset),
                );
                ctx.lineWidth = border.width;
                ctx.strokeStyle = border.color;
                ctx.stroke();
                ctx.restore();
            }
        };
    }

    global.WBoostFabricImageFrame = { enable: enable, radiusOf: radiusOf, borderOf: borderOf };
})(typeof window !== 'undefined' ? window : globalThis);
