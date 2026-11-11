// Text measuring. The layout never measures the DOM: it asks an injectable `Measurer`
// (canvas in the browser, a deterministic estimator in node).

const NARROW = new Set([...'iIl.,:;!|\'jtfríì']);
const DASHES = new Set([...'–—·']);

function charEm(ch) {
    if (ch === ' ' || ch === ' ') return 0.28;
    if (NARROW.has(ch)) return 0.3;
    if (ch === 'm' || ch === 'w') return 0.82;
    if (ch === 'M' || ch === 'W') return 0.9;
    if (DASHES.has(ch)) return 0.56;
    if (ch >= '0' && ch <= '9') return 0.54;
    const lower = ch.toLowerCase();
    const upper = ch.toUpperCase();
    if (lower !== upper) return ch === lower ? 0.54 : 0.66;
    return 0.6;
}

/** Deterministic estimator (node tests, server-side tooling). */
export function createEstimatorMeasurer() {
    return {
        width(text, font) {
            let em = 0;
            let count = 0;
            for (const ch of String(text)) {
                em += charEm(ch);
                count++;
            }
            if ((font.weight || 400) >= 600) em *= 1.06;
            return em * font.sizeMm + count * (font.letterSpacingMm || 0);
        },
    };
}

/** BROWSER ONLY: measures with a 2D canvas and the loaded web fonts. */
export function createCanvasMeasurer() {
    const canvas = typeof document !== 'undefined' ? document.createElement('canvas') : new OffscreenCanvas(8, 8);
    const ctx = canvas.getContext('2d');
    const cache = new Map();
    // a face that finishes loading changes the widths measured with its fallback so far
    if (typeof document !== 'undefined' && document.fonts && typeof document.fonts.addEventListener === 'function') {
        document.fonts.addEventListener('loadingdone', () => cache.clear());
    }
    return {
        width(text, font) {
            const value = String(text);
            const key = `${font.weight || 400}|${font.family}|${value}`;
            let per100 = cache.get(key);
            if (per100 === undefined) {
                ctx.font = `${font.weight || 400} 100px ${font.family}`;
                per100 = ctx.measureText(value).width;
                if (cache.size > 20000) cache.clear();
                cache.set(key, per100);
            }
            return (per100 / 100) * font.sizeMm + [...value].length * (font.letterSpacingMm || 0);
        },
    };
}

/** BROWSER ONLY: resolves when the given project families are usable for measuring. */
export async function ensureFontsLoaded(fonts, families) {
    if (typeof document === 'undefined' || !document.fonts) return;
    const wanted = (families || []).filter((family) => (fonts || []).some((f) => f.family === family));
    await Promise.all(wanted.map((family) => document.fonts.load(`16px "${family}"`).catch(() => null)));
    await document.fonts.load('16px "Outfit"').catch(() => null);
    await document.fonts.ready;
}

/**
 * Greedy word wrap. A word wider than the limit stays alone on its line; with `maxLines` the
 * remainder is joined onto the last line. Text is never truncated.
 */
export function wrapText(text, maxWidthMm, font, measurer, opts = {}) {
    const words = String(text || '').split(' ').filter((w) => w !== '');
    if (!words.length) return [];
    const lines = [];
    let line = '';
    for (const word of words) {
        const candidate = line ? `${line} ${word}` : word;
        if (line && measurer.width(candidate, font) > maxWidthMm) {
            lines.push(line);
            line = word;
        } else {
            line = candidate;
        }
    }
    lines.push(line);
    const max = opts.maxLines;
    if (max && lines.length > max) {
        return [...lines.slice(0, max - 1), lines.slice(max - 1).join(' ')];
    }
    return lines;
}

/** Every project font family a layout refers to. */
export function usedFamilies(layout) {
    const out = [];
    const walk = (node) => {
        if (!node || typeof node !== 'object') return;
        if (Array.isArray(node)) {
            node.forEach(walk);
            return;
        }
        for (const [key, value] of Object.entries(node)) {
            if (key === 'family') {
                if (typeof value === 'string' && value !== '' && !out.includes(value)) out.push(value);
            } else {
                walk(value);
            }
        }
    };
    walk(layout && layout.styles);
    return out;
}
