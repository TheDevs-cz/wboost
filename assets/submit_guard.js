/*
 * Site-wide guard for NATIVE form posts (the browser submits and navigates):
 * a form that is already sending cannot be sent again, and its submit button
 * shows that it works (busy_button.js). Loaded once from app.js.
 *
 * Why: a click that "does nothing visible" gets clicked again — two posts of
 * the same form race on the server (two copies created, a delete hitting a
 * row that is already gone, two heavy exports). Sentry WEB-4X was the editor
 * flavour of this (editor_save.js guards the editors' XHR saves).
 *
 * How:
 * - `submit` is caught in the CAPTURE phase on document, before any form
 *   handler: a second submit of a form that is sending is cancelled outright
 *   (no controller sees it).
 * - Whether the post is native is only known after every handler ran: a
 *   tick later, a submit that some handler `preventDefault()`ed (Stimulus
 *   fetch saves, Turbo, Live Components) is left alone — those guard
 *   themselves. Only POST forms are guarded (a GET form is a search/filter).
 * - A native post either navigates (the page goes away — nothing to reset)
 *   or answers with a DOWNLOAD (the page stays and the browser has no event
 *   for "download done"). For that, the guard adds a random `_submit_token`
 *   to THAT post only — through the `formdata` event of the submission, never
 *   as a field in the DOM: a field would ride along with every fetch that
 *   builds `new FormData(form)` meanwhile (the fill pages' live previews do)
 *   and the echo of such a fetch would release the button early. The server
 *   echoes the token as the short-lived cookie
 *   `wbsubmit_<token>` on whatever it answers
 *   (Services/Http/SubmitTokenCookieListener) — the guard polls for that
 *   cookie and resets the button the moment the download arrives.
 * - A page restored from the back/forward cache is reset.
 *
 * Per form: `data-submit-guard="off"` (no guard at all), `"self"` (guard and
 * token, but the form's own controller paints its busy state — it listens to
 * the `submit-guard:start` / `submit-guard:settled` events on the form).
 * `data-busy-label` on the button or form overrides the derived label.
 */
import { clearBusy, setBusy } from './busy_button.js';

const TOKEN_FIELD = '_submit_token';
const COOKIE_PREFIX = 'wbsubmit_';
const POLL_MS = 250;
/** A download that has not arrived after this long: give the button back anyway. */
const GIVE_UP_MS = 10 * 60 * 1000;

const pending = new Map(); // form → {token, buttons, timer, started}

function guardMode(form) {
    return form.dataset.submitGuard ?? 'on';
}

function isGuarded(form) {
    if (!(form instanceof HTMLFormElement) || guardMode(form) === 'off') {
        return false;
    }
    if ((form.getAttribute('method') ?? 'get').toLowerCase() !== 'post') {
        return false;
    }
    const target = form.getAttribute('target');

    return target === null || target === '' || target === '_self';
}

function submitButtonsOf(form) {
    return [...form.elements].filter((el) => (el instanceof HTMLButtonElement && el.type === 'submit')
        || (el instanceof HTMLInputElement && (el.type === 'submit' || el.type === 'image')));
}

function onSubmitCapture(event) {
    const form = event.target;
    if (!isGuarded(form)) {
        return;
    }

    if (pending.has(form)) {
        // Already sending: this click must not post the form a second time.
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }

    const submitter = event.submitter ?? null;
    const token = Math.random().toString(36).slice(2, 12) + Date.now().toString(36);
    pending.set(form, { token, buttons: [], timer: null, started: Date.now() });

    // The browser builds this submission's form data right after the submit
    // event, in the same task — the token is added there (onFormData) and
    // only there.
    constructing = { form, token };

    setTimeout(() => {
        constructing = null;
        if (event.defaultPrevented) {
            // A handler took the post over (fetch, Turbo, Live): it guards itself.
            pending.delete(form);
            return;
        }
        start(form, submitter);
    }, 0);
}

let constructing = null;

function onFormData(event) {
    if (constructing && event.target === constructing.form) {
        event.formData.set(TOKEN_FIELD, constructing.token);
    }
}

function start(form, submitter) {
    const state = pending.get(form);
    if (!state) {
        return;
    }

    form.setAttribute('aria-busy', 'true');
    form.dispatchEvent(new CustomEvent('submit-guard:start', { bubbles: true, detail: { submitter } }));

    if (guardMode(form) !== 'self') {
        // The clicked button shows the work; the other submit buttons of the
        // form (and `form=`-anchored ones elsewhere) just stop being clickable.
        const busy = submitter ?? submitButtonsOf(form)[0] ?? null;
        if (busy) {
            setBusy(busy);
            state.buttons.push(busy);
        }
        for (const other of submitButtonsOf(form)) {
            if (other !== busy && !other.disabled) {
                other.disabled = true;
                state.buttons.push(other);
            }
        }
    }

    state.timer = setInterval(() => poll(form), POLL_MS);
}

function poll(form) {
    const state = pending.get(form);
    if (!state) {
        return;
    }

    const name = `${COOKIE_PREFIX}${state.token}`;
    const arrived = document.cookie.split(';').some((pair) => pair.trim().startsWith(`${name}=`));

    if (arrived) {
        document.cookie = `${name}=; Max-Age=0; path=/; SameSite=Lax`;
        settle(form);
    } else if (Date.now() - state.started > GIVE_UP_MS) {
        settle(form);
    }
}

function settle(form) {
    const state = pending.get(form);
    if (!state) {
        return;
    }

    clearInterval(state.timer);
    pending.delete(form);
    form.removeAttribute('aria-busy');
    for (const button of state.buttons) {
        if (button.dataset.busy === '1') {
            clearBusy(button);
        } else {
            button.disabled = false;
        }
    }
    form.dispatchEvent(new CustomEvent('submit-guard:settled', { bubbles: true }));
}

document.addEventListener('submit', onSubmitCapture, true);
document.addEventListener('formdata', onFormData, true);

// Turbo forms (the few that opt in: export-version pin/rename, page texts,
// logo colours) submit over fetch — Turbo disables the clicked button itself;
// the guard adds the same busy look as everywhere else and takes it off when
// Turbo is done (Turbo re-enables the button then, so it stays enabled).
document.addEventListener('turbo:submit-start', (event) => {
    const submitter = event.detail?.formSubmission?.submitter ?? null;
    if (submitter && guardMode(event.target) !== 'off') {
        setBusy(submitter, { disable: false });
    }
});
document.addEventListener('turbo:submit-end', (event) => {
    const submitter = event.detail?.formSubmission?.submitter ?? null;
    if (submitter && submitter.dataset.busy === '1') {
        clearBusy(submitter);
        submitter.removeAttribute('disabled');
    }
});

// Back/forward cache: the page comes back exactly as it was left — busy.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        [...pending.keys()].forEach(settle);
    }
});
