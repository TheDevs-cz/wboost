/*
 * The ONE busy look for a button, site-wide: a spinner + what is happening
 * („Ukládám…“, „Mažu…“, …), aria-busy, not clickable, its width kept so the
 * bar around it does not jump. Used by the global form guard
 * (submit_guard.js) for native form posts and by every controller that saves
 * over fetch/XHR — the rule (docs/redesign/design-system.md §7): a button
 * that starts work on the server shows that it is working and cannot start
 * the same work a second time while it runs.
 *
 * The button's own child nodes are MOVED aside and put back, never
 * re-serialized — icons, listeners and Stimulus targets inside it survive.
 */

/** Verb at the start of a label → what the button says while it works. */
const BUSY_VERBS = [
    [/^(uložit|uložte|ulož)/i, 'Ukládám…'],
    [/^(smazat|odstranit|vysypat|vyprázdnit|zrušit)/i, 'Mažu…'],
    [/^(stáhnout|exportovat|vyplnit a exportovat|generovat|vygenerovat)/i, 'Připravuji…'],
    [/^(nahrát|nahrajte)/i, 'Nahrávám…'],
    [/^(odeslat|poslat|pozvat|požádat)/i, 'Odesílám…'],
    [/^(přihlásit)/i, 'Přihlašuji…'],
    [/^(registrovat|zaregistrovat)/i, 'Registruji…'],
    [/^(vytvořit|založit)/i, 'Vytvářím…'],
    [/^(přidat)/i, 'Přidávám…'],
    [/^(obnovit)/i, 'Obnovuji…'],
    [/^(přesunout)/i, 'Přesouvám…'],
    [/^(duplikovat|kopírovat)/i, 'Kopíruji…'],
    [/^(publikovat|zveřejnit)/i, 'Zveřejňuji…'],
    [/^(přijmout)/i, 'Přijímám…'],
    [/^(spustit|zahájit)/i, 'Spouštím…'],
];

const FALLBACK_LABEL = 'Pracuji…';

/**
 * What a button says while busy: its (or its form's) `data-busy-label`,
 * else a verb derived from its own text, else „Pracuji…“. An icon-only
 * button gets '' — it shows the spinner alone.
 *
 * @param {HTMLElement} button
 * @returns {string}
 */
export function busyLabelFor(button) {
    const explicit = button.dataset.busyLabel ?? button.closest('form')?.dataset.busyLabel;
    if (explicit !== undefined) {
        return explicit;
    }

    const text = (button.textContent ?? '').replace(/\s+/g, ' ').trim();
    if (text === '') {
        return '';
    }

    const match = BUSY_VERBS.find(([pattern]) => pattern.test(text));

    return match ? match[1] : FALLBACK_LABEL;
}

/**
 * @param {HTMLElement|null|undefined} button
 * @returns {boolean}
 */
export function isBusy(button) {
    return Boolean(button && button.dataset.busy === '1');
}

/**
 * @param {HTMLElement|null|undefined} button
 * @param {{label?: string, disable?: boolean}} [options] `label` overrides
 *        the derived one; `disable: false` keeps the element enabled (a
 *        submitter must stay enabled until the browser has built the form
 *        data — the guard paints it a tick later).
 */
export function setBusy(button, { label, disable = true } = {}) {
    if (!button || isBusy(button)) {
        return;
    }

    const text = label ?? busyLabelFor(button);
    const width = button.getBoundingClientRect().width;
    const kept = document.createDocumentFragment();
    while (button.firstChild) {
        kept.appendChild(button.firstChild);
    }

    button._busyRestore = {
        kept,
        minWidth: button.style.minWidth,
        disabled: 'disabled' in button ? button.disabled : null,
        ariaDisabled: button.getAttribute('aria-disabled'),
    };

    button.dataset.busy = '1';
    if (width > 0) {
        button.style.minWidth = `${Math.ceil(width)}px`;
    }
    button.classList.add('is-busy');
    button.setAttribute('aria-busy', 'true');
    button.setAttribute('aria-disabled', 'true');
    if (disable && 'disabled' in button) {
        button.disabled = true;
    }

    const spinner = document.createElement('span');
    spinner.className = 'busy-spinner';
    spinner.setAttribute('aria-hidden', 'true');
    button.appendChild(spinner);

    if (text !== '') {
        const span = document.createElement('span');
        span.className = 'busy-label';
        span.textContent = text;
        button.appendChild(span);
    }
}

/**
 * Change the text of a busy button (e.g. upload progress „Ukládám… 42 %“).
 *
 * @param {HTMLElement|null|undefined} button
 * @param {string} text
 */
export function setBusyLabel(button, text) {
    if (!isBusy(button)) {
        return;
    }

    let span = button.querySelector(':scope > .busy-label');
    if (!span) {
        span = document.createElement('span');
        span.className = 'busy-label';
        button.appendChild(span);
    }
    span.textContent = text;
}

/**
 * @param {HTMLElement|null|undefined} button
 */
export function clearBusy(button) {
    if (!isBusy(button)) {
        return;
    }

    const restore = button._busyRestore;
    button.replaceChildren(restore.kept);
    button.style.minWidth = restore.minWidth;
    if (restore.disabled !== null) {
        button.disabled = restore.disabled;
    }
    if (restore.ariaDisabled === null) {
        button.removeAttribute('aria-disabled');
    } else {
        button.setAttribute('aria-disabled', restore.ariaDisabled);
    }
    button.classList.remove('is-busy');
    button.removeAttribute('aria-busy');
    delete button.dataset.busy;
    delete button._busyRestore;
}

/**
 * Run `task` with `button` busy, refusing a second run while one is in
 * flight (resolves to undefined then). The common case for a fetch save:
 *
 *     save(event) { return withBusy(event.currentTarget, () => this._save()); }
 *
 * @template T
 * @param {HTMLElement|null|undefined} button
 * @param {() => Promise<T>} task
 * @param {{label?: string}} [options]
 * @returns {Promise<T|undefined>}
 */
export async function withBusy(button, task, options = {}) {
    if (isBusy(button)) {
        return undefined;
    }

    setBusy(button, options);
    try {
        return await task();
    } finally {
        clearBusy(button);
    }
}
