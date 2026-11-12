// AI sken — S4b "Fotky a loga": the places for pictures (ux.md S4b, §2.4).
// Pictures are ordinary gallery files: uploads go to POST pictures (the scan's gallery folder
// „AI sken / {název}“), gallery picks through the Project:ImageGallery modal (`asset-selected`).
//
// Honest limits (docs/architecture/ai-scan.md § "Deviations"):
//  - no project-logo pre-fill (a project has no reusable logo file);
//  - "Vyplnit" is the BROWSER's crop: a file uploaded here into a place set to „Vyplnit“ is
//    cropped to the place's ratio before it leaves; a gallery picture of another ratio is fitted
//    whole, and the card says so;
//  - "Změnit na tvar" takes its colour from the browser: the average of the original's pixels in
//    the place.
import { api, esc, ic, pl, urlFor, zz } from '../kit.js';
import { toast } from '../../shell/toast.js';
import { MAX_BYTES, cropToRatio } from '../file-checks.js';
import { fixError } from './app.js';
import { boxStyle, cropStyle, head, pixelsStyle } from './parts.js';
import { offLine } from './wait.js';

const MAX_FILES = 30;
const PICTURE = /^image\/(jpeg|png|webp|heic|heif|svg\+xml|gif)$/;
const SHAPES = [['rectangle', 'Obdélník', 'shape'], ['circle', 'Kruh', 'shape'], ['star', 'Hvězda', 'star']];
const SHAPE_NAME = { rectangle: 'obdélník', circle: 'kruh', star: 'hvězda' };
const q = (text) => `„${esc(text)}“`;
const placeName = (ph) => ph.name || (ph.background ? 'Fotka na pozadí' : `Místo ${ph.number}`);
const isDone = (ph) => Boolean(ph.picture) || ph.decidedEmpty || ph.kind === 'input';

function seg(ph, field, options) {
    const current = ph[field];
    return `<div class="seg soft as-seg" role="group" aria-label="${field === 'kind' ? 'Druh' : 'Přizpůsobení'}">${options.map(([v, l]) => `<button type="button" class="${current === v ? 'on' : ''}" aria-pressed="${current === v}" data-act="${field === 'kind' ? 'setKind' : 'setFit'}" data-id="${esc(ph.id)}" data-v="${v}" data-k="ph${esc(ph.id)}${v}">${l}</button>`).join('')}</div>`;
}

function card(app, ph) {
    const p = app.proposal;
    const others = p.placeholders.filter((x) => x.id !== ph.id);
    const name = placeName(ph);
    let body;
    if (ph.picture) {
        const pic = ph.picture;
        const where = pic.missing ? 'už v galerii není' : pic.uploaded ? `nahráno do ${q(`AI sken / ${p.name}`)}` : 'z galerie';
        const fitted = !pic.missing && ph.fit === 'cover' && pic.fitApplied === 'contain';
        body = `<p class="as-ph-note ok">${ic(pic.missing ? 'alert' : 'check-circle', 's14')}${pic.url ? `<span class="as-ph-pick" aria-hidden="true"><img src="${esc(pic.url)}" alt="" loading="lazy"></span>` : ''}<span>${esc(pic.name || 'obrázek')} · ${where}</span></p>
${pic.tooSmall ? `<p class="as-ph-note warn">${ic('alert', 's14')}<span>Obrázek je menší než místo v šabloně (${ph.width} × ${ph.height} px) — v exportu bude neostrý.</span></p>` : ''}${fitted ? `<p class="as-ph-note">${ic('info', 's14')}<span>Obrázek má jiný poměr stran než místo — vejde se celý. ${app.files.has(pic.fileId) ? `<button type="button" class="as-link" data-act="cropToPlace" data-id="${esc(ph.id)}" data-k="phcrop${esc(ph.id)}">Oříznout, aby místo vyplnil</button>` : 'Aby místo vyplnil, nahrajte ho sem ze svého počítače — ořízneme ho.'}</span></p>` : ''}
<div class="as-row">${seg(ph, 'fit', [['contain', 'Vejít celý'], ['cover', 'Vyplnit']])}<button type="button" class="btn xs btn-tertiary" data-act="pickGallery" data-id="${esc(ph.id)}" data-k="phc${esc(ph.id)}">Změnit</button><button type="button" class="btn xs btn-ghost" data-act="clearPicture" data-id="${esc(ph.id)}" data-k="phr${esc(ph.id)}">Odebrat obrázek</button></div>
${others.length ? `<label class="inp sel sm as-move"><select aria-label="Patří jinam — přesunout obrázek" data-chg="movePicture" data-id="${esc(ph.id)}" data-k="phmv${esc(ph.id)}"><option value="">Patří jinam…</option>${others.map((x) => `<option value="${esc(x.id)}">→ ${x.number} ${esc(placeName(x))}</option>`).join('')}</select>${ic('chev-down', 's14')}</label>` : ''}`;
    } else if (ph.kind === 'fixed' && !ph.background) {
        body = `<div class="as-row"><button type="button" class="btn xs btn-tertiary" data-act="pickGallery" data-id="${esc(ph.id)}" data-k="phg${esc(ph.id)}">${ic('image', 's14')}Vybrat z galerie</button><button type="button" class="btn xs btn-tertiary" data-act="uploadTo" data-id="${esc(ph.id)}" data-k="phn${esc(ph.id)}">${ic('upload', 's14')}Nahrát</button></div>`;
    } else {
        body = `<p class="as-ph-note">${ic('info', 's14')}<span>${ph.background ? 'Pozadí přes celou šablonu se vyplňuje při exportu.' : 'Pole, které uživatel vyplní před každým exportem.'}</span></p><div class="as-row"><button type="button" class="btn xs btn-tertiary" data-act="uploadTo" data-id="${esc(ph.id)}" data-k="phs${esc(ph.id)}">${ic('upload', 's14')}Přidat ukázku</button><button type="button" class="btn xs btn-ghost" data-act="pickGallery" data-id="${esc(ph.id)}" data-k="phg${esc(ph.id)}">Z galerie</button><button type="button" class="btn xs btn-ghost${ph.decidedEmpty ? ' on' : ''}" aria-pressed="${ph.decidedEmpty}" data-act="noPicture" data-id="${esc(ph.id)}" data-k="phb${esc(ph.id)}">${ph.decidedEmpty ? ic('check', 's14') : ''}${ph.background ? 'Nechat šedé pozadí' : 'Bez ukázky'}</button></div>`;
    }
    const open = app.menu === ph.id;
    const menu = open ? `<div class="menu" role="menu">${others.some((x) => !x.background) ? `<button type="button" class="mi" role="menuitem" data-act="mergeStart" data-id="${esc(ph.id)}">${ic('layers')}Sloučit s dalšími…</button>` : ''}${SHAPES.map(([v, l, icon]) => `<button type="button" class="mi" role="menuitem" data-act="toShape" data-id="${esc(ph.id)}" data-v="${v}">${ic(icon)}Změnit na tvar: ${l}</button>`).join('')}<div class="menu-sep" role="separator"></div><button type="button" class="mi danger" role="menuitem" data-act="removePlace" data-id="${esc(ph.id)}">${ic('trash')}Smazat místo pro obrázek</button></div>` : '';
    const selected = app.merge && app.merge.has(ph.id);
    const crop = ph.box && p.source
        ? `<span class="crop" role="img" aria-label="Výřez původního obrázku v tomto místě" style="${cropStyle(app.originalUrl(), ph.box, p.source, { pad: 0.12, minRatio: 0.7, maxRatio: 2.2 })}"></span>`
        : `<span class="crop whole" role="img" aria-label="Původní obrázek — pozadí přes celou šablonu" style="aspect-ratio:${p.source ? p.source.width / p.source.height : 1};background-image:url('${esc(app.originalUrl())}');background-size:cover"></span>`;
    return `<article class="as-ph${selected ? ' sel' : ''}" data-region="ph-${esc(ph.id)}" data-hl="${esc(ph.id)}" aria-label="Místo ${ph.number}: ${esc(name)}">
<div class="as-ph-crop">${crop}<b class="as-num">${ph.number}</b>${ph.picture && !ph.picture.missing ? `<span class="chk" role="img" aria-label="Doplněno">${ic('check', 's12')}</span>` : ''}</div>
<div class="as-ph-b"><div class="as-ph-h">${app.merge && !ph.background ? `<label class="as-check" style="flex:none"><input type="checkbox" class="sr" data-chg="mergeToggle" data-id="${esc(ph.id)}" data-k="phm${esc(ph.id)}"${selected ? ' checked' : ''} aria-label="Vybrat ke sloučení: ${esc(name)}"><span class="cb" aria-hidden="true">${ic('check', 's14')}</span></label>` : ''}<input class="as-inl" value="${esc(ph.name || '')}" placeholder="${esc(name)}" maxlength="40" aria-label="Název místa ${ph.number}" data-chg="rename" data-id="${esc(ph.id)}" data-k="phname${esc(ph.id)}"${ph.background ? ' disabled' : ''}><span class="cap">${ph.width} × ${ph.height} px</span>${ph.background ? '' : `<span class="as-ph-menu"><button type="button" class="btn icon xs btn-ghost" aria-haspopup="menu" aria-expanded="${open}" aria-label="Další možnosti: ${esc(name)}" data-act="placeMenu" data-id="${esc(ph.id)}" data-k="phmenu${esc(ph.id)}">${ic('more')}</button>${menu}</span>`}</div>
${ph.kindLocked ? '' : seg(ph, 'kind', [['fixed', 'Součást šablony'], ['input', 'Mění se při každém exportu']])}${body}</div></article>`;
}

/** Places the user removed, merged away or turned into a shape — one line each, with "Vrátit". */
function changes(app) {
    return (app.proposal.changes || []).map((c) => {
        const name = c.name || 'místo pro obrázek';
        const text = c.op === 'shape' ? `${q(name)} změněno na tvar (${SHAPE_NAME[c.shape] || 'tvar'})`
            : c.op === 'merge' ? `${q(name)} sloučeno s ${c.with.length} ${pl(c.with.length, 'dalším místem', 'dalšími místy', 'dalšími místy')}`
                : `Místo ${q(name)} smazáno`;
        return `<div class="as-it decided" data-region="ch-${esc(c.key)}"><p class="as-it-t">${ic('check-circle')}<span>${text}</span></p><button type="button" class="btn xs btn-ghost" data-act="undo" data-key="${esc(c.key)}" data-k="un${esc(c.key)}">Vrátit</button></div>`;
    }).join('');
}

function numbered(app) {
    const p = app.proposal;
    const marks = p.source ? p.placeholders.map((ph) => {
        if (!ph.box) return `<b class="as-num" style="left:4%;top:4%">${ph.number}</b>`;
        const left = Math.max((ph.box[0] / p.source.width) * 100, 3);
        const top = Math.max((ph.box[1] / p.source.height) * 100, 4);
        return `<span class="as-numbox${ph.picture ? ' done' : ' as-place'}" data-box="${esc(ph.id)}" style="${boxStyle(ph.box, p.source)}${ph.picture ? '' : `;${pixelsStyle(app.originalUrl(), ph.box, p.source)}`}"></span><b class="as-num" style="left:${left}%;top:${top}%">${ph.number}</b>`;
    }).join('') : '';
    return `<div class="as-frame" data-busy><img src="${esc(app.proposalUrl())}" alt="Návrh s očíslovanými místy pro obrázky">${marks}</div>`;
}

export function picturesView(app) {
    const p = app.proposal;
    const list = p.placeholders;
    const done = list.filter(isDone).length;
    const empty = p.pictures.unfilled;
    const nsel = app.merge ? app.merge.size : 0;
    const stale = app.previewFailed || p.preview.stale ? `<p class="as-ph-note warn" role="status">${ic('alert', 's14')}<span>Náhled se nepodařilo obnovit. <button type="button" class="as-link" data-act="rerender" data-k="rerender">Zkusit znovu</button></span></p>` : '';
    const status = app.saving ? '<span class="cap" role="status">Ukládám…</span>' : app.saved ? `<span class="cap" role="status">${ic('check', 's12')} Uloženo</span>` : '';
    return `${head(app, 4, 'Fotky a loga')}${offLine(app)}
<div class="as-grid as-s4b" data-region="s4b" data-deep><div class="as-main as-bigpic" data-region="big" data-deep><section class="as-card as-sticky" data-region="bigcard" data-deep><span class="lbl" data-region="lbl">Návrh šablony</span><div class="as-numwrap" data-region="num-${p.preview.seq}-${p.revision}">${numbered(app)}</div><div data-region="cap">${stale}<p class="as-cap">Čísla ukazují, kam který obrázek patří. V prázdných místech je vidět původní obrázek.</p></div></section></div>
<aside class="as-side" data-region="side" data-deep><section class="as-card" data-region="top"><div class="as-row"><h2 tabindex="-1" data-focus>Fotky a loga</h2><span class="as-sp"></span><span class="as-ph-count" aria-label="Doplněno ${done} ${zz(list.length)} ${list.length}">${done} ${zz(list.length)} ${list.length}</span></div>
<input type="file" class="sr" id="asPics" tabindex="-1" aria-hidden="true" multiple accept="image/*" data-chg="multiFiles"><input type="file" class="sr" id="asPlaceFile" tabindex="-1" aria-hidden="true" accept="image/*" data-chg="placeFile">
<button type="button" class="gal-drop gal-drop-compact as-drop as-multi" data-act="multiPick" data-k="bulk" data-dropzone${app.uploading ? ' disabled' : ''}>${ic('upload', '')}<b>${app.uploading ? 'Nahrávám obrázky…' : '<span class="dt-only">Přetáhněte sem víc obrázků najednou</span><span class="ph-only">Klepněte a vyberte víc obrázků</span>'}</b><span class="cap">Přiřadíme je prázdným místům popořadě · nejvýše 10 MB na obrázek</span></button>
<div><button type="button" class="btn xs btn-tertiary" data-act="pickGalleryMulti" data-k="galmulti">${ic('image', 's14')}Vybrat víc z galerie</button></div></section>
<div class="as-ph-list" data-region="list" data-deep>${list.map((ph) => card(app, ph)).join('')}${changes(app)}</div>
${app.merge ? `<div class="as-merge" role="region" aria-label="Sloučení míst" data-region="merge"><span>${nsel < 2 ? 'Vyberte aspoň dvě místa' : `Vybráno: ${nsel}`}</span><span class="as-sp"></span><button type="button" class="btn xs btn-ghost" data-act="mergeCancel" data-k="mergex">Zrušit</button><button type="button" class="btn xs btn-tertiary" data-act="mergeDo" data-k="mergedo"${nsel < 2 ? ' disabled' : ''}>Sloučit${nsel >= 2 ? ` ${nsel} ${pl(nsel, 'místo', 'místa', 'míst')}` : ''}</button></div>` : ''}</aside></div>
<div class="as-foot" data-region="foot"><div class="as-foot-r"><button type="button" class="btn sm btn-tertiary" data-act="goReview" data-k="back">${ic('arrow-left')}Zpět na kontrolu</button>${status}<span class="as-sp"></span><button type="button" class="btn sm btn-primary" data-act="accept" data-k="create"${app.busy === 'accept' ? ' disabled' : ''}>${app.busy === 'accept' ? 'Vytvářím šablonu…' : empty ? `Vytvořit šablonu · ${empty} ${pl(empty, 'obrázek', 'obrázky', 'obrázků')} doplníte později` : 'Vytvořit šablonu a otevřít editor'}${ic('arrow-right')}</button></div>${empty ? '<p class="as-sub">Prázdná místa zůstanou v šabloně šedá. Doplníte je v editoru — při exportu vás na ně upozorníme.</p>' : ''}</div>`;
}

// ---- uploads -------------------------------------------------------------------------------------

function usable(files) {
    const list = [...(files || [])];
    const bad = list.find((f) => !(PICTURE.test(f.type) || /\.(heic|heif)$/i.test(f.name)));
    if (bad) { toast(`${bad.name}: tento soubor neumíme. Nahrajte obrázky JPG, PNG, WebP, SVG nebo HEIC.`, { error: true }); return null; }
    const big = list.find((f) => f.size > MAX_BYTES);
    if (big) { toast(`${big.name}: obrázek je větší než 10 MB. Zmenšete ho nebo ho uložte jako JPG.`, { error: true }); return null; }
    if (list.length > MAX_FILES) { toast(`Najednou jde nahrát nejvýše ${MAX_FILES} obrázků.`, { error: true }); return null; }
    return list;
}

async function send(app, files, place = null) {
    app.uploading = true;
    app.saving += 1;
    app.render();
    const answer = await api.upload(urlFor(app.urls.pictures, app.scan.id), place ? { pictures: files, place } : { pictures: files });
    app.uploading = false;
    app.saving -= 1;
    if (answer.ok) {
        (answer.fileIds || []).forEach((id, i) => { if (files[i]) app.files.set(id, files[i].__source || files[i]); });
        app.fixed(answer);
        return answer;
    }
    app.render();
    toast(answer.code === 'network' ? 'Nahrání se nepodařilo. Zkontrolujte připojení a zkuste to znovu.' : fixError(answer), { error: true });
    return answer;
}

async function multi(app, files) {
    const list = usable(files);
    if (!list || !list.length) return;
    const answer = await send(app, list);
    if (!answer.ok) return;
    const n = answer.assigned || 0;
    const left = list.length - n;
    toast(n ? `${n} ${pl(n, 'obrázek přiřazen', 'obrázky přiřazeny', 'obrázků přiřazeno')}${left > 0 ? ` · ${left} ${pl(left, 'zůstal', 'zůstaly', 'zůstalo')} v galerii` : ''}` : 'Žádné prázdné místo — obrázky jsme nepřiřadili');
}

/** One file into one place; with „Vyplnit“ the browser crops it to the place's ratio first. */
async function single(app, placeId, file) {
    const list = usable([file]);
    if (!list) return;
    const ph = app.proposal.placeholders.find((x) => x.id === placeId);
    let out = list[0];
    if (ph && ph.fit === 'cover' && ph.width > 0 && ph.height > 0 && !/svg|heic|heif/i.test(out.type + out.name)) {
        try { const cropped = await cropToRatio(out, ph.width / ph.height); if (cropped !== out) { cropped.__source = out; out = cropped; } } catch (e) { /* the server fits it whole */ }
    }
    await send(app, [out], placeId);
}

/** Opens one of the page's own (visually hidden) file inputs — never a native dialog of our making. */
function openInput(app, id) {
    const input = app.root.querySelector(`#${id}`);
    if (!input) return;
    input.value = '';
    input.click();
}

/** The average colour of the original inside a place — what "Změnit na tvar" is filled with. */
async function measuredColour(app, ph) {
    const p = app.proposal;
    if (!ph.box || !p.source) return null;
    try {
        const image = new Image();
        image.src = app.originalUrl();
        await image.decode();
        const sx = image.naturalWidth / p.source.width;
        const sy = image.naturalHeight / p.source.height;
        const canvas = document.createElement('canvas');
        canvas.width = 8;
        canvas.height = 8;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(image, ph.box[0] * sx, ph.box[1] * sy, Math.max(1, ph.box[2] * sx), Math.max(1, ph.box[3] * sy), 0, 0, 8, 8);
        const { data } = ctx.getImageData(0, 0, 8, 8);
        let r = 0; let g = 0; let b = 0;
        for (let i = 0; i < data.length; i += 4) { r += data[i]; g += data[i + 1]; b += data[i + 2]; }
        const hex = (v) => Math.round(v / 64).toString(16).padStart(2, '0');
        return `#${hex(r)}${hex(g)}${hex(b)}`;
    } catch (e) {
        return null;
    }
}

// ---- gallery picker --------------------------------------------------------------------------------

function galleryModal() {
    const el = document.getElementById('aiScanGalleryModal');
    return el && window.bootstrap ? { el, modal: window.bootstrap.Modal.getOrCreateInstance(el) } : null;
}

function multiBar(app) {
    const host = galleryModal();
    if (!host) return;
    let bar = host.el.querySelector('.as-galbar');
    const multi = app.pickFor && app.pickFor.multi;
    if (!multi) { if (bar) bar.remove(); host.el.querySelectorAll('.as-picked').forEach((button) => button.classList.remove('as-picked')); return; }
    if (!bar) {
        bar = document.createElement('div');
        bar.className = 'as-galbar';
        host.el.querySelector('.modal-content').append(bar);
        bar.addEventListener('click', (event) => {
            if (event.target.closest('[data-gal-assign]')) assignPicked(app);
            if (event.target.closest('[data-gal-clear]')) { app.pickFor.multi.clear(); multiBar(app); }
        });
    }
    const n = multi.size;
    host.el.querySelectorAll('[data-image-gallery-id-param]').forEach((button) => button.classList.toggle('as-picked', multi.has(button.dataset.imageGalleryIdParam)));
    bar.innerHTML = `<span role="status">${n ? `Vybráno: ${n} ${pl(n, 'obrázek', 'obrázky', 'obrázků')}` : 'Klikněte na obrázky, které chcete přiřadit prázdným místům.'}</span><span class="as-sp"></span>${n ? '<button type="button" class="btn xs btn-ghost" data-gal-clear>Zrušit výběr</button>' : ''}<button type="button" class="btn sm btn-primary" data-gal-assign${n ? '' : ' disabled'}>Přiřadit${n ? ` ${n}` : ''}</button>`;
}

async function assignPicked(app) {
    const ids = [...app.pickFor.multi.keys()];
    const host = galleryModal();
    if (host) host.modal.hide();
    app.pickFor = null;
    if (!ids.length) return;
    const answer = await app.fix({ type: 'assign_pictures', fileIds: ids });
    if (!answer.ok) return;
    const n = answer.assigned || 0;
    toast(n ? `${n} ${pl(n, 'obrázek přiřazen', 'obrázky přiřazeny', 'obrázků přiřazeno')}` : 'Žádné prázdné místo — obrázky jsme nepřiřadili');
}

/** `asset-selected` of the gallery modal (image_gallery_controller). */
export function assetSelected(app, detail) {
    if (!app.pickFor || !detail.id) return;
    if (app.pickFor.multi) {
        const multi = app.pickFor.multi;
        if (multi.has(detail.id)) multi.delete(detail.id); else multi.set(detail.id, detail.name || '');
        multiBar(app);
        return;
    }
    const place = app.pickFor.place;
    app.pickFor = null;
    const host = galleryModal();
    if (host) host.modal.hide();
    app.fix({ type: 'set_picture', id: place, fileId: detail.id });
}

function openGallery(app, target) {
    const host = galleryModal();
    if (!host) { toast('Galerii se nepodařilo otevřít. Načtěte stránku znovu.', { error: true }); return; }
    app.pickFor = target;
    multiBar(app);
    host.modal.show();
}

// ---- actions ----------------------------------------------------------------------------------------

const place = (app, el) => app.proposal.placeholders.find((x) => x.id === el.dataset.id);

export const actions = {
    multiPick: (app) => openInput(app, 'asPics'),
    multiFiles: (app, el) => multi(app, el.files),
    uploadTo: (app, el) => { app.placeTarget = el.dataset.id; openInput(app, 'asPlaceFile'); },
    placeFile: (app, el) => { if (app.placeTarget && el.files && el.files[0]) single(app, app.placeTarget, el.files[0]); },
    cropToPlace: (app, el) => { const ph = place(app, el); const source = ph && ph.picture ? app.files.get(ph.picture.fileId) : null; if (source) single(app, ph.id, source); },
    pickGallery: (app, el) => openGallery(app, { place: el.dataset.id }),
    pickGalleryMulti: (app) => openGallery(app, { multi: new Map() }),
    setKind: (app, el) => { if (place(app, el).kind !== el.dataset.v) app.fix({ type: 'set_kind', id: el.dataset.id, kind: el.dataset.v }); },
    setFit: async (app, el) => {
        const ph = place(app, el);
        if (ph.fit === el.dataset.v) return;
        await app.fix({ type: 'set_fit', id: ph.id, fit: el.dataset.v });
        // „Vyplnit“ on a picture uploaded in this session: crop it now and put it back.
        const now = app.proposal.placeholders.find((x) => x.id === ph.id);
        const source = now && now.picture ? app.files.get(now.picture.fileId) : null;
        if (el.dataset.v === 'cover' && source && now.picture.fitApplied === 'contain') await single(app, ph.id, source);
    },
    noPicture: (app, el) => { const ph = place(app, el); app.fix(ph.decidedEmpty ? { type: 'set_picture', id: ph.id, fileId: null } : { type: 'no_picture', id: ph.id }); },
    movePicture: (app, el) => { if (el.value) app.fix({ type: 'move_picture', from: el.dataset.id, to: el.value }); },
    placeMenu: (app, el) => {
        app.menu = app.menu === el.dataset.id ? null : el.dataset.id;
        app.render();
        const first = app.root.querySelector('.as-ph-menu .menu .mi');
        if (first) first.focus();
    },
    removePlace: (app, el) => { app.menu = null; app.fix({ type: 'remove_placeholder', id: el.dataset.id }); },
    toShape: async (app, el) => {
        const ph = place(app, el);
        app.menu = null;
        const colour = await measuredColour(app, ph);
        app.fix({ type: 'placeholder_to_shape', id: ph.id, shape: el.dataset.v, ...(colour ? { colour } : {}) });
    },
    mergeStart: (app, el) => { app.menu = null; app.merge = new Set([el.dataset.id]); app.render(); },
    mergeToggle: (app, el) => { if (el.checked) app.merge.add(el.dataset.id); else app.merge.delete(el.dataset.id); app.render(); },
    mergeCancel: (app) => { app.merge = null; app.render(); },
    mergeDo: async (app) => {
        const ids = [...app.merge];
        app.merge = null;
        const answer = await app.fix({ type: 'merge_placeholders', ids });
        if (answer.ok) toast('Místa sloučena');
    },
};

/** Files dropped on the multi-drop zone (bound by the page controller on the root). */
export function bindDrop(app) {
    const zone = (event) => (event.target.closest ? event.target.closest('[data-dropzone]') : null);
    const files = (event) => event.dataTransfer && [...(event.dataTransfer.types || [])].includes('Files');
    app.root.addEventListener('dragover', (event) => { const z = zone(event); if (z && files(event)) { event.preventDefault(); z.classList.add('over'); } });
    app.root.addEventListener('dragleave', (event) => { const z = zone(event); if (z) z.classList.remove('over'); });
    app.root.addEventListener('drop', (event) => {
        const z = zone(event);
        if (!z || !files(event)) return;
        event.preventDefault();
        z.classList.remove('over');
        if (app.view() === 'pictures') multi(app, event.dataTransfer.files);
    });
}
