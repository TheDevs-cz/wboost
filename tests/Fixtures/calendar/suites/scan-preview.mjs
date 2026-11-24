// The scan review's proposal preview (`ui/scan/preview.js`): the photo region of the uploaded
// picture must land in the proposed photo box 1:1 — the box usually runs into the bleed, the upload
// shows the trimmed page only (K16: a box at −5 mm stretched the trim-only cut by ~4.5 % and the
// original caption showed beside the proposed caption plate).
import assert from 'node:assert/strict';

export default async function (t, load) {
    const { photoCutBox, elementFact, scanLayersFact } = await load('ui/scan/preview.js');
    const { placeOfSlot } = await load('ui/picture-size.js');

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

    // K21: „Co jsme rozpoznali“ says what the scan took of the picture effects, the corners and the number band
    t.test('the facts name the effects, corners and frame of the photo, the logo\'s effects and the number band', () => {
        const layout = {
            boxes: { photo: { x: 10, y: 33, w: 172, h: 100 }, logo: { x: 16, y: 118, w: 46, h: 12 }, cal: { x: 197, y: 27, w: 118, h: 112 } },
            styles: {
                photo: { radius: 4, border: { on: true }, effects: [{ kind: 'dropShadow' }, { kind: 'gradient', on: true }, { kind: 'outerGlow', on: false }] },
                logo: { effects: [{ kind: 'outerGlow' }] },
                cal: { numberSide: 'end', numBg: { color: '#E4E5E4', opacity: 100, grow: 18 } },
            },
        };
        assert.equal(elementFact('photo', layout, page), 'vlevo, zaoblené rohy, rámeček, stín, ztmavení');
        assert.equal(elementFact('logo', layout, page), 'vlevo dole, záře');
        assert.equal(elementFact('cal', layout, page), 'vpravo, čísla vpravo, pozadí čísel');
    });

    t.test('a proposal without them says only where things are', () => {
        const layout = { boxes: { photo: { x: 0, y: 27, w: 197, h: 118 }, logo: { x: 16, y: 118, w: 46, h: 12 }, cal: { x: 197, y: 27, w: 118, h: 112 } },
            styles: { photo: { radius: 0, border: { on: false }, effects: [] }, logo: { effects: [] }, cal: { numberSide: 'end', numBg: { color: null, opacity: 100, grow: 0 } } } };
        assert.equal(elementFact('photo', layout, page), 'vlevo, do okraje');
        assert.equal(elementFact('logo', layout, page), 'vlevo dole');
        assert.equal(elementFact('cal', layout, page), 'vpravo, čísla vpravo');
    });

    // K21: the own layers a scan proposes — one phrase in „Co jsme rozpoznali“, their picture places by layer id
    t.test('the scan\'s own layers are counted by kind; the designer\'s are not', () => {
        const layout = { layers: { scan_band: { kind: 'rect' }, scan_frame: { kind: 'rect' }, scan_rule: { kind: 'line' }, scan_logo2: { kind: 'image' }, mine: { kind: 'ellipse' } } };
        assert.equal(scanLayersFact(layout), '2 obdélníky, 1 čára, 1 obrázek');
        assert.equal(scanLayersFact({ layers: { mine: { kind: 'rect' } } }), '');
        assert.equal(scanLayersFact({}), '');
    });

    t.test('a picture layer\'s slot is its own box and takes the held picture in the preview', () => {
        assert.equal(placeOfSlot('scan_logo2'), 'u:scan_logo2');
        assert.equal(placeOfSlot('header'), 'head');
        assert.equal(placeOfSlot('logo'), 'logo');
    });
}
