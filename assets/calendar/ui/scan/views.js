// „Naskenovat design“ — the markup of the action card and of the panel's screens (ux.md §6 C1–C4,
// S1–S3, S5 in the panel). Strings in, strings out: `scan` is the CalendarScan of ../scan.js, every
// value that did not come from this code goes through `esc()`. Classes are the template wizard's
// (`as-*`, assets/styles/nocturne/ai-scan.css) plus the calendar's own in calendar.css.

import { api, bytesCz, creditLine, dateCz, dialogHead, duration, esc, ic, minutesRange, openDialog, pl, scansAhead, zz } from '../../../ai-scan/kit.js';
import { CAL, UPLOAD_ERRORS, failure, noteText, picturesLater, pill, startHint } from '../../../ai-scan/copy.js';
import { ratioLabel } from '../../../ai-scan/file-checks.js';
import { boxStyle, cropStyle, pixelsStyle } from '../../../ai-scan/page/parts.js';
import { elementFact, sampleWeekLabel } from './preview.js';

const ACCEPT = 'image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf';
const PICTURE_ACCEPT = 'image/jpeg,image/png,image/webp,image/svg+xml';
const PREM = `<span class="tp-prem">${ic('sparkle', 's14')}Premium</span>`;
const IN_PROGRESS = ['checking', 'queued', 'running'];
const LONG_AFTER_SECONDS = 180;
/** Under this short side (px) the review says the result may be less precise (file-checks: calendars are taken from 300 px). */
export const LOW_RESOLUTION_SHORT_SIDE = 600;
const STAGES = CAL.stages;
const stageIndex = (id) => STAGES.findIndex((s) => s.id === id);
const design = (scan) => scan.store.getState().doc.design;

// ---- C1 — the action card ---------------------------------------------------------------------

export function card(scan) {
    const entry = scan.entry;
    const current = scan.scan;
    let button;
    let line = '';
    const btn = (label, attrs = '', icon = '') => `<button type="button" class="btn sm btn-tertiary" data-k="scancard" ${attrs}>${icon}${label}</button>`;
    if (!entry) {
        button = btn('Nahrát obrázek', 'disabled', ic('upload'));
    } else if (current && IN_PROGRESS.includes(current.status)) {
        button = btn('Zobrazit průběh', 'data-act="open" data-cal-action="scan-open"');
        const stage = STAGES[Math.max(1, stageIndex(current.stage || 'read'))];
        const text = current.status === 'checking' ? 'Kontrola obrázku…'
            : current.status === 'queued' ? (current.queuePosition > 0 ? `Ve frontě · ${current.queuePosition} před vámi` : 'Ve frontě')
                : `Sken běží — ${stage.label} · <span data-elapsed data-since="${since(scan, current)}">0:00</span>`;
        line = `<span class="as-act-line"><span class="as-spin" style="width:12px;height:12px"></span><span>${text}</span></span>`;
    } else if (current && current.status === 'ready') {
        button = btn('Zkontrolovat návrh', 'data-act="open" data-cal-action="scan-open"');
        line = `<span class="as-act-line">${ic('eye', 's14')}<span>Návrh rozvržení čeká na kontrolu${current.expiresAt ? ` · <span style="white-space:nowrap">vyprší ${dateCz(current.expiresAt)}</span>` : ''}</span></span>`;
    } else if (current && ['stopped', 'failed', 'refused'].includes(current.status)) {
        button = btn('Zobrazit', 'data-act="open" data-cal-action="scan-open"');
        line = `<span class="as-act-line muted">${ic(current.status === 'stopped' ? 'image-off' : 'alert', 's14')}<span>${current.status === 'stopped' ? 'Sken potřebuje jiný obrázek' : 'Sken se nepodařil'}</span></span>`;
    } else if (entry.state === 'locked') {
        button = btn('Požádat o přístup', 'data-act="request"', ic('lock'));
    } else if (entry.state === 'request_sent') {
        button = btn('Žádost odeslána', 'disabled', ic('check'));
        line = `<span class="as-act-line muted">${ic('check-circle', 's14')}<span>Odesláno ${dateCz(entry.requestSentAt)}. Dáme vám vědět.</span></span>`;
    } else if (entry.state === 'limit_reached') {
        button = btn('Nahrát obrázek', 'disabled', ic('upload'));
        line = `<span class="as-act-line muted">${ic('info', 's14')}<span>${esc(startHint('limit_reached', entry))}</span></span>`;
    } else if (entry.state === 'service_waiting') {
        button = btn('Nahrát obrázek', 'disabled', ic('upload'));
        line = `<span class="as-act-line muted">${ic('alert', 's14')}<span>AI sken je dočasně nedostupný. Víme o tom.</span></span>`;
    } else {
        button = btn('Nahrát obrázek', 'data-act="open" data-cal-action="scan-open"', ic('upload'));
        if (entry.state === 'admin_preview') line = `<span class="as-act-line muted">${ic('shield', 's14')}<span>Projekt AI sken zapnutý nemá — vidíte ho jako administrátor.</span></span>`;
    }
    const applied = entry && entry.applied && !current
        ? `<span class="as-act-line muted">${ic('history', 's14')}<span>Rozvržení je převzaté ze skenu. <button type="button" class="as-link" data-act="restore" data-scan="${esc(entry.applied.scanId)}" data-cal-action="scan-restore" data-k="cardrestore"${scan.restoring ? ' disabled' : ''}>Vrátit původní rozvržení</button> jde do ${dateCz(entry.applied.restoreUntil)}.</span></span>` : '';
    return `<section class="as-act" data-region="card" data-cal-scan-card data-state="${esc(entry ? (current ? current.status : entry.state) : 'loading')}" aria-labelledby="calScanT"><span class="as-act-ico" aria-hidden="true">${ic('sparkle', '')}</span><div class="as-act-b"><span class="as-act-t"><span id="calScanT">${CAL.card.title}</span>${PREM}</span><span class="cap">${CAL.card.text}</span>${line}${applied}</div>${button}</section>`;
}

// ---- the panel ----------------------------------------------------------------------------------

export function panel(scan) {
    const view = scan.view();
    const body = { upload: uploadView, chosen: chosenView, wait: waitView, fail: failView, gone: goneView, review: reviewView, pictures: picturesView }[view](scan);
    const title = view === 'review' ? 'Naskenovat design — kontrola' : view === 'pictures' ? 'Naskenovat design — fotky a loga' : 'Naskenovat design';
    const history = scan.entry && scan.entry.historyCount > 0 ? `<a href="${esc(scan.urls.history)}" class="btn xs btn-ghost as-hist" aria-label="Historie skenů">${ic('history', 's14')}<span>Historie skenů</span></a>` : '';
    return `<section class="as-cpanel" data-region="panel" data-deep data-cal-scan-panel data-view="${view}" aria-labelledby="calScanPT">
<div class="as-cpanel-h" data-region="head-${view}"><h2 id="calScanPT" tabindex="-1" data-focus>${ic('sparkle')}${title}</h2>${history}<button type="button" class="btn icon xs btn-ghost" data-act="close" data-cal-action="scan-close" data-k="scanclose" aria-label="Zavřít panel">${ic('x')}</button></div>
${body}</section>`;
}

// ---- S1 ---------------------------------------------------------------------------------------

const tips = () => `<div><h3 style="margin:0 0 10px">S čím to jde nejlépe</h3><ul class="as-tips">${CAL.tipsOk.map((t, i) => `<li class="ok">${ic('check')}<span><span class="sr">Vhodné: </span>${t}</span></li><li class="no">${ic('x')}<span><span class="sr">Nevhodné: </span>${CAL.tipsNo[i]}</span></li>`).join('')}</ul></div>`;
const fileInput = () => `<input type="file" class="sr" id="calScanFile" tabindex="-1" aria-hidden="true" accept="${ACCEPT}" data-chg="fileInput">`;
const errorText = (error) => (error ? (error.code === 'small' ? CAL.uploadSmall : UPLOAD_ERRORS[error.code])(error.width, error.height) : '');

function uploadView(scan) {
    const error = scan.uploadError;
    return `<div class="as-cgrid" data-region="upload" data-deep><div class="as-main" data-region="zone">${fileInput()}
<button type="button" class="gal-drop as-drop" data-act="pick" data-k="drop" data-dropzone="page" aria-describedby="calScanCap${error ? ' calScanErr' : ''}">${ic('sparkle', '')}<b><span class="dt-only">${CAL.drop.bold}</span><span class="ph-only">${CAL.drop.phone}</span></b><span class="dt-only">nebo <u>vyberte soubor</u> z počítače</span><span class="cap" id="calScanCap">${CAL.drop.cap}</span></button>
${error ? `<p class="hint err" id="calScanErr" role="alert" style="margin:0;display:flex;gap:6px">${ic('alert', 's14')}<span>${esc(errorText(error))}</span></p>` : ''}</div>
<div class="as-card" data-region="tips">${tips()}</div></div>`;
}

// ---- S2 ---------------------------------------------------------------------------------------

/** Why the start button is disabled right now: a refusal code, or null. */
export function blocked(scan) {
    const entry = scan.entry;
    if (!entry) return 'temporarily_unavailable';
    if (entry.state === 'service_waiting') return 'temporarily_unavailable';
    if (entry.state === 'limit_reached') return 'limit_reached';
    if (['locked', 'request_sent'].includes(entry.state)) return 'not_enabled';
    if (!entry.consent.given && !(scan.consent[0] && scan.consent[1])) return 'consent_required';
    return scan.startRefusal;
}

function checks(scan) {
    const picked = scan.picked;
    const ok = (text) => `<li>${ic('check')}${text}</li>`;
    const warn = (text) => `<li class="warn">${ic('alert')}<span><span class="sr">Varování: </span>${text}</span></li>`;
    const rows = [ok('Formát a velikost jsou v pořádku')];
    if (picked.type === 'heic') rows.push(warn('Fotka z telefonu (HEIC). Pokud jde o fotku tisku, výsledek bude jen přibližný.'));
    if (picked.type === 'pdf') rows.push(ok('PDF — naskenujeme jeho první stránku jako obrázek'));
    if (picked.width) rows.push(picked.warnings.includes('small') ? warn(CAL.lowResolutionCheck) : ok('Dostatečné rozlišení'));
    if (picked.sharpnessChecked) rows.push(picked.warnings.includes('blurry') ? warn('Obrázek je rozmazaný — výsledek bude jen přibližný') : ok('Obrázek je ostrý'));
    if (picked.width) {
        const page = design(scan).page;
        const same = Math.abs(picked.width / picked.height / (page.width / page.height) - 1) <= 0.04;
        rows.push(same ? ok(CAL.ratioOk) : warn(esc(CAL.ratioOther(fmt(page.width), fmt(page.height)))));
    }
    return `<ul class="as-checks" aria-label="Kontrola souboru v prohlížeči">${rows.join('')}</ul>`;
}
const fmt = (n) => String(Math.round(n * 10) / 10).replace('.', ',');

const CONSENT_SENT = 'Obrázek a údaje z něj odešleme ke zpracování službě Claude společnosti Anthropic (USA). Anthropic je maže do 30 dnů. My uchováme původní obrázek 30 dní.';
const PRIVACY_LINK = '<button type="button" class="as-link" data-act="privacy" data-k="privacy">Jak s obrázky nakládáme</button>';
function consentBlock(scan) {
    const check = (i, html) => `<label class="as-check"><input type="checkbox" class="sr" data-chg="consent" data-i="${i}" data-k="consent${i}"${scan.consent[i] ? ' checked' : ''}><span class="cb" aria-hidden="true">${ic('check', 's14')}</span><span>${html}</span></label>`;
    if (!scan.entry.consent.given) {
        return `<div class="as-consent" role="group" aria-label="Souhlasy">${check(0, `<b>Souhlasím, že obrázek a údaje z něj (texty, barvy, názvy písem) odešlete ke zpracování službě Claude společnosti Anthropic (USA).</b> Anthropic je maže do 30 dnů. My uchováme původní obrázek 30 dní. ${PRIVACY_LINK}`)}${check(1, '<b>Potvrzuji, že tuto grafiku smím použít.</b>')}</div>`;
    }
    return `<div class="as-consent"><p class="as-ph-note">${ic('info', 's14')}<span>${CONSENT_SENT} ${PRIVACY_LINK}</span></p><p class="as-ph-note">${ic('info', 's14')}<span>Spuštěním potvrzujete, že tuto grafiku smíte použít.</span></p></div>`;
}

function startBlock(scan) {
    const entry = scan.entry;
    const code = blocked(scan);
    const hint = startHint(code, entry);
    const position = entry.queue.position || 0;
    const startIn = minutesRange(entry.queue.estimatedStartSeconds);
    const doneIn = minutesRange(entry.queue.estimatedFinishSeconds);
    const queue = !code && position > 0 && startIn && doneIn
        ? `<p class="as-qline" style="margin:0">${ic('clock')}<span>${scansAhead(position)}. Začneme ${startIn === 'během chvíle' ? startIn : `asi za ${startIn}`}, hotovo asi za ${doneIn}.</span></p>` : '';
    const credits = entry.credits;
    const credit = code === 'limit_reached' ? `V projektu tento měsíc zbývá 0 ${zz(credits.limit)} ${credits.limit}` : creditLine(credits);
    return `${queue}<button type="button" class="btn btn-primary w-100" data-k="start" data-act="start" data-cal-action="scan-start"${code || scan.starting ? ' disabled aria-describedby="calScanStartHint"' : ''}>${ic('sparkle')}${scan.starting ? 'Nahrávám obrázek…' : 'Spustit rozpoznávání'}</button>
${hint ? `<p class="hint" id="calScanStartHint" style="margin:-6px 0 0;text-align:center">${esc(hint)}</p>` : ''}
<p class="as-credit" style="margin:0">${ic('sparkle', 's14')}<span>${esc(credit)}</span></p>`;
}

function chosenView(scan) {
    const picked = scan.picked;
    const facts = [picked.width ? `${picked.width} × ${picked.height} px` : null, picked.width ? ratioLabel(picked.width, picked.height) : null, bytesCz(picked.file.size)].filter(Boolean).join(' · ');
    const thumb = picked.previewUrl
        ? `<div class="as-frame"><img src="${esc(picked.previewUrl)}" alt="Náhled vybraného obrázku"></div>`
        : `<div class="as-file-th none">${ic('image', '')}<span class="cap">Náhled uvidíte po nahrání</span></div>`;
    const has = design(scan).layout ? `<p class="as-ph-note">${ic('info', 's14')}<span>${CAL.hasLayout}</span></p>` : '';
    return `<div class="as-cgrid" data-region="chosen" data-deep>
<div class="as-main" data-region="file"><div class="as-card" data-dropzone="page">${fileInput()}${thumb}<div class="as-file-b"><b>${esc(picked.file.name)}</b><span class="cap">${facts}</span>${checks(scan)}
<div><button type="button" class="btn xs btn-tertiary" data-act="pick" data-k="other">${ic('refresh', 's14')}Vybrat jiný obrázek</button></div></div>
<p class="cap" style="margin:0">Zatím jsme nic neodeslali — tohle jsme zjistili ve vašem prohlížeči.</p></div></div>
<div class="as-card" data-region="start"><h3>Co převezmeme</h3><p style="margin:0;font-size:13px;line-height:1.55;color:var(--text-2)">${CAL.takeOver}</p>${has}${consentBlock(scan)}${startBlock(scan)}</div></div>`;
}

// ---- S3 ---------------------------------------------------------------------------------------

function since(scan, row) {
    const created = Date.parse(row.createdAt);
    const first = scan.run ? scan.run.first : Date.now();
    return (row.try || 1) === 1 && Number.isFinite(created) && created <= first ? created : first;
}
const overEstimate = (scan) => scan.scan.status === 'queued' && scan.run && scan.run.queueDeadline !== null && Date.now() > scan.run.queueDeadline && scan.scan.queuePosition > 0;
const isLong = (scan) => scan.scan.status === 'running' && scan.run && scan.run.runBase !== null && Date.now() - scan.run.runBase > LONG_AFTER_SECONDS * 1000;

export function announcement(row) {
    if (row.status === 'checking') return 'Kontrolujeme obrázek.';
    if (row.status === 'queued') return row.waiting ? 'Sken čeká. AI sken teď nepracuje.' : row.restarted ? 'Sken se přerušil, spouštíme ho znovu.' : 'Obrázek je v pořádku. Čeká ve frontě.';
    if (row.status === 'running') { const s = STAGES[stageIndex(row.stage)]; return s ? `Sken běží. ${s.label}.` : 'Sken běží.'; }
    return fail(row).title;
}

/**
 * What the server's stage log says about one stage (`stages` of the status document:
 * `{ seconds, note, noteParams }`) → `{ took: seconds | null, note: [code, params] | null }`. The
 * browser keeps no durations of its own — a reload, another tab and somebody else show the same.
 */
function stageSeen(scan, id) {
    const logged = (scan.scan && scan.scan.stages && scan.scan.stages[id]) || {};
    return { took: typeof logged.seconds === 'number' ? logged.seconds : null, note: logged.note ? [logged.note, logged.noteParams || null] : null };
}
/** The live note is about another stage (what the reading found): it stays with that row. */
const notedElsewhere = (row, stageId) => Boolean(row.note) && Object.entries(row.stages || {}).some(([id, x]) => id !== stageId && x && x.note === row.note);

function stageList(scan, { now = -1, failed = false } = {}) {
    const row = scan.scan;
    return `<ol class="as-stages" aria-label="Průběh skenu">${STAGES.map((s, i) => {
        const state = failed && i === now ? 'fail' : i < now ? 'done' : i === now ? 'now' : 'todo';
        const sr = { done: 'hotovo', now: 'právě běží', todo: 'čeká', fail: 'nepodařilo se' }[state];
        const icon = state === 'done' ? ic('check') : state === 'now' ? '<span class="as-spin"></span>' : state === 'fail' ? ic('x-circle') : '';
        const seen = stageSeen(scan, s.id);
        const live = state === 'now' && !failed && !notedElsewhere(row, s.id) ? noteText(row.note, row.noteParams) : null;
        const note = live || (state === 'done' && seen.note ? noteText(seen.note[0], seen.note[1]) : null);
        const took = state === 'done' && seen.took !== null ? duration(seen.took) : '';
        const round = state === 'now' && s.id === 'refine' && row.round >= 1 ? ` <span class="pill pch">kolo ${Math.min(2, row.round)} ze 2</span>` : '';
        return `<li class="${state}"><span class="ic">${icon}</span><span>${s.label}${round}<span class="sr"> — ${sr}</span></span><span class="dur">${took}</span>${note ? `<span class="note">${esc(note)}</span>` : ''}</li>`;
    }).join('')}</ol>`;
}
const bar = (now) => `<div class="as-bar" role="img" aria-label="Hotové fáze: ${Math.max(0, now)} z ${STAGES.length}">${STAGES.map((s, i) => `<i class="${i < now ? 'done' : ''}"></i>`).join('')}</div>`;

function statusCard(scan) {
    const row = scan.scan;
    const elapsed = `<b data-elapsed data-since="${since(scan, row)}">0:00</b>`;
    const buttons = `<div class="as-row"><button type="button" class="btn sm btn-ghost" data-act="cancel" data-k="cancel"${scan.busy === 'cancel' ? ' disabled' : ''}>Zrušit sken</button></div>`;
    let head;
    let body = '';
    if (row.status === 'running') {
        const now = Math.max(1, stageIndex(row.stage || (scan.run && scan.run.last) || 'read'));
        const stage = STAGES[now];
        const long = isLong(scan);
        const doneIn = minutesRange(row.estimatedFinishSeconds);
        head = `<div class="as-status-t"><h3 class="as-status-h">${stage.title}</h3></div><p class="as-time" style="margin:0">${elapsed}${long ? '' : doneIn ? ` · hotovo ${doneIn === 'během chvíle' ? doneIn : `asi za ${doneIn}`}` : ' · obvykle 1–3 min'}</p>${bar(now)}${long ? '<p class="as-long" style="margin:0">Trvá to déle než obvykle — nejdéle asi 12 minut. Klidně odejděte, dáme vědět.</p>' : ''}`;
        const note = notedElsewhere(row, stage.id) ? null : noteText(row.note, row.noteParams);
        body = `<p class="as-nowline as-phone"><span class="as-spin"></span><span><b>${stage.label}</b>${note ? `<br><span class="cap">${esc(note)}</span>` : ''}</span></p><details class="as-details as-prubeh"${scan.stagesOpen ?? window.innerWidth > 640 ? ' open' : ''} data-k="prubeh"><summary data-act="toggleStages">${ic('chev-right')}Průběh</summary>${stageList(scan, { now })}</details>`;
    } else {
        const position = row.queuePosition || 0;
        const startIn = minutesRange(row.estimatedStartSeconds);
        const doneIn = minutesRange(row.estimatedFinishSeconds);
        let title = 'Čeká ve frontě';
        let text;
        let spin = true;
        if (row.waiting) {
            title = 'Sken čeká';
            text = 'AI sken teď nepracuje. Víme o tom. Váš sken počká ve frontě nejdéle hodinu — dáme vědět, jak dopadne. Nezapočítá se, pokud ho nestihneme.';
            spin = false;
        } else if (row.status === 'checking') {
            title = 'Kontrolujeme obrázek…';
            text = 'Trvá to pár vteřin. Ověřujeme, že z něj půjde udělat návrh.';
        } else if (row.restarted) {
            text = 'Sken se přerušil, spouštíme ho znovu.';
        } else if (overEstimate(scan)) {
            text = `Sken před vámi trvá déle než obvykle. Jste stále ${position}. v pořadí.`;
        } else if (position > 0) {
            text = `${scansAhead(position)} (jeden právě běží).${startIn && doneIn ? `<br>Začneme ${startIn === 'během chvíle' ? startIn : `asi za ${startIn}`}, hotovo asi za ${doneIn}.` : ''}`;
        } else {
            text = 'Jste na řadě — začneme během chvíle.';
        }
        head = `<div class="as-status-t">${spin ? '<span class="as-spin"></span>' : `<span class="as-result-ico pch" style="width:32px;height:32px">${ic('clock')}</span>`}<h3 class="as-status-h">${title}</h3>${pill(row.status === 'checking' ? 'checking' : 'queued')}</div><p class="as-status-p">${text}</p><p class="as-time" style="margin:0">Uplynulo ${elapsed}</p>`;
    }
    return `<section class="as-card as-status" aria-label="Stav skenu">${head}${body}<div class="as-leave">${ic('check-circle')}<span>${CAL.leave}</span></div>${buttons}</section>`;
}

/**
 * The uploaded picture as this browser can show it. The status document says which copy exists
 * (`picture`: `working` once the pre-check passed, `original` before that unless it is a HEIC or
 * a PDF, null when there is none) — the panel never asks for a file that is not there.
 */
function picture(scan, alt) {
    const which = scan.scan.picture;
    return which ? `<img src="${esc(scan.image(which))}" alt="${esc(alt)}">` : '';
}

function waitView(scan) {
    const row = scan.scan;
    const running = row.status === 'running';
    const original = picture(scan, 'Původní obrázek');
    // The latest render of the running run (`previewCandidate`; `previewSeq` only says "changed").
    // After an automatic restart the renders of the run that broke are gone from the server: the
    // last one this panel showed stays (the address is unchanged — the browser's cache answers).
    const key0 = `${row.id}:${row.try || 1}`;
    if (running && row.previewCandidate) scan.lastPreview = { key: key0, url: scan.image('candidate', `?n=${row.previewCandidate}&v=${row.previewSeq || 0}`), seq: row.previewSeq || 0 };
    else if (!(row.status === 'queued' && row.restarted) || !scan.lastPreview || scan.lastPreview.key !== key0) scan.lastPreview = null;
    const seq = scan.lastPreview ? scan.lastPreview.seq : 0;
    let preview;
    let caption;
    let key = `${seq}-${running ? 'r' : 'w'}`;
    if (seq > 0) {
        preview = `<div class="as-frame${running ? ' busy' : ''}"><img class="fade-in" src="${esc(scan.lastPreview.url)}" alt="Průběžný náhled rozvržení" onerror="this.onerror=null;this.closest('.as-frame').classList.add('none')"><span class="as-noimg">${ic('image', '')}Náhled se právě obnovuje</span></div>`;
        caption = !running ? 'Poslední náhled před přerušením' : row.round >= 1 ? `Náhled po ${row.round}. kole porovnání` : 'První náhled — ještě ho doladíme';
    } else {
        preview = original ? `<div class="as-frame${running ? ' pending' : ''} dim">${picture(scan, '')}</div>` : '';
        caption = running ? 'První náhled uvidíte za pár vteřin.' : 'Náhled se začne tvořit, až sken přijde na řadu.';
        key = `${key}-${row.picture || 'none'}`;
    }
    return `<div class="as-cgrid side-first" data-region="wait" data-deep><div class="as-main as-cpics" data-region="pics" data-deep>
<figure class="as-pic" data-region="orig-${row.picture || 'none'}"><figcaption><span class="lbl">Váš obrázek</span></figcaption><div class="as-frame${original ? '' : ' none'}">${original}<span class="as-noimg">${ic('image', '')}Náhled uvidíte po kontrole obrázku</span></div></figure>
<figure class="as-pic" data-region="prev-${key}"><figcaption><span class="lbl">Průběžný náhled</span><span class="cap">${caption}</span></figcaption>${preview}</figure></div>
<div data-region="status">${statusCard(scan)}</div></div>
<p class="cap" style="margin:0" data-region="usable">${CAL.usable}</p>`;
}

// ---- S5 ---------------------------------------------------------------------------------------

/** The result card's copy: the shared one, with the calendar's two own cards. */
function fail(row) {
    const f = failure(row.status, row.code);
    const own = row.status === 'stopped' ? CAL.failure[row.code] : null;
    return own ? { ...f, ...own } : f;
}

function failView(scan) {
    const row = scan.scan;
    const f = fail(row);
    const run = scan.run || {};
    const tryTail = (row.try || 1) >= 2 ? ` · ${row.try}. pokus` : '';
    const state = scan.entry ? scan.entry.state : 'service_waiting';
    const blocker = scan.actionRefusal
        || (state === 'limit_reached' ? 'limit_reached' : state === 'service_waiting' ? 'temporarily_unavailable' : ['locked', 'request_sent'].includes(state) ? 'not_enabled' : null);
    const exhausted = row.status === 'failed' && (!row.canRetry || ['no_retries_left', 'source_unscannable'].includes(scan.actionRefusal));
    const hint = blocker && !exhausted ? startHint(blocker, scan.entry) : null;

    let stages = '';
    if (f.stop) {
        const check = stageSeen(scan, 'check');
        stages = `<ol class="as-stages" aria-label="Průběh skenu"><li class="stop"><span class="ic">${ic('info')}</span><span>Kontrola obrázku — zastaveno</span><span class="dur">${check.took !== null ? duration(check.took) : ''}</span></li></ol>`;
    } else if (!f.noStages) {
        const at = stageIndex(row.stage || run.last || '');
        if (at >= 0) stages = stageList(scan, { now: at, failed: true });
    }

    const acts = [];
    if (f.upload) acts.push(`<button type="button" class="btn sm btn-primary" data-act="other" data-k="other">${ic('upload')}Nahrát jiný obrázek</button>`);
    if (f.retry && !exhausted) acts.push(`<button type="button" class="btn sm ${hint ? 'btn-tertiary' : 'btn-primary'}" data-act="retry" data-k="retry"${hint || scan.busy === 'retry' ? ' disabled aria-describedby="calScanFHint"' : ''}>${ic('refresh')}Zkusit znovu</button>`);
    if (f.anyway && row.canContinue) acts.push(`<button type="button" class="btn sm btn-tertiary" data-act="continueAnyway" data-k="anyway"${hint || scan.busy === 'continue' ? ' disabled aria-describedby="calScanFHint"' : ''}>Zkusit přesto</button>`);
    if (f.other || (f.retry && exhausted)) acts.push(`<button type="button" class="btn sm ${exhausted ? 'btn-primary' : 'btn-tertiary'}" data-act="other" data-k="other2">Vybrat jiný obrázek</button>`);

    const cardHtml = `<section class="as-card as-status" aria-label="Výsledek skenu"><div class="as-status-t"><span class="as-result-ico ${f.tone}">${ic(f.icon, '')}</span>${pill(row.status, tryTail)}</div><h3 class="as-status-h">${f.title}</h3><p class="as-status-p">${f.text}</p><p class="cap" style="margin:0">${ic('check', 's12')} Sken se nezapočítal.</p>
${stages}${exhausted ? '<p style="margin:0;font-size:13px;font-weight:600">Tento obrázek se nám nedaří zpracovat. Zkuste jiný.</p>' : ''}<div class="as-row">${acts.join('')}</div>
${f.anyway && row.canContinue && scan.entry ? `<p class="cap" style="margin:0">${ic('sparkle', 's14')} ${esc(creditLine(scan.entry.credits, 'Zkusit přesto spotřebuje 1 sken').replace('tento měsíc ', ''))}. Jde to jen jednou.</p>` : ''}${hint ? `<p class="hint" id="calScanFHint" style="margin:0">${ic('info', 's14')} ${esc(hint)}</p>` : ''}
<div class="as-status-end"><button type="button" class="btn sm ghost danger" data-act="discard" data-cal-action="scan-discard" data-k="discard"${scan.busy === 'discard' ? ' disabled' : ''}>${ic('trash')}Zahodit</button><span class="cap">Jinak sken zmizí sám za 30 dní.</span></div></section>`;

    return `<div class="as-cgrid side-first" data-region="fail" data-deep><div class="as-main" data-region="pic-${row.picture || 'none'}"><figure class="as-pic as-cpic-one"><figcaption><span class="lbl">Váš obrázek</span></figcaption><div class="as-frame${row.picture ? '' : ' none'}">${picture(scan, 'Původní obrázek')}<span class="as-noimg">${ic('image', '')}${row.hasPicture === false ? 'Obrázek už nemáme uložený' : 'Náhled tohoto obrázku prohlížeč neumí zobrazit'}</span></div></figure></div><div data-region="result">${cardHtml}</div></div>`;
}

// ---- a scan that is finished with ---------------------------------------------------------------

function goneView(scan) {
    const row = scan.scan;
    const applied = row.status === 'accepted' ? (scan.proposal && scan.proposal.applied) || null : null;
    const copy = {
        cancelled: ['Sken zrušen', 'Nahrajte obrázek znovu, nebo zkuste jiný.'],
        discarded: ['Sken byl zahozen', 'Návrh i obrázek jsme smazali.'],
        expired: ['Sken vypršel', 'Po 30 dnech jsme obrázek i nepřijatý návrh smazali.'],
        accepted: applied && applied.restored ? ['Původní rozvržení je zpět', 'Rozvržení z tohoto skenu jste vrátili. Texty, fotky a obsah stránek zůstaly.']
            : ['Rozvržení z tohoto skenu je použité', applied && applied.restorable ? `Původní rozvržení můžete vrátit do ${dateCz(applied.restoreUntil)}. Texty, fotky a obsah stránek zůstanou.` : 'Původní rozvržení už vrátit nejde.'],
    }[row.status] || ['Sken už není k dispozici', ''];
    const restore = applied && applied.restorable ? `<button type="button" class="btn sm btn-tertiary" data-act="restore" data-scan="${esc(row.id)}" data-cal-action="scan-restore" data-k="restore"${scan.restoring ? ' disabled' : ''}>${ic('undo')}Vrátit původní rozvržení</button>` : '';
    const upload = scan.entry && scan.entry.canStart ? `<button type="button" class="btn sm ${row.status === 'accepted' ? 'btn-tertiary' : 'btn-primary'}" data-act="other" data-k="other">${ic('upload')}Nahrát obrázek</button>` : '';
    return `<section class="as-card as-status" data-region="gone" style="max-width:560px"><div class="as-status-t"><span class="as-result-ico neutral">${ic(row.status === 'accepted' ? 'check' : 'info', '')}</span>${row.status === 'accepted' ? '<span class="pill mint">Rozvržení použito</span>' : pill(row.status)}</div><h3 class="as-status-h">${copy[0]}</h3><p class="as-status-p">${esc(copy[1])}</p><div class="as-row">${restore}${upload}</div></section>`;
}

// ---- C4 — review ----------------------------------------------------------------------------------

// The elements a calendar layout has, as outlines over the proposal (`Ukázat vrstvy`): box key →
// letter tag. Boxes are page millimetres; an element that is hidden or whose switch is off has none.
const LAYER_TAGS = { photo: 'F', head: 'H', cal: 'K', month: 'M', wk: 'T', cap: 'P', logo: 'L', qr: 'Q' };
const LAYER_FEATURE = { cap: 'captions', wk: 'weekNumbers', qr: 'qr' };
const pc = (v) => Math.round(v * 100) / 100;

/** `{ x, y, w, h }` in page mm → the percent style of a box over the page, cut at the page edge. */
function mmStyle(box, page) {
    const x0 = Math.max(0, box.x);
    const y0 = Math.max(0, box.y);
    const x1 = Math.min(page.width, box.x + box.w);
    const y1 = Math.min(page.height, box.y + box.h);
    return `left:${pc((x0 / page.width) * 100)}%;top:${pc((y0 / page.height) * 100)}%;width:${pc((Math.max(0, x1 - x0) / page.width) * 100)}%;height:${pc((Math.max(0, y1 - y0) / page.height) * 100)}%`;
}

/** The boxes of the proposed layout that are on the page: `[key, box]` in stack-neutral order. */
export function layerBoxes(scan) {
    const p = scan.proposal;
    const d = design(scan);
    const boxes = (p.layout && p.layout.boxes) || {};
    return Object.keys(LAYER_TAGS).filter((key) => {
        const box = boxes[key];
        if (!box || box.hidden || !(box.w > 0) || !(box.h > 0)) return false;
        const feature = LAYER_FEATURE[key];
        return !feature || Boolean(d.features[feature]) || scan.ticked.has(feature);
    }).map((key) => [key, boxes[key]]);
}

function layerOverlays(scan) {
    const page = design(scan).page;
    return layerBoxes(scan).map(([key, box]) => `<span class="as-box${scan.layers ? '' : ' ghost'}" data-box="${key}" style="${mmStyle(box, page)}"><span class="as-ltag">${LAYER_TAGS[key]}</span></span>`).join('');
}

function layerLegend(scan) {
    const rows = layerBoxes(scan).map(([key]) => `<span><span class="as-ltag">${LAYER_TAGS[key]}</span>${CAL.elements[key]}</span>`).join('');
    return rows ? `<div class="as-legend" aria-label="Vrstvy návrhu">${rows}</div>` : '';
}

/** A box of the layout (page mm) in the pixels of the uploaded picture, or null. */
export function sourceBox(scan, box) {
    const p = scan.proposal;
    const source = p.source && p.source.width && p.source.height ? p.source : null;
    if (!source || !box) return null;
    const page = design(scan).page;
    const [px, py, pw, ph] = p.pageBox || [0, 0, source.width, source.height];
    const x0 = Math.max(0, px + (Math.max(0, box.x) / page.width) * pw);
    const y0 = Math.max(0, py + (Math.max(0, box.y) / page.height) * ph);
    const x1 = Math.min(source.width, px + (Math.min(page.width, box.x + box.w) / page.width) * pw);
    const y1 = Math.min(source.height, py + (Math.min(page.height, box.y + box.h) / page.height) * ph);
    return x1 - x0 >= 2 && y1 - y0 >= 2 ? [x0, y0, x1 - x0, y1 - y0] : null;
}

/** `Vedle sebe | Posuvník` around the original and the live proposal (`[data-proposal]` is filled by scan.js). */
function compare(scan) {
    const p = scan.proposal;
    const page = design(scan).page;
    const ratio = `aspect-ratio:${page.width} / ${page.height}`;
    const source = p.source && p.source.width && p.source.height ? p.source : null;
    const box = source ? p.pageBox || [0, 0, source.width, source.height] : null;
    const original = box
        ? `<span class="cal-scan-orig" role="img" aria-label="Vaše stránka" style="${pixelsStyle(scan.image('working'), box, source)}"></span>`
        : `<img class="cal-scan-orig" src="${esc(scan.image('working'))}" alt="Vaše stránka">`;
    const proposal = `<span class="cal-scan-prop" data-proposal role="img" aria-label="Návrh rozvržení"></span>${layerOverlays(scan)}`;
    if (scan.compare === 'slider') {
        return `<div class="as-compare as-cal-cmp"><div class="as-frame" style="${ratio}">${proposal}<div class="as-cmp-top" style="--pos:${scan.pos}%">${original}</div><span class="as-cmp-lab l">Vaše stránka</span><span class="as-cmp-lab r">Návrh rozvržení</span><button type="button" class="as-handle" style="--pos:${scan.pos}%" role="slider" aria-label="Posuvník porovnání" aria-orientation="horizontal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round(scan.pos)}" data-k="handle"><span class="knob">${ic('swap-horizontal')}</span></button></div></div>`;
    }
    return `<div class="as-side2 stack"><figure class="as-pic"><figcaption><span class="lbl">Vaše stránka</span></figcaption><div class="as-frame" style="${ratio}">${original}</div></figure><figure class="as-pic"><figcaption><span class="lbl">Návrh rozvržení</span></figcaption><div class="as-frame" style="${ratio}">${proposal}</div></figure></div>`;
}

/** Arrow keys on the comparison slider; pointer drags are bound in `bindSlider`. */
export function sliderKey(scan, event) {
    const handle = event.target.closest ? event.target.closest('.as-handle') : null;
    if (!handle) return;
    const step = { ArrowLeft: -4, ArrowRight: 4, Home: -100, End: 100 }[event.key];
    if (!step) return;
    event.preventDefault();
    setPos(scan, scan.pos + step);
}
function setPos(scan, value) {
    scan.pos = Math.max(0, Math.min(100, value));
    const box = scan.root && scan.root.querySelector('.as-compare');
    if (!box) return;
    box.querySelectorAll('.as-cmp-top, .as-handle').forEach((el) => el.style.setProperty('--pos', `${scan.pos}%`));
    const handle = box.querySelector('.as-handle');
    if (handle) handle.setAttribute('aria-valuenow', String(Math.round(scan.pos)));
}
/** Pointer drag of the comparison slider (delegated on the panel root once per mount). */
export function bindSlider(scan, root) {
    let dragging = false;
    const move = (event) => {
        const frame = root.querySelector('.as-compare .as-frame');
        if (!dragging || !frame) return;
        const rect = frame.getBoundingClientRect();
        setPos(scan, ((event.clientX - rect.left) / Math.max(1, rect.width)) * 100);
    };
    const down = (event) => {
        if (!event.target.closest || !event.target.closest('.as-compare')) return;
        dragging = true;
        move(event);
    };
    const up = () => { dragging = false; };
    root.addEventListener('pointerdown', down);
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
    return () => {
        root.removeEventListener('pointerdown', down);
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', up);
    };
}

function recognised(scan) {
    const p = scan.proposal;
    const page = design(scan).page;
    const rows = p.recognised.map((item) => {
        const [cls, icon, sr] = CAL.states[item.state] || CAL.states.found;
        const fact = item.state === 'absent' ? (item.kept ? CAL.kept : CAL.absent) : `${item.state === 'approximate' ? 'přibližně' : ''}${item.state === 'approximate' && elementFact(item.element, p.layout, page) ? ' — ' : ''}${elementFact(item.element, p.layout, page)}`;
        return `<li class="${cls}"${LAYER_TAGS[item.element] ? ` data-hl="${esc(item.element)}"` : ''}>${ic(icon)}<b>${CAL.elements[item.element] || esc(item.element)}<span class="sr"> — ${sr}</span></b><span class="f">${esc(fact)}</span></li>`;
    }).join('');
    return rows ? `<div class="as-card" data-region="rec"><h3>Co jsme rozpoznali</h3><ul class="as-rec">${rows}</ul><p class="cap" style="margin:0">Co jsme nenašli, zůstane v kalendáři nastavené jako dosud. Sken nic nevypíná.</p></div>` : '';
}

function fontsCard(scan) {
    const p = scan.proposal;
    const rows = (p.fonts || []).map((font) => {
        const name = CAL.fontGroups[font.group];
        const text = (CAL.fontPattern[font.pattern] || CAL.fontPattern.undetermined)(font.face ? esc(font.face) : '');
        return name ? `<li><span>Písmo ${name}: ${text}.</span></li>` : '';
    }).join('');
    const none = p.projectFaces === 0
        ? `<div class="as-it"><p class="as-it-t">${ic('alert', 's16')}<span>Projekt nemá žádné písmo</span></p><p>Kalendář se vykreslí náhradním písmem a v tisku může vypadat jinak.</p><div class="as-it-acts"><a class="btn xs btn-tertiary" href="${esc(scan.urls.fonts)}" target="_blank" rel="noopener">${ic('upload', 's14')}Nahrát písma</a></div></div>` : '';
    if (!rows && !none) return '';
    return `<div class="as-card" data-region="fonts"><h3>Písma</h3>${none}${rows ? `<div class="as-info"><ul>${rows}</ul></div><p class="cap" style="margin:0">Písma změníte v dalším kroku Úpravy prvků.</p>` : ''}</div>`;
}

function cantCard(scan) {
    const p = scan.proposal;
    const source = p.source && p.source.width ? p.source : null;
    const cant = (p.notExpressible || []).map((item) => {
        const crop = source && item.box ? `<span class="as-cantc" role="img" aria-label="Výřez původní stránky" style="${cropStyle(scan.image('working'), item.box, source, { pad: 0.15, minRatio: 1.6, maxRatio: 5 })}"></span>` : '<span></span>';
        return `<li>${crop}<span>${CAL.notExpressible[item.code] || ''}</span></li>`;
    }).join('');
    const features = (p.features || []).filter((f) => CAL.features[f.key]).map((f) => `<label class="as-check"><input type="checkbox" class="sr" data-chg="feature" data-key="${esc(f.key)}" data-k="feat${esc(f.key)}"${scan.ticked.has(f.key) ? ' checked' : ''}><span class="cb" aria-hidden="true">${ic('check', 's14')}</span><span>${CAL.features[f.key]}</span></label>`).join('');
    if (!cant && !features) return '';
    return `<div class="as-card" data-region="cant">${cant ? `<h3>Tohle kalendář neumí</h3><ul class="as-cantl">${cant}</ul>` : ''}${features ? `<h3>Co stránka ukazuje a kalendář má vypnuté</h3>${features}<p class="cap" style="margin:0">Bez zaškrtnutí převezmeme jen polohy, písma a barvy.</p>` : ''}</div>`;
}

function infoCard(scan) {
    const p = scan.proposal;
    // a two-week page reduced to one week is said at the top of the review (`fortnightAlert`), not here
    const rows = (p.notes || []).filter((note) => note.code !== 'fortnight_left_half').map((note) => (CAL.notes[note.code] ? `<li><span>${esc(CAL.notes[note.code](note))}</span></li>` : ''));
    if (p.approximate && !(p.notes || []).some((n) => n.code === 'colours_estimated')) rows.push(`<li><span>${CAL.notes.colours_estimated()}</span></li>`);
    if (p.source && p.source.width && Math.min(p.source.width, p.source.height) < LOW_RESOLUTION_SHORT_SIDE) rows.push(`<li><span>${CAL.lowResolution}</span></li>`);
    if (p.unfinished) rows.push('<li><span>Sken jsme museli ukončit dřív — návrh je nedokončený. Zkontrolujte ho pečlivěji.</span></li>');
    const list = rows.filter(Boolean).join('');
    return list ? `<div class="as-card" data-region="info"><h3>Pro informaci</h3><div class="as-info" style="border-top:0;padding-top:0"><ul>${list}</ul></div></div>` : '';
}

/** „Jak se sken povedl?“ (ux.md S4 — Feedback), the template wizard's block. */
function feedback(scan) {
    const rated = scan.proposal.rating !== null && scan.proposal.rating !== undefined;
    const done = scan.feedback === 'sent' || (rated && scan.feedback !== 'down');
    return `<div class="as-card" data-region="fb"><div class="as-fb" style="border-top:0;padding-top:0"><span>Jak se sken povedl?</span>${done ? `<span class="as-muted" role="status">${ic('check-circle', 's14')} Díky za hodnocení</span>` : `<span class="as-fb-b"><button type="button" class="btn xs btn-tertiary" aria-pressed="false" data-act="rate" data-v="1" data-k="fbu">${ic('thumb-up', 's14')}Dobře</button><button type="button" class="btn xs btn-tertiary" aria-pressed="${scan.feedback === 'down'}" data-act="rate" data-v="-1" data-k="fbd">${ic('thumb-down', 's14')}Špatně</button></span>`}${scan.feedback === 'down' ? `<div class="as-fb-form"><label class="inp sm"><input type="text" maxlength="500" placeholder="Co se nepovedlo? (nepovinné)" aria-label="Co se nepovedlo? (nepovinné)" data-k="fbt" data-enter="sendFeedback"></label><button type="button" class="btn xs btn-tertiary" data-act="sendFeedback" data-k="fbs">Odeslat</button></div>` : ''}</div></div>`;
}

/** How many places for a picture stay empty when the layout is applied now. */
const emptyPictures = (p) => p.pictures.filter((item) => !item.picture && !item.current).length;

function applyButton(scan) {
    const p = scan.proposal;
    const label = `${p.hasLayout ? 'Nahradit rozvržení' : 'Použít rozvržení'}${picturesLater(emptyPictures(p))}`;
    if (scan.store.isDirty()) {
        return `<button type="button" class="btn sm btn-tertiary" data-act="save" data-k="scansave">Uložit</button><button type="button" class="btn sm btn-primary" disabled aria-describedby="calScanApplyHint" data-cal-action="scan-apply" data-k="apply">${label}${ic('arrow-right')}</button>`;
    }
    return `<button type="button" class="btn sm btn-primary" data-act="apply" data-cal-action="scan-apply" data-k="apply"${scan.busy === 'apply' ? ' disabled' : ''}>${label}${ic('arrow-right')}</button>`;
}

function foot(scan, primary, { back = '', apply = false } = {}) {
    const until = dateCz(scan.proposal.expiresAt);
    const sub = !apply ? '' : scan.store.isDirty()
        ? '<p class="as-sub"><span id="calScanApplyHint" class="cal-scan-warn">Nejdřív uložte rozpracované změny.</span></p>'
        : `<p class="as-sub">Uloží se hned. Původní rozvržení vrátíte tlačítkem Vrátit původní${until ? ` — do ${until}` : ''}.</p>`;
    return `<div class="as-foot" data-region="foot"><div class="as-foot-l">${ic('info')}<span>Kalendář se nezmění, dokud rozvržení nepoužijete.${until ? ` Návrh uchováme do ${until}.` : ''}</span></div><div class="as-foot-r">${back}<button type="button" class="btn sm ghost danger" data-act="discard" data-cal-action="scan-discard" data-k="discard"${scan.busy === 'discard' ? ' disabled' : ''}>Zahodit</button><button type="button" class="btn sm btn-tertiary" data-act="rescan" data-cal-action="scan-rescan" data-k="rescan">${ic('refresh')}Naskenovat znovu</button><span class="as-sp"></span>${primary}</div>${sub}</div>`;
}

/**
 * The page showed TWO weeks and the calendar is a 7-day one: the proposal holds the left week only.
 * Said above everything else — half of the picture has no counterpart in the proposal.
 */
function fortnightAlert(scan) {
    if (!(scan.proposal.notes || []).some((note) => note.code === 'fortnight_left_half')) return '';
    return `<div class="alert c-warning" role="status" data-region="fortnight" data-cal-scan-fortnight>${ic('alert', '')}<div><div class="alert-t">${CAL.fortnight.title}</div><div class="alert-d">${CAL.fortnight.text}</div></div></div>`;
}

function reviewView(scan) {
    const p = scan.proposal;
    const week = sampleWeekLabel(scan.store.getState().doc, p);
    const seg = `<div class="seg soft as-seg" role="group" aria-label="Způsob porovnání">${[['side', 'Vedle sebe'], ['slider', 'Posuvník']].map(([v, l]) => `<button type="button" class="${scan.compare === v ? 'on' : ''}" aria-pressed="${scan.compare === v}" data-act="compareMode" data-v="${v}" data-k="cmp${v}">${l}</button>`).join('')}</div>`;
    const hasPictures = p.pictures.length > 0;
    const primary = hasPictures
        ? `<button type="button" class="btn sm btn-primary" data-act="toPictures" data-cal-action="scan-pictures" data-k="topictures">Pokračovat na obrázky${ic('arrow-right')}</button>`
        : applyButton(scan);
    return `${fortnightAlert(scan)}<div class="as-cgrid" data-region="review" data-deep>
<div class="as-main" data-region="viewer-${scan.compare}-${scan.layers ? 'l' : 'p'}"><div class="as-viewbar">${seg}<label class="as-switch"><input type="checkbox" class="sr" role="switch" data-chg="toggleLayers" data-k="layers"${scan.layers ? ' checked' : ''}><span class="sw sm" aria-hidden="true"></span><span>Ukázat vrstvy</span></label></div>${compare(scan)}${scan.layers ? layerLegend(scan) : ''}<p class="as-cap">${week ? `Náhled ukazuje týden ${week}. ` : ''}${p.photoBox ? 'Fotka je z vašeho obrázku — do kalendáře se nevloží.' : ''}</p></div>
<div class="as-main" data-region="side" data-deep><div class="as-card as-sumcard" data-region="sum"><h3 class="as-sum"><b>Návrh rozvržení je připravený.</b></h3><p class="cap" style="margin:0">Tohle je návrh — kalendář se zatím nezměnil.</p></div>${recognised(scan)}${fontsCard(scan)}${cantCard(scan)}${infoCard(scan)}${feedback(scan)}</div></div>
${foot(scan, primary, { apply: !hasPictures })}`;
}

// ---- C4b — pictures ---------------------------------------------------------------------------

/** Where a place for a picture sits in the layout: its box in page mm (null = the whole page). */
function slotBox(scan, slot) {
    const boxes = (scan.proposal.layout && scan.proposal.layout.boxes) || {};
    const box = slot === 'logo' ? boxes.logo : slot === 'header' ? boxes.head : null;
    return box && box.w > 0 && box.h > 0 ? box : null;
}

function pictureCard(scan, item, number) {
    const p = scan.proposal;
    const busy = scan.busy === `picture:${item.slot}`;
    const name = CAL.slots[item.slot] || esc(item.slot);
    const slot = esc(item.slot);
    const dis = busy ? ' disabled' : '';
    const gallery = (label, cls = 'btn-tertiary') => (scan.hasGallery() ? `<button type="button" class="btn xs ${cls}" data-act="pictureGallery" data-slot="${slot}" data-k="picg${slot}"${dis}>${ic('image', 's14')}${label}</button>` : '');
    const upload = (label, cls = 'btn-tertiary') => `<button type="button" class="btn xs ${cls}" data-act="picturePick" data-slot="${slot}" data-k="pic${slot}"${dis}>${ic('upload', 's14')}${label}</button>`;
    let body;
    if (item.picture) {
        const pic = item.picture;
        body = `<p class="as-ph-note ok">${ic('check-circle', 's14')}<span class="as-ph-pick" aria-hidden="true"><img src="${esc(pic.url)}" alt="" loading="lazy"></span><span>${esc(pic.name)}${pic.width ? ` · ${pic.width} × ${pic.height} px` : ''}</span></p><div class="as-row">${gallery('Změnit')}${upload(scan.hasGallery() ? 'Nahrát jiný' : 'Změnit', scan.hasGallery() ? 'btn-ghost' : 'btn-tertiary')}<button type="button" class="btn xs btn-ghost" data-act="pictureDrop" data-slot="${slot}" data-k="picdrop${slot}"${dis}>Odebrat obrázek</button></div>`;
    } else if (item.current) {
        body = `<p class="as-ph-note ok">${ic('check-circle', 's14')}<span>Zůstane obrázek, který kalendář už má.</span></p><div class="as-row">${gallery('Vybrat z galerie')}${upload('Nahrát jiný')}</div>`;
    } else {
        body = `<p class="as-ph-note">${ic('info', 's14')}<span>Můžete ho doplnit i později v kroku Úpravy prvků.</span></p><div class="as-row">${gallery('Vybrat z galerie')}${upload('Nahrát')}</div>`;
    }
    const source = p.source && p.source.width && p.source.height ? p.source : null;
    const px = sourceBox(scan, slotBox(scan, item.slot));
    let crop;
    if (source && px) crop = `<span class="crop" role="img" aria-label="Výřez vaší stránky v tomto místě" style="${cropStyle(scan.image('working'), px, source, { pad: 0.12, minRatio: 0.7, maxRatio: 2.2 })}"></span>`;
    else if (source) crop = `<span class="crop whole" role="img" aria-label="Vaše stránka — obrázek přes celou stránku" style="aspect-ratio:${pc(source.width / source.height)};background-image:url('${esc(scan.image('working'))}');background-size:cover"></span>`;
    else crop = `<span class="crop cal-scan-nopic">${ic('image', '')}</span>`;
    return `<article class="as-ph" data-region="pic-${slot}" data-slot="${slot}" data-hl="pic-${slot}" data-dropzone="slot" aria-label="Místo ${number}: ${name}"><div class="as-ph-crop">${crop}<b class="as-num">${number}</b>${item.picture ? `<span class="chk" role="img" aria-label="Doplněno">${ic('check', 's12')}</span>` : ''}</div><div class="as-ph-b"><div class="as-ph-h"><b>${name}</b>${busy ? '<span class="as-spin" style="width:12px;height:12px"></span>' : ''}</div>${body}</div></article>`;
}

/** The numbers over the proposal: where each picture goes; an empty place shows the page's own pixels. */
function numberedPlaces(scan) {
    const p = scan.proposal;
    const page = design(scan).page;
    const source = p.source && p.source.width && p.source.height ? p.source : null;
    return p.pictures.map((item, i) => {
        const box = slotBox(scan, item.slot);
        const n = i + 1;
        if (!box) return `<b class="as-num" style="left:2%;top:${pc(100 - 14 - 12 * (p.pictures.length - n))}%">${n}</b>`;
        const px = !item.picture && !item.current && source ? sourceBox(scan, box) : null;
        const left = Math.max((Math.max(0, box.x) / page.width) * 100, 1.5);
        const top = Math.max((Math.max(0, box.y) / page.height) * 100, 3);
        return `<span class="as-numbox${item.picture || item.current ? ' done' : ' as-place'}" data-box="pic-${esc(item.slot)}" style="${mmStyle(box, page)}${px ? `;${pixelsStyle(scan.image('working'), px, source)}` : ''}"></span><b class="as-num" style="left:${pc(left)}%;top:${pc(top)}%">${n}</b>`;
    }).join('');
}

function picturesView(scan) {
    const p = scan.proposal;
    const page = design(scan).page;
    const done = p.pictures.filter((item) => item.picture || item.current).length;
    const back = `<button type="button" class="btn sm btn-tertiary" data-act="toReview" data-k="toreview">${ic('arrow-left')}Zpět na kontrolu</button>`;
    const formats = 'JPG, PNG, WebP nebo SVG, nejvýše 10 MB';
    const multi = p.pictures.length > 1
        ? `<button type="button" class="gal-drop gal-drop-compact as-drop as-multi" data-act="picturesPick" data-k="bulk" data-dropzone="pictures"${scan.busy ? ' disabled' : ''}>${ic('upload', '')}<b><span class="dt-only">Přetáhněte sem víc obrázků najednou</span><span class="ph-only">Klepněte a vyberte víc obrázků</span></b><span class="cap">Přiřadíme je prázdným místům popořadě · ${formats}</span></button>` : '';
    return `<div class="as-cgrid" data-region="pictures" data-deep>
<div class="as-main" data-region="prop"><span class="lbl">Návrh rozvržení</span><div class="as-frame" style="aspect-ratio:${page.width} / ${page.height}"><span class="cal-scan-prop" data-proposal role="img" aria-label="Návrh rozvržení s očíslovanými místy pro obrázky"></span>${numberedPlaces(scan)}</div><p class="as-cap">Čísla ukazují, kam který obrázek patří. V prázdných místech je vidět původní obrázek. Fotografii týdne nevybíráte — je to obsah každé stránky.</p></div>
<div class="as-main" data-region="list" data-deep><input type="file" class="sr" id="calScanPic" tabindex="-1" aria-hidden="true" accept="${PICTURE_ACCEPT}" data-chg="pictureInput"><input type="file" class="sr" id="calScanPics" tabindex="-1" aria-hidden="true" multiple accept="${PICTURE_ACCEPT}" data-chg="picturesInput"><div class="as-row" data-region="count"><h3 style="margin:0;font-size:15px">Fotky a loga</h3><span class="as-sp"></span><span class="as-ph-count" aria-label="Doplněno ${done} ${zz(p.pictures.length)} ${p.pictures.length}">${done} ${zz(p.pictures.length)} ${p.pictures.length}</span></div>${multi ? `<div data-region="multi">${multi}</div>` : ''}<div class="as-ph-list" data-region="cards" data-deep>${p.pictures.map((item, i) => pictureCard(scan, item, i + 1)).join('')}</div><p class="cap" style="margin:0" data-region="note">Vybraný obrázek zůstane u skenu — z galerie si bereme jeho kopii. Obrázkem kalendáře se stane, až rozvržení použijete. ${formats}. Obrázek můžete na místo také přetáhnout.</p></div></div>
${foot(scan, applyButton(scan), { back, apply: true })}`;
}

/** „Naskenovat znovu“ (ux.md S4): the same picture again, or a better one. → 'same' | 'new' | null */
export function rescanDialog(scan) {
    const can = Boolean(scan.entry && scan.entry.canStart);
    return new Promise((resolve) => {
        let chosen = null;
        const dialog = openDialog(`${dialogHead('Naskenovat znovu')}<p>Nový sken může dopadnout jinak, ne nutně lépe.</p>
<div class="as-opts"><button type="button" class="btn sm btn-tertiary" data-mode="same"${can ? ' data-autofocus' : ' disabled'}>Stejný obrázek znovu</button><button type="button" class="btn sm btn-tertiary" data-mode="new"${can ? '' : ' disabled'}>Nahrát lepší obrázek</button></div>
<p class="as-credit" style="justify-content:flex-start">${ic('sparkle', 's14')}<span>${esc(can ? creditLine(scan.entry.credits, 'Spotřebuje další sken').replace('tento měsíc ', '') : 'Nové skeny teď v tomto projektu spustit nejdou.')}</span></p>
<p class="cap">Tento návrh zůstane uložený, dokud ho nezahodíte nebo nevyprší. Kalendář se nezmění.</p>
<div class="dlg-f"><button type="button" class="btn sm btn-ghost" data-close>Zrušit</button></div>`, { onClose: () => resolve(chosen) });
        dialog.addEventListener('click', (event) => {
            const button = event.target.closest('[data-mode]');
            if (!button || button.disabled) return;
            chosen = button.dataset.mode;
            dialog.close();
        });
    });
}

/** `2 obrázky přiřazeny` */
export const assignedText = (n) => `${n} ${pl(n, 'obrázek přiřazen', 'obrázky přiřazeny', 'obrázků přiřazeno')}`;

// ---- dialogs ----------------------------------------------------------------------------------

export function privacyDialog() {
    return openDialog(`${dialogHead('Jak s obrázky nakládáme')}
<div class="as-dlg-sec"><b>Co odesíláme a komu.</b><p>Obrázek, texty a barvy z něj, názvy písem a barvy palety projektu posíláme službě Claude společnosti Anthropic (USA), která v obrázku rozpozná texty, tvary a rozvržení. Nikomu dalšímu je nepředáváme.</p></div>
<div class="as-dlg-sec"><b>Kdo obrázek uvidí.</b><p>Lidé, kteří mohou projekt upravovat. Tým WBoost jen při řešení potíží, nebo když sken ohodnotíte jako nepovedený či nám napíšete.</p></div>
<div class="as-dlg-sec"><b>K čemu slouží.</b><p>Jen k vytvoření vašeho návrhu. Anthropic data zaslaná přes API nepoužívá k trénování modelů. My vaše obrázky nepoužíváme k trénování ani k testování služby.</p></div>
<div class="as-dlg-sec"><b>Jak dlouho je držíme.</b><p>My 30 dní od skenu — i když sken zrušíte nebo se nepodaří; potom obrázek i nepřijatý návrh smažeme. Anthropic do 30 dnů; déle, nejvýše 2 roky, jen když obsah vyhodnotí jako porušení svých pravidel.</p></div>
<div class="as-dlg-sec"><b>Co nenahrávat.</b><p>Grafiku, ke které nemáte práva, a obrázky s citlivými osobními údaji.</p></div>
<div class="dlg-f"><button type="button" class="btn sm btn-primary" data-close data-autofocus>Rozumím</button></div>`);
}

/** §9.1 — „Požádat o přístup“ from the calendar wizard (`origin: calendars`). */
export function requestDialog(scan) {
    const dialog = openDialog(
        `<div class="dlg-h"><h2 style="display:flex;align-items:center;gap:10px">AI sken ${PREM}</h2><button type="button" class="btn icon xs btn-ghost" data-close data-x aria-label="Zavřít">${ic('x')}</button></div>
<p class="lead">Stránka vašeho kalendáře → stejné rozvržení za pár minut.</p>
<p>AI sken je placená funkce, kterou zapínáme pro každý projekt zvlášť. Napište si o ni — ozveme se vám e‑mailem s podmínkami.</p>
<label class="field"><span class="field-l">K čemu ho chcete použít? <small>nepovinné</small></span><textarea class="form-control" rows="3" maxlength="500" data-autofocus data-message placeholder="Např. chceme převzít vzhled loňského kalendáře"></textarea></label>
<p class="cap">Odešleme: vaše jméno a e‑mail, projekt „${esc(scan.env.projectName || '')}“ a tuto zprávu. Dostane ji tým WBoost.</p>
<p class="hint err" role="alert" data-error hidden></p>
<div class="dlg-f"><button type="button" class="btn sm btn-tertiary" data-close>Zrušit</button><button type="button" class="btn sm btn-primary" data-send>Požádat o přístup</button></div>`,
        { cls: 'as-dlg-m' },
    );
    const send = dialog.querySelector('[data-send]');
    const error = dialog.querySelector('[data-error]');
    send.addEventListener('click', async () => {
        send.disabled = true;
        error.hidden = true;
        const answer = await api.post(scan.urls.accessRequest, { origin: 'calendars', message: dialog.querySelector('[data-message]').value.trim() || null });
        if (!answer.ok) {
            send.disabled = false;
            error.textContent = answer.code === 'rate_limited' ? 'Dnes jste už poslali pět žádostí. Zkuste to prosím zítra.'
                : answer.code === 'wrong_state' ? 'AI sken už v tomto projektu použít můžete — obnovte stránku.'
                    : answer.code === 'network' ? 'Žádost se nepodařilo odeslat. Zkontrolujte připojení a zkuste to znovu.' : 'Žádost se nepodařilo odeslat. Zkuste to prosím za chvíli.';
            error.hidden = false;
            return;
        }
        scan.entry.state = 'request_sent';
        scan.entry.requestSentAt = answer.sentAt || new Date().toISOString();
        scan.render();
        dialog.innerHTML = `<div class="dlg-h"><h2>Žádost odeslána</h2><button type="button" class="btn icon xs btn-ghost" data-close data-x aria-label="Zavřít">${ic('x')}</button></div>
<div class="as-dlg-head"><span class="ico-c sm as-ico-mint">${ic('check-circle')}</span><p>Ozveme se vám na ${esc(answer.email || 'váš e‑mail')}.</p></div>
<div class="dlg-f"><button type="button" class="btn sm btn-primary" data-close data-done>Zavřít</button></div>`;
        dialog.querySelector('[data-done]').focus();
    });
    return dialog;
}

