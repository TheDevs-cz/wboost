// The question before an export of a template with unfilled pictures
// (docs/ai-scan/ux.md T4, docs/architecture/ai-scan.md § "Unfilled pictures").
//
// Every web export answers 409 `{code: "unfilled_pictures", count, confirmField,
// editorUrl}` to a JSON request until the request carries `allowUnfilled=1`.
//   askUnfilled(answer) → Promise<boolean>  (true = "Exportovat přesto")
import { esc, ic, pl, openDialog } from './kit.js';

export const UNFILLED = 'unfilled_pictures';
export const CONFIRM_FIELD = 'allowUnfilled';

export function unfilledCopy(count) {
    const n = Math.max(1, Number(count) || 1);
    return {
        title: n === 1 ? 'Šablona má 1 nedoplněný obrázek' : `Šablona má ${n} ${pl(n, 'nedoplněný obrázek', 'nedoplněné obrázky', 'nedoplněných obrázků')}`,
        text: n === 1
            ? 'V exportu bude na jeho místě šedý zástupný obrázek. Doplňte ho v editoru, nebo exportujte přesto.'
            : n < 5
                ? 'V exportu budou na jejich místě šedé zástupné obrázky. Doplňte je v editoru, nebo exportujte přesto.'
                : `V exportu bude na jejich místě ${n} šedých zástupných obrázků. Doplňte je v editoru, nebo exportujte přesto.`,
    };
}

export function askUnfilled({ count, editorUrl }) {
    const copy = unfilledCopy(count);
    return new Promise((resolve) => {
        let answer = false;
        const dialog = openDialog(
            `<div class="as-dlg-head"><span class="ico-c sm pch">${ic('alert', '')}</span><h2>${esc(copy.title)}</h2></div>
<p>${esc(copy.text)}</p>
<div class="dlg-f"><button type="button" class="btn sm btn-tertiary" data-export-anyway>Exportovat přesto</button>${editorUrl ? `<a class="btn sm btn-primary" href="${esc(editorUrl)}" data-autofocus>Otevřít editor</a>` : '<button type="button" class="btn sm btn-primary" data-close data-autofocus>Zavřít</button>'}</div>`,
            { alert: true, onClose: () => resolve(answer) },
        );
        dialog.querySelector('[data-export-anyway]').addEventListener('click', () => { answer = true; dialog.close(); });
    });
}

/** `attachment; filename="a.png"; filename*=UTF-8''a.png` → the file name. */
export function attachmentName(header, fallback = 'export') {
    const star = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(header || '');
    if (star) { try { return decodeURIComponent(star[1].trim()); } catch (e) { /* fall through */ } }
    const plain = /filename\s*=\s*"?([^";]+)"?/i.exec(header || '');
    return plain ? plain[1].trim() : fallback;
}

export function saveBlob(blob, name) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.hidden = true;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 60000);
}
