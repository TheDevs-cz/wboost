// Project font helpers. `fonts` is the bootstrap list
// [{ family, fontName, faceName, weight, style, url }] (architecture §C.4).

export const FALLBACK_FAMILY = '"Outfit", Arial, sans-serif';

export function fontNames(fonts) {
    const out = [];
    for (const face of fonts || []) {
        if (!out.includes(face.fontName)) out.push(face.fontName);
    }
    return out;
}

export function facesOf(fonts, fontName) {
    return (fonts || []).filter((f) => f.fontName === fontName);
}

/** Upright preferred, nearest weight, ties → heavier. */
export function nearestFace(fonts, fontName, weight) {
    const faces = facesOf(fonts, fontName);
    if (!faces.length) return null;
    const upright = faces.filter((f) => (f.style || 'normal') === 'normal');
    const pool = upright.length ? upright : faces;
    let best = null;
    for (const face of pool) {
        if (!best) {
            best = face;
            continue;
        }
        const d = Math.abs(face.weight - weight);
        const bd = Math.abs(best.weight - weight);
        if (d < bd || (d === bd && face.weight > best.weight)) best = face;
    }
    return best;
}

export function faceByFamily(fonts, family) {
    if (!family) return null;
    return (fonts || []).find((f) => f.family === family) || null;
}

/** CSS family + weight to draw a text style with. */
export function cssFont(style, fonts) {
    const face = faceByFamily(fonts, style && style.family);
    if (face) {
        // every project face is registered as its own @font-face family
        return { family: `"${face.family}"`, weight: 400 };
    }
    return { family: FALLBACK_FAMILY, weight: Math.min(700, Number(style && style.weight) || 400) };
}

const WEIGHT_LABELS = {
    100: 'Thin',
    200: 'Extra Light',
    300: 'Light',
    400: 'Regular',
    500: 'Medium',
    600: 'Semi Bold',
    700: 'Bold',
    800: 'Extra Bold',
    900: 'Black',
};

export function weightLabel(weight) {
    const w = Math.min(900, Math.max(100, Math.round((Number(weight) || 400) / 100) * 100));
    return WEIGHT_LABELS[w];
}
