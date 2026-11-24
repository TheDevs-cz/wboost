// A starting caption from a photo's file name („Fotky a popisky“, feedback K11).
import assert from 'node:assert/strict';

export default async function (t, load) {
    const { captionFromFileName } = await load('model/photo-caption.js');

    t.test('human names: extension, separators and the ordering prefix go', () => {
        const kept = {
            'Léto na horách.jpg': 'Léto na horách',
            'leto_na_horach.JPG': 'leto na horach',
            'vylet--do-Beskyd.jpeg': 'vylet do Beskyd',
            '01 Jaro v sadu.jpg': 'Jaro v sadu',
            '01_jaro.png': 'jaro',
            '07-Babí léto.webp': 'Babí léto',
            '1. Sněhulák.heic': 'Sněhulák',
            '12) Vánoce.jpg': 'Vánoce',
            'Foto z dovolené.jpg': 'Foto z dovolené',
            'Image of the farm.png': 'Image of the farm',
            // K18: generic words are captions too — „the file name without its extension“
            'Foto.jpg': 'Foto',
            'Foto.png': 'Foto',
            'foto_12.jpg': 'foto 12',
            'Obrázek 1.png': 'Obrázek 1',
            'image (3).png': 'image (3)',
            'Photo 2.jpg': 'Photo 2',
            '2024 Chata.jpg': '2024 Chata',
            'cesta/k/souboru/Ples.jpg': 'Ples',
            // macOS hands out decomposed (NFD) names
            'Le\u0301to.jpg': 'Léto',
        };
        for (const [name, caption] of Object.entries(kept)) assert.equal(captionFromFileName(name), caption, name);
    });

    t.test('camera, phone and generated names give no caption', () => {
        const skipped = [
            'IMG_1234.jpg', 'IMG-20240512-WA0003.jpg', 'img1234.jpeg', 'DSC01234.JPG', 'DSCN0042.jpg', 'DSCF7781.RAF',
            'PXL_20240512_143022123.jpg', 'VID_20240101.mp4', 'MVIMG_20190101_1200.jpg', 'DJI_0042.jpg', 'GOPR0123.jpg',
            'P1010234.JPG', '_MG_4471.CR2', 'Screenshot 2024-05-12 at 14.03.22.png', 'Snímek obrazovky 2024-05-12 v 14.03.22.png',
            'WhatsApp Image 2024-05-12 at 14.03.22.jpeg', 'unnamed.jpg',
            '20240512_143022.jpg', '2024-05-12.jpg', '0001.jpg', '01.jpg', '01_IMG_1234.jpg',
            '3f2504e0-4f89-11d3-9a0c-0305e82c3301.jpg', 'a3f9c0de12b4e5f6.jpg', 'ab.jpg', '01 ab.jpg', '', '.jpg',
        ];
        for (const name of skipped) assert.equal(captionFromFileName(name), null, name);
        assert.equal(captionFromFileName(null), null);
        assert.equal(captionFromFileName(undefined), null);
    });

    t.test('the caption is cut to the cap', () => {
        const long = `${'Dlouhý název '.repeat(10)}.jpg`;
        const caption = captionFromFileName(long, 80);
        assert.ok(caption.length <= 80);
        assert.ok(caption.startsWith('Dlouhý název Dlouhý'));
        assert.equal(caption, caption.trim());
        assert.ok(long.startsWith(`${caption} `), 'cut at a word boundary');
        assert.equal(
            captionFromFileName('03 Velmi dlouhý název fotky z obecní slavnosti na návsi u kapličky 2024 finální verze.png'),
            'Velmi dlouhý název fotky z obecní slavnosti na návsi u kapličky 2024 finální',
        );
        // one endless word: a hard cut
        assert.equal(captionFromFileName(`${'x'.repeat(100)}.jpg`), 'x'.repeat(80));
    });
}
