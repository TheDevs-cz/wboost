// AI sken — S3 (waiting: kontrola → fronta → sken), S5 (stopped / failed) and the card of a scan
// that is finished with (cancelled, discarded, expired). ux.md S3, S5, §2.4.
import { api, confirmDialog, creditLine, dateCz, duration, esc, ic, minutesRange, scansAhead, urlFor } from '../kit.js';
import { toast } from '../../shell/toast.js';
import { STAGES, failure, noteText, pill, stageIndex, startHint } from '../copy.js';
import { boxStyle, head, historyLink } from './parts.js';
import { usePicture } from './upload.js';

const LONG_AFTER_SECONDS = 180;

// ---- what this browser keeps about the run ----------------------------------------------------
// The durations of finished stages and the note each ended with come from the status document
// (`stages`, the server's stage log). The browser only remembers, per scan and try, when it first
// saw the scan (the ticking time of a retry), the last stage it saw and the two clocks that
// change the card's text — in sessionStorage, so a reload keeps them.

const memoryKey = (scan) => `wb-as:${scan.id}:${scan.try || 1}`;
function memory(scan) {
    try {
        const raw = window.sessionStorage.getItem(memoryKey(scan));
        if (raw) return JSON.parse(raw);
    } catch (e) { /* private mode */ }
    return { first: Date.now(), last: null, queueDeadline: null, runBase: null, restarted: false };
}
function remember(scan, data) {
    try { window.sessionStorage.setItem(memoryKey(scan), JSON.stringify(data)); } catch (e) { /* private mode */ }
}

/** Folds a fresh status into the observed run. Called on every status the page receives. */
export function observe(app, scan) {
    const m = memory(scan);
    const now = Date.now();
    const stage = scan.status === 'checking' ? 'check' : scan.status === 'running' ? scan.stage || m.last : null;

    // An automatic restart: the stage list starts again (the elapsed time keeps running).
    if (scan.restarted && !m.restarted) {
        m.restarted = true;
        m.last = null;
    }
    if (stage) m.last = stage;
    else if (scan.status === 'queued') m.last = 'check';
    if (scan.status === 'running' && typeof scan.runningSeconds === 'number') m.runBase = now - scan.runningSeconds * 1000;
    if (scan.status === 'queued' && scan.queuePosition > 0 && Array.isArray(scan.estimatedStartSeconds) && m.queueDeadline === null) {
        m.queueDeadline = now + scan.estimatedStartSeconds[1] * 1000;
    }
    if (scan.status !== 'queued') m.queueDeadline = null;
    remember(scan, m);
    app.run = m;
    return m;
}

/** When the scan (this try) started waiting, for the ticking time. */
function since(app, scan) {
    const created = Date.parse(scan.createdAt);
    const first = app.run ? app.run.first : Date.now();
    // The first try counts from the upload; a retry from when this browser saw it start.
    return (scan.try || 1) === 1 && Number.isFinite(created) && created <= first ? created : first;
}

const overEstimate = (app) => app.scan.status === 'queued' && app.run && app.run.queueDeadline !== null && Date.now() > app.run.queueDeadline && app.scan.queuePosition > 0;
const isLong = (app) => app.scan.status === 'running' && app.run && app.run.runBase !== null && Date.now() - app.run.runBase > LONG_AFTER_SECONDS * 1000;

/** The clock crossed a line that changes the card's text (3 min of running, estimate exceeded). */
export function needsRender(app) {
    const flags = `${overEstimate(app)}${isLong(app)}`;
    const changed = app.waitFlags !== undefined && app.waitFlags !== flags;
    app.waitFlags = flags;
    return changed;
}

export function announcement(scan) {
    if (scan.status === 'checking') return 'Kontrolujeme obrázek.';
    if (scan.status === 'queued') return scan.waiting ? 'Sken čeká. AI sken teď nepracuje.' : scan.restarted ? 'Sken se přerušil, spouštíme ho znovu.' : 'Obrázek je v pořádku. Čeká ve frontě.';
    if (scan.status === 'running') { const s = STAGES[stageIndex(scan.stage)]; return s ? `Sken běží. ${s.label}.` : 'Sken běží.'; }
    if (scan.status === 'cancelled') return 'Sken zrušen.';
    return failTitle(scan);
}
export const failTitle = (scan) => failure(scan.status, scan.code).title;

// ---- S3 -------------------------------------------------------------------------------------------

/** The live note is about another stage (the texts the reading found): it stays with that row. */
const notedElsewhere = (scan, stageId) => Boolean(scan.note) && Object.entries(scan.stages || {}).some(([id, x]) => id !== stageId && x.note === scan.note);

function stageList(app, { now = -1, fail = false } = {}) {
    const scan = app.scan;
    return `<ol class="as-stages" aria-label="Průběh skenu">${STAGES.map((s, i) => {
        const state = fail && i === now ? 'fail' : i < now ? 'done' : i === now ? 'now' : 'todo';
        const sr = { done: 'hotovo', now: 'právě běží', todo: 'čeká', fail: 'nepodařilo se' }[state];
        const icon = state === 'done' ? ic('check') : state === 'now' ? '<span class="as-spin"></span>' : state === 'fail' ? ic('x-circle') : '';
        const seen = (scan.stages && scan.stages[s.id]) || {};
        // The running stage shows the live note — unless that note is about an earlier stage
        // (the texts the reading found), which stays with its own row.
        const live = state === 'now' && !fail && !notedElsewhere(scan, s.id) ? noteText(scan.note, scan.noteParams) : null;
        const note = live || (state === 'done' && seen.note ? noteText(seen.note, seen.noteParams) : null);
        const took = state === 'done' && typeof seen.seconds === 'number' ? duration(seen.seconds) : '';
        const round = state === 'now' && s.id === 'refine' && scan.round >= 1 ? ` <span class="pill pch">kolo ${Math.min(2, scan.round)} ze 2</span>` : '';
        return `<li class="${state}"><span class="ic">${icon}</span><span>${s.label}${round}<span class="sr"> — ${sr}</span></span><span class="dur">${took}</span>${note ? `<span class="note">${esc(note)}</span>` : ''}</li>`;
    }).join('')}</ol>`;
}
const bar = (now) => `<div class="as-bar" role="img" aria-label="Hotové fáze: ${Math.max(0, now)} z ${STAGES.length}">${STAGES.map((s, i) => `<i class="${i < now ? 'done' : ''}"></i>`).join('')}</div>`;
const leave = '<div class="as-leave">' + ic('check-circle') + '<span><b>Můžete odejít — dáme vědět.</b> Sken běží na serveru. Návrh najdete v Šablonách; když tu nebudete, pošleme e‑mail.</span></div>';

function statusCard(app) {
    const scan = app.scan;
    const elapsed = `<b data-elapsed data-since="${since(app, scan)}">0:00</b>`;
    const buttons = `<div class="as-row"><a href="${esc(app.urls.templates)}" class="btn sm btn-tertiary">${ic('arrow-left')}Zpět na šablony</a><button type="button" class="btn sm btn-ghost" data-act="cancel" data-k="cancel"${app.busy === 'cancel' ? ' disabled' : ''}>Zrušit sken</button></div>`;
    let headHtml;
    let body = '';
    if (scan.status === 'running') {
        const now = Math.max(1, stageIndex(scan.stage || (app.run && app.run.last) || 'read'));
        const stage = STAGES[now];
        const long = isLong(app);
        const doneIn = minutesRange(scan.estimatedFinishSeconds);
        headHtml = `<div class="as-status-t"><h2 tabindex="-1" data-focus>${stage.title}</h2></div><p class="as-time" style="margin:0">${elapsed}${long ? '' : doneIn ? ` · hotovo ${doneIn === 'během chvíle' ? doneIn : `asi za ${doneIn}`}` : ' · obvykle 1–3 min'}</p>${bar(now)}${long ? '<p class="as-long" style="margin:0">Trvá to déle než obvykle — nejdéle asi 12 minut. Klidně odejděte, dáme vědět.</p>' : ''}`;
        const note = notedElsewhere(scan, stage.id) ? null : noteText(scan.note, scan.noteParams);
        const round = stage.id === 'refine' && scan.round >= 1 ? ` <span class="pill pch">kolo ${Math.min(2, scan.round)} ze 2</span>` : '';
        // Phone: the current stage on the card, the full list collapsed under „Průběh“.
        body = `<p class="as-nowline as-phone"><span class="as-spin"></span><span><b>${stage.label}</b>${round}${note ? `<br><span class="cap">${esc(note)}</span>` : ''}</span></p><details class="as-details as-prubeh"${app.stagesOpen ?? window.innerWidth > 640 ? ' open' : ''} data-k="prubeh"><summary data-act="toggleStages">${ic('chev-right')}Průběh</summary>${stageList(app, { now })}</details>`;
    } else {
        const position = scan.queuePosition || 0;
        const startIn = minutesRange(scan.estimatedStartSeconds);
        const doneIn = minutesRange(scan.estimatedFinishSeconds);
        let title;
        let text;
        let spin = true;
        if (scan.waiting) {
            title = 'Sken čeká';
            text = 'AI sken teď nepracuje. Víme o tom. Váš sken počká ve frontě nejdéle hodinu — dáme vědět, jak dopadne. Nezapočítá se, pokud ho nestihneme.';
            spin = false;
        } else if (scan.status === 'checking') {
            title = 'Kontrolujeme obrázek…';
            text = 'Trvá to pár vteřin. Ověřujeme, že z něj půjde udělat návrh.';
        } else if (scan.restarted) {
            title = 'Čeká ve frontě';
            text = 'Sken se přerušil, spouštíme ho znovu.';
        } else if (overEstimate(app)) {
            title = 'Čeká ve frontě';
            text = `Sken před vámi trvá déle než obvykle. Jste stále ${position}. v pořadí.`;
        } else if (position > 0) {
            title = 'Čeká ve frontě';
            text = `${scansAhead(position)} (jeden právě běží).${startIn && doneIn ? `<br>Začneme ${startIn === 'během chvíle' ? startIn : `asi za ${startIn}`}, hotovo asi za ${doneIn}.` : ''}`;
        } else {
            title = 'Čeká ve frontě';
            text = 'Jste na řadě — začneme během chvíle.';
        }
        headHtml = `<div class="as-status-t">${spin ? '<span class="as-spin"></span>' : `<span class="as-result-ico pch" style="width:32px;height:32px">${ic('clock')}</span>`}<h2 tabindex="-1" data-focus>${title}</h2>${pill(scan.status === 'checking' ? 'checking' : 'queued')}</div><p class="as-status-p">${text}</p><p class="as-time" style="margin:0">Uplynulo ${elapsed}</p>`;
        if (scan.status === 'queued' && scan.restarted) body = stageList(app, { now: 0 });
    }
    return `<section class="as-card as-status" aria-label="Stav skenu">${headHtml}${body}${leave}${buttons}</section>`;
}

/**
 * The uploaded picture as this browser can show it. The status document says which copy exists
 * (`picture`: the scanner's normalised copy once the pre-check passed — any browser can show
 * it, also a HEIC —, before that the original unless it is a HEIC, null when there is none), so
 * the page never asks for a file that is not there.
 */
function picture(app, alt, cls = '') {
    const which = app.scan.picture;
    return which ? `<img src="${esc(app.image(which))}" alt="${esc(alt)}"${cls ? ` class="${cls}"` : ''}>` : '';
}

/** „Tyto texty jsme našli“ — the lines the reading found, outlined on the picture (ux.md S3). */
function foundTexts(scan) {
    const found = scan.textBoxes;
    if (!found || !Array.isArray(found.boxes) || !found.boxes.length || !(found.width > 0) || !(found.height > 0)) return '';
    return found.boxes.map((box) => `<span class="as-found" style="${boxStyle(box, found)}"></span>`).join('');
}

function pics(app) {
    const scan = app.scan;
    const original = picture(app, 'Původní obrázek');
    const running = scan.status === 'running';
    // The latest render of the running run. After an automatic restart the renders of the run
    // that broke are gone from the server: the last one this page showed stays (from the cache).
    const key0 = `${scan.id}:${scan.try || 1}`;
    if (running && scan.previewCandidate) app.lastPreview = { key: key0, url: app.image('candidate', `?n=${scan.previewCandidate}&v=${scan.previewSeq || 0}`), seq: scan.previewSeq || 0 };
    else if (!(scan.status === 'queued' && scan.restarted) || !app.lastPreview || app.lastPreview.key !== key0) app.lastPreview = null;
    const seq = app.lastPreview ? app.lastPreview.seq : 0;
    const found = running && scan.picture === 'working' ? foundTexts(scan) : '';
    let preview;
    let caption;
    let key = `${seq}-${running ? 'r' : 'w'}`;
    if (seq > 0) {
        preview = `<div class="as-frame${running ? ' busy' : ''}"><img class="fade-in" src="${esc(app.lastPreview.url)}" alt="Průběžný náhled návrhu" onerror="this.onerror=null;this.closest('.as-frame').classList.add('none')"><span class="as-noimg">${ic('image', '')}Náhled se právě obnovuje</span></div>`;
        caption = !running ? 'Poslední náhled před přerušením' : scan.round >= 1 ? `Náhled po ${scan.round}. kole porovnání` : 'První náhled — ještě ho doladíme';
    } else if (found) {
        // The first real evidence, a few seconds in: the original with the found lines outlined.
        const n = scan.textBoxes.boxes.length;
        preview = `<div class="as-frame busy as-found-frame"><img class="fade-in" src="${esc(app.image('working'))}" alt="Původní obrázek s vyznačenými texty, které jsme našli">${found}</div>`;
        caption = `Tyto texty jsme našli${n ? ` (${n})` : ''}`;
        key = `found-${n}`;
    } else {
        preview = original ? `<div class="as-frame${running ? ' pending' : ''} dim">${picture(app, '')}</div>` : '';
        caption = running ? 'První náhled uvidíte za pár vteřin.' : 'Náhled se začne tvořit, až sken přijde na řadu.';
        key = `${key}-${scan.picture || 'none'}`;
    }
    return `<section class="as-card" aria-label="Obrázky" data-region="card" data-deep><div class="as-pics" data-region="grid" data-deep><figure class="as-pic" data-region="orig-${scan.picture || 'none'}"><figcaption><span class="lbl">Váš obrázek</span></figcaption><div class="as-frame${original ? '' : ' none'}">${original}<span class="as-noimg">${ic('image', '')}Náhled uvidíte po kontrole obrázku</span></div></figure><figure class="as-pic" data-region="prev-${key}"><figcaption><span class="lbl">Průběžný náhled</span><span class="cap">${caption}</span></figcaption>${preview}</figure></div></section>`;
}

export function waitView(app) {
    return `${head(app, 2, 'Rozpoznávání')}${offLine(app)}
<div class="as-grid side-first" data-region="wait" data-deep><div class="as-main" data-region="pics" data-deep>${pics(app)}</div><aside class="as-side" data-region="status">${statusCard(app)}</aside></div>`;
}

/** F12 — the project lost AI scan meanwhile. */
function offLine(app) {
    return ['locked', 'request_sent'].includes(app.entry.state)
        ? `<p class="as-qline" style="margin:0" data-region="off">${ic('info')}<span>AI sken je pro tento projekt vypnutý. Rozpracované návrhy můžete dokončit, nové skeny spustit nejdou.</span></p>` : '';
}
export { offLine };

// ---- S5 -------------------------------------------------------------------------------------------

export function failView(app) {
    const scan = app.scan;
    const f = failure(scan.status, scan.code);
    if (!app.run) observe(app, scan);
    const run = app.run;
    // `hasPicture` is a field of the list row (the page's boot value); a scan started in this
    // page has only its status document — its picture was uploaded a moment ago.
    const tryTail = (scan.try || 1) >= 2 ? ` · ${scan.try}. pokus` : '';
    // Why "Zkusit znovu" / "Zkusit přesto" cannot run now: a limit or the service (disabled, with
    // the hint), or the picture failed twice (the button goes).
    const state = app.entry.state;
    const blocker = app.actionRefusal
        || (state === 'limit_reached' ? 'limit_reached' : state === 'service_waiting' ? 'temporarily_unavailable' : ['locked', 'request_sent'].includes(state) ? 'not_enabled' : null);
    const exhausted = scan.status === 'failed' && (!scan.canRetry || ['no_retries_left', 'source_unscannable'].includes(app.actionRefusal));
    const hint = blocker && !exhausted ? startHint(blocker, app.entry) : null;

    let stages = '';
    if (f.stop) {
        const check = (scan.stages && scan.stages.check) || {};
        stages = `<ol class="as-stages" aria-label="Průběh skenu"><li class="stop"><span class="ic">${ic('info')}</span><span>Kontrola obrázku — zastaveno</span><span class="dur">${typeof check.seconds === 'number' ? duration(check.seconds) : ''}</span></li></ol>`;
    } else if (!f.noStages) {
        const at = stageIndex(scan.stage || run.last || '');
        if (at >= 0) stages = stageList(app, { now: at, fail: true });
    }

    const acts = [];
    const upload = (cls) => `<a href="${esc(app.urls.new)}" class="btn sm ${cls}">${ic('upload')}Nahrát jiný obrázek</a>`;
    if (f.upload) acts.push(upload('btn-primary'));
    if (f.retry && !exhausted) acts.push(`<button type="button" class="btn sm ${hint ? 'btn-tertiary' : 'btn-primary'}" data-act="retry" data-k="retry"${hint || app.busy === 'retry' ? ' disabled aria-describedby="asFHint"' : ''}>${ic('refresh')}Zkusit znovu</button>`);
    if (f.anyway && scan.canContinue) acts.push(`<button type="button" class="btn sm btn-tertiary" data-act="continueAnyway" data-k="anyway"${hint || app.busy === 'continue' ? ' disabled aria-describedby="asFHint"' : ''}>Zkusit přesto</button>`);
    if (f.other || (f.retry && exhausted)) acts.push(`<a href="${esc(app.urls.new)}" class="btn sm ${exhausted ? 'btn-primary' : 'btn-tertiary'}">Vybrat jiný obrázek</a>`);
    if (f.contact) acts.push(`<a href="${esc(app.urls.contact)}?subject=${encodeURIComponent('AI sken odmítl obrázek')}" class="btn sm btn-tertiary">${ic('mail')}Napsat nám</a>`);

    const card = `<section class="as-card as-status" aria-label="Výsledek skenu"><div class="as-status-t"><span class="as-result-ico ${f.tone}">${ic(f.icon, '')}</span>${pill(scan.status, tryTail)}</div><h2 tabindex="-1" data-focus>${f.title}</h2><p class="as-status-p">${f.text}</p><p class="cap" style="margin:0">${ic('check', 's12')} Sken se nezapočítal.</p>
${stages}${exhausted ? '<p style="margin:0;font-size:13px;font-weight:600">Tento obrázek se nám nedaří zpracovat. Zkuste jiný.</p>' : ''}<div class="as-row">${acts.join('')}</div>
${f.anyway && scan.canContinue ? `<p class="cap" style="margin:0">${ic('sparkle', 's14')} ${esc(creditLine(app.entry.credits, 'Zkusit přesto spotřebuje 1 sken').replace('tento měsíc ', ''))}. Jde to jen jednou.</p>` : ''}${hint ? `<p class="hint" id="asFHint" style="margin:0">${ic('info', 's14')} ${esc(hint)}</p>` : ''}
<div class="as-status-end"><button type="button" class="btn sm ghost danger" data-act="discardScan" data-k="discard"${app.busy === 'discard' ? ' disabled' : ''}>${ic('trash')}Zahodit</button><span class="cap">Jinak sken zmizí sám za 30 dní.</span></div></section>`;

    return `${head(app, 2, 'Rozpoznávání')}
<div class="as-grid side-first" data-region="fail" data-deep><div class="as-main" data-region="pic"><section class="as-card"><figure class="as-pic as-pic-one"><figcaption><span class="lbl">Váš obrázek</span></figcaption><div class="as-frame${scan.picture ? '' : ' none'}">${picture(app, 'Původní obrázek')}<span class="as-noimg">${ic('image', '')}${scan.hasPicture === false ? 'Obrázek už nemáme uložený' : 'Náhled tohoto obrázku prohlížeč neumí zobrazit'}</span></div></figure></section></div><aside class="as-side" data-region="result">${card}</aside></div>`;
}

// ---- a scan that is finished with ------------------------------------------------------------------

export function goneView(app) {
    const scan = app.scan;
    const copy = {
        cancelled: ['Sken zrušen', scan.hasPicture ? `Obrázek u nás zůstane do ${dateCz(scan.expiresAt) || 'konce 30denní lhůty'}. Můžete ho naskenovat znovu, nebo nahrát jiný.` : 'Nahrajte obrázek znovu, nebo zkuste jiný.'],
        discarded: ['Sken byl zahozen', 'Návrh i obrázek jsme smazali.'],
        expired: ['Sken vypršel', 'Po 30 dnech jsme obrázek i nepřijatý návrh smazali.'],
    }[scan.status] || ['Sken už není k dispozici', ''];
    // The sentence says the picture is kept — so the page offers it: „Stejný obrázek znovu“ puts
    // the stored original back on „Než začneme“ (format, consent, what it costs), nothing starts yet.
    const again = scan.status === 'cancelled' && scan.hasPicture && app.entry.canStart;
    return `<div class="as-backrow" data-region="backrow"><a href="${esc(app.urls.templates)}" class="back">${ic('arrow-left')}Šablony</a>${historyLink(app)}</div>
<header class="head as-head" data-region="head"><div class="head-main"><span class="lbl">Projekt · ${esc(app.project.name)}</span><h1>Šablona z obrázku</h1></div></header>
<section class="as-card as-status" data-region="gone" style="max-width:560px"><div class="as-status-t"><span class="as-result-ico neutral">${ic('info', '')}</span>${pill(scan.status)}</div><h2 tabindex="-1" data-focus>${copy[0]}</h2><p class="as-status-p">${esc(copy[1])}</p>
<div class="as-row">${again ? `<button type="button" class="btn sm btn-primary" data-act="rescanSame" data-k="again"${app.busy === 'rescan' ? ' disabled' : ''}>${ic('refresh')}${app.busy === 'rescan' ? 'Načítám obrázek…' : 'Stejný obrázek znovu'}</button>` : ''}${app.entry.canStart ? `<a href="${esc(app.urls.new)}" class="btn sm ${again ? 'btn-tertiary' : 'btn-primary'}">${ic('upload')}${again ? 'Nahrát jiný obrázek' : 'Nahrát obrázek'}</a>` : ''}<a href="${esc(app.urls.templates)}" class="btn sm btn-tertiary">Zpět na šablony</a>${scan.status === 'cancelled' ? `<button type="button" class="btn sm ghost danger" data-act="discardScan" data-k="discard">${ic('trash')}Zahodit</button>` : ''}</div></section>`;
}

// ---- actions ----------------------------------------------------------------------------------------

async function act(app, name, action) {
    if (app.busy) return null;
    app.busy = name;
    app.render();
    const answer = await api.post(urlFor(app.urls.action, app.scan.id).replace(/\/cancel$/, `/${action}`));
    app.busy = null;
    return answer;
}

async function again(app, name, action) {
    const answer = await act(app, name, action);
    if (!answer) return;
    if (answer.ok && answer.scan) {
        app.actionRefusal = null;
        app.scan = { ...app.scan, ...answer.scan, seen: false };
        app.notifyShell();
        await app.enter();
        return;
    }
    if (answer.code === 'wrong_state') { window.location.reload(); return; }
    app.actionRefusal = answer.code === 'network' || answer.code === 'http_error' ? null : answer.code;
    await app.refreshEntry();
    app.render();
    toast(startHint(answer.code, app.entry) || 'Sken se teď nepodařilo spustit. Zkuste to za chvíli.', { error: true });
}

export const actions = {
    toggleStages: (app, el) => { window.setTimeout(() => { app.stagesOpen = el.parentElement.open; }, 0); },
    cancel: async (app) => {
        const running = app.scan.status === 'running';
        const yes = await confirmDialog({
            title: 'Zrušit sken?',
            text: running ? 'Sken už běží. Zastavíme ho, ale započítá se. Obrázek u nás zůstane 30 dní.' : 'Sken se zastaví a nezapočítá se. Obrázek u nás zůstane 30 dní, můžete ho použít znovu.',
            cancel: 'Pokračovat ve skenu',
            confirm: 'Zrušit sken',
        });
        if (!yes) return;
        const answer = await act(app, 'cancel', 'cancel');
        if (!answer) return;
        if (!answer.ok) {
            // The scan ended while the dialog was open: show how.
            const status = await api.get(urlFor(app.urls.status, app.scan.id));
            if (status.ok && status.scan) {
                app.scan = { ...app.scan, ...status.scan };
                observe(app, app.scan);
                app.notifyShell();
                await app.enter();
            } else app.render();
            return;
        }
        app.notifyShell();
        toast('Sken zrušen');
        const picked = app.picked;
        if (picked) {
            // Back to S2 with the picture (ux.md §11): the file is still in this page.
            app.scan = null;
            app.pushScan(null);
            document.documentElement.dataset.asScan = '';
            await usePicture(app, picked.file);
            return;
        }
        app.scan = { ...app.scan, ...answer.scan };
        await app.enter();
    },
    retry: (app) => again(app, 'retry', 'retry'),
    continueAnyway: (app) => again(app, 'continue', 'continue'),
    discardScan: async (app) => {
        const yes = await confirmDialog({ title: 'Zahodit sken?', text: 'Sken zmizí ze seznamů a jeho obrázek smažeme.', confirm: 'Zahodit' });
        if (!yes) return;
        const answer = await act(app, 'discard', 'discard');
        if (!answer) return;
        if (answer.ok || answer.code === 'wrong_state') { app.leave(app.urls.templates, 'Sken zahozen'); return; }
        app.render();
        toast('Sken se nepodařilo zahodit. Zkuste to znovu.', { error: true });
    },
};
