// Odkaz pro klienta (handoff §4.7, map §8.12, architecture §G.3) — STUB of phase P3, owned by P7b.
//
// What the stub does: shows the link in a read-only field. No copy button, no regeneration.
//
// Contract (final): `openClientLink(ctx): { close() }` — designer only (`ctx.env.clientUrl`,
// `ctx.api.clientLink('copied' | 'regenerate')`; a regenerated URL is written back to
// `ctx.env.clientUrl`). The dialog carries `data-cal-dialog="client-link"`.

import { h, openModal } from './dom.js';

export function openClientLink(ctx) {
    const field = h('input', { type: 'text', readonly: true, value: ctx.env.clientUrl || '', 'aria-label': 'Odkaz pro klienta', onFocus: (event) => event.target.select() });
    const body = h(
        'div',
        { class: 'cal-clink', 'data-cal-stub': 'P7b' },
        h('p', null, 'Klient po otevření uvidí kalendář v náhledu a může rovnou upravovat fotky, popisky, odpady, akce a psát komentáře — místo korektur. Každá změna se zapíše do historie.'),
        h('label', { class: 'inp' }, field),
    );
    const modal = openModal({ title: 'Odkaz pro klienta', body });
    modal.el.dataset.calDialog = 'client-link';
    return { close: () => modal.close() };
}
