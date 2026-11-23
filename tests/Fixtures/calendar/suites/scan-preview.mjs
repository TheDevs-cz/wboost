// The scan review's proposal preview (`ui/scan/preview.js`): the photo region of the uploaded
// picture must land in the proposed photo box 1:1 — the box usually runs into the bleed, the upload
// shows the trimmed page only (K16: a box at −5 mm stretched the trim-only cut by ~4.5 % and the
// original caption showed beside the proposed caption plate).
import assert from 'node:assert/strict';

export default async function (t, load) {
    const { photoCutBox } = await load('ui/scan/preview.js');

    // the Chotěbuz scan of 2026-11-16 (prod 01a1e350-…): a 310 × 145 mm page in a 3661 × 1713 px picture
    const page = { width: 310, height: 145 };
    const proposal = {
        pageBox: [0, 0, 3661, 1713],
        photoBox: [0, 139, 1323, 1574],
        layout: { boxes: { photo: { x: -5, y: 11.8, w: 117, h: 138.2 } } },
    };

    t.test('the cut covers the whole photo box, bleed included', () => {
        const cut = photoCutBox(proposal, page);
        const s = 3661 / 310;
        assert.ok(Math.abs(cut[0] - -5 * s) < 0.01, `x ${cut[0]}`);
        assert.ok(Math.abs(cut[1] - 11.8 * (1713 / 145)) < 0.01, `y ${cut[1]}`);
        assert.ok(Math.abs(cut[2] - 117 * s) < 0.01, `w ${cut[2]}`);
        assert.ok(Math.abs(cut[3] - 138.2 * (1713 / 145)) < 0.01, `h ${cut[3]}`);
    });

    t.test('its ratio is the photo box ratio: the cover fit places it 1:1 (zoom 1, nothing cut away)', () => {
        const [, , w, h] = photoCutBox(proposal, page);
        const box = proposal.layout.boxes.photo;
        // in page mm (a picture of another ratio is stretched onto the page, as the scanner does)
        assert.ok(Math.abs((w / (3661 / 310)) / (h / (1713 / 145)) - box.w / box.h) < 1e-6);
        // the cut picture differs from the box ratio by < 0.1 %: the cover fit cuts nothing visible
        assert.ok(Math.abs(w / h / (box.w / box.h) - 1) < 0.001);
    });

    t.test('a page box inside the picture (a margin around the page) is honoured', () => {
        const cut = photoCutBox({ ...proposal, pageBox: [100, 50, 3100, 1450] }, page);
        assert.ok(Math.abs(cut[0] - (100 - 5 * 10)) < 0.01, `x ${cut[0]}`);
        assert.ok(Math.abs(cut[1] - (50 + 11.8 * 10)) < 0.01, `y ${cut[1]}`);
    });

    t.test('without a layout photo box or a page box: the scanner photoBox as it is', () => {
        assert.deepEqual(photoCutBox({ ...proposal, pageBox: null }, page), proposal.photoBox);
        assert.deepEqual(photoCutBox({ ...proposal, layout: { boxes: {} } }, page), proposal.photoBox);
        assert.equal(photoCutBox({ photoBox: null, layout: { boxes: {} } }, page), null);
    });
}
