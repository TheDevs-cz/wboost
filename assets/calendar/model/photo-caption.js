// A starting caption for a week photo from its file name („Fotky a popisky“, feedback K11).
//
// Pure. `captionFromFileName(name, max)` returns a human caption or `null`:
//   - the extension goes, `_` / `-` runs become spaces, Unicode is NFC (macOS hands out NFD names);
//   - a leading ordering prefix (`01 `, `01_`, `01-`, `1. `, `1) `) goes — bulk upload orders by name;
//   - camera / generated names give `null`: `IMG_1234`, `DSC01234`, `PXL_2024…`, `VID_…`,
//     `Screenshot…`, `Snímek obrazovky…`, `WhatsApp Image…`, uuids, hex hashes, and anything
//     left with fewer than three letters (pure digits, dates);
//   - the result is cut to `max` characters (the caption cap), at a word boundary when one is near.
// The caller uses it only when the week has no caption yet and the picture carries no embedded one.

const GENERATED = [
    // camera and phone files: a short prefix followed by digits
    /^(img|dsc[nf]?|dscf|pxl|vid|mvimg|pano|dji|gopr|gp|sam|imag|pict?|photo|p|_?mg|cimg|wp|hpim|sdc|kif|r)[\s_-]?\d/i,
    /^(screenshot|screen shot|snímek obrazovky|snimek obrazovky|bildschirmfoto|capture d.écran)/i,
    /^(whatsapp|signal|telegram|messenger|fb_img|received_|unnamed)([\s_-]|\d|$)/i,
    // a generic word plus a number only: `image (3)`, `foto_12`, `Obrázek 1`
    /^(image|img|obrázek|obrazek|photo|foto|fotka|picture|pic|untitled|bez názvu)[\s_()-]*\d*[\s()]*$/i,
];
const UUID = /^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i;
const HEX = /^[0-9a-f]{12,}$/i;

export function captionFromFileName(name, max = 80) {
    if (typeof name !== 'string') return null;
    let stem = name.normalize('NFC').split(/[\\/]/).pop().trim();
    stem = stem.replace(/\.[a-z0-9]{1,5}$/i, '').trim();
    // ordering prefix: 1–3 digits, then a separator (or a dot / bracket and a space)
    stem = stem.replace(/^\d{1,3}(?:[._)-]\s*|\s+)(?=\S)/, '');
    if (!stem || UUID.test(stem) || HEX.test(stem)) return null;
    if (GENERATED.some((re) => re.test(stem))) return null;
    const text = stem.replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
    if ((text.match(/\p{L}/gu) || []).length < 3) return null;
    if (text.length <= max) return text;
    // too long: cut at the last word boundary that keeps most of it
    const cut = text.slice(0, max + 1).lastIndexOf(' ');
    return (cut >= max * 0.6 ? text.slice(0, cut) : text.slice(0, max)).trim();
}
