// The crop of a photo in its frame — model/photo-crop.js (feedback K14).
import assert from 'node:assert/strict';

const near = (a, b, eps, msg) => assert.ok(Math.abs(a - b) <= eps, `${msg}: ${a} vs ${b}`);

// deterministic pseudo-random numbers
function rng(seed) {
    let s = seed >>> 0;
    return () => {
        s = (s * 1664525 + 1013904223) >>> 0;
        return s / 4294967296;
    };
}

/** Is the point inside the convex polygon (corners in order)? */
function inside(poly, [x, y], eps = 1e-6) {
    let sign = 0;
    for (let i = 0; i < poly.length; i++) {
        const [ax, ay] = poly[i];
        const [bx, by] = poly[(i + 1) % poly.length];
        const cross = (bx - ax) * (y - ay) - (by - ay) * (x - ax);
        if (Math.abs(cross) <= eps) continue;
        const s = Math.sign(cross);
        if (sign && s !== sign) return false;
        sign = s;
    }
    return true;
}

export default async function (t, load) {
    const crop = await load('model/photo-crop.js');
    const box = { x: 84, y: 27, w: 142, h: 112 };
    const land = { width: 6000, height: 4000 };
    const port = { width: 3816, height: 5400 };

    t.test('without rotation and flip the placement is the pre-K14 formula, literally', () => {
        const r = rng(1);
        for (let i = 0; i < 200; i++) {
            const asset = r() < 0.5 ? land : port;
            const c = { zoom: 1 + r() * 2, px: r(), py: r() };
            const p = crop.photoPlacement(box, asset, c);
            const ratio = asset.width / asset.height;
            const dh = Math.max(box.w / ratio, box.h) * c.zoom;
            const dw = dh * ratio;
            assert.equal(p.transform, null);
            assert.equal(p.w, dw);
            assert.equal(p.h, dh);
            assert.equal(p.x, box.x + (box.w - dw) * c.px);
            assert.equal(p.y, box.y + (box.h - dh) * c.py);
            near(p.w, asset.width * p.k, 1e-9, 'k');
        }
    });

    t.test('the frame is always covered — any angle, zoom, pan and flip', () => {
        const r = rng(7);
        const frames = [box, { x: -5, y: -5, w: 320, h: 37 }, { x: 10, y: 10, w: 40, h: 120 }];
        for (let i = 0; i < 2000; i++) {
            const b = frames[i % frames.length];
            const asset = [land, port, { width: 1000, height: 1000 }][i % 3];
            const c = { zoom: 1 + r() * 4, px: r(), py: r(), rotate: -180 + r() * 360, flip: r() < 0.5 };
            const corners = crop.pictureCorners(b, asset, c);
            for (const pt of [[b.x, b.y], [b.x + b.w, b.y], [b.x + b.w, b.y + b.h], [b.x, b.y + b.h]]) {
                assert.ok(inside(corners, pt, 1e-6), `corner ${pt} uncovered for ${JSON.stringify(c)}`);
            }
        }
    });

    t.test('a placed picture has the size of its cover scale × zoom, and its dpi follows', () => {
        const p = crop.photoPlacement(box, land, { zoom: 1, px: 0.5, py: 0.5, rotate: 90 });
        // turned by 90°, the frame's 142 mm run along the picture's 4000 px side
        near(p.k, Math.max(112 / 6000, 142 / 4000), 1e-12, 'k');
        assert.equal(crop.photoDpi(box, land, { zoom: 1, px: 0.5, py: 0.5 }), Math.round(25.4 / Math.max(142 / 6000, 112 / 4000)));
        assert.ok(crop.photoDpi(box, land, { zoom: 1, px: 0.5, py: 0.5, rotate: 10 }) < crop.photoDpi(box, land, { zoom: 1, px: 0.5, py: 0.5 }), 'straightening costs resolution');
        assert.equal(crop.photoPlacement(box, { width: 0, height: 0 }, {}), null);
    });

    t.test('transformAttr: translate, rotate, mirror — and nothing for a plain crop', () => {
        assert.equal(crop.transformAttr(crop.photoPlacement(box, land, { zoom: 1, px: 0.5, py: 0.5 })), null);
        const p = crop.photoPlacement(box, land, { zoom: 1, px: 0.5, py: 0.5, flip: true });
        assert.equal(crop.transformAttr(p, (v) => String(Math.round(v * 100) / 100)), 'translate(155 83) scale(-1 1)');
        const q = crop.photoPlacement(box, land, { zoom: 1, px: 0.5, py: 0.5, rotate: -12.5 });
        assert.match(crop.transformAttr(q), /^translate\(155 83\) rotate\(-12\.5\)$/);
        near(q.x, -q.w / 2, 1e-12, 'x');
    });

    t.test('cropItem writes the optional keys only when they are not default', () => {
        assert.deepEqual(crop.cropItem('a', { zoom: 1, px: 0.5, py: 0.5 }), { asset: 'a', zoom: 1, px: 0.5, py: 0.5 });
        assert.deepEqual(crop.cropItem('a', { zoom: 1, px: 0.5, py: 0.5, rotate: 0, flip: false }), { asset: 'a', zoom: 1, px: 0.5, py: 0.5 });
        assert.deepEqual(crop.cropItem('a', { zoom: 1.23456, px: 0.123456, py: 1, rotate: 90.04, flip: true }), { asset: 'a', zoom: 1.235, px: 0.1235, py: 1, rotate: 90, flip: true });
        assert.deepEqual(crop.cropItem('a', { zoom: 9, px: -1, py: 2, rotate: 270 }), { asset: 'a', zoom: 5, px: 0, py: 1, rotate: -90 });
        assert.deepEqual(crop.cropItem('a', null), { asset: 'a', zoom: 1, px: 0.5, py: 0.5 });
        assert.ok(crop.isDefaultCrop({ zoom: 1, px: 0.5, py: 0.5, rotate: 0 }));
        assert.ok(!crop.isDefaultCrop({ zoom: 1, px: 0.5, py: 0.5, flip: true }));
    });

    t.test('normalizeAngle keeps (−180, 180]', () => {
        assert.equal(crop.normalizeAngle(180), 180);
        assert.equal(crop.normalizeAngle(-180), 180);
        assert.equal(crop.normalizeAngle(270), -90);
        assert.equal(crop.normalizeAngle(-450), -90);
        assert.equal(crop.normalizeAngle('x'), 0);
    });

    t.test('panBy: the picture follows the pointer at any angle, and stops at the frame', () => {
        for (const rotate of [0, 30, 90, -135]) {
            const start = { zoom: 2, px: 0.5, py: 0.5, rotate, flip: rotate === 30 };
            const before = crop.photoPlacement(box, land, start);
            const moved = crop.panBy(box, land, start, 5, -3);
            const after = crop.photoPlacement(box, land, moved);
            const centre = (p) => (p.transform ? [p.transform.cx, p.transform.cy] : [p.x + p.w / 2, p.y + p.h / 2]);
            const [x0, y0] = centre(before);
            const [x1, y1] = centre(after);
            near(x1 - x0, 5, 1e-9, `dx at ${rotate}°`);
            near(y1 - y0, -3, 1e-9, `dy at ${rotate}°`);
        }
        const far = crop.panBy(box, land, { zoom: 2, px: 0.5, py: 0.5 }, 1e6, -1e6);
        assert.equal(far.px, 0);
        assert.equal(far.py, 1);
        // nothing to move at 100 % along the covering axis
        assert.equal(crop.panBy(box, land, { zoom: 1, px: 0.5, py: 0.5 }, 0, 50).py, 0.5);
    });

    t.test('zoomTo keeps the anchored picture point in place', () => {
        const start = { zoom: 1.5, px: 0.3, py: 0.6, rotate: 20 };
        const anchor = { x: 120, y: 70 };
        const toPicture = (c, pt) => {
            const p = crop.photoPlacement(box, land, c);
            const a = (-c.rotate * Math.PI) / 180;
            const dx = pt.x - p.transform.cx;
            const dy = pt.y - p.transform.cy;
            return [(dx * Math.cos(a) - dy * Math.sin(a)) / p.k, (dx * Math.sin(a) + dy * Math.cos(a)) / p.k];
        };
        const zoomed = crop.zoomTo(box, land, start, 2.5, anchor);
        const [u0, v0] = toPicture(start, anchor);
        const [u1, v1] = toPicture({ ...zoomed, rotate: 20 }, anchor);
        near(u1, u0, 1e-6, 'u');
        near(v1, v0, 1e-6, 'v');
        assert.equal(zoomed.zoom, 2.5);
        assert.equal(crop.zoomTo(box, land, start, 99).zoom, 5);
        assert.equal(crop.zoomTo(box, land, start, 0.2).zoom, 1);
    });

    t.test('mirrorCrop mirrors what the frame shows, and twice is the identity', () => {
        const r = rng(3);
        for (let i = 0; i < 50; i++) {
            const c = { zoom: 1 + r() * 3, px: Math.round(r() * 1e4) / 1e4, py: r(), rotate: Math.round((-90 + r() * 180) * 10) / 10, flip: r() < 0.5 };
            const m = crop.mirrorCrop(c);
            const a = crop.pictureCorners(box, land, c);
            const b = crop.pictureCorners(box, land, m);
            const axis = box.x + box.w / 2;
            // the mirrored corner set = the original's mirrored about the frame's vertical centre line
            const key = (pts) => pts.map(([x, y]) => `${x.toFixed(6)},${y.toFixed(6)}`).sort().join(' ');
            assert.equal(key(b), key(a.map(([x, y]) => [2 * axis - x, y])), JSON.stringify(c));
            const back = crop.mirrorCrop(m);
            near(back.px, c.px, 1e-9, 'px');
            assert.equal(back.flip, c.flip);
            assert.equal(back.rotate, crop.normalizeAngle(c.rotate));
        }
    });

    t.test('quarter turns: four are the identity; splitAngle separates the straightening', () => {
        let c = { zoom: 1.4, px: 0.5, py: 0.5 };
        for (let i = 0; i < 4; i++) c = crop.rotateBy90(box, land, c, 1);
        assert.equal(c.rotate, 0);
        near(c.px, 0.5, 1e-9, 'px');
        assert.equal(crop.rotateBy90(box, land, { rotate: 170 }, 1).rotate, -100);
        assert.deepEqual(crop.splitAngle(97.5), { quarter: 90, straighten: 7.5 });
        assert.deepEqual(crop.splitAngle(-44), { quarter: 0, straighten: -44 });
        assert.deepEqual(crop.splitAngle(-170), { quarter: 180, straighten: 10 });
        assert.deepEqual(crop.splitAngle(180), { quarter: 180, straighten: 0 });
    });

    t.test('a portrait picture turned sideways reads as landscape', () => {
        assert.ok(crop.isPortraitAsPlaced(port, {}));
        assert.ok(!crop.isPortraitAsPlaced(port, { rotate: 90 }));
        assert.ok(crop.isPortraitAsPlaced(land, { rotate: -90 }));
        assert.ok(!crop.isPortraitAsPlaced(land, { rotate: 30 }));
    });

    t.test('pageContentOf carries rotate / flip of a page photo and drops what is not a crop', async () => {
        const { pageContentOf } = await load('model/content.js');
        const photo = { asset: 'a1', zoom: 2, px: 0.25, py: 1, rotate: 90, flip: true, url: 'x' };
        assert.deepEqual(pageContentOf({ pageContent: { cover: { photo } } }, 'cover').photo, { asset: 'a1', zoom: 2, px: 0.25, py: 1, rotate: 90, flip: true });
        assert.deepEqual(pageContentOf({ pageContent: { cover: { photo: { asset: 'a1', zoom: 1, px: 0.5, py: 0.5 } } } }, 'cover').photo, { asset: 'a1', zoom: 1, px: 0.5, py: 0.5 });
        assert.equal(pageContentOf({ pageContent: { cover: { photo: { zoom: 1 } } } }, 'cover').photo, null);
    });

    t.test('photoIn: an unedited crop keeps the pre-K14 markup; rotate / flip only add a transform, never a filter', async () => {
        const { photoIn } = await load('render/svg.js');
        const asset = { url: 'p.jpg', width: 6000, height: 4000 };
        const plain = photoIn(box, asset, { zoom: 1.25, px: 0.3, py: 0.6 }, 0, {}, 'x');
        // the pre-K14 formula, written out
        const dh = Math.max(box.w / 1.5, box.h) * 1.25;
        const dw = dh * 1.5;
        const n = (v) => v.toFixed(3).replace(/\.?0+$/, '');
        assert.ok(plain.includes(`<image href="p.jpg" x="${n(box.x + (box.w - dw) * 0.3)}" y="${n(box.y + (box.h - dh) * 0.6)}" width="${n(dw)}" height="${n(dh)}" preserveAspectRatio="none"/>`), plain);
        const turned = photoIn(box, asset, { zoom: 1, px: 0.5, py: 0.5, rotate: 90, flip: true }, 2, {}, 'x');
        assert.match(turned, /<image [^>]*transform="translate\(155 83\) rotate\(90\) scale\(-1 1\)"/);
        assert.ok(turned.includes('clip-path="url(#x-p)"'));
        assert.ok(!/filter=|mask=/.test(turned));
    });

    t.test('the page warnings read the crop: dpi includes the turn, a zoom number still works', async () => {
        const { photoDpi } = await load('render/warnings.js');
        assert.equal(photoDpi(land, box, 2), crop.photoDpi(box, land, { zoom: 2 }));
        assert.equal(photoDpi(land, box, { zoom: 1, px: 0.5, py: 0.5, rotate: 90 }), crop.photoDpi(box, land, { rotate: 90 }));
        assert.ok(photoDpi(land, box, { zoom: 1, rotate: 90 }) < photoDpi(land, box, 1));
    });
}
