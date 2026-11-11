// „Pozice a velikost“ — X, Y, Š, V of the selected element in mm from the top-left trim corner
// (handoff §5.6, architecture §D.6 / §E.2). Owner: P5a; `props-kal.js` (P5b) mounts the same block
// for the Kalendárium.
//
//   positionSection(ctx, elementKey) → { el: HTMLElement, refresh(): void }
//
//   elementKey  'photo' | 'cap' | 'logo' | 'head' | 'month' | 'wk' | 'qr' | 'cal' (never 'bg' — it has no box)
//   el          the `.cal-psec` to place into the panel; inputs carry `data-field="x|y|w|h"`
//   refresh()   re-reads the box from the store — call it on every `layout` event (a drag on the
//               page, undo, auto-grow) — a focused field is left alone while the user types
//
// Behaviour: − / + move by 0.5 mm, typed values accept a decimal comma. Every change is one
// `store.change` through `setField` (`editor/drag.js`): X / Y move the box (clamped to the outer
// bleed edge, never touching other elements), Š / V move the right / bottom edge, stop at the bleed
// edge and at the minimum size, and take the neighbours along when Propojit prvky is on. After a
// change the four fields show what the store really holds, so a corrected value is visible at once.
//
// Kalendárium of a 14-day calendar: a `1. týden | 2. týden` switch (`[data-prop="cal.frame"]`) picks
// the frame; it writes `ui.selection.frame` (the panel is rebuilt by `props.js` on that change).

import { K, bleedOf } from '../../model/defaults.js';
import { visibleBoxKeys } from '../../model/elements.js';
import { setField } from '../../editor/drag.js';
import { numberField, segmented } from '../dom.js';
import { labelled, pair, section } from './panel.js';
import { showNumber } from './number.js';

const FIELDS = [
    ['x', 'X'],
    ['y', 'Y'],
    ['w', 'Š'],
    ['h', 'V'],
];

export function positionSection(ctx, elementKey) {
    const { store } = ctx;
    const design = () => store.getState().doc.design;
    const fortnightCal = () => elementKey === 'cal' && design().type === 'fortnight';
    const boxKey = () => {
        const selection = store.getState().ui.selection;
        return fortnightCal() && selection && selection.frame === 1 ? 'cal2' : elementKey;
    };
    const box = () => {
        const layout = design().layout;
        return layout ? layout.boxes[boxKey()] : null;
    };

    const fields = {};
    const sync = (force) => {
        const b = box();
        if (!b) return;
        for (const [key] of FIELDS) {
            if (force) showNumber(fields[key], b[key]);
            else fields[key].setValue(b[key]);
        }
    };

    const start = box() || { x: 0, y: 0, w: 0, h: 0 };
    for (const [key, label] of FIELDS) {
        fields[key] = numberField({
            value: start[key],
            step: K.FIELD_STEP,
            decimals: 1,
            unit: 'mm',
            label,
            attrs: { 'data-field': key },
            onChange(value) {
                const d = design();
                const target = boxKey();
                store.change((layout) => ({
                    ...layout,
                    boxes: setField(layout.boxes, target, key, value, { page: d.page, bleedMm: bleedOf(d.print), visibleKeys: visibleBoxKeys(d), link: d.linkElements }),
                }));
                // the store may have corrected the value (limits, minimum size): show what is stored
                sync(true);
            },
        });
    }

    let frame = null;
    if (fortnightCal()) {
        const selection = store.getState().ui.selection;
        frame = segmented({
            label: 'Týden',
            full: true,
            value: selection && selection.frame === 1 ? 1 : 0,
            attrs: { 'data-prop': 'cal.frame' },
            options: [
                { value: 0, label: '1. týden' },
                { value: 1, label: '2. týden' },
            ],
            onChange: (next) => {
                const now = store.getState().ui.selection;
                store.setUi({ selection: { ...(now || { el: 'cal' }), frame: next } });
            },
        });
        frame.classList.add('cal-side');
    }

    const el = section(
        'Pozice a velikost',
        frame,
        pair(...FIELDS.map(([key, label]) => labelled(label, fields[key]))),
    );

    return { el, refresh: () => sync(false) };
}
