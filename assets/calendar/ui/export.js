// Stáhnout PDF — the export dialog (docs/architecture/calendars.md § "PDF export"; copy: docs/calendar/architecture.md §G.3).
// CSS: calendar-dialogs.css (`.cal-exp…`); the two variants are the kit's radio cards (`.rpick > .rcard`).
//
// Contract: `openExport(o): { close() }` — the SAME dialog from step 5 (designer, read-only member,
// client link) and from a card of the list page; it needs addresses only, never the calendar document.
//   o.name     calendar name (dialog subtitle)
//   o.url      POST address of the export request (`env.urls.export`)
//   o.csrf     CSRF token (`env.csrf`)
//   o.toast    `toast(message, error?, action?)` of ui/dom.js
//   o.unsaved  optional `{ dirty(): boolean, save(): Promise<{ ok }> }` — the workspace. The export
//              is made from the SAVED calendar, so with unsaved changes the dialog says so and offers
//              „Uložit a exportovat“ beside exporting the saved state.
//
// One export at a time per dialog: choose a variant → the job is requested (an unchanged calendar is
// answered at once with the file that already exists) → the status address is polled every 1.5 s
// while the dialog is open („Připravuji PDF… 12 z 55 stran“, then „Dokončuji soubor…“ while the file
// is assembled and stored) → the download starts by itself through a hidden iframe (a navigation
// would trip the workspace's unsaved-changes guard) and a „Stáhnout“ link stays in the dialog.
//
// On opening the dialog asks the server what is there for the saved calendar (GET of `o.url`): a job
// on its way — started in this dialog before it was closed, in another tab or by somebody else — is
// picked up at once and its progress shown; a finished file is marked on its card („Připraveno ·
// 527 MB“, `[data-cal-export-ready]`) and the action reads „Stáhnout PDF“. A failed job is a toast with „Zkusit znovu“; the dialog returns to the
// choice. Closing the dialog only stops the polling — the job goes on and the next request for the
// same variant finds its file.
//
// DOM hooks: the dialog `[data-cal-dialog="export"][data-cal-export-state="choose | running | done"]`,
// `input[data-cal-export-variant="print | approval"]`, `[data-cal-action="export-start"]`,
// `[data-cal-action="export-save-start"]` (only with unsaved changes), `[data-cal-export-unsaved]`,
// `[data-cal-export-progress]` (text) + `progress`, `a[data-cal-action="export-download"]`,
// `[data-cal-action="export-again"]`.

import { fetchCurrentExports, fetchExport, requestExport } from '../state/api.js';
import { h, icon, openModal } from './dom.js';

const POLL_MS = 1500;
/** Consecutive failed status reads after which the dialog gives up (the job itself may still finish). */
const POLL_FAILURES = 4;

const VARIANTS = [
    {
        key: 'print',
        title: 'Pro tisk',
        text: 'Všechny strany tak, jak půjdou do tiskárny — se spadávkou a ořezovými značkami podle nastavení tisku, fotky v plné kvalitě.',
    },
    {
        key: 'approval',
        title: 'Náhled k odsouhlasení',
        text: 'Strany oříznuté na čistý formát, fotky v menší kvalitě — malý soubor, který se dá poslat e‑mailem.',
    },
];

export function formatBytes(bytes) {
    const value = Number(bytes);
    if (!(value > 0)) return '';
    if (value < 1e6) return `${Math.max(1, Math.round(value / 1e3))} kB`;
    return `${(value / 1e6).toLocaleString('cs-CZ', { maximumFractionDigits: value < 1e7 ? 1 : 0 })} MB`;
}

/** Starts a file download without leaving the page (the answer is an attachment, or a redirect to one). */
function startDownload(url) {
    for (const old of document.querySelectorAll('iframe[data-cal-export-frame]')) old.remove();
    document.body.append(h('iframe', { src: url, hidden: true, tabindex: '-1', 'aria-hidden': 'true', 'data-cal-export-frame': '' }));
}

export function openExport({ name, url, csrf, toast, unsaved = null }) {
    let variant = 'print';
    let timer = null;
    let closed = false;
    let busy = false;
    let failures = 0;
    const readyVariants = new Set();

    const radios = VARIANTS.map((v) => {
        const input = h('input', {
            type: 'radio',
            class: 'sr',
            name: 'cal-export-variant',
            value: v.key,
            checked: v.key === variant,
            'data-cal-export-variant': v.key,
            onChange: () => {
                variant = v.key;
                label();
            },
        });
        const ready = h('span', { class: 'cal-exp-ready', hidden: true, 'data-cal-export-ready': v.key });
        return { key: v.key, input, ready, card: h('label', { class: 'rcard' }, input, h('span', { class: 'rd', 'aria-hidden': 'true' }), h('span', { class: 'rtx' }, h('span', { class: 'rt' }, v.title), h('span', { class: 'rsub' }, v.text), ready)) };
    });
    const picker = h('div', { class: 'rpick', role: 'radiogroup', 'aria-label': 'Varianta PDF' }, ...radios.map((r) => r.card));

    const unsavedNote = h('p', { class: 'cal-exp-note', role: 'status', 'data-cal-export-unsaved': '', hidden: true }, icon('alert', 's16'), h('span', null, 'Export použije uložený stav — nejdřív uložte změny.'));

    const progressText = h('span', { 'data-cal-export-progress': '' }, 'Připravuji PDF…');
    const bar = h('progress', { max: '1', value: '0', 'aria-label': 'Průběh přípravy PDF' });
    const running = h('div', { class: 'cal-exp-run', role: 'status', 'aria-live': 'polite', hidden: true }, h('p', null, h('span', { class: 'spinner-border spinner-border-sm', 'aria-hidden': 'true' }), progressText), bar, h('p', { class: 'cap' }, 'Okno můžete zavřít — PDF se připraví i tak a při dalším otevření ho tu najdete.'));

    const fileName = h('b');
    const fileSize = h('span', { class: 'cap' });
    const download = h('a', { class: 'btn sm btn-primary', href: '#', download: '', 'data-cal-action': 'export-download' }, icon('download', 's16'), 'Stáhnout');
    const again = h('button', { type: 'button', class: 'btn sm btn-ghost', 'data-cal-action': 'export-again', onClick: () => show('choose') }, 'Jiná varianta');
    const done = h('div', { class: 'cal-exp-done', hidden: true }, h('span', { class: 'ico-c' }, icon('check-circle', 's20')), h('div', { class: 'cal-exp-file' }, h('span', null, 'PDF je připravené — stahování začalo.'), fileName, fileSize), again);

    const start = h('button', { type: 'button', class: 'btn sm btn-primary', 'data-cal-action': 'export-start', onClick: () => run(false) }, icon('download', 's16'), 'Připravit PDF');
    const saveStart = h('button', { type: 'button', class: 'btn sm btn-primary', 'data-cal-action': 'export-save-start', hidden: true, onClick: () => run(true) }, icon('download', 's16'), 'Uložit a exportovat');
    const footer = h('div', { class: 'cal-exp-foot' }, start, saveStart, download);

    const body = h('div', { class: 'cal-exp' }, name ? h('p', { class: 'cap cal-exp-name' }, name) : null, picker, unsavedNote, running, done);
    const modal = openModal({
        title: 'Stáhnout PDF',
        body,
        footer,
        done: { label: 'Zavřít', kind: 'tertiary' },
        onClose: () => {
            closed = true;
            clearTimeout(timer);
        },
    });
    modal.el.dataset.calDialog = 'export';

    /** `choose` | `running` | `done` */
    function show(state) {
        modal.el.dataset.calExportState = state;
        const dirty = Boolean(unsaved && unsaved.dirty());
        const choosing = state === 'choose';
        picker.hidden = state === 'done';
        for (const r of radios) r.input.disabled = !choosing;
        picker.classList.toggle('is-locked', !choosing);
        unsavedNote.hidden = !(choosing && dirty);
        running.hidden = state !== 'running';
        done.hidden = state !== 'done';
        // with unsaved changes the primary action saves first; exporting the saved state stays possible
        saveStart.hidden = !(choosing && dirty);
        start.hidden = !choosing;
        label();
        download.hidden = state !== 'done';
        if (choosing) (radios.find((r) => r.input.checked) || radios[0]).input.focus({ preventScroll: true });
    }

    /** The action's words: a finished file of the chosen variant is only fetched, not prepared. */
    function label() {
        const dirty = Boolean(unsaved && unsaved.dirty());
        start.className = `btn sm ${dirty ? 'btn-tertiary' : 'btn-primary'}`;
        start.lastChild.textContent = dirty ? 'Exportovat uložený stav' : readyVariants.has(variant) ? 'Stáhnout PDF' : 'Připravit PDF';
    }

    function progress(job) {
        const total = Number(job.total) || 0;
        const finished = Math.min(total, Number(job.done) || 0);
        if (job.status === 'running' && total > 0 && finished >= total) {
            // every page is drawn: the file is being put together and stored (seconds for a big one)
            progressText.textContent = 'Dokončuji soubor…';
            bar.removeAttribute('value');
            return;
        }
        if (job.status === 'queued' || !total) {
            progressText.textContent = job.status === 'queued' ? 'Připravuji PDF… čeká ve frontě' : 'Připravuji PDF…';
            bar.removeAttribute('value');
            return;
        }
        progressText.textContent = `Připravuji PDF… ${finished} z ${total} stran`;
        bar.max = total;
        bar.value = finished;
    }

    function fail(message) {
        busy = false;
        if (closed) return;
        show('choose');
        toast(message || 'PDF se nepodařilo připravit', true, { label: 'Zkusit znovu', onClick: () => (closed ? openExport({ name, url, csrf, toast, unsaved }) : run(false)) });
    }

    function finish(job) {
        busy = false;
        if (closed) return;
        const card = radios.find((r) => r.key === job.variant);
        if (card) {
            readyVariants.add(card.key);
            card.ready.textContent = `Připraveno${job.sizeBytes ? ` · ${formatBytes(job.sizeBytes)}` : ''}`;
            card.ready.hidden = false;
        }
        fileName.textContent = job.fileName || 'kalendar.pdf';
        fileSize.textContent = formatBytes(job.sizeBytes);
        download.href = job.urls.download;
        download.setAttribute('download', job.fileName || '');
        show('done');
        startDownload(job.urls.download);
        download.focus({ preventScroll: true });
    }

    function follow(job) {
        if (closed) return;
        if (job.status === 'done' && job.urls && job.urls.download) {
            finish(job);
            return;
        }
        if (job.status === 'failed') {
            fail(job.error);
            return;
        }
        progress(job);
        timer = setTimeout(async () => {
            let next = job;
            try {
                next = await fetchExport(job.urls.status);
                failures = 0;
            } catch (error) {
                failures += 1;
                if (failures >= POLL_FAILURES) {
                    fail(error && error.message);
                    return;
                }
            }
            follow(next);
        }, POLL_MS);
    }

    async function run(saveFirst) {
        if (busy || closed) return;
        busy = true;
        failures = 0;
        if (saveFirst && unsaved) {
            saveStart.classList.add('is-loading');
            saveStart.disabled = true;
            start.disabled = true;
            let saved = null;
            try {
                saved = await unsaved.save();
            } catch (e) {
                saved = null;
            }
            saveStart.classList.remove('is-loading');
            saveStart.disabled = false;
            start.disabled = false;
            if (closed) return;
            if (!saved || !saved.ok) {
                // the workspace has already said why the save failed
                busy = false;
                show('choose');
                return;
            }
        }
        show('running');
        progress({ status: 'queued', total: 0 });
        try {
            follow(await requestExport(url, csrf, variant));
        } catch (error) {
            fail(error && error.message);
        }
    }

    show('choose');

    // what is already there for the saved calendar: a running job is picked up, a finished file marked
    fetchCurrentExports(url).then((current) => {
        if (closed || busy) return;
        for (const r of radios) {
            const job = current[r.key];
            if (job && job.status === 'done') {
                readyVariants.add(r.key);
                r.ready.textContent = `Připraveno${job.sizeBytes ? ` · ${formatBytes(job.sizeBytes)}` : ''}`;
                r.ready.hidden = false;
            }
        }
        const pending = radios.find((r) => current[r.key] && (current[r.key].status === 'queued' || current[r.key].status === 'running'));
        if (pending) {
            variant = pending.key;
            pending.input.checked = true;
            busy = true;
            show('running');
            follow(current[pending.key]);
            return;
        }
        label();
    });

    return { close: () => modal.close() };
}
