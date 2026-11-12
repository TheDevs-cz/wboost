// AI sken — pieces shared by the screens of the page: head, pictures, crops, boxes.
import { esc, ic, zz } from '../kit.js';

export const PREM = `<span class="tp-prem">${ic('sparkle', 's14')}Premium</span>`;
export const LEAD = 'Nahrajte hotovou grafiku a AI z ní poskládá upravitelnou šablonu — texty, tvary, barvy a místa pro obrázky. Trvá to obvykle 1–3 minuty a nemusíte u toho čekat.';

/** How many steps the wizard has: the fourth only when the proposal has places for pictures. */
export const stepCount = (app) => (app.proposal && !app.proposal.placeholders.length ? 3 : 4);

/** Back row + head with the position label (ux.md §5). */
export function head(app, n, name, { lead = false } = {}) {
    const of = stepCount(app);
    return `<div class="as-backrow" data-region="backrow"><a href="${esc(app.urls.templates)}" class="back">${ic('arrow-left')}Šablony</a>${historyLink(app)}</div>
<header class="head as-head" data-region="head"><div class="head-main"><span class="lbl">Projekt · ${esc(app.project.name)}</span><h1>Šablona z obrázku ${PREM}</h1>${lead ? `<span class="head-lead">${LEAD}</span>` : ''}<span class="as-step">Krok <b>${n} ${zz(of)} ${of}</b> · ${name}</span></div></header>`;
}
export const historyLink = (app) => (app.entry && app.entry.historyCount > 0 ? `<a href="${esc(app.urls.history)}" class="btn xs btn-ghost as-hist">${ic('history', 's14')}<span>Historie skenů</span></a>` : '');

/** `[x, y, w, h]` in source pixels → percent style of an absolutely placed box. */
export function boxStyle(box, source) {
    const [x, y, w, h] = box;
    return `left:${pc(x / source.width)}%;top:${pc(y / source.height)}%;width:${pc(w / source.width)}%;height:${pc(h / source.height)}%`;
}
const pc = (fraction) => Math.round(fraction * 10000) / 100;

/**
 * The picture's own pixels inside `box` as a CSS background of an element that has the box's
 * shape: background-size scales the whole picture so the box fills the element.
 */
export function pixelsStyle(url, box, source) {
    const [x, y, w, h] = box;
    const sx = (source.width / Math.max(1, w)) * 100;
    const sy = (source.height / Math.max(1, h)) * 100;
    const px = source.width - w <= 0 ? 0 : (x / (source.width - w)) * 100;
    const py = source.height - h <= 0 ? 0 : (y / (source.height - h)) * 100;
    return `background-image:url('${esc(url)}');background-size:${r2(sx)}% ${r2(sy)}%;background-position:${r2(px)}% ${r2(py)}%`;
}
const r2 = (v) => Math.round(v * 100) / 100;

/**
 * A crop of `url` around `box` (grown by `pad`, clamped to the picture) for a `<span>` of the
 * given aspect: returns the style incl. `aspect-ratio`, so the crop is never distorted.
 */
export function cropStyle(url, box, source, { pad = 0.25, minRatio = 0.6, maxRatio = 6 } = {}) {
    let [x, y, w, h] = box;
    const gx = w * pad;
    const gy = h * pad;
    x = Math.max(0, x - gx);
    y = Math.max(0, y - gy);
    w = Math.min(source.width - x, w + 2 * gx);
    h = Math.min(source.height - y, h + 2 * gy);
    let ratio = w / Math.max(1, h);
    // Keep the crop inside a usable shape by widening / heightening the window, never by stretching.
    if (ratio > maxRatio) { const nh = Math.min(source.height, w / maxRatio); y = Math.max(0, Math.min(source.height - nh, y - (nh - h) / 2)); h = nh; }
    if (ratio < minRatio) { const nw = Math.min(source.width, h * minRatio); x = Math.max(0, Math.min(source.width - nw, x - (nw - w) / 2)); w = nw; }
    ratio = w / Math.max(1, h);
    return `aspect-ratio:${r2(ratio)};${pixelsStyle(url, [x, y, w, h], source)}`;
}

/** Original | proposal crop pair of one spot (principle 4). */
export function crops(app, box) {
    const p = app.proposal;
    if (!box || !p.source) return '';
    return `<div class="as-crops"><figure class="as-crop"><span role="img" aria-label="Výřez původního obrázku" style="${cropStyle(app.originalUrl(), box, p.source, { minRatio: 1.6 })}"></span><figcaption>Původní</figcaption></figure><figure class="as-crop"><span role="img" aria-label="Výřez návrhu" style="${cropStyle(app.proposalUrl(), box, p.source, { minRatio: 1.6 })}"></span><figcaption>Návrh</figcaption></figure></div>`;
}
export function cropOne(app, box, label = 'Výřez původního obrázku') {
    const p = app.proposal;
    if (!box || !p.source) return '';
    return `<div class="as-crops one"><figure class="as-crop"><span role="img" aria-label="${esc(label)}" style="${cropStyle(app.originalUrl(), box, p.source, { minRatio: 1.6 })}"></span><figcaption>Původní</figcaption></figure></div>`;
}

/** The union of several `[x, y, w, h]` boxes. */
export function union(boxes) {
    const list = (boxes || []).filter(Boolean);
    if (!list.length) return null;
    const x1 = Math.min(...list.map((b) => b[0]));
    const y1 = Math.min(...list.map((b) => b[1]));
    const x2 = Math.max(...list.map((b) => b[0] + b[2]));
    const y2 = Math.max(...list.map((b) => b[1] + b[3]));
    return [x1, y1, x2 - x1, y2 - y1];
}

/** A picture that falls back through other sources when one is missing (404). */
export function fallbackImg(sources, alt, cls = '') {
    const [first, ...rest] = sources;
    return `<img src="${esc(first)}" alt="${esc(alt)}"${cls ? ` class="${cls}"` : ''} data-fallback="${esc(rest.join('|'))}" onerror="var f=(this.dataset.fallback||'').split('|').filter(Boolean);if(f.length){this.dataset.fallback=f.slice(1).join('|');this.src=f[0];}else{this.onerror=null;this.closest('.as-frame')&&this.closest('.as-frame').classList.add('none');}">`;
}
