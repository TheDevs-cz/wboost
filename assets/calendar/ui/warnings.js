// Warnings in the UI (architecture §D.8, §E.12; handoff §4.8; map §7; feedback K12).
//
// The data is `store.warnings()` (`Map<pageIndex, Warning[]>`, computed by `render/warnings.js`
// from the page geometry with the real text measurer and from the asset map — nothing is stored).
// `Warning = { pageIndex, kind: 'portrait' | 'dpi' | 'icons' | 'event' | 'overflow', target, facts, message }`.
// Warnings inform; they never block saving, Dokončit or anything else — peach, not rose.
// What a warning SAYS (title, facts, fix, actions) is `describeWarning` of `render/warning-text.js`;
// every surface below renders that one description.
//
// Contract:
//   `warningChip(ctx, opts: { onClick(): void }): { el: HTMLElement, destroy() }`
//     the chip `button[data-cal-action="warnings"]` reading `{n} upozornění` (`data-count="<n>"`);
//     hidden while there are none; keeps itself up to date from store events. The step decides
//     what a click does (step 4: `nextWarningPage`; step 5: `openWarnings`).
//   `openWarnings(ctx, opts?: { onPick(pageIndex): void, handlers?(pageIndex): Handlers }): { close() }`
//     the dialog `Upozornění ({n})` (`data-cal-dialog="warnings"`): one `.cal-wpage` per page with
//     warnings, in page order — its head `button[data-warning-page="<index>"]` (page title, jumps
//     to the page) and one `warningCard` per warning. A jump or an action closes the dialog; an
//     action first shows the page (`onPick`) and runs once the dialog is gone (a file picker at
//     once — it needs the click). Empty: `Bez kolizí.` The list follows the document while open.
//   `warningCard(ctx, warning, opts?: { handlers?, title?: boolean, onAction?(run) })`
//     `div.cal-warn[data-warning-kind]`: title (unless `title: false`), `.cal-warn-x` facts,
//     `.cal-warn-h` the fix, `.cal-warn-a` with `button[data-warning-action="<id>"]` per action
//     this role and surface can do. `onAction(run, id)` wraps the click (close a popover first …).
//   `openWarningPopover(ctx, list, anchor, opts?: { handlers?, focus?, returnFocus?, onClose? })`
//     the cards of `list` in a `ui/popover.js` popover `[data-cal-popover="warning"]`; title = the
//     warning's title (one) or `{n} upozornění`; closes on an outside click.
//   `bindWarningBadge(button, ctx, getList, getHandlers?)`
//     makes a badge button open that popover: mouse hover (opens without taking focus, closes
//     when the pointer leaves badge and popover) and click / tap / Enter (opens it pinned, focus
//     on the first action). Returns `{ destroy() }`.
//   `badgeLabel(list): string`   aria-label / tooltip of a badge: the title(s) of its warnings
//
// Handlers: `{ [actionId]: (action, warning) => void }` from the surface; they win over the
// built-in ones. Built in (any surface): 'photo-replace' (a file picker, then the page's photo),
// 'photo-zoom-reset', 'events' (Akce a spolky on the warning's month — when the role has the tool),
// 'element' (designer: step 3 with the part selected). 'photo-crop', 'day', 'page-content' exist
// only where the surface hands them in (step 5's overlay / step 4's panel). An action without a
// handler is not shown.
//
// Data for the other surfaces (page, thumbnail, day) — pure reads of `store.warnings()`:
//   `warningCount(ctx): number`                      all warnings of the calendar
//   `pageWarnings(ctx, pageIndex): Warning[]`        of one page (thumbnail badge, step-4 page panel)
//   `photoWarnings(ctx, pageIndex): Warning[]`       the page's photo warnings (mark on the photo frame)
//   `dayWarnings(ctx, pageIndex): Map<date, Warning[]>`   the page's per-day warnings (mark beside a row)
//   `nextWarningPage(ctx, after): number | null`     next page index with a warning after `after`, wrapping
//   `warningTip(list): string`                       the messages joined by ` · `

import { describeWarning } from '../render/warning-text.js';
import { debounce, h, icon, openModal } from './dom.js';
import { openPopover } from './popover.js';
import { openTool, visibleTools } from './tools/index.js';
import { writeToolState, ymOf } from './tools/datepicker.js';
import { setPagePhoto, uploadAsset } from './tools/photos.js';

const EMPTY = Object.freeze([]);
const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/** The five kinds in the order they are listed on a page. */
export const WARNING_KINDS = Object.freeze(['portrait', 'dpi', 'icons', 'event', 'overflow']);

export function warningCount(ctx) {
    let n = 0;
    for (const list of ctx.store.warnings().values()) n += list.length;
    return n;
}

export function pageWarnings(ctx, pageIndex) {
    return ctx.store.warnings().get(pageIndex) || EMPTY;
}

export function photoWarnings(ctx, pageIndex) {
    return pageWarnings(ctx, pageIndex).filter((warning) => warning.target && warning.target.el === 'photo');
}

export function dayWarnings(ctx, pageIndex) {
    const out = new Map();
    for (const warning of pageWarnings(ctx, pageIndex)) {
        const date = warning.target && warning.target.date;
        if (!date) continue;
        if (!out.has(date)) out.set(date, []);
        out.get(date).push(warning);
    }
    return out;
}

export function nextWarningPage(ctx, after) {
    const indexes = [...ctx.store.warnings().keys()].sort((a, b) => a - b);
    if (!indexes.length) return null;
    const next = indexes.find((index) => index > after);
    return next === undefined ? indexes[0] : next;
}

export const warningTip = (list) => (list || EMPTY).map((warning) => warning.message).join(' · ');

const designerOf = (ctx) => ctx.env.mode === 'designer';
const describe = (ctx, warning) => describeWarning(warning, { designer: designerOf(ctx) });

export function badgeLabel(list) {
    if (!list || !list.length) return '';
    const titles = list.map((warning) => describeWarning(warning).title);
    return list.length === 1 ? `Upozornění: ${titles[0]}` : `${list.length} upozornění: ${titles.join(', ')}`;
}

// ---- actions ---------------------------------------------------------------------------------

/** A file picker for the page's photo; the new photo starts centred at 100 % (`setPagePhoto`). */
function pickPhoto(ctx, page) {
    if (!page) return;
    const input = h('input', { type: 'file', class: 'sr', accept: PHOTO_TYPES.join(','), 'data-warning-upload': true });
    document.body.append(input);
    input.addEventListener('change', async () => {
        const file = input.files && input.files[0];
        input.remove();
        if (!file) return;
        if (!PHOTO_TYPES.includes(file.type)) {
            ctx.toast('Fotka musí být JPG, PNG nebo WebP.', true);
            return;
        }
        if (ctx.env.maxFileSize && file.size > ctx.env.maxFileSize) {
            ctx.toast('Soubor je větší než 10 MB.', true);
            return;
        }
        const info = await uploadAsset(ctx, file, 'photo');
        const current = ctx.store.pages().find((p) => p.key === page.key);
        if (!info || !current) return;
        setPagePhoto(ctx, current, info);
        ctx.toast(`Fotka vyměněna — ${current.title}`);
    });
    // a cancelled picker leaves no input behind
    input.addEventListener('cancel', () => input.remove());
    input.click();
}

function builtInHandlers(ctx, warning) {
    const { store } = ctx;
    const page = () => store.pages()[warning.pageIndex] || null;
    const out = {
        'photo-replace': () => pickPhoto(ctx, page()),
        'photo-zoom-reset': () => {
            const current = page();
            const item = current ? store.getState().doc.content.pagePhotos[current.key] : null;
            if (item) store.putItem('pagePhotos', current.key, { ...item, zoom: 1 }, current.title);
        },
    };
    const date = warning.target && warning.target.date;
    if (date && store.getState().doc.design.features.events && visibleTools(ctx).some((tool) => tool.key === 'events')) {
        out.events = () => {
            writeToolState(ctx, 'events', { ym: ymOf(date), view: 'm', filter: '*' });
            openTool('events', ctx);
        };
    }
    if (designerOf(ctx)) {
        out.element = (action) => {
            store.setStep(3);
            if (action.selection) store.setUi({ selection: { ...action.selection } });
        };
    }
    return out;
}

/** The actions of `warning` this role and surface can do: `[{ id, label, run() }]`. */
export function warningActions(ctx, warning, handlers = null) {
    const own = handlers || {};
    const built = builtInHandlers(ctx, warning);
    const out = [];
    for (const action of describe(ctx, warning).actions) {
        const fn = own[action.id] || built[action.id];
        if (fn) out.push({ ...action, run: () => fn(action, warning) });
    }
    return out;
}

// ---- rendering -------------------------------------------------------------------------------

export function warningCard(ctx, warning, opts = {}) {
    const d = describe(ctx, warning);
    const actions = warningActions(ctx, warning, opts.handlers || null);
    const wrap = (action) => () => (opts.onAction ? opts.onAction(action.run, action.id) : action.run());
    return h(
        'div',
        { class: 'cal-warn', 'data-warning-kind': warning.kind },
        opts.title === false ? null : h('b', { class: 'cal-warn-t' }, icon('alert', 's14'), h('span', null, d.title)),
        h('p', { class: 'cal-warn-x' }, d.text),
        d.hint ? h('p', { class: 'cal-warn-h' }, d.hint) : null,
        actions.length
            ? h(
                  'div',
                  { class: 'cal-warn-a' },
                  ...actions.map((action, i) =>
                      h('button', { type: 'button', class: `btn xs ${i === 0 ? 'btn-secondary' : 'btn-tertiary'}`, 'data-warning-action': action.id, onClick: wrap(action) }, action.label),
                  ),
              )
            : null,
    );
}

export function openWarningPopover(ctx, list, anchor, opts = {}) {
    const one = list.length === 1;
    let popover = null;
    // an action closes the popover first, so focus does not jump back to the badge under a new dialog
    const onAction = (run) => {
        if (popover) popover.close();
        run();
    };
    const body = h('div', { class: `cal-wpop${one ? ' one' : ''}` }, ...list.map((warning) => warningCard(ctx, warning, { handlers: opts.handlers || null, title: !one, onAction })));
    popover = openPopover({
        kind: 'warning',
        title: one ? describe(ctx, list[0]).title : `${list.length} upozornění`,
        body,
        anchor,
        closeLabel: 'Zavřít',
        focus: opts.focus !== false,
        dismissOutside: true,
        returnFocus: opts.returnFocus || null,
        onClose: opts.onClose || null,
    });
    popover.el.classList.add('cal-pop-warn');
    return popover;
}

const HOVER_OPEN = 180;
const HOVER_CLOSE = 260;

export function bindWarningBadge(button, ctx, getList, getHandlers = null) {
    let popover = null;
    let pinned = false;
    let openTimer = 0;
    let closeTimer = 0;

    const clear = () => {
        clearTimeout(openTimer);
        clearTimeout(closeTimer);
    };

    function open(pin) {
        const list = getList();
        if (!list || !list.length) return;
        pinned = pin;
        const opened = openWarningPopover(ctx, list, button, {
            handlers: getHandlers ? getHandlers() : null,
            focus: pin,
            returnFocus: () => button,
            onClose: () => {
                if (popover === opened) popover = null;
                pinned = false;
                button.setAttribute('aria-expanded', 'false');
            },
        });
        popover = opened;
        button.setAttribute('aria-expanded', 'true');
        opened.el.addEventListener('pointerenter', () => clearTimeout(closeTimer));
        opened.el.addEventListener('pointerleave', (event) => event.pointerType === 'mouse' && closeSoon());
        opened.el.addEventListener('focusin', () => {
            pinned = true;
        });
    }

    function closeSoon() {
        clearTimeout(closeTimer);
        if (!popover || pinned) return;
        closeTimer = setTimeout(() => {
            if (popover && !pinned) popover.close();
        }, HOVER_CLOSE);
    }

    const onEnter = (event) => {
        if (event.pointerType !== 'mouse') return;
        clearTimeout(closeTimer);
        if (popover) return;
        clearTimeout(openTimer);
        openTimer = setTimeout(() => open(false), HOVER_OPEN);
    };
    const onLeave = (event) => {
        if (event.pointerType !== 'mouse') return;
        clearTimeout(openTimer);
        closeSoon();
    };
    const onClick = (event) => {
        event.preventDefault();
        event.stopPropagation();
        clear();
        if (popover && pinned) {
            popover.close();
            return;
        }
        if (popover) {
            // opened by hovering: the click keeps it open and moves into it
            pinned = true;
            const first = popover.el.querySelector('[data-warning-action]') || popover.el.querySelector('button');
            if (first) first.focus({ preventScroll: true });
            return;
        }
        open(true);
    };

    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-expanded', 'false');
    button.addEventListener('pointerenter', onEnter);
    button.addEventListener('pointerleave', onLeave);
    button.addEventListener('click', onClick);
    return {
        destroy() {
            clear();
            button.removeEventListener('pointerenter', onEnter);
            button.removeEventListener('pointerleave', onLeave);
            button.removeEventListener('click', onClick);
            if (popover) popover.close();
        },
    };
}

// ---- chip + dialog ---------------------------------------------------------------------------

export function warningChip(ctx, opts = {}) {
    const text = h('span');
    const el = h('button', { type: 'button', class: 'cal-wchip', 'data-cal-action': 'warnings', hidden: true, onClick: () => opts.onClick && opts.onClick() }, icon('alert', 's14'), text);
    const sync = () => {
        const n = warningCount(ctx);
        el.hidden = n === 0;
        el.dataset.count = String(n);
        text.textContent = `${n} upozornění`;
        el.title = n ? 'Co je potřeba zkontrolovat a jak to opravit' : '';
    };
    const later = debounce(sync, 150);
    const unsubscribe = ctx.store.subscribe((event) => {
        if (event.type === 'ui' || event.type === 'status' || (event.type === 'layout' && event.live)) return;
        later();
    });
    sync();
    return {
        el,
        destroy() {
            unsubscribe();
            later.cancel();
            el.remove();
        },
    };
}

export function openWarnings(ctx, opts = {}) {
    const { store } = ctx;
    const body = h('div', { class: 'cal-wlist' });
    let modal = null;
    // an action waiting for the dialog to be gone (a second dialog must not open under a closing one)
    let pending = null;

    const pick = (index) => {
        if (opts.onPick) opts.onPick(index);
        modal.close();
    };

    function render() {
        const pages = store.pages();
        const warnings = store.warnings();
        const indexes = [...warnings.keys()].sort((a, b) => a - b);
        const n = warningCount(ctx);
        const title = `Upozornění (${n})`;
        if (modal) {
            modal.el.setAttribute('aria-label', title);
            const heading = modal.el.querySelector('.modal-title');
            if (heading) heading.textContent = title;
        }
        if (!indexes.length) {
            body.replaceChildren(h('p', { class: 'cal-empty-note' }, 'Bez kolizí.'));
            return title;
        }
        body.replaceChildren(
            h('p', { class: 'cal-wlead cap' }, 'Upozornění nic neblokují — kalendář můžete uložit i dokončit. U každého je, co s ním udělat; kliknutím na název strany na ni přejdete.'),
            ...indexes.map((index) => {
                const page = pages[index];
                const list = warnings.get(index);
                const handlers = opts.handlers ? opts.handlers(index) : null;
                const onAction = (run, id) => {
                    if (opts.onPick) opts.onPick(index);
                    if (id === 'photo-replace') {
                        // the file picker needs the click itself
                        run();
                        modal.close();
                        return;
                    }
                    pending = run;
                    modal.close();
                };
                return h(
                    'section',
                    { class: 'cal-wpage', 'data-warn': String(list.length) },
                    h(
                        'button',
                        { type: 'button', class: 'cal-wpage-h', 'data-warning-page': String(index), onClick: () => pick(index) },
                        h('b', null, page ? page.title : `Strana ${index + 1}`),
                        page && page.subtitle ? h('span', { class: 'cap' }, page.subtitle) : null,
                        h('span', { class: 'cal-wpage-go' }, 'Přejít na stranu', icon('chev-right', 's16')),
                    ),
                    // every action goes through the dialog: show the page, close, then act
                    ...list.map((warning) => warningCard(ctx, warning, { handlers, onAction })),
                );
            }),
        );
        return title;
    }

    const later = debounce(render, 150);
    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'ui' || event.type === 'status' || (event.type === 'layout' && event.live)) return;
        later();
    });
    modal = openModal({
        title: render(),
        body,
        size: 'lg',
        onClose: () => {
            unsubscribe();
            later.cancel();
            const run = pending;
            pending = null;
            if (run) run();
        },
    });
    modal.el.dataset.calDialog = 'warnings';
    return { close: () => modal.close() };
}
