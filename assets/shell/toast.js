// The app-wide toast (docs/architecture/app-shell.md § "Toasts"): one stack
// (`.toasts`, bottom right, `aria-live`), Nocturne `.toast-n`. Used by the AI
// scan surfaces and by the shell's scan notice; the calendar keeps its own
// copy in assets/calendar/ui/dom.js (same stack element, same look).
//
//   toast('Uloženo')
//   toast('Sken se nepodařil', { error: true, action: { label: 'Zobrazit', href: '/…' } })
//   toast('Návrh je připravený', { ai: true, timeout: 10000, action: { label: 'Zkontrolovat', onClick } })
//
// A toast with an action stays 10 s and has a close button; a plain one 4 s.
// `key` de-duplicates: a second toast with the same key replaces the first.

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const esc = (text) => String(text ?? '').replace(/[&<>"']/g, (ch) => ESCAPES[ch]);

function stack() {
    let el = document.querySelector('.toasts');
    if (!el) {
        el = document.createElement('div');
        el.className = 'toasts';
        el.setAttribute('aria-live', 'polite');
        document.body.append(el);
    }
    return el;
}

export function toast(message, options = {}) {
    const { error = false, ai = false, action = null, key = null } = options;
    const timeout = options.timeout ?? (action ? 10000 : 4000);
    const host = stack();
    if (key) host.querySelectorAll(`[data-toast-key="${CSS.escape(key)}"]`).forEach((old) => old.remove());

    const node = document.createElement('div');
    node.className = `toast-n as-toast${error ? ' err' : ''}${ai ? ' ai' : ''}`;
    node.setAttribute('role', error ? 'alert' : 'status');
    if (key) node.dataset.toastKey = key;
    const iconName = error ? 'alert' : ai ? 'sparkle' : 'check-circle';
    node.innerHTML = `<span class="i i-${iconName} s18" aria-hidden="true"></span><span class="t">${esc(message)}</span>`;

    const close = () => node.remove();
    if (action) {
        const control = document.createElement(action.href ? 'a' : 'button');
        control.className = 'btn xs btn-tertiary';
        control.textContent = action.label;
        if (action.href) {
            control.href = action.href;
            if (action.newTab) { control.target = '_blank'; control.rel = 'noopener'; }
        } else {
            control.type = 'button';
        }
        control.addEventListener('click', () => {
            if (action.onClick) action.onClick();
            close();
        });
        node.append(control);
        const x = document.createElement('button');
        x.type = 'button';
        x.className = 'btn icon xs btn-ghost';
        x.setAttribute('aria-label', 'Zavřít oznámení');
        x.innerHTML = '<span class="i i-x s14" aria-hidden="true"></span>';
        x.addEventListener('click', close);
        node.append(x);
    }
    host.append(node);
    if (timeout > 0) window.setTimeout(close, timeout);
    return node;
}

/** A toast that survives one navigation (shown by the next page's shell). */
const FLASH_KEY = 'wb-toast';
export function toastAfterNavigation(message, options = {}) {
    try { window.sessionStorage.setItem(FLASH_KEY, JSON.stringify({ message, options })); } catch (e) { /* private mode */ }
}
export function showPendingToast() {
    let raw = null;
    try { raw = window.sessionStorage.getItem(FLASH_KEY); window.sessionStorage.removeItem(FLASH_KEY); } catch (e) { return; }
    if (!raw) return;
    try { const { message, options } = JSON.parse(raw); toast(message, options || {}); } catch (e) { /* malformed */ }
}
