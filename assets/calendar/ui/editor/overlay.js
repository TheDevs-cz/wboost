// Selection chrome of the editor stage (architecture §D.6): the selection frame with its eight
// handles and the name tag, the dim outline of the element's other frame on a 14-day page
// (Kalendárium, Měsíc and Číslo týdne have one frame per week column there), the
// highlight boxes of a selected Kalendárium part and the snap guides. Owner P4; used by `stage.js`.
//
// `mountOverlay(overlayRoot, ctx): { update(geometry, scale), destroy() }`
//   overlayRoot  `div.cal-ovl` — origin at the TRIM corner, coordinates are `mm × scale` px
//   update       redraws everything from the store state (`ui.selection`, `ui.guides`, the boxes)
//                and the `WeekGeometry` of the page on the stage; nodes are kept and moved, never
//                rebuilt, so a handle stays the same element for the whole drag
//
// Everything here is positioned from state — never from `getBoundingClientRect` of SVG nodes.
// DOM hooks (§D.10): `[data-handle="n…nw"]`, `[data-frame-tag]`, `[data-part-box]`, `[data-guide="x|y"]`.

import { bleedOf } from '../../model/defaults.js';
import { FRAME_LABELS, boxKeysOf, elementLabel, isElementVisible, partLabel } from '../../model/elements.js';
import { h } from '../dom.js';

// corners first, sides last: on a small frame the side handles lie on top
const HANDLE_ORDER = ['nw', 'ne', 'se', 'sw', 'n', 'e', 's', 'w'];
const PART_PAD = 0.5; // mm around a highlighted part
const TAG_ROOM = 30; // px the tag needs under the frame
const TIGHT_PX = 36; // frames smaller than this get handles without the enlarged grab area

const px = (v) => `${Math.round(v * 100) / 100}px`;

function place(el, box, scale) {
    el.style.left = px(box.x * scale);
    el.style.top = px(box.y * scale);
    el.style.width = px(box.w * scale);
    el.style.height = px(box.h * scale);
}

/** The box key the selection frame is drawn around, or null (nothing / Pozadí / not drawn). */
export function selectedBoxKey(design, selection) {
    if (!selection || selection.el === 'bg' || !design.layout || !isElementVisible(design, selection.el)) return null;
    const keys = boxKeysOf(selection.el, design);
    const key = keys[selection.frame || 0] || keys[0];
    return key && design.layout.boxes[key] ? key : null;
}

export function mountOverlay(overlayRoot, ctx) {
    const { store } = ctx;
    const tag = h('span', { class: 'cal-tag', 'data-frame-tag': '' });
    const frame = h('div', { class: 'cal-sel', hidden: true }, ...HANDLE_ORDER.map((d) => h('i', { class: `cal-h cal-h-${d}`, 'data-handle': d })), tag);
    const other = h('div', { class: 'cal-sel other', hidden: true });
    const partBoxes = h('div', { class: 'cal-pboxes' });
    const guides = h('div', { class: 'cal-gds' });
    overlayRoot.append(other, partBoxes, frame, guides);

    /** Keeps exactly `count` children in `parent`, creating them with `make`. */
    function pool(parent, count, make) {
        while (parent.children.length > count) parent.lastElementChild.remove();
        while (parent.children.length < count) parent.append(make());
        return parent.children;
    }

    function update(geometry, scale) {
        const state = store.getState();
        const { design } = state.doc;
        const selection = state.ui.selection;
        const key = scale > 0 ? selectedBoxKey(design, selection) : null;
        const B = bleedOf(design.print);

        frame.hidden = !key;
        other.hidden = true;
        if (key) {
            const box = design.layout.boxes[key];
            const part = selection.part || null;
            place(frame, box, scale);
            frame.classList.toggle('dim', Boolean(part));
            // on a small frame the handles keep their bare 10 px, so neighbours do not swallow each other
            frame.classList.toggle('tight', Math.min(box.w, box.h) * scale < TIGHT_PX);
            // a frame that reaches the lower edge of the canvas carries its tag inside
            frame.classList.toggle('tag-in', (box.y + box.h) * scale + TAG_ROOM > (design.page.height + B) * scale + 12);
            const keys = boxKeysOf(selection.el, design);
            let text = elementLabel(selection.el);
            if (part) text += ` › ${partLabel(part)}`;
            else if (keys.length > 1) text += ` · ${FRAME_LABELS[keys.indexOf(key)]}`;
            if (tag.textContent !== text) tag.textContent = text;
            frame.dataset.box = key;
            if (keys.length > 1) {
                const otherBox = design.layout.boxes[keys.find((k) => k !== key)];
                if (otherBox) {
                    other.hidden = false;
                    place(other, otherBox, scale);
                }
            }
        }

        const rects = [];
        if (key && selection.part && geometry) {
            for (const f of geometry.frames) {
                for (const row of f.rows) {
                    for (const part of row.parts) {
                        if (part.key === selection.part) rects.push({ rect: part.rect, frame: f.index, date: row.date });
                    }
                }
            }
        }
        const nodes = pool(partBoxes, rects.length, () => h('span', { class: 'cal-pbox', 'data-part-box': '' }));
        rects.forEach(({ rect, frame: frameIndex, date }, i) => {
            const node = nodes[i];
            place(node, { x: rect.x - PART_PAD, y: rect.y - PART_PAD, w: rect.w + 2 * PART_PAD, h: rect.h + 2 * PART_PAD }, scale);
            node.dataset.partBox = selection.part;
            node.dataset.frame = String(frameIndex);
            node.dataset.date = date;
        });

        const list = scale > 0 ? state.ui.guides || [] : [];
        const lines = pool(guides, list.length, () => h('span', { class: 'cal-gd' }));
        list.forEach((guide, i) => {
            const line = lines[i];
            const vertical = guide.axis === 'x';
            line.className = `cal-gd ${vertical ? 'v' : 'hz'}`;
            line.dataset.guide = guide.axis;
            line.dataset.pos = String(guide.pos);
            line.style.left = vertical ? px(guide.pos * scale) : '0px';
            line.style.top = vertical ? '0px' : px(guide.pos * scale);
            line.style.width = vertical ? '' : px(design.page.width * scale);
            line.style.height = vertical ? px(design.page.height * scale) : '';
        });
    }

    return {
        update,
        destroy() {
            frame.remove();
            other.remove();
            partBoxes.remove();
            guides.remove();
        },
    };
}
