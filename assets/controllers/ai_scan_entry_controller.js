/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { api, esc, ic, openDialog, dateCz } from '../ai-scan/kit.js';

/*
 * The LOCKED "Šablona z obrázku" card (templates/ai_scan/_entry_card.html.twig,
 * docs/ai-scan/ux.md T1 + §9.1): "Požádat o přístup" closes the Bootstrap
 * "Nová šablona" modal first — never a modal over a modal — then opens the
 * request dialog (native <dialog>: focus stays inside, Escape closes, focus
 * returns). The request really posts (`ai_scan_access_request`): stored and
 * e-mailed to the administrators. Once sent, the card flips to "Žádost
 * odeslána" without a reload.
 *
 * The same controller sits on the locked state of "Historie skenů"
 * (templates/ai_scan/history.html.twig) — no modal to close there
 * (`modal` unset), `origin` says which module the page hangs under.
 */
const BEFORE_AFTER = `<figure class="as-ba as-ba-wf">
<svg viewBox="0 0 96 120" role="img" aria-label="Původní obrázek"><rect class="wf-card" x="1" y="1" width="94" height="118" rx="6"/><rect class="wf-ph" x="8" y="8" width="80" height="104" rx="4"/><circle class="wf-b" cx="28" cy="30" r="8"/><path class="wf-b" d="M12 104l26-30 18 18 12-12 16 24z"/><rect class="wf-b" x="14" y="52" width="52" height="7" rx="3.5"/></svg>
${ic('arrow-right', '')}
<svg viewBox="0 0 96 120" role="img" aria-label="Návrh s vyznačenými vrstvami"><rect class="wf-card" x="1" y="1" width="94" height="118" rx="6"/><rect class="wf-dash" x="10" y="12" width="26" height="26" rx="3"/><rect class="wf-acc" x="10" y="50" width="58" height="8" rx="4"/><rect class="wf-dash" x="8" y="46" width="62" height="16" rx="3"/><rect class="wf-b" x="10" y="72" width="70" height="4" rx="2"/><rect class="wf-b" x="10" y="81" width="56" height="4" rx="2"/><rect class="wf-dash" x="8" y="68" width="76" height="22" rx="3"/><rect class="wf-dash" x="62" y="96" width="24" height="16" rx="3"/></svg>
<figcaption>ukázka</figcaption></figure>`;

const PREM = `<span class="tp-prem">${ic('sparkle', 's14')}Premium</span>`;

export default class extends Controller {
    static targets = ['button', 'sentLine', 'sentText'];

    static values = { url: String, project: String, modal: String, origin: { type: String, default: 'templates' } };

    request() {
        const modalEl = this.modalValue ? document.querySelector(this.modalValue) : null;
        const modal = modalEl && window.bootstrap ? window.bootstrap.Modal.getInstance(modalEl) : null;
        if (modal && modalEl.classList.contains('show')) {
            modalEl.addEventListener('hidden.bs.modal', () => this.open(), { once: true });
            modal.hide();
        } else {
            this.open();
        }
    }

    open() {
        // Focus goes back to whatever opened "Nová šablona" (the card's button is hidden with its modal).
        const opener = document.querySelector(`[data-bs-target="${this.modalValue}"]:not(.icon)`) || document.querySelector(`[data-bs-target="${this.modalValue}"]`);
        if (opener) opener.focus({ preventScroll: true });

        const dialog = openDialog(
            `<div class="dlg-h"><h2 style="display:flex;align-items:center;gap:10px">AI sken ${PREM}</h2><button type="button" class="btn icon xs btn-ghost" data-close data-x aria-label="Zavřít">${ic('x')}</button></div>
<p class="lead">Hotová grafika → upravitelná šablona za pár minut.</p>
${BEFORE_AFTER}
<p>AI sken je placená funkce, kterou zapínáme pro každý projekt zvlášť. Napište si o ni — ozveme se vám e‑mailem s podmínkami.</p>
<label class="field"><span class="field-l">K čemu ho chcete použít? <small>nepovinné</small></span><textarea class="form-control" rows="3" maxlength="500" data-autofocus data-message placeholder="Např. chceme převést loňské plakáty na šablony"></textarea></label>
<p class="cap">Odešleme: vaše jméno a e‑mail, projekt „${esc(this.projectValue)}“ a tuto zprávu. Dostane ji tým WBoost.</p>
<p class="hint err" role="alert" data-error hidden></p>
<div class="dlg-f"><button type="button" class="btn sm btn-tertiary" data-close>Zrušit</button><button type="button" class="btn sm btn-primary" data-send>Požádat o přístup</button></div>`,
            { cls: 'as-dlg-m' },
        );
        const send = dialog.querySelector('[data-send]');
        const error = dialog.querySelector('[data-error]');
        send.addEventListener('click', async () => {
            send.disabled = true;
            error.hidden = true;
            const answer = await api.post(this.urlValue, { origin: this.originValue === 'calendars' ? 'calendars' : 'templates', message: dialog.querySelector('[data-message]').value.trim() || null });
            if (!answer.ok) {
                send.disabled = false;
                error.textContent = this.problem(answer);
                error.hidden = false;
                if (answer.code === 'wrong_state') send.hidden = true;
                return;
            }
            this.sent(answer.sentAt);
            dialog.innerHTML = `<div class="dlg-h"><h2 id="${esc(dialog.getAttribute('aria-labelledby') || 'asDlgSent')}">Žádost odeslána</h2><button type="button" class="btn icon xs btn-ghost" data-close data-x aria-label="Zavřít">${ic('x')}</button></div>
<div class="as-dlg-head"><span class="ico-c sm as-ico-mint">${ic('check-circle')}</span><p>Ozveme se vám na ${esc(answer.email || 'váš e‑mail')}.</p></div>
<div class="dlg-f"><button type="button" class="btn sm btn-primary" data-close data-done>Zavřít</button></div>`;
            dialog.querySelector('[data-done]').focus();
        });
    }

    problem(answer) {
        switch (answer.code) {
            case 'rate_limited': return 'Dnes jste už poslali pět žádostí. Zkuste to prosím zítra.';
            case 'wrong_state': return 'AI sken už v tomto projektu použít můžete — obnovte stránku.';
            case 'network': return 'Žádost se nepodařilo odeslat. Zkontrolujte připojení a zkuste to znovu.';
            default: return answer.status === 403 ? 'Na žádost v tomto projektu nemáte oprávnění.' : 'Žádost se nepodařilo odeslat. Zkuste to prosím za chvíli.';
        }
    }

    /** The card becomes "Žádost odeslána" (ux.md T1, request sent). */
    sent(sentAt) {
        this.element.classList.add('as-inert', 'is-soon');
        this.element.dataset.aiScanState = 'request_sent';
        if (this.hasSentLineTarget) {
            this.sentTextTarget.textContent = `Odesláno ${dateCz(sentAt || new Date().toISOString())}. Dáme vám vědět.`;
            this.sentLineTarget.hidden = false;
        }
        if (this.hasButtonTarget) {
            this.buttonTarget.disabled = true;
            this.buttonTarget.removeAttribute('data-action');
            this.buttonTarget.innerHTML = `${ic('check')}Žádost odeslána`;
        }
    }
}
