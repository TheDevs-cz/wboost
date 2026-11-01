/*
 * Bootstrap 5.3 JS as the app's behaviour layer (Modal / Dropdown / Collapse /
 * Tooltip / Alert dismiss). Importing the ESM build registers Bootstrap's
 * data-API (`data-bs-toggle`, `data-bs-dismiss`) on the document; exposing it
 * as `window.bootstrap` keeps the ~23 Stimulus calls to the global
 * `bootstrap.Modal` working (they used to rely on Hyper's vendor.min.js).
 *
 * Imported FIRST by assets/app.js, so the global exists before any Stimulus
 * controller connects.
 */
import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

// Hyper's app.min.js used to initialise tooltips/popovers and the password
// eye; keep those opt-in markups working.
const initTooltips = (root) => {
    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => bootstrap.Tooltip.getOrCreateInstance(el));
    root.querySelectorAll('[data-bs-toggle="popover"]').forEach((el) => bootstrap.Popover.getOrCreateInstance(el));
};

// Show/hide password toggle (Hyper markup on the auth pages:
// .input-group-text[data-password] next to the password input).
document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-password]');
    const input = toggle?.parentElement?.querySelector('input');
    if (!toggle || !input) return;
    const show = toggle.getAttribute('data-password') !== 'true';
    input.type = show ? 'text' : 'password';
    toggle.setAttribute('data-password', show ? 'true' : 'false');
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initTooltips(document));
} else {
    initTooltips(document);
}

export default bootstrap;
