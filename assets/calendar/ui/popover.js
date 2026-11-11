// Floating editors of step 5 (handoff §4.7, map §8.12 "Popover chrome"). Owner: phase P7a; used by
// `ui/step5-overlay.js` only. CSS: calendar-preview.css (`.cal-pop`).
//
// `openPopover(options): { el, close(), setContent(next), confirm() }`
//   options.title          heading (also the dialog's aria-label)
//   options.body           Node
//   options.anchor         Element | DOMRect-like — the popover opens centred under it (above when
//                          there is no room below); `options.at: { x, y }` is a point instead
//   options.confirmLabel   text of the confirm button (no button without it)
//   options.onConfirm      `() => boolean | void` — returning `false` keeps the popover open (an
//                          empty event name or comment: nothing is saved, the field keeps focus)
//   options.onClose        called once when the popover is gone, whatever closed it
//   options.kind           value of `data-cal-popover` (e2e hook)
//   options.returnFocus    `() => Element | null` — receives focus after closing
//   options.wide           a larger popover for a whole form (`.cal-pop.wide`; a sheet docked to
//                          the bottom of a phone screen, so the page above stays visible)
//   options.align          'end' = opened flush with the right edge of the window instead of
//                          centred under the anchor
//   options.closeLabel     aria-label / tooltip of the close button (default „Zavřít bez uložení“;
//                          a popover whose fields write as you type says „Zavřít“)
//
// Behaviour: `position: fixed`, always clamped into the viewport (on open, on content change, after
// a drag, on window resize); draggable by its grip (`makeDraggable` of the shared
// `controllers/popover_drag.js`); Enter in an `<input>` / `<select>` confirms (Ctrl / ⌘ + Enter in a
// `<textarea>`); Esc and the close button discard. One popover at a time — opening another closes
// the previous one, and so does any Bootstrap modal that opens (tools, Historie …).
// `setContent({ title, body, confirmLabel?, onConfirm?, kind? })` swaps the content in place: the
// popover stays where the user left it (plus menu → Odpad / Akce / Komentář).

import { makeDraggable } from '../../controllers/popover_drag.js';
import { h, icon } from './dom.js';

const EDGE = 12;
const GAP = 10;

let current = null;

/** Closes the open popover, if any (page change, step unmount). */
export function closePopover() {
    if (current) current.close();
}

export function openPopover(options) {
    closePopover();

    let onConfirm = options.onConfirm || null;
    let closed = false;

    const titleEl = h('b', { class: 'cal-pop-t' }, options.title || '');
    const okText = h('span');
    const ok = h('button', { type: 'button', class: 'btn xs cal-pop-ok', 'data-popover-confirm': true, hidden: true, onClick: () => confirm() }, icon('check', 's14'), okText);
    const grip = h('span', { class: 'cal-pop-grip', 'data-popover-grip': true, title: 'Přetažením přesunete', 'aria-hidden': 'true' }, icon('grip', 's14'));
    const close = h('button', { type: 'button', class: 'btn icon xs btn-ghost cal-pop-x', 'data-popover-close': true, 'aria-label': options.closeLabel || 'Zavřít bez uložení', title: options.closeLabel || null, onClick: () => api.close() }, icon('x', 's16'));
    const bodyEl = h('div', { class: 'cal-pop-b' });
    const el = h('div', { class: `cal-pop${options.wide ? ' wide' : ''}`, role: 'dialog', 'aria-label': options.title || '' }, h('div', { class: 'cal-pop-h' }, grip, titleEl, h('span', { class: 'cal-pop-sp' }), ok, close), bodyEl);

    function clamp() {
        const rect = el.getBoundingClientRect();
        const left = Math.max(EDGE, Math.min(window.innerWidth - rect.width - EDGE, rect.left));
        const top = Math.max(EDGE, Math.min(window.innerHeight - rect.height - EDGE, rect.top));
        el.style.left = `${Math.round(left)}px`;
        el.style.top = `${Math.round(top)}px`;
    }

    function place() {
        const a = options.anchor ? (typeof options.anchor.getBoundingClientRect === 'function' ? options.anchor.getBoundingClientRect() : options.anchor) : null;
        const at = options.at || { x: window.innerWidth / 2, y: window.innerHeight / 3 };
        const anchor = a || { left: at.x, right: at.x, top: at.y, bottom: at.y, width: 0 };
        const rect = el.getBoundingClientRect();
        let top = anchor.bottom + GAP;
        if (top + rect.height > window.innerHeight - EDGE) top = anchor.top - rect.height - GAP;
        // `align: 'end'`: flush with the right edge of the window (a form that should leave the page visible)
        el.style.left = options.align === 'end' ? `${window.innerWidth - rect.width - EDGE}px` : `${anchor.left + (anchor.width || 0) / 2 - rect.width / 2}px`;
        el.style.top = `${top}px`;
        clamp();
    }

    function fill(next) {
        if (next.title !== undefined) {
            titleEl.textContent = next.title;
            el.setAttribute('aria-label', next.title);
        }
        onConfirm = next.onConfirm || null;
        ok.hidden = !next.confirmLabel;
        okText.textContent = next.confirmLabel || '';
        if (next.kind) el.dataset.calPopover = next.kind;
        bodyEl.replaceChildren(next.body);
    }

    function focusFirst() {
        const field = bodyEl.querySelector('input:not([type="hidden"]), textarea, select') || bodyEl.querySelector('button') || close;
        field.focus({ preventScroll: true });
        if (typeof field.select === 'function' && field.matches('input')) field.select();
    }

    function confirm() {
        if (closed || !onConfirm) return;
        if (onConfirm() !== false) api.close();
    }

    const onKeyDown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            api.close();
            return;
        }
        if (event.key !== 'Enter' || event.isComposing || !el.contains(event.target)) return;
        const target = event.target;
        const field = target.matches('input:not([type="file"]):not([type="range"]), select') || (target.matches('textarea') && (event.ctrlKey || event.metaKey));
        if (!field || !onConfirm) return;
        event.preventDefault();
        event.stopPropagation();
        confirm();
    };
    const onResize = () => clamp();
    const onModal = () => api.close();
    // `makeDraggable` moves the popover by raw pointer deltas; bring it back inside afterwards
    const onGripDown = () => document.addEventListener('pointerup', () => !closed && clamp(), { once: true });

    const api = {
        el,
        confirm,
        close() {
            if (closed) return;
            closed = true;
            const hadFocus = el.contains(document.activeElement);
            document.removeEventListener('keydown', onKeyDown, true);
            document.removeEventListener('show.bs.modal', onModal);
            window.removeEventListener('resize', onResize);
            el.remove();
            if (current === api) current = null;
            if (options.onClose) options.onClose();
            const back = hadFocus && options.returnFocus ? options.returnFocus() : null;
            if (back && document.contains(back)) back.focus({ preventScroll: true });
        },
        /** Swaps title / body / confirm in place; the position is kept (only clamped). */
        setContent(next) {
            if (closed) return;
            fill(next);
            clamp();
            focusFirst();
        },
    };

    fill(options);
    document.body.append(el);
    place();
    makeDraggable(grip, el);
    grip.addEventListener('pointerdown', onGripDown);
    document.addEventListener('keydown', onKeyDown, true);
    document.addEventListener('show.bs.modal', onModal);
    window.addEventListener('resize', onResize);
    current = api;
    focusFirst();
    return api;
}
