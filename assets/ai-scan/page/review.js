// AI sken — S4 (review: compare, "K rozhodnutí", "Vrstvy", action bar) and the read-only page of
// a scan whose template exists (T4). ux.md S4, T4, §2.4. Every change is one fix, saved and
// rendered by the server at once (docs/architecture/ai-scan.md § "Fixes"); the answer's proposal
// document replaces the state, so "Vrátit" is `undo` of an item key and nothing is kept here.
import { api, confirmDialog, creditLine, dateCz, dialogHead, esc, ic, openDialog, pl, urlFor } from '../kit.js';
import { toast } from '../../shell/toast.js';
import { FONT_ROLES, SIMPLIFICATIONS, startHint, summaryLine, toDecideLine } from '../copy.js';
import { boxStyle, cropOne, crops, cropStyle, head, historyLink, pixelsStyle, union } from './parts.js';
import { offLine } from './wait.js';
import { uploadFonts, usePicture } from './upload.js';

const TAG = { text: 'T', place: 'O', shape: 'Tv', background: 'P' };
const fontRole = (role) => FONT_ROLES[role] || FONT_ROLES.other;
const q = (text) => `„${esc(text)}“`;

// ---- viewer -------------------------------------------------------------------------------------

function overlays(app, { tags = true, readOnly = false } = {}) {
    const p = app.proposal;
    if (!p.source) return '';
    const original = app.originalUrl();
    // Empty places show the original's own pixels, dimmed — on screen only.
    const places = p.placeholders.filter((ph) => ph.box && !ph.picture).map((ph) => `<span class="as-place" data-box="${esc(ph.id)}" style="${boxStyle(ph.box, p.source)};${pixelsStyle(original, ph.box, p.source)}">${tags && !readOnly && ph.box[2] / p.source.width > 0.09 ? `<span class="as-place-tag${ph.box[1] / p.source.height < 0.08 ? ' in' : ''}">místo pro obrázek</span>` : ''}</span>`).join('');
    const boxes = p.elements.filter((el) => el.box).map((el) => `<span class="as-box${app.layers ? '' : ' ghost'}" data-box="${esc(el.id)}" style="${boxStyle(el.box, p.source)}"><span class="as-ltag">${TAG[el.type] || '?'}</span></span>`).join('');
    // Decisions about something that is not a layer (left-out things) highlight their own box.
    const extra = p.decisions.filter((d) => d.type === 'omitted' && d.box && !d.decided).map((d) => `<span class="as-box ghost" data-box="${esc(d.key)}" style="${boxStyle(d.box, p.source)}"><span class="as-ltag">?</span></span>`).join('');
    return places + boxes + extra;
}

function viewer(app, { readOnly = false } = {}) {
    const p = app.proposal;
    const a = app.originalUrl();
    const b = app.proposalUrl();
    const la = 'Původní obrázek';
    const lb = readOnly ? 'Návrh ze skenu' : 'Návrh šablony';
    const over = overlays(app, { readOnly });
    const seg = `<div class="seg soft as-seg" role="group" aria-label="Způsob porovnání">${[['slider', 'Posuvník'], ['side', 'Vedle sebe']].map(([v, l]) => `<button type="button" class="${app.compare === v ? 'on' : ''}" aria-pressed="${app.compare === v}" data-act="compareMode" data-v="${v}" data-k="cmp${v}">${l}</button>`).join('')}</div>`;
    const body = app.compare === 'slider'
        ? `<div class="as-compare"><div class="as-frame" data-busy><img src="${esc(b)}" alt="${lb}" draggable="false">${over}<div class="as-cmp-top"><img src="${esc(a)}" alt="${la}" draggable="false"></div><span class="as-cmp-lab l">${la}</span><span class="as-cmp-lab r">${lb}</span><button type="button" class="as-handle" role="slider" aria-label="Posuvník porovnání" aria-orientation="horizontal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="50" data-k="handle"><span class="knob">${ic('swap-horizontal')}</span></button></div></div>`
        : `<div class="as-side2"><figure class="as-pic"><figcaption><span class="lbl">${la}</span></figcaption><div class="as-frame"><img src="${esc(a)}" alt="${la}"></div></figure><figure class="as-pic"><figcaption><span class="lbl">${lb}</span></figcaption><div class="as-frame" data-busy><img src="${esc(b)}" alt="${lb}">${over}</div></figure></div>`;
    const c = p.counts;
    const legend = [[c.texts, 'T', 'Texty'], [c.places, 'O', 'Obrázky'], [c.shapes, 'Tv', 'Tvary'], [c.background ? 1 : 0, 'P', 'Pozadí']].filter(([n]) => n > 0).map(([n, tag, label]) => `<span><span class="as-ltag">${tag}</span>${label} ${n}</span>`).join('');
    const stale = app.previewFailed || p.preview.stale
        ? `<p class="as-ph-note warn" role="status">${ic('alert', 's14')}<span>Náhled se nepodařilo obnovit. <button type="button" class="as-link" data-act="rerender" data-k="rerender">Zkusit znovu</button></span></p>` : '';
    const hasEmptyPlace = p.placeholders.some((ph) => ph.box && !ph.picture);
    return `<section class="as-card" aria-label="Porovnání" data-region="viewer" data-deep>
<div class="as-viewbar" data-region="viewbar">${seg}<label class="as-switch"><input type="checkbox" class="sr" role="switch" data-chg="toggleLayers" data-k="layers"${app.layers ? ' checked' : ''}><span class="sw sm" aria-hidden="true"></span><span>Ukázat vrstvy</span></label></div>
<div data-region="cmp" class="as-cmp-wrap">${body}</div>
<div data-region="under" class="as-under">${stale}<div class="as-legend" aria-label="Vrstvy návrhu">${legend}</div>${hasEmptyPlace && !readOnly ? '<p class="as-cap">V místech pro obrázky je vidět původní obrázek — do šablony se nepřenese.</p>' : ''}</div></section>`;
}

// ---- "K rozhodnutí" ----------------------------------------------------------------------------

const decided = (hl, text, key, k) => `<div class="as-it decided" data-hl="${esc(hl)}"><p class="as-it-t">${ic('check-circle')}<span>${text}</span></p><button type="button" class="btn xs btn-ghost" data-act="undo" data-key="${esc(key)}" data-k="un${esc(k)}">Vrátit</button></div>`;
const item = (hl, tag, cropHtml, title, body, acts) => `<article class="as-it" data-hl="${esc(hl)}">${cropHtml}<h3 class="as-it-t"><span class="as-ltag" aria-hidden="true">${tag}</span><span>${title}</span></h3>${body}${acts ? `<div class="as-it-acts">${acts}</div>` : ''}</article>`;

/**
 * „Vybrat jiné písmo ▾“ (ux.md S4): the project's faces, each drawn in its own typeface, the one
 * in use ticked; the last row adds a font file to the project and switches to it. A popover of
 * the same kind as the `⋯` menu of a picture card (`app.menu`, Escape, click outside).
 */
function fontSelect(app, group, k, current = null) {
    const id = `font:${k}`;
    const open = app.menu === id;
    const faces = app.proposal.fonts;
    const rows = faces.map((face) => `<button type="button" class="mi as-fontopt" role="menuitemradio" aria-checked="${face === current}" data-act="pickFont" data-group="${esc(group)}" data-face="${esc(face)}"><span class="as-fontopt-n" style="font-family:${cssFamily(face)}">${esc(face)}</span>${face === current ? `<span class="end">${ic('check')}<span class="sr">používá se</span></span>` : ''}</button>`).join('');
    const menu = open ? `<div class="menu as-fontmenu" role="menu" aria-label="Písma projektu">${rows || '<p class="cap" style="margin:6px 12px">Projekt nemá žádné písmo.</p>'}<div class="menu-sep" role="separator"></div><button type="button" class="mi" role="menuitem" data-act="uploadFont" data-group="${esc(group)}">${ic('upload')}Přidat písmo…<span class="end">TTF, OTF, WOFF</span></button></div>` : '';
    return `<span class="as-ph-menu as-fontpick"><button type="button" class="btn xs btn-tertiary" aria-haspopup="menu" aria-expanded="${open}" data-act="fontMenu" data-id="${esc(id)}" data-k="phmenu${esc(id)}"${app.fontBusy ? ' disabled' : ''}>Vybrat jiné písmo${ic('chev-down', 's14')}</button>${menu}</span>`;
}
/** A face string as a CSS font-family value (the name the picker's @font-face rules use). */
const cssFamily = (face) => `'${String(face).replace(/[^\p{L}\p{N} ()._-]/gu, '')}', sans-serif`;

/** One @font-face per project face, so the picker can draw each in its own typeface. */
function fontFaces(app) {
    const files = (app.proposal && app.proposal.fontFiles) || {};
    const css = Object.entries(files).map(([face, url]) => `@font-face{font-family:${cssFamily(face).split(',')[0]};src:url("${String(url).replace(/["\\\n]/g, '')}");font-display:swap}`).join('');
    let style = document.getElementById('asFontFaces');
    if (!style) { style = document.createElement('style'); style.id = 'asFontFaces'; document.head.append(style); }
    if (style.textContent !== css) style.textContent = css;
}
const fontUpload = (group, k) => `<button type="button" class="btn xs btn-tertiary" data-act="uploadFont" data-group="${esc(group)}" data-k="fu${esc(k)}">${ic('upload', 's14')}Nahrát správné písmo</button>`;

function fontDecision(app, d) {
    const role = fontRole(d.role);
    const hl = d.texts[0] || d.key;
    if (d.decided) {
        return decided(hl, d.decision === 'choose_font' ? `Písmo ${role}: ${esc(d.face)} — texty jsme přepočítali` : `Písmo ${role}: necháváte ${esc(d.face)}`, d.key, d.key);
    }
    const n = d.texts.length;
    const concerns = `Týká se ${n} ${pl(n, 'textu', 'textů', 'textů')}.`;
    const size = d.sizeChangePercent ? `velikost jsme upravili o ${Math.abs(d.sizeChangePercent)} %, aby řádky vyšly stejně.` : 'velikost zůstala stejná.';
    const title = d.pattern === 'undetermined' ? `Písmo ${role} se nepodařilo určit` : `Písmo ${role} jsme nahradili`;
    const text = d.pattern === 'undetermined'
        ? `<p>Textu je málo nebo je písmo příliš ozdobné, takže ho s písmy projektu nejde porovnat. Použili jsme <b>${esc(d.face)}</b>. ${concerns}</p>`
        : `<p>V projektu jsme stejné písmo nenašli. Použili jsme nejbližší: <b>${esc(d.face)}</b>. Tvary písmen se od obrázku liší; ${size} ${concerns}</p>`;
    return item(hl, 'T', crops(app, union(d.boxes.slice(0, 1))), title, text, `${fontSelect(app, d.group, d.key, d.face)}${fontUpload(d.group, d.key)}<button type="button" class="btn xs btn-ghost" data-act="keepFont" data-group="${esc(d.group)}" data-k="fk${esc(d.key)}">Nechat</button>`);
}

function doubtfulDecision(app, d) {
    if (d.decided) return decided(d.id, `Text potvrzen: ${q(d.text)}`, d.key, d.key);
    return item(d.id, 'T', cropOne(app, d.box), 'Přečetli jsme to správně?', `<p>Tímto textem si nejsme jistí.</p><label class="inp sm"><input type="text" value="${esc(d.text)}" maxlength="2000" aria-label="Přepis textu" data-k="dt${esc(d.id)}" data-enter="confirmText" data-id="${esc(d.id)}"></label>`, `<button type="button" class="btn xs btn-tertiary" data-act="confirmText" data-id="${esc(d.id)}" data-k="dc${esc(d.id)}">${ic('check', 's14')}Potvrdit</button>`);
}

function omittedDecision(app, rows) {
    const p = app.proposal;
    const n = rows.length;
    const title = `${n} ${pl(n, 'prvek jsme vynechali', 'prvky jsme vynechali', 'prvků jsme vynechali')}`;
    const lines = rows.map((d) => {
        const crop = d.box && p.source ? `<span class="as-leftcrop" role="img" aria-label="Výřez původního obrázku: vynechaný prvek" style="${cropStyle(app.originalUrl(), d.box, p.source, { pad: 0.6, minRatio: 0.7, maxRatio: 2.4 })}"></span>` : '';
        if (d.decided) {
            return `<div class="as-leftrow done" data-hl="${esc(d.decision === 'place' ? d.placeId : d.key)}">${crop}<span class="as-leftdone">${ic('check-circle', 's14')}${d.decision === 'place' ? 'Přidáno místo pro obrázek — doplníte ho v dalším kroku' : 'Necháváte být'}</span><button type="button" class="btn xs btn-ghost" data-act="undo" data-key="${esc(d.key)}" data-k="un${esc(d.key)}">Vrátit</button></div>`;
        }
        return `<div class="as-leftrow" data-hl="${esc(d.key)}">${crop}<span class="as-it-acts"><button type="button" class="btn xs btn-tertiary" data-act="addPlace" data-omitted="${d.omitted}" data-k="oa${d.omitted}">${ic('image-plus', 's14')}Přidat místo pro obrázek</button><button type="button" class="btn xs btn-ghost" data-act="keepOmitted" data-omitted="${d.omitted}" data-k="ok${d.omitted}">Nechat být</button></span></div>`;
    }).join('');
    const open = rows.some((d) => !d.decided);
    return `<article class="as-it${open ? '' : ' as-it-quiet'}"><h3 class="as-it-t"><span class="as-ltag" aria-hidden="true">?</span><span>${title}</span></h3><p>${n === 1 ? 'Nepodařilo se ho převést na text ani tvar.' : 'Nepodařilo se je převést na text ani tvar.'}</p>${lines}</article>`;
}

function staleDecision(app, d) {
    const p = app.proposal;
    if (d.type === 'missing_font') {
        const groups = [...p.decisions, ...p.information].filter((e) => e.group && e.face === d.face).map((e) => e.group);
        const group = groups.join('|');
        return item(d.key, 'T', '', `Písmo ${esc(d.face)} už v projektu není`, '<p>Mezitím ho někdo z projektu odebral. Vyberte jiné písmo, nebo ho nahrajte znovu.</p>', group ? `${fontSelect(app, group, d.key)}${fontUpload(group, d.key)}` : fontUpload('', d.key));
    }
    const place = p.placeholders.find((ph) => ph.id === d.placeId);
    return item(d.placeId, 'O', '', `Obrázek ${place && place.picture && place.picture.name ? q(place.picture.name) : ''} už v galerii není`, '<p>Mezitím ho někdo z galerie odstranil. Vyberte jiný, nebo místo nechte prázdné.</p>', `<button type="button" class="btn xs btn-tertiary" data-act="goPictures" data-k="mp${esc(d.placeId)}">${ic('image', 's14')}Vybrat jiný obrázek</button><button type="button" class="btn xs btn-ghost" data-act="clearPicture" data-id="${esc(d.placeId)}" data-k="mc${esc(d.placeId)}">Odebrat obrázek</button>`);
}

function information(app) {
    const p = app.proposal;
    const lines = [];
    const simple = [];
    for (const row of p.information) {
        if (row.code === 'font_match') {
            lines.push(`<li><span>Písmo ${fontRole(row.role)}: <b>${esc(row.face)}</b> z projektu — změřená shoda s obrázkem.</span></li>`);
        } else if (row.code === 'shrunk') {
            lines.push(row.undone
                ? `<li><span>Písmo u ${row.n} ${pl(row.n, 'textu', 'textů', 'textů')} má zpět původní velikost.</span><button type="button" class="btn xs btn-ghost" data-act="undo" data-key="${esc(row.undoKey)}" data-k="unshrink">Zmenšit znovu</button></li>`
                : `<li><span>Zmenšili jsme písmo u ${row.n} ${pl(row.n, 'textu', 'textů', 'textů')}, aby se ${row.n === 1 ? 'vešel' : 'vešly'} jako v originále.</span><button type="button" class="btn xs btn-ghost" data-act="infoUndo" data-fix="${esc(row.undo)}" data-k="doshrink">Vrátit</button></li>`);
        } else if (row.code === 'palette') {
            const pairs = row.pairs.slice(0, 6).map((pair) => `<span class="as-sw-pair" role="img" aria-label="${esc(pair.from)} nahrazena ${esc(pair.to)}"><i style="background:${esc(pair.from)}"></i>${ic('arrow-right', 's12')}<i style="background:${esc(pair.to)}"></i></span>`).join('');
            lines.push(row.undone
                ? `<li><span>${pairs}Barvy jsou přesně podle obrázku.</span><button type="button" class="btn xs btn-ghost" data-act="undo" data-key="${esc(row.undoKey)}" data-k="unpalette">Sjednotit s paletou</button></li>`
                : `<li><span>${pairs}${row.n} ${pl(row.n, 'barvu jsme sjednotili', 'barvy jsme sjednotili', 'barev jsme sjednotili')} s paletou projektu.</span><button type="button" class="btn xs btn-ghost" data-act="infoUndo" data-fix="${esc(row.undo)}" data-k="dopalette">Vrátit přesné barvy</button></li>`);
        } else if (row.code === 'simplification') {
            const sentence = SIMPLIFICATIONS[row.simplification];
            if (sentence) { const text = sentence(row.n || 1); if (!simple.includes(text)) simple.push(text); }
        } else if (row.code === 'pictures_not_taken') {
            lines.push('<li><span>Obrázky z nahrané grafiky nepřebíráme. Každý má v šabloně své místo — doplníte je v dalším kroku.</span></li>');
        } else if (row.code === 'note' && row.note === 'no_texts') {
            lines.push('<li><span>Texty jsme v obrázku nenašli.</span></li>');
        }
    }
    if (simple.length) lines.splice(Math.max(0, lines.length - (p.placeholders.length ? 1 : 0)), 0, `<li><span>Zjednodušili jsme ${simple.length} ${pl(simple.length, 'věc', 'věci', 'věcí')}:</span><ul class="sub">${simple.map((t) => `<li>${t}</li>`).join('')}</ul></li>`);
    if (!lines.length) return '';
    return `<details class="as-details as-info"${app.infoOpen ? ' open' : ''}><summary data-act="toggleInfo" data-k="info">${ic('chev-right')}Pro informaci (${lines.length})</summary><ul>${lines.join('')}</ul></details>`;
}

function feedback(app) {
    const rated = app.proposal.rating !== null && app.proposal.rating !== undefined;
    const done = app.feedback === 'sent' || (rated && app.feedback !== 'down');
    return `<div class="as-fb"><span>Jak se sken povedl?</span>${done ? `<span class="as-muted" role="status">${ic('check-circle', 's14')} Díky za hodnocení</span>` : `<span class="as-fb-b"><button type="button" class="btn xs btn-tertiary" aria-pressed="false" data-act="rate" data-v="1" data-k="fbu">${ic('thumb-up', 's14')}Dobře</button><button type="button" class="btn xs btn-tertiary" aria-pressed="${app.feedback === 'down'}" data-act="rate" data-v="-1" data-k="fbd">${ic('thumb-down', 's14')}Špatně</button></span>`}${app.feedback === 'down' ? `<div class="as-fb-form"><label class="inp sm"><input type="text" maxlength="500" placeholder="Co se nepovedlo? (nepovinné)" aria-label="Co se nepovedlo? (nepovinné)" data-k="fbt" data-enter="sendFeedback"></label><button type="button" class="btn xs btn-tertiary" data-act="sendFeedback" data-k="fbs">Odeslat</button></div>` : ''}</div>`;
}

function decisionsTab(app) {
    const p = app.proposal;
    const parts = [];
    for (const d of p.decisions.filter((x) => x.type === 'missing_font' || x.type === 'missing_picture')) parts.push(staleDecision(app, d));
    for (const d of p.decisions.filter((x) => x.type === 'font')) parts.push(fontDecision(app, d));
    for (const d of p.decisions.filter((x) => x.type === 'doubtful_text')) parts.push(doubtfulDecision(app, d));
    const omitted = p.decisions.filter((x) => x.type === 'omitted');
    if (omitted.length) parts.push(omittedDecision(app, omitted));
    const info = information(app);
    const nothing = !p.decisions.length && !p.information.some((r) => ['shrunk', 'palette', 'simplification'].includes(r.code));
    const closing = nothing
        ? '<div class="as-nothing"><h3>Nic jsme nenahrazovali</h3><p class="cap">Žádné písmo, text ani prvek jsme nemuseli nahradit ani vynechat. Přesto si návrh porovnejte s originálem.</p></div>'
        : p.toDecide > 0 ? '<p class="cap" style="margin:0">Nic z toho nebrání šablonu vytvořit.</p>' : '<p class="cap" style="margin:0">Nic k rozhodnutí. Přesto si návrh porovnejte s originálem.</p>';
    return `${parts.join('')}${closing}${info}${feedback(app)}`;
}

// ---- "Vrstvy" ----------------------------------------------------------------------------------

function fontNote(p, id) {
    const d = p.decisions.find((x) => x.type === 'font' && x.texts.includes(id));
    if (d) return d.decision === 'choose_font' ? 'vybrané písmo' : d.pattern === 'undetermined' ? 'nepodařilo se určit' : 'nejbližší v projektu';
    return p.information.some((x) => x.code === 'font_match' && x.texts.includes(id)) ? 'změřená shoda' : null;
}

function layersTab(app, readOnly = false) {
    const p = app.proposal;
    const dis = readOnly ? ' disabled' : '';
    const rows = [...p.elements].reverse().map((el) => {
        const fallback = { text: 'Text', place: 'Místo pro obrázek', shape: 'Tvar', background: 'Pozadí' }[el.type] || 'Vrstva';
        const name = el.type === 'background' || (el.type === 'place' && !el.box)
            ? `<span class="as-inl-ro">${esc(el.name || fallback)}</span>`
            : `<input class="as-inl" value="${esc(el.name || '')}" placeholder="${fallback}" maxlength="40" aria-label="Název vrstvy ${esc(el.name || fallback)}" data-chg="rename" data-id="${esc(el.id)}" data-k="ln${esc(el.id)}"${dis}>`;
        let sub;
        if (el.type === 'text') {
            const note = fontNote(p, el.id);
            sub = `${el.doubtful && p.decisions.some((d) => d.type === 'doubtful_text' && d.id === el.id && !d.decided) ? '<span class="as-dot" role="img" aria-label="Nejistý přepis — je i v K rozhodnutí"></span>' : ''}<input class="as-inl txt" value="${esc(el.text)}" maxlength="2000" aria-label="Text vrstvy ${esc(el.name || fallback)}" data-chg="setText" data-id="${esc(el.id)}" data-k="lt${esc(el.id)}"${dis}><label class="as-switch"><input type="checkbox" class="sr" role="switch" data-chg="setInput" data-id="${esc(el.id)}" data-k="lsw${esc(el.id)}"${el.input ? ' checked' : ''}${dis}><span class="sw sm" aria-hidden="true"></span><span>${el.input ? 'vyplňuje uživatel' : 'pevný text'}</span></label>${el.font ? `<span class="as-layfont">${ic('font', 's14')}${esc(el.font)}${note ? ` — ${note}` : ''}</span>` : ''}`;
        } else if (el.type === 'place') {
            const ph = p.placeholders.find((x) => x.id === el.id);
            sub = `místo pro obrázek${ph ? ` · ${ph.width} × ${ph.height} px` : ''}`;
        } else if (el.type === 'shape') {
            sub = el.fill && el.fill !== 'gradient' ? `<span><span class="as-swatch" style="background:${esc(el.fill)}"></span> tvar · ${esc(el.fill)}</span>` : 'tvar · přechod';
        } else {
            sub = el.fill && el.fill !== 'gradient' ? `<span><span class="as-swatch" style="background:${esc(el.fill)}"></span> barva · ${esc(el.fill)}</span>` : el.fill === 'gradient' ? 'přechod barev' : 'fotka na pozadí — místo pro obrázek';
        }
        return `<li class="as-layer" data-hl="${esc(el.id)}"><span class="as-ltag" aria-hidden="true">${TAG[el.type] || '?'}</span><span class="nm">${name}</span><span class="sub">${sub}</span></li>`;
    }).join('');
    return `<ul class="as-layers">${rows}</ul>${readOnly ? '' : '<p class="hint" style="margin:0">Zapnutý přepínač = pole, které uživatel vyplní před exportem. Polohy a velikosti doladíte v editoru.</p>'}`;
}

// ---- screen ------------------------------------------------------------------------------------

export function reviewView(app) {
    const p = app.proposal;
    const scan = app.scan;
    const hasPlaces = p.placeholders.length > 0;
    const layerCount = p.elements.length;
    const primary = hasPlaces
        ? `<button type="button" class="btn sm btn-primary" data-act="goPictures" data-k="next">Pokračovat na obrázky${ic('arrow-right')}</button>`
        : `<button type="button" class="btn sm btn-primary" data-act="accept" data-k="next"${app.busy === 'accept' ? ' disabled' : ''}>${app.busy === 'accept' ? 'Vytvářím šablonu…' : 'Vytvořit šablonu a otevřít editor'}${ic('arrow-right')}</button>`;
    const status = app.saving ? '<span class="cap" role="status">Ukládám…</span>' : app.saved ? `<span class="cap" role="status">${ic('check', 's12')} Uloženo</span>` : '';
    return `${head(app, 3, 'Kontrola')}
${p.unfinished ? `<div class="alert c-warning" role="status" data-region="unfinished">${ic('clock', '')}<div><div class="alert-t">Sken jsme nestihli doladit</div><div class="alert-d">Zastavili jsme ho po dosažení časového limitu a nechali nejlepší verzi. Sken se započítal.</div></div></div>` : ''}${offLine(app)}
<div class="as-grid as-review" data-region="review" data-deep><div class="as-main" data-region="main" data-deep>${viewer(app)}</div>
<aside class="as-side" data-region="side" data-deep><section class="as-card as-sumcard" data-region="sum"><h2 class="as-sum" tabindex="-1" data-focus>${summaryLine(p.counts)} <b>${toDecideLine(p.toDecide)}</b></h2><p class="cap" style="margin:0">Tohle je návrh — šablona zatím neexistuje.${scan.mine ? '' : ` Sken spustil(a) ${esc(scan.requestedBy || 'někdo jiný')}.`}</p></section>
<section class="as-card as-panel-card" aria-label="Zpráva ze skenu" data-region="panel" data-deep><div class="tabs-u" role="tablist" aria-label="Zpráva ze skenu" data-region="tabs"><button type="button" role="tab" id="asTabDec" aria-selected="${app.tab === 'decisions'}" aria-controls="asTabP" data-act="tab" data-v="decisions" data-k="tabdec">K rozhodnutí <span class="pd-n">${p.toDecide}</span></button><button type="button" role="tab" id="asTabLay" aria-selected="${app.tab === 'layers'}" aria-controls="asTabP" data-act="tab" data-v="layers" data-k="tablay">Vrstvy <span class="pd-n">${layerCount}</span></button></div>
<div class="as-panel-body" id="asTabP" role="tabpanel" aria-labelledby="${app.tab === 'decisions' ? 'asTabDec' : 'asTabLay'}" tabindex="-1" data-region="body-${app.tab}" data-scroll="panel-${app.tab}">${app.tab === 'decisions' ? decisionsTab(app) : layersTab(app)}</div></section></aside></div>
<input type="file" class="sr" id="asReviewFont" tabindex="-1" aria-hidden="true" accept=".ttf,.otf,.woff" data-chg="reviewFontFile" data-region="fontfile">
<div class="as-foot" data-region="foot" data-deep><div class="as-foot-l" data-region="line">${ic('info')}<span>Návrh se stane šablonou, až ho vytvoříte. Uchováme ho do ${dateCz(p.expiresAt)}.</span>${status}</div>
<div class="as-foot-r" data-region="row"><label class="inp">${ic('edit')}<input type="text" value="${esc(p.name)}" maxlength="30" aria-label="Název šablony" data-chg="setName" data-k="name"></label><span class="as-sp"></span><button type="button" class="btn sm ghost danger" data-act="discardProposal" data-k="disc">Zahodit</button><button type="button" class="btn sm btn-tertiary" data-act="rescan" data-k="resc"${app.busy === 'rescan' ? ' disabled' : ''}>${ic('refresh')}${app.busy === 'rescan' ? 'Spouštím…' : 'Naskenovat znovu'}</button>${primary}</div></div>`;
}

export function readonlyView(app) {
    const p = app.proposal;
    const scan = app.scan;
    const editor = scan.resultVariantId ? urlFor(app.urls.editor, scan.resultVariantId) : null;
    const name = scan.name || (p && p.name) || 'šablona';
    const headHtml = `<div class="as-backrow" data-region="backrow"><a href="${esc(app.urls.templates)}" class="back">${ic('arrow-left')}Šablony</a>${historyLink(app)}</div>
<header class="head as-head" data-region="head"><div class="head-main"><span class="lbl">Projekt · ${esc(app.project.name)}</span><h1>Sken: ${esc(name)} <span class="pill mint">${ic('check', 's12')}Šablona vytvořena</span></h1><span class="as-step">${scan.finishedAt ? `Sken z ${dateCz(scan.finishedAt)}` : 'Hotovo'}${scan.expiresAt ? ` · sken a původní obrázek uchováme do ${dateCz(scan.expiresAt)}` : ''}</span></div>${editor ? `<div class="head-tools"><a href="${esc(editor)}" class="btn btn-primary" data-k="open">${ic('edit')}Otevřít šablonu</a></div>` : ''}</header>`;
    if (!p) {
        return `${headHtml}<section class="as-card" data-region="gone"><h2 tabindex="-1" data-focus>Šablona je vytvořená</h2><p class="as-status-p">Sken a původní obrázek jsme po 30 dnech smazali. Šablona zůstává v projektu.</p></section>`;
    }
    const rows = [];
    for (const d of p.decisions) {
        if (d.type === 'font') rows.push([d.decided, `Písmo ${fontRole(d.role)}: ${esc(d.face)}${d.decision === 'choose_font' ? ' — vybrané' : d.pattern === 'undetermined' ? ' — nepodařilo se určit' : ' — nejbližší v projektu'}`]);
        else if (d.type === 'doubtful_text') rows.push([d.decided, d.decided ? `Text potvrzen: ${q(d.text)}` : `Nejistý přepis: ${q(d.text)}`]);
        else if (d.type === 'omitted') rows.push([d.decided, d.decision === 'place' ? 'Vynechaný prvek: přidáno místo pro obrázek' : d.decided ? 'Vynechaný prvek: ponechán' : '1 prvek vynechán']);
    }
    for (const ph of p.placeholders) {
        if (!ph.picture && ph.kind === 'fixed' && !ph.background) rows.push([false, `Místo ${q(ph.name || `č. ${ph.number}`)} zůstalo prázdné`]);
    }
    const open = rows.filter(([done]) => !done).length;
    const list = rows.length
        ? `<ul class="as-rolist">${rows.map(([done, text]) => `<li>${ic(done ? 'check-circle' : 'alert-circle', 's16')}<span>${text}<span class="sr">${done ? ' — vyřešeno' : ' — otevřené'}</span></span>${!done && editor ? `<a href="${esc(editor)}" class="btn xs btn-ghost">Otevřít v editoru</a>` : ''}</li>`).join('')}</ul>`
        : '<p class="cap" style="margin:0">Při skenu jsme nic nenahrazovali ani nevynechali.</p>';
    return `${headHtml}
<div class="as-grid as-review" data-region="review" data-deep><div class="as-main" data-region="main" data-deep>${viewer(app, { readOnly: true })}</div>
<aside class="as-side" data-region="side" data-deep><section class="as-card as-sumcard" data-region="sum"><h2 class="as-sum" tabindex="-1" data-focus>${summaryLine(p.counts, 'Vytvořeno')} <b>${open ? `V editoru ${pl(open, 'zbývá', 'zbývají', 'zbývá')} ${open} ${pl(open, 'věc', 'věci', 'věcí')}.` : 'Nic nezbývá.'}</b></h2><p class="cap" style="margin:0">Sken je jen ke čtení.</p></section>
<section class="as-card as-panel-card" aria-label="Zpráva ze skenu" data-region="panel"><div class="as-panel-body" data-scroll="panel-ro"><h3>Zpráva ze skenu</h3>${list}<p class="cap" style="margin:0">Šablona už existuje, proto se tady nedá nic měnit — úpravy dělejte v editoru.</p>${information(app).replace(/<button[^>]*data-act="(?:undo|infoUndo)"[^>]*>.*?<\/button>/g, '')}</div></section></aside></div>`;
}

// ---- slider ------------------------------------------------------------------------------------

function setPos(app, value) {
    app.pos = Math.max(0, Math.min(100, Math.round(value)));
    const box = app.root.querySelector('.as-compare');
    if (!box) return;
    box.style.setProperty('--pos', `${app.pos}%`);
    const handle = box.querySelector('.as-handle');
    handle.setAttribute('aria-valuenow', String(app.pos));
    handle.setAttribute('aria-valuetext', `${app.pos} % původní`);
}

export function sliderKey(app, event) {
    const step = { ArrowLeft: -5, ArrowRight: 5, ArrowDown: -5, ArrowUp: 5 }[event.key];
    if (step) { event.preventDefault(); setPos(app, app.pos + step); } else if (event.key === 'Home') { event.preventDefault(); setPos(app, 0); } else if (event.key === 'End') { event.preventDefault(); setPos(app, 100); }
}

/** The handle is the only thing that drags (touch-action: pan-y on the viewer — the page scrolls). */
export function afterRender(app) {
    // The sweep while a fix renders and the slider's position live on the nodes, not in the
    // markup — so neither rebuilds the pictures.
    app.root.querySelectorAll('[data-busy]').forEach((frame) => frame.classList.toggle('busy', app.saving > 0));
    if (app.menu && String(app.menu).startsWith('font:')) fontFaces(app);
    const handle = app.root.querySelector('.as-handle');
    if (handle) setPos(app, app.pos);
    if (!handle || handle.__bound) return;
    handle.__bound = true;
    const move = (event) => {
        const rect = handle.parentElement.getBoundingClientRect();
        setPos(app, ((event.clientX - rect.left) / rect.width) * 100);
    };
    handle.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        handle.focus({ preventScroll: true });
        handle.setPointerCapture(event.pointerId);
        handle.addEventListener('pointermove', move);
    });
    const end = (event) => {
        handle.removeEventListener('pointermove', move);
        try { handle.releasePointerCapture(event.pointerId); } catch (e) { /* already released */ }
    };
    handle.addEventListener('pointerup', end);
    handle.addEventListener('pointercancel', end);
}

// ---- actions -------------------------------------------------------------------------------------

/** "Vytvořit šablonu…" — one template however often it is pressed (ux.md T4, F15). */
export async function accept(app) {
    if (app.busy) return;
    app.busy = 'accept';
    app.render();
    await app.queue; // every fix is saved first
    const answer = await api.post(urlFor(app.urls.accept, app.scan.id));
    app.busy = null;
    if (answer.ok && answer.editorUrl) {
        const n = answer.elements || 0;
        app.notifyShell();
        app.leave(answer.editorUrl, `Šablona „${app.proposal.name}“ vytvořena ze skenu — ${n} ${pl(n, 'prvek', 'prvky', 'prvků')}`, { ai: true, timeout: 8000 });
        return;
    }
    if (answer.code === 'wrong_state') { window.location.reload(); return; }
    if (answer.proposal) app.proposal = answer.proposal;
    if (answer.code === 'stale_references') { app.step = 'review'; app.tab = 'decisions'; }
    app.render();
    toast('Šablonu se teď nepodařilo vytvořit. Zkuste to za chvíli.', { error: true, action: answer.code === 'stale_references' ? null : { label: 'Zkusit znovu', onClick: () => accept(app) } });
    if (answer.code === 'stale_references') app.focusHeading();
}

async function chooseFont(app, groups, face) {
    for (const group of String(groups).split('|').filter(Boolean)) await app.fix({ type: 'choose_font', group, face });
}

/** The stored original as a File — what a new scan of the same picture uploads. */
async function storedOriginal(app) {
    try {
        const response = await fetch(app.image('original'), { credentials: 'same-origin' });
        if (!response.ok) return null;
        const blob = await response.blob();
        return new File([blob], app.scan.fileName || 'obrazek', { type: blob.type });
    } catch (e) { return null; }
}

/**
 * „Naskenovat znovu“ (ux.md S4). `new` → the upload page. `format` → the stored original goes
 * back to S2, where the format is chosen. `same` → a new scan of the same picture in the same
 * format starts at once (the dialog already said what it costs); when it cannot start — a
 * limit, the queue, a consent whose wording changed — the picture lands on S2, which says why.
 */
async function rescanWith(app, mode) {
    if (mode === 'new') { app.leave(app.urls.new); return; }
    if (app.busy) return;
    app.busy = 'rescan';
    app.render();
    const file = await storedOriginal(app);
    if (!file) { app.busy = null; app.render(); toast('Původní obrázek se nepodařilo načíst. Nahrajte ho znovu.', { error: true }); return; }
    let refusal = null;
    if (mode === 'same') {
        const answer = await api.upload(app.urls.start, {
            picture: file,
            kind: 'template',
            requestId: window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : `r-${Date.now()}-${Math.random().toString(16).slice(2)}`,
            consent: '0',
            options: JSON.stringify(app.scan.options || {}),
        });
        if (answer.ok && answer.scan) {
            app.busy = null;
            app.proposal = null;
            app.step = 'review';
            app.scan = { ...answer.scan, name: file.name, fileName: file.name, mine: true, seen: false, hasPicture: true, projectId: app.project.id, options: app.scan.options || {} };
            app.pushScan(app.scan);
            app.notifyShell();
            window.scrollTo({ top: 0 });
            await app.enter();
            return;
        }
        refusal = answer.code === 'network' || answer.code === 'http_error' ? 'network' : answer.code;
    }
    app.busy = null;
    app.scan = null;
    app.proposal = null;
    app.step = 'review';
    app.pushScan(null);
    document.documentElement.dataset.asScan = '';
    await app.refreshEntry();
    await usePicture(app, file);
    if (refusal) {
        if (refusal !== 'network' && refusal !== 'consent_required') { app.startRefusal = refusal; app.render(); }
        toast(refusal === 'network' ? 'Nahrání se nepodařilo. Zkontrolujte připojení a zkuste to znovu.' : startHint(refusal, app.entry) || 'Sken se teď nepodařilo spustit. Zkuste to za chvíli.', { error: true });
    }
}

export const actions = {
    compareMode: (app, el) => { app.compare = el.dataset.v; try { window.localStorage.setItem('wb-as-cmp', app.compare); } catch (e) { /* private mode */ } app.render(); },
    toggleLayers: (app, el) => { app.layers = el.checked; app.render(); },
    tab: (app, el) => { app.tab = el.dataset.v; app.render(); },
    toggleInfo: (app, el) => { window.setTimeout(() => { app.infoOpen = el.parentElement.open; }, 0); },
    undo: (app, el) => app.fix({ type: 'undo', key: el.dataset.key }),
    infoUndo: (app, el) => app.fix({ type: el.dataset.fix }),
    keepFont: (app, el) => app.fix({ type: 'keep_font', group: el.dataset.group }),
    fontMenu: (app, el) => {
        app.menu = app.menu === el.dataset.id ? null : el.dataset.id;
        app.render();
        const menu = el.parentElement && app.root.querySelector('.as-fontpick .menu');
        const first = menu && (menu.querySelector('[aria-checked="true"]') || menu.querySelector('.mi'));
        if (first) first.focus();
    },
    pickFont: (app, el) => { app.menu = null; chooseFont(app, el.dataset.group, el.dataset.face); },
    uploadFont: (app, el) => {
        app.menu = null;
        app.fontTarget = el.dataset.group || '';
        const input = app.root.querySelector('#asReviewFont');
        if (input) { input.value = ''; input.click(); }
    },
    reviewFontFile: async (app, el) => {
        const added = await uploadFonts(app, el.files);
        app.render();
        if (!added.length) return;
        // The face string of the new face: "<family> (<face>)" — read it from the project's list.
        await app.loadProposal();
        const wanted = `${added[0].family} (${added[0].face})`;
        const face = app.proposal.fonts.find((f) => f === wanted) || app.proposal.fonts.find((f) => f.startsWith(`${added[0].family} (`));
        if (face && app.fontTarget) await chooseFont(app, app.fontTarget, face); else app.render();
    },
    confirmText: (app, el) => {
        const id = el.dataset.id;
        const input = app.root.querySelector(`[data-k="dt${CSS.escape(id)}"]`);
        const d = app.proposal.decisions.find((x) => x.type === 'doubtful_text' && x.id === id);
        const text = input ? input.value : d.text;
        if (input) input.defaultValue = input.value;
        app.fix(text !== d.text ? { type: 'confirm_text', id, text } : { type: 'confirm_text', id });
    },
    addPlace: (app, el) => app.fix({ type: 'add_placeholder', omitted: Number(el.dataset.omitted) }),
    keepOmitted: (app, el) => app.fix({ type: 'keep_omitted', omitted: Number(el.dataset.omitted) }),
    clearPicture: (app, el) => app.fix({ type: 'set_picture', id: el.dataset.id, fileId: null }),
    rename: (app, el) => { const name = el.value.trim(); if (name === el.defaultValue.trim()) return; el.defaultValue = el.value; if (name) app.fix({ type: 'rename', id: el.dataset.id, name }); else app.fix({ type: 'undo', key: `name:${el.dataset.id}` }, { silent: true }); },
    setText: (app, el) => { if (el.value === el.defaultValue) return; el.defaultValue = el.value; app.fix({ type: 'set_text', id: el.dataset.id, text: el.value }); },
    setInput: (app, el) => app.fix({ type: 'set_input', id: el.dataset.id, input: el.checked }),
    setName: (app, el) => {
        const name = el.value.trim();
        if (name === app.proposal.name) return;
        if (name.length < 3 || name.length > 30) { toast('Název musí mít 3 až 30 znaků.', { error: true }); el.value = app.proposal.name; return; }
        el.defaultValue = el.value;
        app.fix({ type: 'set_name', name });
    },
    rerender: async (app) => {
        app.saving += 1;
        app.render();
        const answer = await api.post(urlFor(app.urls.preview, app.scan.id));
        app.saving -= 1;
        app.fixed(answer);
    },
    rate: async (app, el) => {
        const rating = Number(el.dataset.v);
        if (rating === -1) { app.feedback = 'down'; app.render(); const input = app.root.querySelector('[data-k="fbt"]'); if (input) input.focus(); }
        const answer = await api.post(urlFor(app.urls.rating, app.scan.id), { rating });
        if (answer.ok) { app.proposal.rating = rating; if (rating === 1) { app.feedback = 'sent'; toast('Díky za hodnocení'); } app.render(); }
    },
    sendFeedback: async (app) => {
        const input = app.root.querySelector('[data-k="fbt"]');
        const note = input ? input.value.trim() : '';
        await api.post(urlFor(app.urls.rating, app.scan.id), note ? { rating: -1, note } : { rating: -1 });
        app.proposal.rating = -1;
        app.feedback = 'sent';
        app.render();
        toast('Díky za hodnocení');
    },
    goPictures: (app) => { app.step = 'pictures'; window.history.replaceState({}, '', '#obrazky'); app.render(); window.scrollTo({ top: 0 }); app.focusHeading(); },
    goReview: (app) => { app.step = 'review'; app.merge = null; app.menu = null; window.history.replaceState({}, '', window.location.pathname); app.render(); window.scrollTo({ top: 0 }); app.focusHeading(); },
    accept: (app) => accept(app),
    discardProposal: async (app) => {
        const yes = await confirmDialog({ title: 'Zahodit návrh?', text: 'Návrh se smaže a sken zůstane započítaný. Obrázky, které jste k němu nahráli, přesuneme do Koše v galerii.', confirm: 'Zahodit' });
        if (!yes) return;
        const answer = await api.post(urlFor(app.urls.action, app.scan.id).replace(/\/cancel$/, '/discard'));
        if (answer.ok || answer.code === 'wrong_state') { app.notifyShell(); app.leave(app.entry.canStart ? app.urls.new : app.urls.templates, 'Návrh zahozen'); return; }
        toast('Návrh se nepodařilo zahodit. Zkuste to znovu.', { error: true });
    },
    rescan: (app) => {
        const can = app.entry.canStart;
        const dialog = openDialog(`${dialogHead('Naskenovat znovu')}<p>Nový sken může dopadnout jinak, ne nutně lépe.</p>
<div class="as-opts"><button type="button" class="btn sm btn-tertiary" data-mode="format"${can ? ' data-autofocus' : ' disabled'}>Stejný obrázek, jiný formát</button><button type="button" class="btn sm btn-tertiary" data-mode="same"${can ? '' : ' disabled'}>Stejný obrázek znovu</button><button type="button" class="btn sm btn-tertiary" data-mode="new"${can ? '' : ' disabled'}>Nahrát lepší obrázek</button></div>
<p class="as-credit" style="justify-content:flex-start">${ic('sparkle', 's14')}<span>${esc(can ? creditLine(app.entry.credits, 'Spotřebuje další sken').replace('tento měsíc ', '') : 'Nové skeny teď v tomto projektu spustit nejdou.')}</span></p>
<p class="cap">Chybí vám jen písmo? Nový sken není potřeba — nahrajte ho u položky písma, texty se přepočítají zdarma. Tento návrh zůstane uložený, dokud ho nezahodíte.</p>
<div class="dlg-f"><button type="button" class="btn sm btn-ghost" data-close>Zrušit</button></div>`);
        dialog.addEventListener('click', (event) => {
            const button = event.target.closest('[data-mode]');
            if (!button || button.disabled) return;
            dialog.close();
            rescanWith(app, button.dataset.mode);
        });
    },
};
