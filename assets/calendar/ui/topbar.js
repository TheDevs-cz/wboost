// Workspace top bar (handoff §4.2, map §8.4): back arrow, name, the five steps, undo / redo,
// save status, Uložit, Zpět, Pokračovat / Dokončit / Uložit vzhled. A client gets the reduced bar:
// name as text, status and Uložit.
//
// Built once and updated in place from store events, so the name field keeps its focus.
//
// AI scan (`ctx.scan`, ui/scan.js): while a scan of this calendar is unfinished — or its proposal
// waits — step 2 carries a peach dot (`.as-stepdot`); while the scan panel shows a lavender
// primary of its own, Pokračovat is drawn tertiary (one primary per view).

import { h, icon } from './dom.js';

export const STEPS = Object.freeze(['Typ', 'Rozvržení', 'Úpravy prvků', 'Obsah', 'Náhled']);

const STATUS_TEXT = { clean: 'Vše uloženo', dirty: 'Neuložené změny', saving: 'Ukládám…', error: 'Uložení se nepovedlo' };

/**
 * @param root  the header slot of the workspace
 * @param ctx   `Ctx`; uses `ctx.actions.save()`, `ctx.actions.leave()`, `ctx.actions.finish()`
 * @returns `{ destroy() }`
 */
export function mountTopBar(root, ctx) {
    const { store, env } = ctx;
    const designer = env.mode === 'designer';
    const design = () => store.getState().doc.design;

    const back = env.urls && env.urls.list
        ? h('a', { class: 'btn icon sm btn-tertiary cal-back', href: env.urls.list, 'aria-label': 'Zpět', title: 'Zpět', 'data-cal-action': 'back-to-list', onClick: (event) => { event.preventDefault(); ctx.actions.leave(); } }, icon('arrow-left', 's16'))
        : null;

    let name;
    if (designer) {
        name = h('input', { class: 'cal-name', type: 'text', maxlength: '60', autocomplete: 'off', spellcheck: 'false', 'aria-label': 'Název kalendáře', 'data-cal-name': '', value: design().name });
        name.addEventListener('input', () => {
            if (name.value.trim() !== '') store.setDesign({ name: name.value.trim() });
        });
        name.addEventListener('change', () => {
            name.value = design().name;
        });
        name.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                name.blur();
            } else if (event.key === 'Escape') {
                name.value = design().name;
                name.blur();
            }
        });
    } else {
        name = h('span', { class: 'cal-name ro', 'data-cal-name': '' }, design().name);
    }

    const title = h('div', { class: 'cal-title' }, h('span', { class: 'lbl' }, `${env.projectName} · Kalendáře${designer ? ' · vzhled' : ''}`), name);

    const stepButtons = [];
    let steps = null;
    if (designer) {
        steps = h('ol', { class: 'cal-steps', 'aria-label': 'Kroky nastavení' });
        STEPS.forEach((label, index) => {
            const n = index + 1;
            const number = h('span', { class: 'cal-stn', 'aria-hidden': 'true' }, String(n));
            const button = h('button', { type: 'button', class: 'cal-step', 'data-cal-step': String(n), title: label, onClick: () => store.setStep(n) }, number, h('span', { class: 'cal-stl' }, label));
            if (n === 2) button.append(h('span', { class: 'as-stepdot', role: 'img', hidden: true, 'data-cal-scan-dot': '' }));
            stepButtons.push({ n, button, number });
            steps.append(h('li', null, button));
        });
    }

    const undo = h('button', { type: 'button', class: 'btn icon sm btn-ghost', 'aria-label': 'Zpět o krok', title: 'Zpět (Ctrl+Z)', 'data-cal-action': 'undo', onClick: () => store.undo() }, icon('undo', 's16'));
    const redo = h('button', { type: 'button', class: 'btn icon sm btn-ghost', 'aria-label': 'Znovu', title: 'Znovu (Ctrl+Shift+Z)', 'data-cal-action': 'redo', onClick: () => store.redo() }, icon('redo', 's16'));
    const history = designer ? h('div', { class: 'cal-hist', hidden: true }, undo, redo) : null;

    const status = h('span', { class: 'cal-status', 'data-cal-status': 'clean', role: 'status' });
    const save = h('button', { type: 'button', class: 'btn sm btn-tertiary', title: 'Uložit (Ctrl+S)', 'data-cal-action': 'save', onClick: () => ctx.actions.save() }, 'Uložit');
    const prev = designer ? h('button', { type: 'button', class: 'btn sm btn-tertiary cal-prev', 'data-cal-action': 'prev-step', onClick: () => store.setStep(design().step - 1) }, 'Zpět') : null;
    const next = designer
        ? h('button', {
            type: 'button',
            class: 'btn sm btn-primary cal-next',
            'data-cal-action': 'next-step',
            onClick: () => {
                if (design().step === 5) ctx.actions.finish();
                else store.setStep(design().step + 1);
            },
        })
        : null;

    const bar = h('header', { class: `cal-top${designer ? '' : ' client'}` }, h('div', { class: 'cal-top-id' }, back, title), steps, history, h('span', { class: 'cal-top-sp' }), h('div', { class: 'cal-top-acts' }, status, save, prev, next));
    root.replaceChildren(bar);

    function syncSteps() {
        if (!designer) return;
        const d = design();
        for (const { n, button, number } of stepButtons) {
            const on = n === d.step;
            const done = n < d.step;
            button.className = `cal-step${on ? ' on' : ''}${done ? ' done' : ''}`;
            if (on) button.setAttribute('aria-current', 'step');
            else button.removeAttribute('aria-current');
            button.disabled = n >= 3 && !d.layout;
            number.replaceChildren(done ? icon('check', 's12') : String(n));
        }
        prev.hidden = d.step <= 1;
        next.disabled = d.step >= 2 && !d.layout;
        if (d.step === 5) next.replaceChildren(d.finished ? 'Uložit vzhled' : 'Dokončit');
        else next.replaceChildren('Pokračovat', icon('arrow-right', 's16'));
    }

    function syncScan() {
        if (!designer) return;
        const scan = ctx.scan;
        const dot = steps.querySelector('[data-cal-scan-dot]');
        const unfinished = Boolean(scan && scan.scannable() && scan.unfinished());
        const waiting = Boolean(scan && scan.scannable() && scan.attention());
        dot.hidden = !(unfinished || waiting);
        dot.setAttribute('aria-label', unfinished ? 'sken běží' : 'návrh rozvržení čeká na kontrolu');
        const quiet = Boolean(scan && design().step === 2 && scan.primaryShown());
        next.classList.toggle('btn-primary', !quiet);
        next.classList.toggle('btn-tertiary', quiet);
    }

    function syncHistory() {
        if (!designer) return;
        const d = design();
        const editor = Boolean(d.layout) && (d.step === 3 || (d.step === 4 && store.getState().ui.contentView === 'style'));
        history.hidden = !editor;
        undo.disabled = !store.canUndo();
        redo.disabled = !store.canRedo();
    }

    function syncStatus() {
        const value = store.getState().status;
        status.dataset.calStatus = value;
        status.replaceChildren(...(value === 'clean' ? [icon('check-circle', 's14')] : []), STATUS_TEXT[value] || '');
        save.classList.toggle('is-loading', value === 'saving');
    }

    function syncName() {
        const value = design().name;
        if (designer) {
            if (document.activeElement !== name && name.value !== value) name.value = value;
        } else {
            name.textContent = value;
        }
    }

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status') syncStatus();
        else if (event.type === 'ui') {
            if (event.keys.includes('contentView')) syncHistory();
        } else if (event.type === 'layout') {
            if (!event.live) {
                syncSteps();
                syncHistory();
            }
        } else if (event.type !== 'content') {
            syncSteps();
            syncHistory();
            syncName();
            syncScan();
        }
    });
    const unsubscribeScan = ctx.scan ? ctx.scan.subscribe(syncScan) : null;

    syncSteps();
    syncHistory();
    syncStatus();
    syncScan();

    return {
        destroy() {
            unsubscribe();
            if (unsubscribeScan) unsubscribeScan();
            root.replaceChildren();
        },
    };
}
