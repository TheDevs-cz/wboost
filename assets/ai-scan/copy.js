// AI sken — the Czech copy of the flow, verbatim from docs/ai-scan/ux.md (revision 5).
// Codes come from the status document (docs/architecture/ai-scan.md); nothing here is prose
// from a picture.
import { ic, pl, firstOfMonthCz } from './kit.js';

/** §2.1 — pill per state: [tone, icon, label]. */
export const PILLS = {
    checking: ['pch', 'spin', 'Kontrola obrázku'],
    queued: ['pch', 'clock', 'Ve frontě'],
    running: ['pch', 'spin', 'Sken běží'],
    ready: ['pch', 'eye', 'Připraveno ke kontrole'],
    accepted: ['mint', 'check', 'Šablona vytvořena'],
    discarded: ['neutral', null, 'Zahozeno'],
    cancelled: ['neutral', null, 'Zrušeno'],
    stopped: ['neutral', 'image-off', 'Potřebuje jiný obrázek'],
    failed: ['rose', 'x-circle', 'Nepodařilo se'],
    refused: ['rose', 'x-circle', 'Odmítnuto'],
    expired: ['neutral', null, 'Vypršelo'],
};
export function pill(status, tail = '') {
    const [tone, icon, label] = PILLS[status] || PILLS.expired;
    const mark = icon === 'spin' ? '<span class="as-spin" style="width:10px;height:10px;border-width:1.5px"></span>' : icon ? ic(icon, 's12') : '';
    return `<span class="pill ${tone}">${mark}${label}${tail}</span>`;
}

/** S3 — the stages exactly as the scanner reports them (templates). */
export const STAGES = [
    { id: 'check', label: 'Kontrola obrázku', title: 'Kontroluji obrázek…' },
    { id: 'read', label: 'Čtení textů', title: 'Čtu texty…' },
    { id: 'analyse', label: 'Rozbor grafiky', title: 'Rozebírám grafiku…' },
    { id: 'build', label: 'Sestavení šablony', title: 'Sestavuji šablonu…' },
    { id: 'refine', label: 'Porovnání a doladění', title: 'Porovnávám s originálem…' },
    { id: 'finish', label: 'Dokončení', title: 'Dokončuji…' },
];
export const stageIndex = (id) => STAGES.findIndex((s) => s.id === id);
/** A stage note (a code with integer parameters) in Czech; null when there is none. */
export function noteText(note, params) {
    const n = Number(params && params.n);
    switch (note) {
        case 'texts_found': return n > 0 ? `${pl(n, 'Nalezen', 'Nalezeny', 'Nalezeno')} ${n} ${pl(n, 'text', 'texty', 'textů')}` : 'Texty jsme nenašli';
        case 'rows_found': return n > 0 ? `${n} ${pl(n, 'řádek dní', 'řádky dní', 'řádků dní')}` : null;
        case 'zooming': return 'Prohlížím detail';
        case 'measuring': return 'Měřím barvy a tvary';
        case 'fonts': return 'Porovnávám písma';
        case 'provider_retry': return `Služba AI je vytížená, zkoušíme to znovu (${Number(params && params.n) || 1}/${Number(params && params.of) || 3})`;
        default: return null;
    }
}

/** S1 — refusals of a file. */
export const UPLOAD_ERRORS = {
    type: () => 'Tento soubor neumíme. Nahrajte obrázek JPG, PNG, WebP nebo HEIC.',
    source: () => 'PDF a zdrojové soubory zatím neumíme. Uložte stránku jako obrázek (PNG nebo JPG) a nahrajte ji.',
    many: () => 'Nahrajte jen jeden obrázek.',
    bytes: () => 'Obrázek je větší než 10 MB. Zmenšete ho nebo ho uložte jako JPG.',
    small: (w, h) => `Obrázek je příliš malý (${w} × ${h} px). Kratší strana musí mít aspoň 600 px, lépe 1000 px a víc.`,
    large: (w, h) => `Obrázek je příliš velký (${w} × ${h} px). Zmenšete ho — nejvýše 8000 px na stranu a 36 megapixelů.`,
    network: () => 'Nahrání se nepodařilo. Zkontrolujte připojení a zkuste to znovu.',
};

/** S2 — why the start button is disabled / a start was refused: code → hint (html-safe text). */
export function startHint(code, entry) {
    const month = firstOfMonthCz(entry && entry.nextMonth);
    switch (code) {
        case 'consent_required': return 'Nejdřív potvrďte oba souhlasy.';
        case 'project_queue_full': return 'V projektu už běží nebo čekají 4 skeny. Počkejte, až některý doběhne.';
        case 'limit_reached': return `Projekt má skeny pro tento měsíc vyčerpané. Další budou od ${month}.`;
        case 'daily_starts_reached': return 'Dnes už jste spustili 10 skenů. Zítra můžete pokračovat.';
        case 'queue_full': return 'Fronta je teď plná. Zkuste to za chvíli.';
        case 'project_paused': return 'AI dnes odmítla tři obrázky z tohoto projektu. Další skeny půjdou spustit zítra.';
        case 'temporarily_unavailable': return 'AI sken je dočasně nedostupný. Víme o tom. Zkuste to později.';
        case 'no_fonts': return 'Projekt zatím nemá žádné písmo. Bez písma neumíme vytvořit texty.';
        case 'source_unscannable': return 'Tento obrázek se nám nedaří zpracovat. Zkuste jiný.';
        case 'no_retries_left': return 'Tento obrázek se nám nedaří zpracovat. Zkuste jiný.';
        case 'not_enabled': return 'AI sken je pro tento projekt vypnutý. Rozpracované návrhy můžete dokončit, nové skeny spustit nejdou.';
        default: return null;
    }
}

const STOP = { tone: 'neutral', icon: 'image-off', stop: true };
const FAIL = { tone: '', icon: 'alert' };
/** S5 — the result card per status + code: title, text, which actions. */
export function failure(status, code) {
    if (status === 'refused') {
        return { tone: '', icon: 'x-circle', title: 'Tento obrázek AI odmítla zpracovat', text: 'Služba ho vyhodnotila jako obsah, se kterým nepracuje. Pokud si myslíte, že jde o omyl, napište nám.', upload: true, contact: true };
    }
    if (status === 'stopped') {
        switch (code) {
            case 'photo_of_print': return { ...STOP, title: 'Tohle vypadá jako fotka tisku', text: 'Fotka z ruky zkreslí rozměry i barvy, výsledek bude jen přibližný. Nejlépe funguje export nebo snímek obrazovky.', upload: true, anyway: true };
            case 'blurry_or_small': return { ...STOP, title: 'Obrázek je příliš rozmazaný', text: 'Texty v něm nepřečteme. Nahrajte ostřejší nebo větší verzi.', upload: true };
            case 'not_expected_kind': return { ...STOP, title: 'V obrázku jsme nenašli grafiku', text: 'Obrázek je prázdný nebo je to jen fotografie bez textů a tvarů.', upload: true };
            case 'several_designs': return { ...STOP, title: 'V obrázku je víc grafik', text: 'Z jednoho obrázku umíme udělat jednu šablonu. Ořízněte ho na jednu grafiku.', upload: true };
            default: return { ...STOP, title: 'Tento obrázek neumíme zpracovat', text: 'Soubor je poškozený nebo v neobvyklém formátu. Uložte ho znovu jako PNG nebo JPG.', upload: true };
        }
    }
    switch (code) {
        case 'renderer_unavailable': return { ...FAIL, title: 'Sken se nepodařil', text: 'Náhled se teď nepodařilo vykreslit. Váš obrázek máme uložený — zkuste to znovu.', retry: true, other: true };
        case 'timeout': return { ...FAIL, icon: 'clock', title: 'Sken trval příliš dlouho', text: 'Zastavili jsme ho, protože trval mnohem déle než obvykle. Pomůže jednodušší nebo menší obrázek.', retry: true, other: true };
        case 'interrupted': return { ...FAIL, title: 'Sken se přerušil', text: 'Na naší straně se něco pokazilo. Váš obrázek máme uložený.', retry: true };
        case 'queue_timeout': return { ...FAIL, icon: 'clock', title: 'Sken jsme nestihli spustit', text: 'Čekal ve frontě déle než hodinu, proto jsme ho zastavili. Váš obrázek máme uložený — zkuste to znovu.', retry: true, noStages: true };
        case 'build': return { ...FAIL, title: 'Návrh se nepodařilo sestavit', text: 'Obrázek jsme přečetli, ale návrh se nepodařilo poskládat. Dali jsme o tom vědět vývojářům.', retry: true, other: true };
        default: return { ...FAIL, title: 'Sken se nepodařil', text: 'Služba AI teď neodpovídá. Váš obrázek máme uložený — stačí to zkusit znovu.', retry: true, other: true };
    }
}
/** One line for a card / list: what happened (no title). */
export function failureShort(status, code) {
    if (status === 'refused') return 'AI obrázek odmítla zpracovat';
    if (status === 'stopped') return { photo_of_print: 'Vypadá jako fotka tisku', blurry_or_small: 'Obrázek je příliš rozmazaný', not_expected_kind: 'V obrázku jsme nenašli grafiku', several_designs: 'V obrázku je víc grafik' }[code] || 'Obrázek neumíme zpracovat';
    return { renderer_unavailable: 'Náhled se nepodařilo vykreslit', timeout: 'Trval příliš dlouho', interrupted: 'Sken se přerušil', queue_timeout: 'Nestihli jsme ho spustit', build: 'Návrh se nepodařilo sestavit' }[code] || 'Služba AI neodpovídala';
}

/** §2.4 — the summary line of the review. */
export function summaryLine(counts, done = 'Hotovo') {
    const parts = [
        counts.texts ? `${counts.texts} ${pl(counts.texts, 'text', 'texty', 'textů')}` : '',
        counts.shapes ? `${counts.shapes} ${pl(counts.shapes, 'tvar', 'tvary', 'tvarů')}` : '',
        counts.places ? `${counts.places} ${pl(counts.places, 'místo pro obrázek', 'místa pro obrázky', 'míst pro obrázky')}` : '',
        counts.background ? 'pozadí' : '',
    ].filter(Boolean);
    return `${done}: ${parts.join(', ')}.`;
}
export function toDecideLine(n) {
    return n > 0 ? `${pl(n, 'Zbývá', 'Zbývají', 'Zbývá')} rozhodnout ${n} ${pl(n, 'věc', 'věci', 'věcí')}.` : 'Nic k rozhodnutí.';
}

/** S4 "Pro informaci" — the closed list of simplifications: code → sentence. */
export const SIMPLIFICATIONS = {
    shadow_omitted: () => 'Stín pod nadpisem jsme vynechali.',
    rotation_straightened: () => 'Otočený štítek je narovnaný.',
    outline_filled: () => 'Obrys písma neumíme — písmo je plné.',
    letter_spacing_approximate: () => 'Rozestupy písmen jsou jen přibližné.',
    mixed_styles: (n) => `Dvoubarevný řádek je rozdělený na ${Math.max(2, n)} ${pl(Math.max(2, n), 'text', 'texty', 'textů')}.`,
    gradient_approximate: () => 'Přechod barev v pozadí je jen přibližný.',
    pattern_as_place: () => 'Vzor nebo textura má své místo pro obrázek — na tvary ji nepřevádíme.',
};
/** Font group roles of the report → the genitive used in `Písmo {skupiny}`. */
export const FONT_ROLES = { headline: 'nadpisů', subheadline: 'podnadpisů', body: 'textů', button: 'tlačítek', caption: 'popisků', other: 'dalších textů' };
