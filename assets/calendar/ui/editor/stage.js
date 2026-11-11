// Editor stage (architecture §D.6, handoff §5): the stage bar (Propojit prvky · sample-page note ·
// zoom), the sample page with its bleed, the selection overlay, the pointer pipeline and the hint
// line. Owner P4.
//
// `mountStage(root, ctx, opts: { embedded: boolean }): { destroy() }`
//
//   .cal-stage
//     .cal-stage-bar          [data-cal-action="link"] · note · [data-cal-action="zoom-out|zoom-fit|zoom-in"]
//     .cal-cvw                scroll container (the zoomed page may be larger than the stage)
//       .cal-cv               (W + 2B)·s × (H + 2B)·s px, s = fitScale × zoom — ALL pointer input lands here
//         .cal-page-svg       renderPage(sample, { bleed: true, placeholders: 'all' })
//         svg.cal-chrome      hatched bleed ring + dashed trim line (pointer-events: none)
//         .cal-ovl            selection chrome of overlay.js — origin at the TRIM corner, mm × s px
//         .cal-ovl.cal-ovl-parts   the same origin, handed to mountPartsOverlay (P5b)
//     .cal-hint               hint line; [data-cal-action="offset-reset"] when the selected part was moved
//     [data-cal-action="panel-toggle"]   collapses the properties column (step 3 only)
//
// Contracts other modules rely on:
//   - interaction never depends on SVG hit testing: the pointer is converted to mm and goes through
//     the pure `hitTest` / `partAt`; the page SVG has `pointer-events: none`
//   - a drag is `store.beginChange()` → `updateLive()` per move → `commitChange()` (one undo step);
//     boxes come from `dragTo(session, …)`, always computed from the boxes at pointer down
//   - renders are coalesced into a microtask, so the DOM is current as soon as the code that changed
//     the store returns; the page SVG is re-rendered only when the document or the sample changed
//   - `partsOverlay.update(geometry, scale, selection)` runs after every render (§D.2); a
//     `pointerdown` that a part bar stopped never reaches the pipeline
//   - the stage probe (`scale()`, `toClient()`, `page()`) is registered while mounted

import { K, bleedOf } from '../../model/defaults.js';
import { boxKeysOf, visibleBoxKeys } from '../../model/elements.js';
import { samplePage } from '../../generate/pages.js';
import { renderPage } from '../../render/page.js';
import { beginDrag, dragTo } from '../../editor/drag.js';
import { containsPoint, hitTest, partAt } from '../../editor/geometry.js';
import { dragPartOffset } from '../../editor/parts.js';
import { fitScale, zoomIn, zoomOut } from '../../editor/zoom.js';
import { h, icon } from '../dom.js';
import { mountOverlay, selectedBoxKey } from './overlay.js';
import { mountPartsOverlay } from './parts-overlay.js';

const LINK_TIP = 'Zapnuto: změna velikosti prvku posune i prvky, které s ním sousedí (fotka, kalendárium, hlavička). Vypnuto: každý prvek zvlášť.';
const HINT_ELEMENT = 'Klikněte na prvek, táhněte ho a měňte velikost za úchyty · změna platí pro všechny strany';
const HINT_BLOCK = 'Klikněte znovu na část kalendária — číslo, den, jmeniny, svátek… — a nastavte její styl';
const HINT_PART = 'Táhněte pro posun ve všech řádcích · Esc = zpět na celé kalendárium';
const STACKED = '(max-width: 960px)';
// parts whose offset falls back to the offset of Ikony until they get their own (§E.6.8)
const FOLLOWS_ICON = ['waste', 'moon'];
const UI_KEYS = ['selection', 'zoom', 'guides', 'panelCollapsed'];

const sameSelection = (a, b) => (!a && !b) || Boolean(a && b && a.el === b.el && (a.frame || 0) === (b.frame || 0) && (a.part || null) === (b.part || null));

/** Hatched bleed ring and dashed trim line, in the viewBox of the page (mm). */
function chromeMarkup(page, B, id) {
    const { width: W, height: H } = page;
    if (!(B > 0)) return '';
    const ring = `M${-B} ${-B}h${W + 2 * B}v${H + 2 * B}h${-(W + 2 * B)}zM0 0v${H}h${W}v${-H}z`;
    return (
        `<svg xmlns="http://www.w3.org/2000/svg" class="cal-chrome" viewBox="${-B} ${-B} ${W + 2 * B} ${H + 2 * B}" aria-hidden="true">` +
        `<defs><pattern id="${id}" width="1.6" height="1.6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="0.55" height="1.6" fill="#E0708A"/></pattern></defs>` +
        `<path d="${ring}" fill="url(#${id})" fill-rule="evenodd" opacity=".35" data-chrome="bleed"/>` +
        `<rect x="0" y="0" width="${W}" height="${H}" fill="none" stroke="#D9486A" stroke-width="1" stroke-dasharray="5 4" vector-effect="non-scaling-stroke" data-chrome="trim"/>` +
        '</svg>'
    );
}

let instances = 0;

export function mountStage(root, ctx, opts = {}) {
    const { store } = ctx;
    const embedded = opts.embedded === true;
    const id = `calstage${++instances}`;
    const stacked = window.matchMedia(STACKED);

    // ---- DOM ------------------------------------------------------------------------------

    const linkButton = h('button', { type: 'button', class: 'cal-link', 'data-cal-action': 'link', title: LINK_TIP, onClick: toggleLink }, icon('link', 's16'), h('span', { class: 'cal-link-t' }, 'Propojit prvky'));
    const zoomOutButton = h('button', { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': 'Oddálit', title: 'Oddálit', 'data-cal-action': 'zoom-out', onClick: () => setZoom(zoomOut(store.getState().ui.zoom)) }, icon('minus', 's16'));
    const zoomFitButton = h('button', { type: 'button', class: 'cal-zmv', title: 'Přizpůsobit oknu', 'data-cal-action': 'zoom-fit', onClick: () => setZoom(1) });
    const zoomInButton = h('button', { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': 'Přiblížit', title: 'Přiblížit', 'data-cal-action': 'zoom-in', onClick: () => setZoom(zoomIn(store.getState().ui.zoom)) }, icon('plus', 's16'));
    const bar = h(
        'div',
        { class: 'cal-stage-bar' },
        linkButton,
        h('span', { class: 'cal-pgsep', 'aria-hidden': 'true' }),
        h('span', { class: 'cap cal-stage-note' }, h('b', null, 'Vzorová strana'), ' · styl platí pro všechny strany'),
        h('span', { class: 'cal-pgsep', 'aria-hidden': 'true' }),
        h('div', { class: 'cal-zm', role: 'group', 'aria-label': 'Zoom' }, zoomOutButton, zoomFitButton, zoomInButton),
    );

    const pageHost = h('div', { class: 'cal-page-svg' });
    const chromeHost = h('div', { class: 'cal-chrome-host' });
    const overlayRoot = h('div', { class: 'cal-ovl' });
    const partsRoot = h('div', { class: 'cal-ovl cal-ovl-parts' });
    const canvas = h('div', { class: 'cal-cv' }, pageHost, chromeHost, overlayRoot, partsRoot);
    const viewport = h('div', { class: 'cal-cvw' }, canvas);
    const hint = h('p', { class: 'cal-hint cap', 'aria-live': 'polite' });
    const panelToggle = embedded ? null : h('button', { type: 'button', class: 'cal-rc', 'data-cal-action': 'panel-toggle', onClick: () => store.setUi({ panelCollapsed: !store.getState().ui.panelCollapsed }) });
    const stage = h('div', { class: `cal-stage${embedded ? ' embedded' : ''}` }, bar, viewport, hint, panelToggle);
    root.replaceChildren(stage);

    const overlay = mountOverlay(overlayRoot, ctx);
    const partsOverlay = mountPartsOverlay(partsRoot, ctx);

    // ---- rendering ------------------------------------------------------------------------

    let scale = 0;
    let sample = null;
    let renderedDoc = null;
    let renderedSample = null;
    let chromeKey = '';
    let hintKey = '';
    let pending = false;
    let destroyed = false;

    function render() {
        pending = false;
        if (destroyed) return;
        const state = store.getState();
        const { design } = state.doc;
        if (!design.layout) return;
        const B = bleedOf(design.print);
        const { width: W, height: H } = design.page;

        // 100 % = the page fits the stage; when the columns are stacked the page is fitted by width only
        const next = fitScale(stage.clientWidth, stacked.matches ? Infinity : stage.clientHeight, design.page, B) * state.ui.zoom;
        if (next !== scale) {
            const fx = viewport.scrollWidth > viewport.clientWidth ? (viewport.scrollLeft + viewport.clientWidth / 2) / viewport.scrollWidth : 0.5;
            const fy = viewport.scrollHeight > viewport.clientHeight ? (viewport.scrollTop + viewport.clientHeight / 2) / viewport.scrollHeight : 0.5;
            scale = next;
            canvas.style.width = `${(W + 2 * B) * scale}px`;
            canvas.style.height = `${(H + 2 * B) * scale}px`;
            for (const layer of [overlayRoot, partsRoot]) {
                layer.style.left = `${B * scale}px`;
                layer.style.top = `${B * scale}px`;
            }
            // keep the centre of the view where it was
            viewport.scrollLeft = fx * viewport.scrollWidth - viewport.clientWidth / 2;
            viewport.scrollTop = fy * viewport.scrollHeight - viewport.clientHeight / 2;
        }

        sample = samplePage(state.doc, store.pages(), state.ui.selection);
        if (state.doc !== renderedDoc || sample !== renderedSample) {
            renderedDoc = state.doc;
            renderedSample = sample;
            pageHost.innerHTML = sample ? renderPage(state.doc, sample, { ...store.layoutOpts(), idPrefix: id, bleed: true, placeholders: 'all' }) : '';
        }
        const nextChrome = `${W}|${H}|${B}`;
        if (nextChrome !== chromeKey) {
            chromeKey = nextChrome;
            chromeHost.innerHTML = chromeMarkup(design.page, B, `${id}-hatch`);
        }

        const geometry = sample ? store.geometry(sample) : null;
        overlay.update(geometry, scale);
        partsOverlay.update(geometry, scale, state.ui.selection);
        syncBar(state);
        syncHint(state);
    }

    /** Coalesces the events of one action into one render that runs before anything else. */
    function schedule() {
        if (pending || destroyed) return;
        pending = true;
        queueMicrotask(render);
    }

    function syncBar(state) {
        const linked = state.doc.design.linkElements !== false;
        linkButton.classList.toggle('on', linked);
        linkButton.setAttribute('aria-pressed', String(linked));
        const zoom = state.ui.zoom;
        const label = `${Math.round(zoom * 100)} %`;
        if (zoomFitButton.textContent !== label) zoomFitButton.textContent = label;
        zoomOutButton.disabled = zoom <= K.ZOOM_STEPS[0] + 1e-9;
        zoomInButton.disabled = zoom >= K.ZOOM_STEPS[K.ZOOM_STEPS.length - 1] - 1e-9;
        stage.classList.toggle('panel-off', !embedded && state.ui.panelCollapsed);
        if (panelToggle) {
            const off = state.ui.panelCollapsed;
            const text = off ? 'Zobrazit pravý panel' : 'Skrýt pravý panel';
            if (panelToggle.getAttribute('aria-label') !== text) {
                panelToggle.setAttribute('aria-label', text);
                panelToggle.title = off ? 'Zobrazit panel' : 'Skrýt panel';
                panelToggle.setAttribute('aria-expanded', String(!off));
                panelToggle.replaceChildren(icon(off ? 'chev-left' : 'chev-right', 's16'));
            }
        }
    }

    function syncHint(state) {
        const selection = state.ui.selection;
        const part = selection && selection.el === 'cal' ? selection.part || null : null;
        const moved = Boolean(part && state.doc.design.layout.styles.offsets[part]);
        const key = selection && selection.el === 'cal' ? (part ? `part:${moved}` : 'block') : 'element';
        if (key === hintKey) return;
        hintKey = key;
        if (!part) {
            hint.replaceChildren(key === 'block' ? HINT_BLOCK : HINT_ELEMENT);
            return;
        }
        hint.replaceChildren(HINT_PART);
        if (moved) hint.append(' · ', h('button', { type: 'button', class: 'btn xs btn-ghost', 'data-cal-action': 'offset-reset', onClick: resetOffset }, 'Vrátit na původní místo'));
    }

    // ---- actions --------------------------------------------------------------------------

    function toggleLink() {
        const on = store.getState().doc.design.linkElements === false;
        store.setDesign({ linkElements: on });
        ctx.toast(on ? 'Prvky propojené — sousedé se přizpůsobí' : 'Prvky odpojené — každý zvlášť');
    }

    function setZoom(zoom) {
        store.setUi({ zoom });
    }

    function resetOffset() {
        const selection = store.getState().ui.selection;
        const part = selection && selection.part;
        if (!part) return;
        store.change((layout) => {
            if (!layout.styles.offsets[part]) return layout;
            const offsets = { ...layout.styles.offsets };
            delete offsets[part];
            return { ...layout, styles: { ...layout.styles, offsets } };
        });
    }

    // ---- pointer pipeline (§D.6) ------------------------------------------------------------

    let gesture = null;

    /** Pointer position in mm from the trim corner. */
    function pointOf(event) {
        const rect = overlayRoot.getBoundingClientRect();
        return { x: (event.clientX - rect.left) / scale, y: (event.clientY - rect.top) / scale };
    }

    function toClient(xMm, yMm) {
        if (pending) render();
        const rect = overlayRoot.getBoundingClientRect();
        return { x: rect.left + xMm * scale, y: rect.top + yMm * scale };
    }

    const frameOfKey = (key) => (key === 'cal2' ? 1 : 0);
    const boxKeyOfTarget = (design, target) => boxKeysOf(target.el, design)[target.frame || 0] || boxKeysOf(target.el, design)[0];
    const selectionOfTarget = (target) => (target.el === 'cal' ? { el: 'cal', frame: target.frame || 0 } : { el: target.el });

    function select(next) {
        if (!sameSelection(next, store.getState().ui.selection)) store.setUi({ selection: next });
    }

    function onPointerDown(event) {
        if (event.button !== 0 || gesture || !scale) return;
        const state = store.getState();
        const { design } = state.doc;
        if (!design.layout) return;
        const selection = state.ui.selection;
        const pt = pointOf(event);
        const base = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, pt, started: false, session: null };

        const handle = event.target.closest ? event.target.closest('[data-handle]') : null;
        if (handle && canvas.contains(handle)) {
            const key = selectedBoxKey(design, selection);
            if (!key) return;
            gesture = { ...base, kind: 'resize', key, handle: handle.dataset.handle };
            capture(event);
            return;
        }

        const target = hitTest(pt, design, selection);
        if (!target) {
            select(null);
            return;
        }
        const selectedKey = selectedBoxKey(design, selection);
        const inside = Boolean(selectedKey && containsPoint(design.layout.boxes[selectedKey], pt));
        const calWasSelected = Boolean(selection && selection.el === 'cal');

        if (calWasSelected && target.el === 'cal') {
            // a press on the selected part moves that part in every row; anything else moves the block
            const hit = partAt(pt, store.geometry(sample));
            if (selection.part && hit && hit.part === selection.part) {
                gesture = { ...base, kind: 'part', part: hit.part, frame: hit.frame, calWasSelected };
            } else {
                gesture = { ...base, kind: 'move', key: inside ? selectedKey : boxKeyOfTarget(design, target), calWasSelected };
            }
        } else if (inside) {
            // the selected element is moved by its frame even when something lies over it
            gesture = { ...base, kind: 'move', key: selectedKey, calWasSelected };
        } else {
            select(selectionOfTarget(target));
            if (target.el === 'bg') return;
            gesture = { ...base, kind: 'move', key: boxKeyOfTarget(design, target), calWasSelected: false };
        }
        capture(event);
    }

    function capture(event) {
        try {
            canvas.setPointerCapture(event.pointerId);
        } catch (e) {
            // a synthetic event without an active pointer: the window listeners below still follow it
        }
    }

    function startGesture() {
        const state = store.getState();
        const { design } = state.doc;
        const g = gesture;
        g.started = true;
        stage.classList.add('dragging');
        canvas.dataset.drag = g.kind === 'resize' ? g.handle : 'move';
        store.beginChange();
        if (g.kind === 'part') {
            const offsets = design.layout.styles.offsets;
            g.startOffset = offsets[g.part] || (FOLLOWS_ICON.includes(g.part) && offsets.icon) || null;
            g.keepZero = FOLLOWS_ICON.includes(g.part) && Boolean(offsets.icon);
            const geometry = store.geometry(sample);
            const frame = geometry ? geometry.frames.find((f) => f.index === g.frame) : null;
            g.mirrored = Boolean(frame && frame.mirrored);
            select({ el: 'cal', frame: g.frame, part: g.part });
            return;
        }
        g.session = beginDrag({
            boxes: design.layout.boxes,
            key: g.key,
            mode: g.kind === 'resize' ? g.handle : 'move',
            page: design.page,
            bleedMm: bleedOf(design.print),
            visibleKeys: visibleBoxKeys(design),
            link: design.linkElements !== false,
        });
        g.last = JSON.stringify(g.session.base);
        // a drag of the Kalendárium moves the whole block and leaves the part level
        if (g.kind === 'move' && (g.key === 'cal' || g.key === 'cal2')) select({ el: 'cal', frame: frameOfKey(g.key) });
    }

    function onPointerMove(event) {
        if (!gesture) {
            hover(event);
            return;
        }
        if (event.pointerId !== gesture.pointerId) return;
        const g = gesture;
        if (!g.started) {
            if (Math.hypot(event.clientX - g.x, event.clientY - g.y) < K.DRAG_PX) return;
            startGesture();
        }
        const now = pointOf(event);
        const dx = now.x - g.pt.x;
        const dy = now.y - g.pt.y;

        if (g.kind === 'part') {
            let offset = dragPartOffset(g.startOffset, dx, dy, !event.altKey, g.mirrored);
            if (!offset && g.keepZero) offset = { x: 0, y: 0 };
            store.updateLive((layout) => {
                const current = layout.styles.offsets[g.part] || null;
                if ((!current && !offset) || (current && offset && current.x === offset.x && current.y === offset.y)) return layout;
                const offsets = { ...layout.styles.offsets };
                if (offset) offsets[g.part] = offset;
                else delete offsets[g.part];
                return { ...layout, styles: { ...layout.styles, offsets } };
            });
            return;
        }

        // back on the starting pixel = exactly the starting state, whatever lies within snapping reach
        const home = Math.abs(event.clientX - g.x) < 1 && Math.abs(event.clientY - g.y) < 1 && Math.abs(dx * scale) < 1 && Math.abs(dy * scale) < 1;
        const result = home ? { boxes: g.session.base, guides: [] } : dragTo(g.session, dx, dy, { snap: !event.altKey, thresholdMm: K.SNAP_PX / scale });
        const text = JSON.stringify(result.boxes);
        if (text !== g.last) {
            g.last = text;
            store.updateLive((layout) => ({ ...layout, boxes: result.boxes }));
        }
        setGuides(result.guides);
    }

    function setGuides(guides) {
        const current = store.getState().ui.guides || [];
        if (current.length === guides.length && current.every((c, i) => c.axis === guides[i].axis && c.pos === guides[i].pos)) return;
        store.setUi({ guides });
    }

    function endGesture() {
        const g = gesture;
        gesture = null;
        stage.classList.remove('dragging');
        delete canvas.dataset.drag;
        if (g && canvas.hasPointerCapture && canvas.hasPointerCapture(g.pointerId)) canvas.releasePointerCapture(g.pointerId);
        return g;
    }

    function onPointerUp(event) {
        if (!gesture || event.pointerId !== gesture.pointerId) return;
        const g = endGesture();
        if (g.started) {
            setGuides([]);
            store.commitChange();
            return;
        }
        if (g.kind === 'resize') return;
        // a click: the topmost element under the pointer; on the already selected Kalendárium the part
        const state = store.getState();
        const selection = state.ui.selection;
        const target = hitTest(g.pt, state.doc.design, selection);
        if (!target) {
            select(null);
        } else if (target.el === 'cal' && g.calWasSelected) {
            const hit = partAt(g.pt, store.geometry(sample));
            if (hit) select({ el: 'cal', frame: hit.frame, part: hit.part });
            else select(selection && selection.part ? { el: 'cal', frame: target.frame || 0, part: selection.part } : { el: 'cal', frame: target.frame || 0 });
        } else {
            select(selectionOfTarget(target));
        }
    }

    function cancelGesture() {
        if (!gesture) return;
        const g = endGesture();
        if (!g.started) return;
        setGuides([]);
        store.cancelChange();
    }

    function onKeyDown(event) {
        // Esc during a drag puts everything back; the key never reaches the selection handling
        if (event.key !== 'Escape' || !gesture || !gesture.started) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        cancelGesture();
    }

    function hover(event) {
        if (!scale || event.pointerType === 'touch') return;
        const state = store.getState();
        if (!state.doc.design.layout) return;
        if (event.target.closest && event.target.closest('[data-handle], .cal-ovl-parts > *')) {
            canvas.style.cursor = '';
            return;
        }
        const target = hitTest(pointOf(event), state.doc.design, state.ui.selection);
        const selectedKey = selectedBoxKey(state.doc.design, state.ui.selection);
        const onSelected = Boolean(selectedKey && containsPoint(state.doc.design.layout.boxes[selectedKey], pointOf(event)));
        canvas.style.cursor = onSelected || (target && target.el !== 'bg') ? 'move' : '';
    }

    /** A press on the empty stage around the page deselects. */
    function onStageDown(event) {
        if (event.button !== 0 || (event.target !== viewport && event.target !== stage)) return;
        select(null);
    }

    canvas.addEventListener('pointerdown', onPointerDown);
    canvas.addEventListener('pointermove', onPointerMove);
    canvas.addEventListener('pointerup', onPointerUp);
    canvas.addEventListener('pointercancel', cancelGesture);
    canvas.addEventListener('dragstart', (event) => event.preventDefault());
    stage.addEventListener('pointerdown', onStageDown);
    window.addEventListener('keydown', onKeyDown, true);
    window.addEventListener('blur', cancelGesture);

    // ---- wiring ---------------------------------------------------------------------------

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status') return;
        if (event.type === 'ui' && !event.keys.some((key) => UI_KEYS.includes(key))) return;
        schedule();
    });
    const resize = new ResizeObserver(schedule);
    resize.observe(stage);
    stacked.addEventListener('change', schedule);
    ctx.setStageProbe({
        scale: () => {
            if (pending) render();
            return scale;
        },
        toClient,
        page: () => sample,
    });
    render();

    return {
        destroy() {
            cancelGesture();
            destroyed = true;
            unsubscribe();
            resize.disconnect();
            stacked.removeEventListener('change', schedule);
            window.removeEventListener('keydown', onKeyDown, true);
            window.removeEventListener('blur', cancelGesture);
            if ((store.getState().ui.guides || []).length) store.setUi({ guides: [] });
            partsOverlay.destroy();
            overlay.destroy();
            ctx.setStageProbe(null);
            root.replaceChildren();
        },
    };
}
