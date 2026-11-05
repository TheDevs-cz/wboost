/**
 * Colour helpers shared by the logo colour mapping (custom_color_controller)
 * and Paleta barev (project_palette_controller) — F7 P2.
 */

/** "1a2b3c" / "#fff" → [r, g, b], null when not a 3- or 6-digit HEX. */
export function hexToRgb(hex) {
    let value = String(hex ?? '').trim().replace(/^#/, '');
    if (/^[0-9a-f]{3}$/i.test(value)) {
        value = value.split('').map((c) => c + c).join('');
    }
    if (!/^[0-9a-f]{6}$/i.test(value)) return null;
    const n = Number.parseInt(value, 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

/**
 * "Dopočítat z HEX": the naive RGB → CMYK conversion (percent, rounded).
 * Only orientational — print values from the designer always win.
 */
export function cmykFromRgb(rgb) {
    const [r, g, b] = rgb.map((value) => value / 255);
    const k = 1 - Math.max(r, g, b);
    if (k >= 1) return [0, 0, 0, 100];
    return [(1 - r - k) / (1 - k), (1 - g - k) / (1 - k), (1 - b - k) / (1 - k), k].map((value) => Math.round(value * 100));
}

/** Contrast-aware text on a swatch: true = use dark text. */
export function isLightRgb(rgb) {
    return (0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]) / 255 > 0.6;
}
