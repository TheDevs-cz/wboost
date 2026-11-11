// Content collections: ids, ordering, page overrides (architecture §B.6).
import assert from 'node:assert/strict';
import { ENV } from '../helpers.mjs';

export default async function (t, load) {
    const C = await load('model/content.js');
    const { normalizeContent } = await load('model/normalize.js');
    const { defaultLayout } = await load('model/layouts.js');
    const { DEFAULT_PAGE } = await load('model/defaults.js');

    t.test('collection registry and client whitelist', () => {
        assert.deepEqual(Object.keys(C.COLLECTIONS).sort(), ['clubs', 'comments', 'events', 'moonEdits', 'names', 'pageCaptions', 'pageContent', 'pageGroups', 'pageImages', 'pagePhotos', 'pageQr', 'pageSetup', 'shiftOps', 'specialPages', 'wasteExtras', 'wasteKinds']);
        const where = (flag) => Object.keys(C.COLLECTIONS).filter((k) => C.COLLECTIONS[k][flag]).sort();
        // the client fills in texts, photos and contact groups of the cover / back / special pages
        // (`pageContent`, `pageGroups`); what those pages look like (`pageSetup`, `specialPages`) is the designer's
        assert.deepEqual(where('clientWritable'), ['clubs', 'comments', 'events', 'names', 'pageCaptions', 'pageContent', 'pageGroups', 'pagePhotos', 'wasteExtras', 'wasteKinds']);
        assert.deepEqual(where('ordered'), ['clubs', 'pageGroups', 'shiftOps', 'specialPages', 'wasteKinds']);
        // NOT page-keyed: „Vrátit na styl“ (clearPageOverrides) must never remove a page's content
        assert.deepEqual(C.clearPageOverrides({ pageContent: { cover: { title: 'A' } }, pageSetup: { cover: {} }, pagePhotos: {}, pageCaptions: {}, pageImages: {}, pageQr: {} }, 'cover'), []);
        assert.equal(C.pageHasOverrides({ pageContent: { cover: { title: 'A' } }, pageSetup: { cover: {} } }, 'cover'), false);
        // a year change moves dated items only
        const kept = { pageContent: { cover: { title: 'A', subtitle: '', note: '', photo: null } }, pageGroups: { g: { page: 'sp:contacts', heading: 'H', lines: [], highlight: false, pos: 0 } }, pageSetup: { cover: { layout: 1, logo: 'tc', logoSize: 's' } } };
        assert.ok(C.shiftContentYear(normalizeContent(kept), 2027, 2028).ops.every((op) => !['pageContent', 'pageGroups', 'pageSetup'].includes(op.c)));
        assert.deepEqual(where('pageKeyed'), ['pageCaptions', 'pageImages', 'pagePhotos', 'pageQr']);
        assert.deepEqual(where('dated'), ['comments', 'events', 'moonEdits', 'names', 'wasteExtras']);
        assert.deepEqual(Object.keys(normalizeContent({})).sort(), ['v', ...Object.keys(C.COLLECTIONS)].sort());
    });

    t.test('newId: 16 base-62 characters, unique, deterministic with an injected random', () => {
        const seen = new Set();
        for (let i = 0; i < 500; i++) {
            const id = C.newId();
            assert.match(id, /^[A-Za-z0-9]{16}$/);
            seen.add(id);
        }
        assert.equal(seen.size, 500);
        let n = 0;
        const rnd = () => ((n++ * 7) % 62) / 62 + 0.001;
        n = 0;
        const a = C.newId(rnd);
        n = 0;
        const b = C.newId(rnd);
        assert.equal(a, b);
        assert.match(a, /^[A-Za-z0-9]{16}$/);
        assert.match(C.newId(() => 0.999999), /^z{16}$/);
    });

    t.test('sorted: by pos then id, by date then id', () => {
        const ordered = { b: { pos: 1 }, a: { pos: 1 }, c: { pos: 0 } };
        assert.deepEqual(C.sorted(ordered, 'pos').map(([id]) => id), ['c', 'a', 'b']);
        const dated = { z: { date: '2027-01-02' }, y: { date: '2027-01-01' }, x: { date: '2027-01-02' } };
        assert.deepEqual(C.sorted(dated, 'date').map(([id]) => id), ['y', 'x', 'z']);
        assert.deepEqual(C.sorted(undefined, 'pos'), []);
        assert.equal(C.nextPos(ordered), 2);
        assert.equal(C.nextPos({}), 0);
    });

    t.test('reorder returns only the items whose pos changed', () => {
        const col = { a: { name: 'A', pos: 0 }, b: { name: 'B', pos: 1 }, c: { name: 'C', pos: 2 }, d: { name: 'D', pos: 3 } };
        assert.deepEqual(C.reorder(col, ['a', 'c', 'b', 'd']), { c: { name: 'C', pos: 1 }, b: { name: 'B', pos: 2 } });
        assert.deepEqual(C.reorder(col, ['a', 'b', 'c', 'd']), {});
        // ids missing from the list keep their order behind it; unknown ids are ignored
        assert.deepEqual(C.reorder(col, ['d', 'nope']), { d: { name: 'D', pos: 0 }, a: { name: 'A', pos: 1 }, b: { name: 'B', pos: 2 }, c: { name: 'C', pos: 3 } });
        assert.equal(col.d.pos, 3);
    });

    t.test('date limits of a calendar year', () => {
        assert.deepEqual(C.dateLimits(2027), { min: '2026-12-01', max: '2028-01-31' });
    });

    t.test('page overrides: any of the four collections counts; reset deletes them all', () => {
        const content = normalizeContent({
            pagePhotos: { w05: { asset: 'a', zoom: 1, px: 0.5, py: 0.5 } },
            pageCaptions: { w05: { text: 'x' }, w06: { text: 'y' } },
            pageImages: { w07: { head: null, bg: 'b' } },
            pageQr: { w05: { asset: 'q' } },
        });
        assert.equal(C.pageHasOverrides(content, 'w05'), true);
        assert.equal(C.pageHasOverrides(content, 'w06'), true);
        assert.equal(C.pageHasOverrides(content, 'w07'), true);
        assert.equal(C.pageHasOverrides(content, 'w08'), false);
        assert.deepEqual(C.clearPageOverrides(content, 'w05'), [{ c: 'pagePhotos', id: 'w05', v: null }, { c: 'pageCaptions', id: 'w05', v: null }, { c: 'pageQr', id: 'w05', v: null }]);
        assert.deepEqual(C.clearPageOverrides(content, 'w08'), []);
    });

    t.test('special page items: defaults for the four generated kinds', () => {
        const content = normalizeContent({ specialPages: { intro: { kind: 'intro', on: true, layout: 2, position: 'back', nextYear: false, name: 'Slovo starosty', asset: null, text: null, fileName: null, pos: 0 } } });
        assert.equal(C.specialPageItem(content, 'intro').name, 'Slovo starosty');
        assert.deepEqual(C.specialPageItem(content, 'year'), { kind: 'year', on: false, layout: 0, position: 'front', nextYear: true, name: '', asset: null, text: null, fileName: null, pos: 2 });
        assert.deepEqual(C.specialPageItem(content, 'waste').pos, 3);
        assert.equal(C.specialPageItem(content, 'contacts').nextYear, false);
        assert.equal(C.specialPageItem(content, 'unknown'), null);
    });

    t.test('layoutAssetIds finds every referenced asset once', () => {
        const layout = defaultLayout('photoLeft', DEFAULT_PAGE, 5, ENV);
        assert.deepEqual(C.layoutAssetIds(layout), []);
        layout.styles.logo.asset = 'logo-1';
        layout.styles.head.bg.asset = 'head-1';
        layout.styles.bg.asset = 'head-1';
        layout.styles.qr.asset = 'qr-1';
        layout.styles.moon.icons = [null, 'moon-1', null, 'moon-3'];
        layout.styles.icons.ev.asset = 'ev-1';
        layout.styles.icons.season.assets = ['s0', null, 's2', null];
        layout.styles.icons.season.asset = 's-one';
        layout.styles.waste.kinds = { bio: { color: null, icon: 'bin-1' } };
        assert.deepEqual(C.layoutAssetIds(layout).sort(), ['bin-1', 'ev-1', 'head-1', 'logo-1', 'moon-1', 'moon-3', 'qr-1', 's-one', 's0', 's2']);
        assert.deepEqual(C.layoutAssetIds(null), []);
    });
}
