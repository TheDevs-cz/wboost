// What a page warning says and what fixes it (architecture §E.12; feedback K12). Pure — no DOM.
//
// ONE place for the wording, so the mark on the photo, the thumbnail badge, the „Upozornění“
// list, the step-4 page panel and MCP (`warning.message`, built by `render/warnings.js` from
// `warningMessage`) say the same thing.
//
// `describeWarning(warning, { designer = true }): { kind, title, text, hint, actions }`
//   title    a plain Czech headline („Fotka má na tisk malé rozlišení“)
//   text     the concrete facts (pixels, dpi, dates, counts — numbers the Czech way)
//   hint     how to fix it; the client's variant leaves out what only the designer can change
//   actions  `[{ id, label }]` in order of usefulness; the UI binds each id to a handler and drops
//            those the current surface cannot do. Ids:
//              'photo-replace'     pick another photo for the page
//              'photo-crop'        move / zoom the crop of the page's photo
//              'photo-zoom-reset'  back to 100 % (the zoom costs resolution)
//              'day'               the day's menu (its collections and events, removable)
//              'events'            the tool „Akce a spolky“ on the warning's month
//              'element'           designer only: step 3 with `action.selection` selected
//              'page-content'      the content fields of a cover / back / special page
// `warningMessage(warning)`  title + facts + hint as one sentence block (designer wording) — the
//                            `message` of a warning and the text MCP returns.
//
// Facts a warning carries (`warning.facts`, set by `render/warnings.js`):
//   portrait  { width, height, keep }            photo px; `keep` = visible share of its height
//   dpi       { dpi, width, height, zoom, frameW, frameH, dpiAt100 }   frame in mm incl. bleed
//   icons     { date, total, drawn }
//   event     { date, count }                    events of the day (dropped ones included)
//   overflow  { issue }                          the sentence of `specialPageIssues`

/** The dpi under which a photo is flagged, and the dpi a print photo should ideally have. */
export const MIN_DPI = 200;
export const IDEAL_DPI = 300;

const NBSP = ' ';

/** An integer the Czech way: thousands grouped by a non-breaking space („3 660“). */
export function czNumber(n) {
    const value = Math.round(Number(n) || 0);
    const sign = value < 0 ? '-' : '';
    return sign + String(Math.abs(value)).replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
}

/** A share 0..1 as a Czech percentage („37 %“). */
export const czPercent = (share) => `${Math.round(Math.max(0, Math.min(1, share)) * 100)}${NBSP}%`;

/** „1 ikona“ · „2 ikony“ · „5 ikon“ — forms = [one, two-to-four, five-and-more]. */
export function plural(n, forms) {
    if (n === 1) return forms[0];
    if (n >= 2 && n <= 4) return forms[1];
    return forms[2];
}

const czDay = (isoDate) => `${Number(isoDate.slice(8, 10))}.${NBSP}${Number(isoDate.slice(5, 7))}.`;
const px = (w, h) => `${czNumber(w)}${NBSP}×${NBSP}${czNumber(h)}${NBSP}px`;

/** Pixels a frame-shaped photo needs to print at `dpi` at 100 % (a replaced photo starts at 100 %). */
export function pixelsFor(frameW, frameH, dpi) {
    return { width: Math.ceil((frameW * dpi) / 25.4), height: Math.ceil((frameH * dpi) / 25.4) };
}

const capitalise = (text) => text.charAt(0).toUpperCase() + text.slice(1);
const sentence = (text) => (/[.!?…]$/.test(text) ? text : `${text}.`);

function describePortrait(f) {
    return {
        title: 'Fotka na výšku v rámečku na šířku',
        text: `Fotka má ${px(f.width, f.height)} a stojí na výšku, rámeček je na šířku — vytiskne se jen ${czPercent(f.keep)} její výšky, zbytek se ořízne.`,
        hint: 'Nahrajte fotku na šířku, nebo posuňte výřez tak, aby bylo vidět to důležité.',
        actions: [
            { id: 'photo-crop', label: 'Posunout výřez' },
            { id: 'photo-replace', label: 'Vyměnit fotku' },
        ],
    };
}

function describeDpi(f) {
    const min = pixelsFor(f.frameW, f.frameH, MIN_DPI);
    const ideal = pixelsFor(f.frameW, f.frameH, IDEAL_DPI);
    const zoomed = f.zoom > 1.0001;
    let text = `Fotka má ${px(f.width, f.height)} — v této velikosti na stránce to vychází jen na ${f.dpi}${NBSP}dpi, vytiskne se neostře.`;
    if (zoomed) text += ` Přiblížení na ${Math.round(f.zoom * 100)}${NBSP}% rozlišení ještě snižuje — při 100${NBSP}% by měla ${f.dpiAt100}${NBSP}dpi.`;
    const actions = [{ id: 'photo-replace', label: 'Vyměnit fotku' }];
    if (zoomed) actions.push({ id: 'photo-zoom-reset', label: 'Zrušit přiblížení' });
    return {
        title: 'Fotka má na tisk malé rozlišení',
        text,
        hint: `Pro ostrý tisk nahrajte fotku aspoň ${px(min.width, min.height)} (${MIN_DPI}${NBSP}dpi), ideálně ${px(ideal.width, ideal.height)} (${IDEAL_DPI}${NBSP}dpi).`,
        actions,
    };
}

function describeIcons(f, designer) {
    const missing = Math.max(0, f.total - f.drawn);
    const actions = [{ id: 'day', label: 'Upravit den' }];
    if (designer) actions.push({ id: 'element', label: 'Upravit mřížku ikon', selection: { el: 'cal', part: 'icon' } });
    return {
        title: 'Ikony se do řádku nevejdou',
        text: `Ve dni ${czDay(f.date)} ${plural(f.total, ['je', 'jsou', 'je'])} ${f.total} ${plural(f.total, ['ikona', 'ikony', 'ikon'])}, místo je jen pro ${f.drawn} — ${missing} ${plural(missing, ['se nevytiskne', 'se nevytisknou', 'se nevytiskne'])}.`,
        hint: designer
            ? 'Uberte v tento den svoz nebo akci, nebo v návrhu zvolte mřížku ikon s více místy.'
            : 'Uberte v tento den svoz nebo akci, nebo požádejte grafika o víc místa pro ikony.',
        actions,
    };
}

function describeEvent(f, designer) {
    const many = f.count > 1;
    const actions = [{ id: 'events', label: 'Upravit akce' }];
    if (designer) actions.push({ id: 'element', label: 'Zvětšit pole pro akce', selection: { el: 'cal', part: 'ev' } });
    return {
        title: many ? 'Akce se do pole nevejdou' : 'Akce se do pole nevejde',
        text: many
            ? `${f.count} ${plural(f.count, ['akce', 'akce', 'akcí'])} ze dne ${czDay(f.date)} se do pole pro akce ${plural(f.count, ['nevejde', 'nevejdou', 'nevejde'])} — text přeteče přes jeho okraj.`
            : `Ve dni ${czDay(f.date)} je název akce delší, než pole pro akce pojme — text přeteče přes jeho okraj.`,
        hint: designer
            ? 'Zkraťte název akce nebo ji přesuňte na jiný den, nebo v návrhu zvětšete pole pro akce.'
            : 'Zkraťte název akce, nebo ji přesuňte na jiný den.',
        actions,
    };
}

function describeOverflow(f) {
    const issue = String((f && f.issue) || 'Text se nevejde');
    const cut = issue.indexOf(' — ');
    const what = cut >= 0 ? issue.slice(0, cut) : issue;
    const how = cut >= 0 ? issue.slice(cut + 3) : 'zkraťte ho';
    return {
        title: 'Text se na stranu nevejde',
        text: `${sentence(what)} Co se nevejde, skončí třemi tečkami „…“.`,
        hint: sentence(capitalise(how)),
        actions: [{ id: 'page-content', label: 'Upravit obsah stránky' }],
    };
}

export function describeWarning(warning, opts = {}) {
    const designer = opts.designer !== false;
    const f = warning.facts || {};
    let out;
    switch (warning.kind) {
        case 'portrait':
            out = describePortrait(f);
            break;
        case 'dpi':
            out = describeDpi(f);
            break;
        case 'icons':
            out = describeIcons(f, designer);
            break;
        case 'event':
            out = describeEvent(f, designer);
            break;
        case 'overflow':
            out = describeOverflow(f);
            break;
        default:
            out = { title: 'Upozornění', text: String(warning.message || ''), hint: '', actions: [] };
    }
    return { kind: warning.kind, ...out };
}

/** Title, facts and the fix as one text — `warning.message`, the tooltip and what MCP returns. */
export function warningMessage(warning) {
    const d = describeWarning(warning);
    return [sentence(d.title), d.text, d.hint].filter(Boolean).join(' ');
}
