// AI sken — what the lists around a scan share (T5 strip, §8 history): the two row actions.
import { api, confirmDialog, urlFor } from './kit.js';
import { startHint } from './copy.js';
import { toast } from '../shell/toast.js';

/**
 * "Zkusit znovu" from a list: reserves a scan again and opens the scan page; a refusal is said
 * in a toast (limits, the queue, the service — or no retry left).
 * @returns {Promise<boolean>} whether the retry started
 */
export async function retryScan({ actionUrl, pageUrl, id }) {
    const answer = await api.post(urlFor(actionUrl, id).replace(/\/(cancel|discard|retry|continue)$/, '/retry'));
    if (answer.ok) {
        window.location.href = urlFor(pageUrl, id);
        return true;
    }
    const hint = startHint(answer.code, { nextMonth: answer.nextMonth });
    toast(answer.code === 'limit_reached' ? 'Projekt má skeny pro tento měsíc vyčerpané.' : hint || (answer.code === 'wrong_state' ? 'Sken už je v jiném stavu — obnovte stránku.' : 'Sken se teď nepodařilo spustit znovu. Zkuste to za chvíli.'), { error: true });
    return false;
}

/**
 * "Zahodit" from a list — asks first (never a native confirm).
 * @returns {Promise<boolean>} whether the scan was discarded
 */
export async function discardScan({ actionUrl, id, proposal = false }) {
    const yes = await confirmDialog(proposal
        ? { title: 'Zahodit návrh?', text: 'Návrh se smaže a sken zůstane započítaný. Obrázky, které jste k němu nahráli, přesuneme do Koše v galerii.', confirm: 'Zahodit' }
        : { title: 'Zahodit sken?', text: 'Sken zmizí ze seznamů a jeho obrázek smažeme.', confirm: 'Zahodit' });
    if (!yes) return false;
    const answer = await api.post(urlFor(actionUrl, id).replace(/\/(cancel|discard|retry|continue)$/, '/discard'));
    if (!answer.ok) {
        toast(answer.code === 'wrong_state' ? 'Sken už je v jiném stavu — obnovte stránku.' : 'Sken se nepodařilo zahodit. Zkuste to za chvíli.', { error: true });
        return false;
    }
    toast(proposal ? 'Návrh zahozen' : 'Sken zahozen');
    return true;
}
