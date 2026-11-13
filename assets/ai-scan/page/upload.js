// AI sken — S1 (upload) and S2 (picture chosen: facts, format, fonts, consent, start). ux.md S1, S2, T2.
import { api, bytesCz, creditLine, esc, ic, minutesRange, openDialog, dialogHead, pl, scansAhead, zz } from '../kit.js';
import { toast } from '../../shell/toast.js';
import { UPLOAD_ERRORS, startHint } from '../copy.js';
import { inspectFiles, printFormats, ratioLabel, GOOD_SHORT_SIDE, TEMPLATE_MAX_LONG_EDGE } from '../file-checks.js';
import { head } from './parts.js';

const ACCEPT = 'image/jpeg,image/png,image/webp,image/heic,image/heif';
const TIPS_OK = ['Export z grafického programu nebo snímek obrazovky', 'Celá grafika bez ořezu, kratší strana aspoň 1000 px', 'Rovné texty, ploché barvy, jasné tvary'];
const TIPS_NO = ['Fotka vytištěného plakátu z mobilu', 'Koláž z více grafik v jednom obrázku', 'Stíny, obrysy a otočené texty — ty zjednodušíme'];
const tips = () => `<div><h3 style="margin:0 0 10px">S čím to jde nejlépe</h3><ul class="as-tips">${TIPS_OK.map((t, i) => `<li class="ok">${ic('check')}<span><span class="sr">Vhodné: </span>${t}</span></li><li class="no">${ic('x')}<span><span class="sr">Nevhodné: </span>${TIPS_NO[i]}</span></li>`).join('')}</ul></div>`;

const errorText = (error) => (error ? UPLOAD_ERRORS[error.code](error.width, error.height) : '');

// ---- S1 ---------------------------------------------------------------------------------------

export function uploadView(app) {
    const error = app.uploadError;
    return `${head(app, 1, 'Nahrání', { lead: true })}${serviceLine(app)}
<section class="as-card" data-region="upload">
<input type="file" class="sr" id="asFile" tabindex="-1" aria-hidden="true" accept="${ACCEPT}" data-chg="fileInput">
<button type="button" class="gal-drop as-drop" data-act="pick" data-k="drop" data-focus aria-describedby="asDropCap${error ? ' asDropErr' : ''}">${ic('sparkle', '')}<b><span class="dt-only">Přetáhněte sem obrázek grafiky</span><span class="ph-only">Klepněte a vyberte obrázek</span></b><span class="dt-only">nebo <u>vyberte soubor</u> z počítače</span><span class="cap" id="asDropCap">JPG, PNG, WebP nebo HEIC · nejvýše 10 MB · jeden obrázek = jedna šablona</span></button>
${error ? `<p class="hint err" id="asDropErr" role="alert" style="margin:0;display:flex;gap:6px">${ic('alert', 's14')}<span>${esc(errorText(error))}${error.code === 'network' && app.picked ? ' <button type="button" class="as-link" data-act="start">Zkusit znovu</button>' : ''}</span></p>` : ''}
${tips()}</section>`;
}

/** F12 and friends: why a new scan cannot start right now, said before the user picks a file. */
function serviceLine(app) {
    const state = app.entry.state;
    const text = state === 'service_waiting' ? 'AI sken je dočasně nedostupný. Víme o tom. Zkuste to později.'
        : state === 'limit_reached' ? startHint('limit_reached', app.entry)
            : state === 'admin_preview' ? 'Projekt AI sken zapnutý nemá — vidíte ho jako administrátor.' : null;
    return text ? `<p class="as-qline" style="margin:0" data-region="service">${ic(state === 'admin_preview' ? 'shield' : 'info')}<span>${esc(text)}</span></p>` : '';
}

// ---- S2 ---------------------------------------------------------------------------------------

/** Why the start button is disabled right now: a refusal code, or null. */
function blocked(app) {
    const entry = app.entry;
    if (entry.state === 'service_waiting') return 'temporarily_unavailable';
    if (entry.state === 'limit_reached') return 'limit_reached';
    if (entry.fonts.faces === 0) return 'no_fonts';
    if (entry.scans.filter((s) => ['checking', 'queued', 'running'].includes(s.status)).length >= 4) return 'project_queue_full';
    if (!entry.consent.given && !(app.consent[0] && app.consent[1])) return 'consent_required';
    return app.startRefusal;
}

function checks(picked) {
    const ok = (text) => `<li>${ic('check')}${text}</li>`;
    const warn = (text) => `<li class="warn">${ic('alert')}<span><span class="sr">Varování: </span>${text}</span></li>`;
    const rows = [ok('Formát a velikost jsou v pořádku')];
    if (picked.type === 'heic') rows.push(warn('Fotka z telefonu (HEIC). Pokud jde o fotku tisku, výsledek bude jen přibližný.'));
    if (picked.width) rows.push(picked.warnings.includes('small') ? warn('Malý obrázek — drobné texty nemusíme přečíst') : ok('Dostatečné rozlišení'));
    if (picked.sharpnessChecked) rows.push(picked.warnings.includes('blurry') ? warn('Obrázek je rozmazaný — výsledek bude jen přibližný') : ok('Obrázek je ostrý'));
    return `<ul class="as-checks" aria-label="Kontrola souboru v prohlížeči">${rows.join('')}</ul>`;
}

/** The format options of T2 for the chosen picture. */
function formats(app) {
    const { width, height } = app.picked;
    if (!width) return { screen: [], print: [] };
    const screen = [{ id: 'source', label: `${ratioLabel(width, height)} · ${width} × ${height} px (podle obrázku)` }];
    if (Math.min(width, height) < GOOD_SHORT_SIDE && Math.max(width, height) * 2 <= TEMPLATE_MAX_LONG_EDGE) screen.push({ id: 'double', label: `${width * 2} × ${height * 2} px`, unit: 'px', width: width * 2, height: height * 2 });
    screen.push({ id: 'custom', label: 'Vlastní šířka…' });
    const print = printFormats(width, height).map((f) => ({ id: `a${f.width}`, label: f.label, unit: 'mm', width: f.width, height: f.height }));
    print.push({ id: 'custom', label: 'Vlastní šířka…' });
    return { screen, print };
}

/** What the start request sends as `options` (nothing = the picture's own size in px). */
function targetOptions(app) {
    const { width, height } = app.picked;
    if (!width) return {};
    const mode = app.format.mode;
    const list = formats(app)[mode];
    const chosen = list.find((f) => f.id === app.format.choice) || list[0];
    if (chosen.id === 'custom') {
        const w = Math.round(Number(String(app.format.custom).replace(',', '.')));
        if (!Number.isFinite(w) || w <= 0) return null;
        const h = Math.max(1, Math.round((w * height) / width));
        const unit = mode === 'print' ? 'mm' : 'px';
        const longEdgePx = unit === 'px' ? Math.max(w, h) : (Math.max(w, h) / 25.4) * 300;
        if (longEdgePx > TEMPLATE_MAX_LONG_EDGE || Math.min(w, h) < (unit === 'px' ? 50 : 10)) return null;
        return { targetUnit: unit, targetWidth: w, targetHeight: h };
    }
    return chosen.unit ? { targetUnit: chosen.unit, targetWidth: chosen.width, targetHeight: chosen.height } : {};
}

function formatField(app) {
    const picked = app.picked;
    if (!picked.width) {
        return `<div class="field"><span class="field-l">Formát šablony <small>podle obrázku</small></span><span class="hint">Rozměr převezmeme z obrázku. Další rozměry přidáte později v editoru.</span></div>`;
    }
    const all = formats(app);
    const mode = app.format.mode;
    const list = all[mode];
    const choice = list.some((f) => f.id === app.format.choice) ? app.format.choice : list[0].id;
    const custom = choice === 'custom';
    const invalid = custom && app.format.custom !== '' && targetOptions(app) === null;
    const h = custom && targetOptions(app) ? targetOptions(app).targetHeight : null;
    return `<div class="field"><span class="field-l">Formát šablony <small>podle obrázku</small></span>
<div class="seg soft as-seg" role="group" aria-label="Určení šablony" style="align-self:flex-start">${[['screen', 'Na obrazovku'], ['print', 'Pro tisk']].map(([v, l]) => `<button type="button" class="${mode === v ? 'on' : ''}" aria-pressed="${mode === v}" data-act="formatMode" data-v="${v}" data-k="fm${v}">${l}</button>`).join('')}</div>
<label class="inp sel"><select aria-label="Rozměr šablony" data-chg="formatChoice" data-k="fchoice">${list.map((f) => `<option value="${f.id}"${f.id === choice ? ' selected' : ''}>${esc(f.label)}</option>`).join('')}</select>${ic('chev-down')}</label>
${custom ? `<label class="inp"><input type="text" inputmode="numeric" value="${esc(app.format.custom)}" placeholder="${mode === 'print' ? 'Šířka v mm' : 'Šířka v px'}" aria-label="${mode === 'print' ? 'Vlastní šířka v milimetrech' : 'Vlastní šířka v pixelech'}" data-chg="formatCustom" data-k="fcustom"${invalid ? ' aria-invalid="true"' : ''}><span class="cap">${mode === 'print' ? 'mm' : 'px'}${h ? ` × ${h} ${mode === 'print' ? 'mm' : 'px'}` : ''}</span></label>` : ''}
${invalid ? `<span class="hint err">${mode === 'print' ? 'Zadejte šířku v milimetrech — delší strana nejvýše 420 mm.' : 'Zadejte šířku v pixelech — delší strana nejvýše 5000 px.'}</span>` : ''}
<span class="hint">${mode === 'print' ? 'Spadávku z obrázku nepoznáme — šablona vznikne bez ní a doplníte ji v editoru.' : 'Další rozměry přidáte později v editoru.'}</span></div>`;
}

function fontsField(app) {
    const n = app.entry.fonts.faces;
    const input = '<input type="file" class="sr" id="asFontFile" tabindex="-1" aria-hidden="true" multiple accept=".ttf,.otf,.woff,font/ttf,font/otf,font/woff" data-chg="fontFiles">';
    if (n === 0) {
        return `<div class="field">${input}<span class="field-l">Písma</span><div class="as-fonts warn" role="alert">${ic('alert')}<span>Projekt zatím nemá žádné písmo. Bez písma neumíme vytvořit texty.</span><button type="button" class="btn xs btn-tertiary" data-act="fontPick" data-k="fontadd">${ic('upload', 's14')}Nahrát písma</button></div><span class="hint">TTF, OTF nebo WOFF. Hotová písma najdete i na stránce <a class="as-link" href="${esc(app.urls.fonts)}" target="_blank" rel="noopener">Fonty</a>.</span></div>`;
    }
    return `<div class="field">${input}<span class="field-l">Písma <small>nepovinné</small></span><div class="as-fonts">${ic('font')}<span>Písma projektu · ${n} ${pl(n, 'řez', 'řezy', 'řezů')}</span><button type="button" class="btn xs btn-tertiary" data-act="fontPick" data-k="fontadd"${app.fontBusy ? ' disabled' : ''}>${ic('upload', 's14')}Přidat</button></div><span class="hint">Znáte písmo použité v grafice? Přidejte ho do projektu — výsledek bude přesnější. Jinak vybereme nejbližší z projektu.</span></div>`;
}

const CONSENT_SENT = 'Obrázek a údaje z něj odešleme ke zpracování službě Claude společnosti Anthropic (USA). Anthropic je maže do 30 dnů. My uchováme původní obrázek 30 dní.';
const PRIVACY_LINK = '<button type="button" class="as-link" data-act="privacy" data-k="privacy">Jak s obrázky nakládáme</button>';
function consentBlock(app) {
    const check = (i, html) => `<label class="as-check"><input type="checkbox" class="sr" data-chg="consent" data-i="${i}" data-k="consent${i}"${app.consent[i] ? ' checked' : ''}><span class="cb" aria-hidden="true">${ic('check', 's14')}</span><span>${html}</span></label>`;
    if (!app.entry.consent.given) {
        return `<div class="as-consent" role="group" aria-label="Souhlasy">${check(0, `<b>Souhlasím, že obrázek a údaje z něj (texty, barvy, názvy písem) odešlete ke zpracování službě Claude společnosti Anthropic (USA).</b> Anthropic je maže do 30 dnů. My uchováme původní obrázek 30 dní. ${PRIVACY_LINK}`)}${check(1, '<b>Potvrzuji, že tuto grafiku smím použít.</b>')}</div>`;
    }
    return `<div class="as-consent"><p class="as-ph-note">${ic('info', 's14')}<span>${CONSENT_SENT} ${PRIVACY_LINK}</span></p><p class="as-ph-note">${ic('info', 's14')}<span>Spuštěním potvrzujete, že tuto grafiku smíte použít.</span></p></div>`;
}

function startBlock(app) {
    const entry = app.entry;
    const code = blocked(app) || (targetOptions(app) === null ? 'format' : null);
    const hint = code === 'format' ? 'Zadejte vlastní šířku šablony.' : startHint(code, entry);
    const show = code === 'project_queue_full' ? ` <a href="${esc(app.urls.templates)}" class="as-link">Zobrazit</a>` : '';
    const position = entry.queue.position || 0;
    const startIn = minutesRange(entry.queue.estimatedStartSeconds);
    const doneIn = minutesRange(entry.queue.estimatedFinishSeconds);
    const queue = !code && position > 0 && startIn && doneIn
        ? `<p class="as-qline" style="margin:0">${ic('clock')}<span>${scansAhead(position)}. Začneme ${startIn === 'během chvíle' ? startIn : `asi za ${startIn}`}, hotovo asi za ${doneIn}.</span></p>` : '';
    const credits = entry.credits;
    const credit = code === 'limit_reached' ? `V projektu tento měsíc zbývá 0 ${zz(credits.limit)} ${credits.limit}` : creditLine(credits);
    return `${queue}<button type="button" class="btn btn-primary w-100" data-k="start" data-act="start"${code || app.starting ? ' disabled aria-describedby="asStartHint"' : ''}>${ic('sparkle')}${app.starting ? 'Nahrávám obrázek…' : 'Spustit rozpoznávání'}</button>
${hint ? `<p class="hint" id="asStartHint" style="margin:-6px 0 0;text-align:center">${esc(hint)}${show}</p>` : ''}
<p class="as-credit" style="margin:0">${ic('sparkle', 's14')}<span>${esc(credit)}</span></p>`;
}

export function chosenView(app) {
    const picked = app.picked;
    const facts = [picked.width ? `${picked.width} × ${picked.height} px` : null, picked.width ? ratioLabel(picked.width, picked.height) : null, bytesCz(picked.file.size)].filter(Boolean).join(' · ');
    const thumb = picked.previewUrl
        ? `<div class="as-file-th"><img src="${esc(picked.previewUrl)}" alt="Náhled vybraného obrázku"></div>`
        : `<div class="as-file-th none">${ic('image', '')}<span class="cap">Náhled uvidíte po nahrání</span></div>`;
    return `${head(app, 1, 'Nahrání')}
<div class="as-grid" data-region="chosen" data-deep>
<div class="as-main" data-region="file"><section class="as-card"><div class="as-file">${thumb}<div class="as-file-b"><b>${esc(picked.file.name)}</b><span class="cap">${facts}</span>${checks(picked)}
<div><input type="file" class="sr" id="asFile" tabindex="-1" aria-hidden="true" accept="${ACCEPT}" data-chg="fileInput"><button type="button" class="btn xs btn-tertiary" data-act="pick" data-k="other">${ic('refresh', 's14')}Vybrat jiný obrázek</button></div></div></div>
<p class="cap" style="margin:0">Zatím jsme nic neodeslali — tohle jsme zjistili ve vašem prohlížeči.</p></section><section class="as-card">${tips()}</section></div>
<aside class="as-side" data-region="start"><section class="as-card" aria-labelledby="asNez"><h2 id="asNez" tabindex="-1" data-focus>Než začneme</h2>${formatField(app)}${fontsField(app)}${consentBlock(app)}${startBlock(app)}</section></aside></div>`;
}

// ---- actions ------------------------------------------------------------------------------------

async function take(app, files) {
    const result = await inspectFiles(files);
    if (!result) return;
    if (app.scan) return; // a scan is on screen — the drop is not for this page state
    if (result.error) {
        app.uploadError = { code: result.error, width: result.width, height: result.height };
        if (app.picked && app.picked.previewUrl) URL.revokeObjectURL(app.picked.previewUrl);
        app.picked = null;
        app.render();
        toast(errorText(app.uploadError), { error: true });
        const zone = app.root.querySelector('[data-k="drop"]');
        if (zone) zone.focus();
        return;
    }
    if (app.picked && app.picked.previewUrl) URL.revokeObjectURL(app.picked.previewUrl);
    app.picked = result;
    app.uploadError = null;
    app.startRefusal = null;
    app.requestId = newRequestId();
    const print = printFormats(result.width || 1, result.height || 1);
    app.format = result.width && print.length ? { mode: 'print', choice: print.find((f) => f.label.startsWith('A4')) ? `a${print.find((f) => f.label.startsWith('A4')).width}` : print[0] && `a${print[0].width}`, custom: '' } : { mode: 'screen', choice: 'source', custom: '' };
    app.render();
    app.focusHeading();
    // The queue and the limits may have moved since the page was opened.
    app.refreshEntry().then(() => { if (!app.scan) app.render(); });
}

function newRequestId() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return `r-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

async function start(app) {
    const picked = app.picked;
    if (!picked || app.starting) return;
    const options = targetOptions(app);
    if (options === null || blocked(app)) return;
    app.starting = true;
    app.render();
    const answer = await api.upload(app.urls.start, {
        picture: picked.file,
        kind: 'template',
        requestId: app.requestId, // the same id on a repeat: a retry never makes a second scan
        consent: !app.entry.consent.given && app.consent[0] && app.consent[1] ? '1' : '0',
        options: JSON.stringify(options),
    });
    app.starting = false;
    if (answer.ok && answer.scan) {
        app.entry.consent.given = true;
        app.scan = { ...answer.scan, name: picked.file.name, fileName: picked.file.name, mine: true, seen: false, hasPicture: true, projectId: app.project.id };
        app.pushScan(app.scan);
        app.notifyShell();
        await app.enter();
        return;
    }
    if (answer.code === 'unsupported_image') {
        const code = { type: 'type', bytes: 'bytes', small: 'small', side: 'large', pixels: 'large' }[answer.reason] || 'type';
        app.uploadError = { code, width: picked.width, height: picked.height };
        app.picked = null;
        app.render();
        toast(errorText(app.uploadError), { error: true });
        return;
    }
    if (answer.code === 'network' || answer.code === 'http_error') {
        app.render();
        toast(UPLOAD_ERRORS.network(), { error: true, action: { label: 'Zkusit znovu', onClick: () => start(app) } });
        return;
    }
    if (answer.code === 'not_enabled') { app.leave(app.urls.templates); return; }
    if (answer.code === 'consent_required') app.entry.consent.given = false;
    app.startRefusal = answer.code;
    await app.refreshEntry();
    app.render();
    const hint = startHint(answer.code, app.entry);
    if (hint) toast(hint, { error: true });
}

async function uploadFonts(app, files) {
    const list = [...files].filter((f) => /\.(ttf|otf|woff)$/i.test(f.name));
    if (!list.length) { toast('Nahrajte soubor písma TTF, OTF nebo WOFF.', { error: true }); return []; }
    app.fontBusy = true;
    app.render();
    const added = [];
    for (const file of list) {
        const form = new FormData();
        form.append('upload_font_form[file]', file, file.name);
        form.append('upload_font_form[_token]', app.boot.fontToken);
        let body = null;
        try {
            const response = await fetch(app.urls.fontUpload, { method: 'POST', body: form, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            body = await response.json();
        } catch (e) { body = null; }
        if (body && (body.status === 'added' || body.status === 'exists')) {
            added.push(body);
            toast(body.status === 'added' ? `Písmo přidáno: ${body.family || ''} ${body.face || ''}`.trim() : `${file.name}: tento řez už v projektu je.`);
        } else {
            toast(`${file.name}: ${(body && (body.error || body.message)) || 'písmo se nepodařilo nahrát.'}`, { error: true });
        }
    }
    app.fontBusy = false;
    return added;
}
export { uploadFonts };

export const actions = {
    pick: (app) => { const input = app.root.querySelector('#asFile'); if (input) { input.value = ''; input.click(); } },
    fileInput: (app, el) => take(app, el.files),
    start: (app) => start(app),
    consent: (app, el) => { app.consent[Number(el.dataset.i)] = el.checked; app.render(); },
    formatMode: (app, el) => { app.format = { ...app.format, mode: el.dataset.v, choice: null }; app.render(); },
    formatChoice: (app, el) => { app.format = { ...app.format, choice: el.value }; app.render(); if (el.value === 'custom') { const input = app.root.querySelector('[data-k="fcustom"]'); if (input) input.focus(); } },
    formatCustom: (app, el) => { app.format = { ...app.format, custom: el.value.trim() }; app.render(); },
    fontPick: (app) => { const input = app.root.querySelector('#asFontFile'); if (input) { input.value = ''; input.click(); } },
    fontFiles: async (app, el) => { await uploadFonts(app, el.files); await app.refreshEntry(); app.startRefusal = null; app.render(); },
    privacy: () => openDialog(`${dialogHead('Jak s obrázky nakládáme')}
<div class="as-dlg-sec"><b>Co odesíláme a komu.</b><p>Obrázek, texty a barvy z něj, názvy písem a barvy palety projektu posíláme službě Claude společnosti Anthropic (USA), která v obrázku rozpozná texty, tvary a rozvržení. Nikomu dalšímu je nepředáváme.</p></div>
<div class="as-dlg-sec"><b>Kdo obrázek uvidí.</b><p>Lidé, kteří mohou projekt upravovat. Tým WBoost jen při řešení potíží, nebo když sken ohodnotíte jako nepovedený či nám napíšete.</p></div>
<div class="as-dlg-sec"><b>K čemu slouží.</b><p>Jen k vytvoření vašeho návrhu. Anthropic data zaslaná přes API nepoužívá k trénování modelů. My vaše obrázky nepoužíváme k trénování ani k testování služby.</p></div>
<div class="as-dlg-sec"><b>Jak dlouho je držíme.</b><p>My 30 dní od skenu — i když sken zrušíte nebo se nepodaří; potom obrázek i nepřijatý návrh smažeme. Anthropic do 30 dnů; déle, nejvýše 2 roky, jen když obsah vyhodnotí jako porušení svých pravidel.</p></div>
<div class="as-dlg-sec"><b>Co nenahrávat.</b><p>Grafiku, ke které nemáte práva, a obrázky s citlivými osobními údaji.</p></div>
<div class="dlg-f"><button type="button" class="btn sm btn-primary" data-close data-autofocus>Rozumím</button></div>`),
};

// ---- the whole page accepts a drop (S1, S2) ---------------------------------------------------

export function bindPageDrop(app) {
    let depth = 0;
    let veil = null;
    const active = () => !app.scan && !document.querySelector('dialog[open], .modal.show');
    const hasFiles = (event) => event.dataTransfer && [...(event.dataTransfer.types || [])].includes('Files');
    const show = (on) => {
        if (on && !veil) {
            veil = document.createElement('div');
            veil.className = 'as-pagedrop';
            veil.textContent = 'Pusťte obrázek — nahraje se sem';
            document.body.append(veil);
        } else if (!on && veil) {
            veil.remove();
            veil = null;
        }
    };
    app.drop = {
        enter: (event) => { if (!active() || !hasFiles(event)) return; depth += 1; show(true); },
        over: (event) => { if (!active() || !hasFiles(event)) return; event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; },
        leave: () => { if (depth > 0) depth -= 1; if (depth === 0) show(false); },
        drop: (event) => {
            depth = 0;
            show(false);
            if (!active() || !hasFiles(event)) return;
            event.preventDefault();
            take(app, event.dataTransfer.files);
        },
    };
    window.addEventListener('dragenter', app.drop.enter);
    window.addEventListener('dragover', app.drop.over);
    window.addEventListener('dragleave', app.drop.leave);
    window.addEventListener('drop', app.drop.drop);
}

export function unbindPageDrop(app) {
    if (!app.drop) return;
    window.removeEventListener('dragenter', app.drop.enter);
    window.removeEventListener('dragover', app.drop.over);
    window.removeEventListener('dragleave', app.drop.leave);
    window.removeEventListener('drop', app.drop.drop);
}

/** Puts a File (e.g. the original of an earlier scan) on S2 as if the user had picked it. */
export const usePicture = (app, file) => take(app, [file]);
