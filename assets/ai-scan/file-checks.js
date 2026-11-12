// AI sken — what the browser can say about a chosen file before anything is sent (ux.md S1, S2).
// The limits mirror Services/AiScan/AiScanUpload (header only): JPEG, PNG, WebP, HEIC;
// ≤ 10 000 000 bytes (DECIMAL — the gallery's limit); each side ≤ 8000 px; ≤ 36 Mpx; short side
// ≥ 600 px. The server checks again; a HEIC's pixels are checked by the scanner.

export const MAX_BYTES = 10 * 1000 * 1000;
export const MAX_SIDE = 8000;
export const MAX_PIXELS = 36 * 1000 * 1000;
export const MIN_SHORT_SIDE = 600;
export const GOOD_SHORT_SIDE = 1000;
/** The longer side of a template made from a scan (ux.md §2.2). */
export const TEMPLATE_MAX_LONG_EDGE = 5000;

const SOURCE_EXTENSIONS = /\.(pdf|psd|psb|ai|eps|idml|indd|svg|sketch|fig|xd|afdesign|afphoto)$/i;

const ascii = (bytes, from, to) => String.fromCharCode(...bytes.subarray(from, to));
const u16be = (b, i) => (b[i] << 8) | b[i + 1];
const u32be = (b, i) => ((b[i] << 24) | (b[i + 1] << 16) | (b[i + 2] << 8) | b[i + 3]) >>> 0;
const u16le = (b, i) => b[i] | (b[i + 1] << 8);
const u24le = (b, i) => b[i] | (b[i + 1] << 8) | (b[i + 2] << 16);

/** `jpeg` | `png` | `webp` | `heic` | `source` (PDF / PSD / …) | null — by magic bytes, like the server. */
export function sniffType(bytes, name = '') {
    if (bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) return 'jpeg';
    if (bytes.length >= 8 && u32be(bytes, 0) === 0x89504e47) return 'png';
    if (bytes.length >= 12 && ascii(bytes, 0, 4) === 'RIFF' && ascii(bytes, 8, 12) === 'WEBP') return 'webp';
    if (bytes.length >= 12 && ascii(bytes, 4, 8) === 'ftyp' && /^(heic|heix|hevc|hevx|heim|heis|mif1|msf1|heif)/.test(ascii(bytes, 8, 12))) return 'heic';
    if (bytes.length >= 4 && (ascii(bytes, 0, 4) === '%PDF' || ascii(bytes, 0, 4) === '8BPS' || ascii(bytes, 0, 4) === '%!PS')) return 'source';
    if (SOURCE_EXTENSIONS.test(name)) return 'source';
    return null;
}

/** Pixel size declared by the header, or null (HEIC, or a header we cannot read). */
export function headerSize(bytes, type) {
    if (type === 'png' && bytes.length >= 24) return { width: u32be(bytes, 16), height: u32be(bytes, 20) };
    if (type === 'webp' && bytes.length >= 30) {
        const chunk = ascii(bytes, 12, 16);
        if (chunk === 'VP8X') return { width: u24le(bytes, 24) + 1, height: u24le(bytes, 27) + 1 };
        if (chunk === 'VP8L') {
            const b = bytes.subarray(21, 25);
            return { width: 1 + (((b[1] & 0x3f) << 8) | b[0]), height: 1 + (((b[3] & 0x0f) << 10) | (b[2] << 2) | ((b[1] & 0xc0) >> 6)) };
        }
        if (chunk === 'VP8 ') return { width: u16le(bytes, 26) & 0x3fff, height: u16le(bytes, 28) & 0x3fff };
        return null;
    }
    if (type === 'jpeg') {
        let i = 2;
        while (i + 9 < bytes.length) {
            if (bytes[i] !== 0xff) { i += 1; continue; }
            const marker = bytes[i + 1];
            if (marker === 0xff) { i += 1; continue; }
            if (marker >= 0xd0 && marker <= 0xd9) { i += 2; continue; }
            const length = u16be(bytes, i + 2);
            // SOF0–SOF15 except DHT (C4), JPG (C8), DAC (CC)
            if (marker >= 0xc0 && marker <= 0xcf && marker !== 0xc4 && marker !== 0xc8 && marker !== 0xcc) {
                return { height: u16be(bytes, i + 5), width: u16be(bytes, i + 7) };
            }
            i += 2 + length;
        }
    }
    return null;
}

const KNOWN_RATIOS = [[1, 1], [4, 5], [5, 4], [3, 4], [4, 3], [2, 3], [3, 2], [9, 16], [16, 9], [1, 2], [2, 1], [3, 1], [1, 3], [21, 9]];
/** `4:5`, `16:9`, … — the nearest common ratio within 1 %, else the decimal form `1:1,41`. */
export function ratioLabel(width, height) {
    const r = width / height;
    for (const [a, b] of KNOWN_RATIOS) {
        if (Math.abs(r / (a / b) - 1) <= 0.01) return `${a}:${b}`;
    }
    return r >= 1 ? `${(Math.round(r * 100) / 100).toString().replace('.', ',')}:1` : `1:${(Math.round((1 / r) * 100) / 100).toString().replace('.', ',')}`;
}

const A_FORMATS = [['A3', 297, 420], ['A4', 210, 297], ['A5', 148, 210], ['A6', 105, 148]];
/** The A formats (up to A3) the picture's ratio matches within 1 %: `[{label, width, height}]` in mm. */
export function printFormats(width, height) {
    const portrait = height >= width;
    const r = portrait ? width / height : height / width;
    if (Math.abs(r / (1 / Math.SQRT2) - 1) > 0.01) return [];
    return A_FORMATS.map(([name, w, h]) => ({ label: `${name} ${portrait ? 'na výšku' : 'na šířku'} · ${portrait ? w : h} × ${portrait ? h : w} mm`, width: portrait ? w : h, height: portrait ? h : w }));
}

/**
 * Sharpness of a decoded picture: the strong end (99th percentile) of the Laplacian's magnitude
 * on a grey copy no larger than 1024 px. A sharp design has crisp edges whatever its flat
 * areas; a soft photo of a print has none. A hint only — never blocks (ux.md S2).
 */
export function isBlurry(bitmap) {
    const scale = Math.min(1, 1024 / Math.max(bitmap.width, bitmap.height));
    const w = Math.max(8, Math.round(bitmap.width * scale));
    const h = Math.max(8, Math.round(bitmap.height * scale));
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(bitmap, 0, 0, w, h);
    const { data } = ctx.getImageData(0, 0, w, h);
    const grey = new Float32Array(w * h);
    for (let i = 0, p = 0; i < data.length; i += 4, p += 1) grey[p] = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
    const histogram = new Uint32Array(1024);
    let count = 0;
    for (let y = 1; y < h - 1; y += 1) {
        for (let x = 1; x < w - 1; x += 1) {
            const p = y * w + x;
            const v = Math.abs(4 * grey[p] - grey[p - 1] - grey[p + 1] - grey[p - w] - grey[p + w]);
            histogram[Math.min(1023, Math.round(v))] += 1;
            count += 1;
        }
    }
    let seen = 0;
    let p99 = 0;
    for (let v = 1023; v >= 0; v -= 1) {
        seen += histogram[v];
        if (seen >= count * 0.01) { p99 = v; break; }
    }
    return p99 < 24;
}

/**
 * Inspects a FileList / array of files the user chose or dropped.
 * → `{ error: 'many' | 'source' | 'type' | 'bytes' | 'small' | 'large', width?, height? }`
 * or `{ file, type, width, height (null for HEIC / unreadable), previewUrl (null when the browser
 *      cannot decode it), warnings: ['small' | 'blurry' | 'heic'] }`.
 */
export async function inspectFiles(files) {
    const list = [...(files || [])];
    if (list.length === 0) return null;
    if (list.length > 1) return { error: 'many' };
    const file = list[0];
    const head = new Uint8Array(await file.slice(0, 512 * 1024).arrayBuffer());
    const type = sniffType(head, file.name);
    if (type === 'source') return { error: 'source' };
    if (type === null) return { error: 'type' };
    if (file.size > MAX_BYTES) return { error: 'bytes' };

    let size = headerSize(head, type);
    let bitmap = null;
    try { bitmap = await createImageBitmap(file); } catch (e) { bitmap = null; }
    if (size === null && bitmap) size = { width: bitmap.width, height: bitmap.height };

    if (size) {
        const { width, height } = size;
        if (Math.min(width, height) < MIN_SHORT_SIDE) return { error: 'small', width, height };
        if (Math.max(width, height) > MAX_SIDE || width * height > MAX_PIXELS) return { error: 'large', width, height };
    }

    const warnings = [];
    if (type === 'heic') warnings.push('heic');
    if (size && Math.min(size.width, size.height) < GOOD_SHORT_SIDE) warnings.push('small');
    let blurChecked = false;
    if (bitmap) {
        try { if (isBlurry(bitmap)) warnings.push('blurry'); blurChecked = true; } catch (e) { blurChecked = false; }
        if (typeof bitmap.close === 'function') bitmap.close();
    }
    return {
        file, type,
        width: size ? size.width : null,
        height: size ? size.height : null,
        previewUrl: bitmap ? URL.createObjectURL(file) : null,
        sharpnessChecked: blurChecked,
        warnings,
    };
}

/** Centre-crops a picture file to `ratio` (w / h) in the browser → a new File (PNG keeps PNG, else JPEG). */
export async function cropToRatio(file, ratio) {
    const bitmap = await createImageBitmap(file);
    const current = bitmap.width / bitmap.height;
    if (Math.abs(current / ratio - 1) <= 0.02) { if (bitmap.close) bitmap.close(); return file; }
    const sw = current > ratio ? Math.round(bitmap.height * ratio) : bitmap.width;
    const sh = current > ratio ? bitmap.height : Math.round(bitmap.width / ratio);
    const canvas = document.createElement('canvas');
    canvas.width = sw;
    canvas.height = sh;
    canvas.getContext('2d').drawImage(bitmap, Math.round((bitmap.width - sw) / 2), Math.round((bitmap.height - sh) / 2), sw, sh, 0, 0, sw, sh);
    if (bitmap.close) bitmap.close();
    const png = file.type === 'image/png' || file.type === 'image/webp';
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, png ? 'image/png' : 'image/jpeg', 0.92));
    if (!blob) return file;
    const stem = file.name.replace(/\.[^.]+$/, '');
    return new File([blob], `${stem}.${png ? 'png' : 'jpg'}`, { type: blob.type });
}
