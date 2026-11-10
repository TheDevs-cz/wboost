/*
 * The arithmetic of the "Nastavení tisku" modal, free of the DOM.
 * computeLayout() is the JS twin of Value/PrintPageGeometry (all in mm), pinned
 * against it by PrintSettingsGeometryContractTest. The fixed offset / length
 * come from the PHP constants through the controller's values.
 */

/** The drawn trim's long side never exceeds this, see previewSvg(). */
const PREVIEW_LONG_SIDE_MM = 100;

export function clampBleed(value, max) {
    return Number.isFinite(value) ? Math.min(max, Math.max(0, value)) : 0;
}

export function computeLayout({ width, height, bleed, marks, offset, length }) {
    const margin = marks ? Math.max(bleed, offset + length) : bleed;

    return {
        margin,
        mediaWidth: width + 2 * margin,
        mediaHeight: height + 2 * margin,
        bleedInset: margin - bleed,
        trimInset: margin,
    };
}

export function formatMm(value) {
    return String(Math.round(value * 100) / 100).replace('.', ',');
}

export function isValidSize({ width, height }) {
    return width > 0 && height > 0;
}

/** The info box: the bold page size, then the clauses separated by " · ". */
export function infoParts(setup) {
    const { mediaWidth, mediaHeight } = computeLayout(setup);
    const clauses = [];

    if (setup.bleed > 0) {
        clauses.push(`grafika přesahuje o ${formatMm(setup.bleed)} mm na každou stranu`);
    }

    if (setup.marks) {
        clauses.push(`značky: posun ${formatMm(setup.offset)} mm od ořezu, délka ${formatMm(setup.length)} mm`);
    }

    if (clauses.length === 0) {
        clauses.push('bez přesahu');
    }

    return { size: `${formatMm(mediaWidth)} × ${formatMm(mediaHeight)} mm`, clauses };
}

/** The one line next to the button on the add forms. */
export function summaryText(setup) {
    const parts = [setup.bleed > 0 ? `Spadávka ${formatMm(setup.bleed)} mm` : 'Bez spadávky'];

    if (setup.marks) {
        parts.push('ořezové značky');
    }

    if (isValidSize(setup)) {
        parts.push(`PDF ${infoParts(setup).size}`);
    }

    return parts.join(' · ');
}

/**
 * The preview drawing. Bleed and marks are true millimetres; a trim longer
 * than PREVIEW_LONG_SIDE_MM is drawn shrunk to that (same proportions), or an
 * A3's 5 mm bleed would be a hairline. Up to 100 mm everything is to scale,
 * and the caption always states the real size. The colours are the printed
 * sheet's, so they do not follow the theme. Only numbers reach this string.
 */
export function previewSvg({ width, height, bleed, marks, offset, length }, uid) {
    const shrink = Math.min(1, PREVIEW_LONG_SIDE_MM / Math.max(width, height));
    const w = width * shrink;
    const h = height * shrink;
    const { margin, mediaWidth, mediaHeight } = computeLayout({ width: w, height: h, bleed, marks, offset, length });
    const pad = 1.5;
    const n = (value) => Math.round(value * 1000) / 1000;
    const box = (grow) => `x="${n(margin - grow)}" y="${n(margin - grow)}" width="${n(w + 2 * grow)}" height="${n(h + 2 * grow)}"`;
    const left = margin;
    const top = margin;
    const right = margin + w;
    const bottom = margin + h;
    const unit = Math.min(w, h);

    let ticks = '';
    if (marks) {
        const start = offset;
        const end = offset + length;
        for (const [x, dx] of [[left, -1], [right, 1]]) {
            for (const [y, dy] of [[top, -1], [bottom, 1]]) {
                ticks += `M${n(x + dx * start)} ${n(y)}H${n(x + dx * end)}M${n(x)} ${n(y + dy * start)}V${n(y + dy * end)}`;
            }
        }
    }

    const ring = bleed > 0
        ? `<path fill="url(#ps-hatch-${uid})" fill-rule="evenodd" d="M${n(left - bleed)} ${n(top - bleed)}h${n(w + 2 * bleed)}v${n(h + 2 * bleed)}h${n(-(w + 2 * bleed))}zM${n(left)} ${n(top)}h${n(w)}v${n(h)}h${n(-w)}z"/>`
            + `<rect ${box(bleed)} fill="none" stroke="#d92d20" stroke-width="1.25" vector-effect="non-scaling-stroke"/>`
        : '';

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${n(-pad)} ${n(-pad)} ${n(mediaWidth + 2 * pad)} ${n(mediaHeight + 2 * pad)}" role="img" aria-hidden="true">`
        + `<defs><linearGradient id="ps-art-${uid}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#5b8def"/><stop offset="1" stop-color="#c26bd6"/></linearGradient>`
        + `<pattern id="ps-hatch-${uid}" width="2" height="2" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><line x1="0" y1="0" x2="0" y2="2" stroke="#d92d20" stroke-width=".55"/></pattern></defs>`
        + `<rect x="0" y="0" width="${n(mediaWidth)}" height="${n(mediaHeight)}" fill="#fff"/>`
        + `<rect ${box(bleed)} fill="url(#ps-art-${uid})"/>`
        + `<circle cx="${n(left + w / 2)}" cy="${n(top + h / 2 - unit * 0.1)}" r="${n(unit * 0.14)}" fill="#fff" fill-opacity=".85"/>`
        + `<text x="${n(left + w / 2)}" y="${n(top + h / 2 + unit * 0.22)}" text-anchor="middle" font-size="${n(unit * 0.15)}" font-weight="700" fill="#fff">LOGO</text>`
        + ring
        + `<rect ${box(0)} fill="none" stroke="#000" stroke-width="1.25" stroke-dasharray="5 4" vector-effect="non-scaling-stroke"/>`
        + (ticks ? `<path d="${ticks}" fill="none" stroke="#fff" stroke-width="3" vector-effect="non-scaling-stroke"/><path d="${ticks}" fill="none" stroke="#000" stroke-width="1.25" vector-effect="non-scaling-stroke"/>` : '')
        + '</svg>';
}
