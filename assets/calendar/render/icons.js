// Built-in row icons, drawn into a mm box (ported from the prototype's calBin2 / calMoon / calClockIco /
// calSeasonIco / calStar; stroke widths are relative to the icon instead of fixed canvas units).

import { MOON_PHASES } from '../generate/czech.js';
import { esc, num, rect, assetUrl } from './svg.js';

const title = (text) => (text ? `<title>${esc(text)}</title>` : '');

/** Waste bin: lid + slightly tapered body. */
export function binIcon(box, color, label) {
    const w = Math.min(box.w, box.h * 0.78);
    const x = box.x + (box.w - w) / 2;
    const { y, h } = box;
    return `<g>${title(label)}${rect({ x, y, w, h: h * 0.16 }, { rx: h * 0.06, fill: color })}<path d="M${num(x + w * 0.1)} ${num(y + h * 0.22)}H${num(x + w * 0.9)}L${num(x + w * 0.8)} ${num(y + h)}H${num(x + w * 0.2)}Z" fill="${esc(color)}"/></g>`;
}

/** 0 nov (dashed ring), 1 první čtvrť (right half), 2 úplněk (disc), 3 poslední čtvrť (left half). */
export function moonIcon(box, phase, color) {
    const r = Math.min(box.w, box.h) / 2;
    const cx = box.x + box.w / 2;
    const cy = box.y + box.h / 2;
    const sw = Math.max(0.15, r * 0.13);
    const label = title(MOON_PHASES[phase]);
    if (phase === 2) return `<circle cx="${num(cx)}" cy="${num(cy)}" r="${num(r)}" fill="${esc(color)}">${label}</circle>`;
    if (phase === 0) {
        return `<circle cx="${num(cx)}" cy="${num(cy)}" r="${num(r - sw / 2)}" fill="none" stroke="${esc(color)}" stroke-width="${num(sw)}" stroke-dasharray="${num(r * 0.5)} ${num(r * 0.42)}">${label}</circle>`;
    }
    return `<g>${label}<circle cx="${num(cx)}" cy="${num(cy)}" r="${num(r - sw / 2)}" fill="none" stroke="${esc(color)}" stroke-width="${num(sw)}"/><path d="M${num(cx)} ${num(cy - r)}A${num(r)} ${num(r)} 0 0 ${phase === 1 ? 1 : 0} ${num(cx)} ${num(cy + r)}Z" fill="${esc(color)}"/></g>`;
}

export function clockIcon(box, color, label) {
    const r = Math.min(box.w, box.h) / 2;
    const cx = box.x + box.w / 2;
    const cy = box.y + box.h / 2;
    const sw = Math.max(0.15, r * 0.16);
    return `<g>${title(label)}<circle cx="${num(cx)}" cy="${num(cy)}" r="${num(r * 0.9)}" fill="none" stroke="${esc(color)}" stroke-width="${num(sw)}"/><path d="M${num(cx)} ${num(cy - r * 0.55)}V${num(cy)}L${num(cx + r * 0.4)} ${num(cy + r * 0.25)}" fill="none" stroke="${esc(color)}" stroke-width="${num(sw)}" stroke-linecap="round"/></g>`;
}

/** 0 spring (flower), 1 summer (sun), 2 autumn (leaf), 3 winter (snowflake). */
export function seasonIcon(box, season, color, label) {
    const r = Math.min(box.w, box.h) / 2;
    const cx = box.x + box.w / 2;
    const cy = box.y + box.h / 2;
    const c = esc(color);
    let body = '';
    if (season === 1) {
        body = `<circle cx="${num(cx)}" cy="${num(cy)}" r="${num(r * 0.42)}" fill="${c}"/>`;
        for (let a = 0; a < 8; a++) {
            const A = (a * Math.PI) / 4;
            body += `<line x1="${num(cx + Math.cos(A) * r * 0.62)}" y1="${num(cy + Math.sin(A) * r * 0.62)}" x2="${num(cx + Math.cos(A) * r * 0.92)}" y2="${num(cy + Math.sin(A) * r * 0.92)}" stroke="${c}" stroke-width="${num(Math.max(0.15, r * 0.14))}" stroke-linecap="round"/>`;
        }
    } else if (season === 3) {
        for (let a = 0; a < 3; a++) {
            const A = (a * Math.PI) / 3;
            body += `<line x1="${num(cx - Math.cos(A) * r * 0.9)}" y1="${num(cy - Math.sin(A) * r * 0.9)}" x2="${num(cx + Math.cos(A) * r * 0.9)}" y2="${num(cy + Math.sin(A) * r * 0.9)}" stroke="${c}" stroke-width="${num(Math.max(0.15, r * 0.15))}" stroke-linecap="round"/>`;
        }
    } else if (season === 2) {
        body = `<path d="M${num(cx - r * 0.7)} ${num(cy + r * 0.7)}C${num(cx - r * 0.8)} ${num(cy - r * 0.5)} ${num(cx + r * 0.2)} ${num(cy - r * 0.9)} ${num(cx + r * 0.8)} ${num(cy - r * 0.8)}C${num(cx + r * 0.9)} ${num(cy - r * 0.1)} ${num(cx + r * 0.4)} ${num(cy + r * 0.8)} ${num(cx - r * 0.7)} ${num(cy + r * 0.7)}Z" fill="${c}"/>`;
    } else {
        for (let a = 0; a < 5; a++) {
            const A = (a * 2 * Math.PI) / 5 - Math.PI / 2;
            body += `<circle cx="${num(cx + Math.cos(A) * r * 0.5)}" cy="${num(cy + Math.sin(A) * r * 0.5)}" r="${num(r * 0.36)}" fill="${c}"/>`;
        }
        body += `<circle cx="${num(cx)}" cy="${num(cy)}" r="${num(r * 0.26)}" fill="#FFFFFF"/>`;
    }
    return `<g>${title(label)}${body}</g>`;
}

/** Event icon: rounded tile with a white star. */
export function starIcon(box, color, label) {
    const cx = box.x + box.w / 2;
    const cy = box.y + box.h / 2;
    const R = Math.min(box.w, box.h) * 0.3;
    const r = R * 0.45;
    const pts = [];
    for (let i = 0; i < 10; i++) {
        const a = -Math.PI / 2 + (i * Math.PI) / 5;
        const q = i % 2 ? r : R;
        pts.push(`${num(cx + q * Math.cos(a))},${num(cy + q * Math.sin(a))}`);
    }
    return `<g>${title(label)}${rect(box, { rx: Math.min(box.w, box.h) * 0.16, fill: color })}<polygon points="${pts.join(' ')}" fill="#FFFFFF"/></g>`;
}

/** An icon of a day row: the uploaded picture when there is one, else the built-in drawing. */
export function iconFragment(ref, box, opts) {
    const assets = (opts && opts.env && opts.env.assets) || {};
    const asset = ref.asset ? assets[ref.asset] : null;
    if (asset) {
        return `<g>${title(ref.title)}<image href="${esc(assetUrl(asset, opts && opts.quality))}" x="${num(box.x)}" y="${num(box.y)}" width="${num(box.w)}" height="${num(box.h)}" preserveAspectRatio="xMidYMid meet"/></g>`;
    }
    switch (ref.category) {
        case 'waste':
            return binIcon(box, ref.color, ref.title);
        case 'moon':
            return moonIcon(box, ref.phase, ref.color);
        case 'time':
            return clockIcon(box, ref.color, ref.title);
        case 'season':
            return seasonIcon(box, ref.season, ref.color, ref.title);
        default:
            return starIcon(box, ref.color, ref.title);
    }
}
