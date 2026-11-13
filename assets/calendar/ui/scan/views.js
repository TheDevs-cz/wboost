// „Naskenovat design“ — the markup of the action card and of the panel's screens (ux.md §6 C1–C4,
// S1–S3, S5 in the panel). Strings in, strings out: `scan` is the CalendarScan of ../scan.js, every
// value that did not come from this code goes through `esc()`. Classes are the template wizard's
// (`as-*`, assets/styles/nocturne/ai-scan.css) plus the calendar's own in calendar.css.

import { api, bytesCz, creditLine, dateCz, dialogHead, duration, esc, ic, minutesRange, openDialog, scansAhead, zz } from '../../../ai-scan/kit.js';
import { CAL, UPLOAD_ERRORS, failure, noteText, picturesLater, pill, startHint } from '../../../ai-scan/copy.js';
import { ratioLabel } from '../../../ai-scan/file-checks.js';
import { cropStyle, pixelsStyle } from '../../../ai-scan/page/parts.js';
import { elementFact, sampleWeekLabel } from './preview.js';

const ACCEPT = 'image/jpeg,image/png,image/webp,image/heic,image/heif';
const PICTURE_ACCEPT = 'image/jpeg,image/png,image/webp';
const PREM = `<span class="tp-prem">${ic('sparkle', 's14')}Premium</span>`;
const IN_PROGRESS = ['checking', 'queued', 'running'];
const LONG_AFTER_SECONDS = 180;
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
const errorText = (error) => (error ? UPLOAD_ERRORS[error.code](error.width, error.height) : '');

function uploadView(scan) {
    const error = scan.uploadError;
    return `<div class="as-cgrid" data-region="upload" data-deep><div class="as-main" data-region="zone">${fileInput()}
<button type="button" class="gal-drop as-drop" data-act="pick" data-k="drop" aria-describedby="calScanCap${error ? ' calScanErr' : ''}">${ic('sparkle', '')}<b><span class="dt-only">${CAL.drop.bold}</span><span class="ph-only">${CAL.drop.phone}</span></b><span class="dt-only">nebo <u>vyberte soubor</u> z počítače</span><span class="cap" id="calScanCap">${CAL.drop.cap}</span></button>
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
    if (picked.width) rows.push(picked.warnings.includes('small') ? warn('Malý obrázek — drobné texty nemusíme přečíst') : ok('Dostatečné rozlišení'));
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
<div class="as-main" data-region="file"><div class="as-card">${fileInput()}${thumb}<div class="as-file-b"><b>${esc(picked.file.name)}</b><span class="cap">${facts}</span>${checks(scan)}
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

function stageList(scan, { now = -1, failed = false } = {}) {
    const run = scan.run || { stages: {} };
    const row = scan.scan;
    return `<ol class="as-stages" aria-label="Průběh skenu">${STAGES.map((s, i) => {
        const state = failed && i === now ? 'fail' : i < now ? 'done' : i === now ? 'now' : 'todo';
        const sr = { done: 'hotovo', now: 'právě běží', todo: 'čeká', fail: 'nepodařilo se' }[state];
        const icon = state === 'done' ? ic('check') : state === 'now' ? '<span class="as-spin"></span>' : state === 'fail' ? ic('x-circle') : '';
        const seen = run.stages[s.id] || {};
        const note = state === 'now' && !failed ? noteText(row.note, row.noteParams) : state === 'done' && seen.note ? noteText(seen.note[0], seen.note[1]) : null;
        const took = state === 'done' && seen.start && seen.end ? duration((seen.end - seen.start) / 1000) : '';
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
        const note = noteText(row.note, row.noteParams);
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

/** The uploaded picture: the scanner's copy once the pre-check passed, the original before. */
function pictureSources(scan) {
    const row = scan.scan;
    const checked = ['queued', 'running', 'ready', 'accepted'].includes(row.status) || (row.status === 'failed' && row.code !== 'provider_unavailable');
    return checked ? [scan.image('working'), scan.image('original')] : [scan.image('original'), scan.image('working')];
}
function fallbackImg(sources, alt) {
    const [first, ...rest] = sources;
    return `<img src="${esc(first)}" alt="${esc(alt)}" data-fallback="${esc(rest.join('|'))}" onerror="var f=(this.dataset.fallback||'').split('|').filter(Boolean);if(f.length){this.dataset.fallback=f.slice(1).join('|');this.src=f[0];}else{this.onerror=null;this.closest('.as-frame')&&this.closest('.as-frame').classList.add('none');}">`;
}

function waitView(scan) {
    const row = scan.scan;
    const seq = row.previewSeq || 0;
    const running = row.status === 'running';
    let preview;
    let caption;
    if (seq > 0) {
        preview = `<div class="as-frame${running ? ' busy' : ''}"><img class="fade-in" src="${esc(scan.image('candidate', `?n=${seq}`))}" alt="Průběžný náhled rozvržení"></div>`;
        caption = !running ? 'Poslední náhled před přerušením' : row.round >= 1 ? `Náhled po ${row.round}. kole porovnání` : 'První náhled — ještě ho doladíme';
    } else {
        preview = `<div class="as-frame${running ? ' pending' : ''} dim">${fallbackImg(pictureSources(scan), '')}</div>`;
        caption = running ? 'První náhled uvidíte za pár vteřin.' : 'Náhled se začne tvořit, až sken přijde na řadu.';
    }
    return `<div class="as-cgrid side-first" data-region="wait" data-deep><div class="as-main" data-region="pics" data-deep>
<figure class="as-pic" data-region="orig-${row.status === 'checking' ? 'c' : 'w'}"><figcaption><span class="lbl">Váš obrázek</span></figcaption><div class="as-frame">${fallbackImg(pictureSources(scan), 'Původní obrázek')}<span class="as-noimg">${ic('image', '')}Náhled uvidíte po kontrole obrázku</span></div></figure>
<figure class="as-pic" data-region="prev-${seq}-${running ? 'r' : 'w'}"><figcaption><span class="lbl">Průběžný náhled</span><span class="cap">${caption}</span></figcaption>${preview}</figure></div>
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
    const run = scan.run || { stages: {} };
    const tryTail = (row.try || 1) >= 2 ? ` · ${row.try}. pokus` : '';
    const state = scan.entry ? scan.entry.state : 'service_waiting';
    const blocker = scan.actionRefusal
        || (state === 'limit_reached' ? 'limit_reached' : state === 'service_waiting' ? 'temporarily_unavailable' : ['locked', 'request_sent'].includes(state) ? 'not_enabled' : null);
    const exhausted = row.status === 'failed' && (!row.canRetry || ['no_retries_left', 'source_unscannable'].includes(scan.actionRefusal));
    const hint = blocker && !exhausted ? startHint(blocker, scan.entry) : null;

    let stages = '';
    if (f.stop) {
        const check = run.stages.check || {};
        stages = `<ol class="as-stages" aria-label="Průběh skenu"><li class="stop"><span class="ic">${ic('info')}</span><span>Kontrola obrázku — zastaveno</span><span class="dur">${check.start && check.end ? duration((check.end - check.start) / 1000) : ''}</span></li></ol>`;
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

    return `<div class="as-cgrid side-first" data-region="fail" data-deep><div class="as-main" data-region="pic"><figure class="as-pic"><figcaption><span class="lbl">Váš obrázek</span></figcaption><div class="as-frame${row.hasPicture === false ? ' none' : ''}">${row.hasPicture === false ? '' : fallbackImg(pictureSources(scan), 'Původní obrázek')}<span class="as-noimg">${ic('image', '')}Obrázek už nemáme uložený</span></div></figure></div><div data-region="result">${cardHtml}</div></div>`;
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
    const proposal = '<span class="cal-scan-prop" data-proposal role="img" aria-label="Návrh rozvržení"></span>';
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
        return `<li class="${cls}">${ic(icon)}<b>${CAL.elements[item.element] || esc(item.element)}<span class="sr"> — ${sr}</span></b><span class="f">${esc(fact)}</span></li>`;
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
    const rows = (p.notes || []).map((note) => (CAL.notes[note.code] ? `<li><span>${esc(CAL.notes[note.code](note))}</span></li>` : ''));
    if (p.approximate && !(p.notes || []).some((n) => n.code === 'colours_estimated')) rows.push(`<li><span>${CAL.notes.colours_estimated()}</span></li>`);
    if (p.unfinished) rows.push('<li><span>Sken jsme museli ukončit dřív — návrh je nedokončený. Zkontrolujte ho pečlivěji.</span></li>');
    const list = rows.filter(Boolean).join('');
    return list ? `<div class="as-card" data-region="info"><h3>Pro informaci</h3><div class="as-info" style="border-top:0;padding-top:0"><ul>${list}</ul></div></div>` : '';
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

function reviewView(scan) {
    const p = scan.proposal;
    const week = sampleWeekLabel(scan.store.getState().doc, p);
    const seg = `<div class="seg soft as-seg" role="group" aria-label="Způsob porovnání">${[['side', 'Vedle sebe'], ['slider', 'Posuvník']].map(([v, l]) => `<button type="button" class="${scan.compare === v ? 'on' : ''}" aria-pressed="${scan.compare === v}" data-act="compareMode" data-v="${v}" data-k="cmp${v}">${l}</button>`).join('')}</div>`;
    const hasPictures = p.pictures.length > 0;
    const primary = hasPictures
        ? `<button type="button" class="btn sm btn-primary" data-act="toPictures" data-cal-action="scan-pictures" data-k="topictures">Pokračovat na obrázky${ic('arrow-right')}</button>`
        : applyButton(scan);
    return `<div class="as-cgrid" data-region="review" data-deep>
<div class="as-main" data-region="viewer-${scan.compare}"><div class="as-viewbar">${seg}</div>${compare(scan)}<p class="as-cap">${week ? `Náhled ukazuje týden ${week}. ` : ''}${p.photoBox ? 'Fotka je z vašeho obrázku — do kalendáře se nevloží.' : ''}</p></div>
<div class="as-main" data-region="side" data-deep><div class="as-card as-sumcard" data-region="sum"><h3 class="as-sum"><b>Návrh rozvržení je připravený.</b></h3><p class="cap" style="margin:0">Tohle je návrh — kalendář se zatím nezměnil.</p></div>${recognised(scan)}${fontsCard(scan)}${cantCard(scan)}${infoCard(scan)}</div></div>
${foot(scan, primary, { apply: !hasPictures })}`;
}

// ---- C4b — pictures ---------------------------------------------------------------------------

function pictureCard(scan, item) {
    const busy = scan.busy === `picture:${item.slot}`;
    const name = CAL.slots[item.slot] || esc(item.slot);
    const pick = (label, icon = 'upload') => `<button type="button" class="btn xs btn-tertiary" data-act="picturePick" data-slot="${esc(item.slot)}" data-k="pic${esc(item.slot)}"${busy ? ' disabled' : ''}>${ic(icon, 's14')}${label}</button>`;
    let thumb;
    let body;
    if (item.picture) {
        thumb = `<span class="crop" style="background-image:url('${esc(item.picture.url)}');background-size:contain;background-position:center"></span>`;
        body = `<span class="cap">${esc(item.picture.name)}${item.picture.width ? ` · ${item.picture.width} × ${item.picture.height} px` : ''}</span><div class="as-row">${pick('Změnit', 'refresh')}<button type="button" class="btn xs btn-ghost" data-act="pictureDrop" data-slot="${esc(item.slot)}" data-k="picdrop${esc(item.slot)}"${busy ? ' disabled' : ''}>Odebrat</button></div>`;
    } else if (item.current) {
        thumb = `<span class="crop cal-scan-nopic">${ic('check', '')}</span>`;
        body = `<p class="as-ph-note ok">${ic('check-circle', 's14')}<span>Zůstane obrázek, který kalendář už má.</span></p><div class="as-row">${pick('Nahrát jiný')}</div>`;
    } else {
        thumb = `<span class="crop cal-scan-nopic">${ic('image', '')}</span>`;
        body = `<p class="as-ph-note">${ic('info', 's14')}<span>Můžete ho doplnit i později v kroku Úpravy prvků.</span></p><div class="as-row">${pick('Nahrát')}</div>`;
    }
    return `<div class="as-ph" data-region="pic-${esc(item.slot)}" data-slot="${esc(item.slot)}"><div class="as-ph-crop">${thumb}</div><div class="as-ph-b"><div class="as-ph-h"><b>${name}</b>${busy ? '<span class="as-spin" style="width:12px;height:12px"></span>' : ''}</div>${body}</div></div>`;
}

function picturesView(scan) {
    const p = scan.proposal;
    const page = design(scan).page;
    const done = p.pictures.filter((item) => item.picture || item.current).length;
    const back = `<button type="button" class="btn sm btn-tertiary" data-act="toReview" data-k="toreview">${ic('arrow-left')}Zpět na kontrolu</button>`;
    return `<div class="as-cgrid" data-region="pictures" data-deep>
<div class="as-main" data-region="prop"><span class="lbl">Návrh rozvržení</span><div class="as-frame" style="aspect-ratio:${page.width} / ${page.height}"><span class="cal-scan-prop" data-proposal role="img" aria-label="Návrh rozvržení"></span></div><p class="as-cap">Fotografii týdne nevybíráte — je to obsah každé stránky.</p></div>
<div class="as-main" data-region="list" data-deep><input type="file" class="sr" id="calScanPic" tabindex="-1" aria-hidden="true" accept="${PICTURE_ACCEPT}" data-chg="pictureInput"><div class="as-row" data-region="count"><h3 style="margin:0;font-size:15px">Fotky a loga</h3><span class="as-sp"></span><span class="as-ph-count">${done} ${zz(p.pictures.length)} ${p.pictures.length}</span></div><div class="as-ph-list" data-region="cards" data-deep>${p.pictures.map((item) => pictureCard(scan, item)).join('')}</div><p class="cap" style="margin:0" data-region="note">Vybraný obrázek zůstane u skenu. Obrázkem kalendáře se stane, až rozvržení použijete. JPG, PNG nebo WebP, nejvýše 10 MB.</p></div></div>
${foot(scan, applyButton(scan), { back, apply: true })}`;
}

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

