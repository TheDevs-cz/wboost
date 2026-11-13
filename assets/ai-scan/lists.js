// AI sken — what the lists around a scan share (T5 strip, §8 history): the row actions.
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

/**
 * "Vrátit původní rozvržení" of a calendar scan from a list (ux.md §6 "Applying"): the layout,
 * layout choice and switches the calendar had before the scan was applied go back — nothing else.
 * When the layout was edited since, the server answers `layout_changed` and the user is asked first.
 * @param {{url: string}} o  the calendar's restore address (`calendar_scan`, action `restore`)
 * @returns {Promise<boolean>} whether the layout was put back
 */
export async function restoreCalendarLayout({ url }) {
    let answer = await api.post(url);
    if (answer.code === 'layout_changed') {
        const yes = await confirmDialog({
            title: 'Vrátit původní rozvržení?',
            text: 'Od použití skenu jste rozvržení upravili. Vrácením o tyto úpravy přijdete. Texty, fotky a obsah stránek zůstanou.',
            confirm: 'Vrátit původní',
        });
        if (!yes) return false;
        answer = await api.post(url, { confirm: '1' });
    }
    if (!answer.ok) {
        toast(answer.code === 'wrong_state' ? 'Původní rozvržení už vrátit nejde.' : 'Původní rozvržení se nepodařilo vrátit. Zkuste to za chvíli.', { error: true });
        return false;
    }
    toast('Původní rozvržení vráceno');
    return true;
}
