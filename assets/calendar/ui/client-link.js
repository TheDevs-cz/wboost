// Odkaz pro klienta (handoff §4.7, §10.11; map §8.12; architecture §C.4, §G.3) — phase P7b.
//
// Contract: `openClientLink(ctx): { close() }` — designer only. Shows `ctx.env.clientUrl`, copies it
// (`ctx.api.clientLink('copied')` records the history sentence; a failed record never bothers the
// user) and regenerates it after a confirmation inside the dialog (`ctx.api.clientLink('regenerate')` — the answer's
// `url` is written back to `ctx.env.clientUrl`; the old link is a 404 from that moment).
// The dialog carries `data-cal-dialog="client-link"`; hooks: `input[data-cal-link-url]`,
// `[data-cal-action="link-copy"]`, `a[data-cal-action="link-open"]`, `[data-cal-action="link-regenerate"]` (asks) →
// `[data-cal-link-confirm]` with `[data-cal-choice="confirm" | "cancel"]`.

import { h, icon, openModal } from './dom.js';

/** Clipboard API first; the selection + `execCommand` path covers plain-http hosts and old browsers. */
async function copyText(field) {
    field.focus();
    field.select();
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(field.value);
            return true;
        } catch (e) {
            // permission denied or the document lost focus: try the selection
        }
    }
    try {
        return document.execCommand('copy') === true;
    } catch (e) {
        return false;
    }
}

export function openClientLink(ctx) {
    const { env, api } = ctx;
    let busy = false;

    const field = h('input', { type: 'text', readonly: true, spellcheck: 'false', value: env.clientUrl || '', 'aria-label': 'Odkaz pro klienta', 'data-cal-link-url': '', onFocus: (event) => event.target.select() });
    const open = h('a', { class: 'btn icon sm btn-ghost', href: env.clientUrl || '#', target: '_blank', rel: 'noopener', 'aria-label': 'Otevřít pohled klienta v novém okně', title: 'Otevřít pohled klienta', 'data-cal-action': 'link-open' }, icon('share', 's16'));

    const copy = h(
        'button',
        {
            type: 'button',
            class: 'btn sm btn-primary',
            'data-cal-action': 'link-copy',
            onClick: async () => {
                if (!(await copyText(field))) {
                    field.focus();
                    field.select();
                    ctx.toast('Zkopírujte odkaz ručně (Ctrl+C)');
                    return;
                }
                ctx.toast('Odkaz zkopírován — pošlete ho klientovi');
                api.clientLink('copied').catch(() => null);
            },
        },
        icon('copy', 's16'),
        'Kopírovat',
    );

    // The confirmation lives inside the dialog: a second Bootstrap modal on top of this one would
    // take the focus (and Esc) away from it when it closes.
    const reNote = h('p', { class: 'cap' }, 'Dostal se odkaz k někomu nepovolanému? Vygenerujte nový. Starý odkaz přestane fungovat.');
    const ask = h('button', { type: 'button', class: 'btn sm btn-tertiary', 'data-cal-action': 'link-regenerate', onClick: () => confirming(true) }, icon('refresh', 's16'), 'Vygenerovat nový odkaz');
    const cancel = h('button', { type: 'button', class: 'btn sm btn-tertiary', 'data-cal-choice': 'cancel', onClick: () => confirming(false) }, 'Zrušit');
    const confirm = h('button', { type: 'button', class: 'btn sm btn-danger', 'data-cal-choice': 'confirm', onClick: () => regenerate() }, 'Vygenerovat nový odkaz');
    const question = h(
        'div',
        { class: 'cal-clink-ask', role: 'alertdialog', 'aria-label': 'Vygenerovat nový odkaz?', 'data-cal-link-confirm': '', hidden: true },
        h('p', null, h('b', null, 'Vygenerovat nový odkaz?'), ' Starý odkaz přestane fungovat. Klient se do kalendáře dostane, až mu pošlete nový.'),
        h('div', { class: 'cal-clink-acts' }, cancel, confirm),
    );
    const idle = h('div', { class: 'cal-clink-idle' }, reNote, ask);

    function confirming(on) {
        if (busy) return;
        idle.hidden = on;
        question.hidden = !on;
        (on ? cancel : ask).focus();
    }

    async function regenerate() {
        if (busy) return;
        busy = true;
        confirm.classList.add('is-loading');
        confirm.disabled = true;
        cancel.disabled = true;
        try {
            const answer = await api.clientLink('regenerate');
            if (!answer || typeof answer.url !== 'string') throw new Error('Odkaz se nepodařilo vytvořit');
            env.clientUrl = answer.url;
            field.value = answer.url;
            open.href = answer.url;
            ctx.toast('Nový odkaz je připravený — starý už nefunguje');
        } catch (error) {
            ctx.toast((error && error.message) || 'Odkaz se nepodařilo vytvořit', true);
        } finally {
            busy = false;
            confirm.classList.remove('is-loading');
            confirm.disabled = false;
            cancel.disabled = false;
            confirming(false);
        }
    }

    const body = h(
        'div',
        { class: 'cal-clink' },
        h('p', null, 'Klient po otevření uvidí kalendář v náhledu a může rovnou upravovat fotky, popisky, odpady, akce a psát komentáře — místo korektur. Každá změna se zapíše do historie.'),
        h('div', { class: 'cal-5sh' }, h('label', { class: 'inp' }, icon('link', 's16'), field), open, copy),
        h('div', { class: 'cal-clink-re' }, idle, question),
    );
    const modal = openModal({ title: 'Odkaz pro klienta', body });
    modal.el.dataset.calDialog = 'client-link';
    return { close: () => modal.close() };
}
