// Font block of a text style: family, cut, size (handoff §6.1 "Fonts", map §8.8 "Text block").
// Owner: P5a; reused by P5b for every Kalendárium text part.
//
//   textProps({ value, onChange, fonts, path?, sizeLimit?, label? }) → HTMLElement `.cal-psec.cal-text`
//
//   value      `TextStyle` = { family: string | null, weight, size, color } (extra keys are ignored)
//   onChange   `(patch) => void` — a PATCH of the style, one of `{ family, weight? }` / `{ weight }` / `{ size }`;
//              merge it into the style object (`styleBinding(store, path).patch`)
//   fonts      `env.fonts` — [{ family, fontName, faceName, weight, style }]; ONLY these are offered
//   path       style path of the TextStyle (`'month'`) → `data-prop` hooks `<path>.family` (Písmo select),
//              `<path>.weight` (Řez select), `<path>.size` (stepper)
//   sizeLimit  key of `LIMITS` for the size (default `fontSize`: 3–60 pt, 0.5)
//   label      section label (default `Písmo`)
//
// Rules: `family` is the exact face family (`"Rubik (Rubik Bold)"`) or `null` = the built-in fallback,
// shown as „Výchozí písmo“ (also when the stored family no longer exists in the project). `weight` is
// the WANTED weight: choosing a cut sets it to the cut's weight; changing the font keeps it and
// picks the cut of the new font nearest to it (upright preferred). Colour and alignment are separate
// controls — a panel adds `colorPicker` / `alignControl` where the element has them.
//
// The element carries `setValue(style)`.

import { faceByFamily, facesOf, fontNames, nearestFace, weightLabel } from '../../model/fonts.js';
import { h, selectField } from '../dom.js';
import { pair } from './panel.js';
import { limitField } from './number.js';

const FALLBACK = '';
const FALLBACK_LABEL = 'Výchozí písmo';
const FALLBACK_WEIGHTS = [400, 500, 600, 700];

function nearestWeight(weights, wanted) {
    let best = weights[0];
    for (const w of weights) {
        const d = Math.abs(w - wanted);
        const bd = Math.abs(best - wanted);
        if (d < bd || (d === bd && w > best)) best = w;
    }
    return best;
}

function cutLabel(face, faces) {
    const italic = /italic|oblique/i.test(`${face.style || ''} ${face.faceName || ''}`);
    const label = `${weightLabel(face.weight)}${italic ? ' Italic' : ''}`;
    // two cuts with the same weight and slope (condensed / display variants): fall back to the face name
    const twins = faces.filter((other) => other.weight === face.weight && /italic|oblique/i.test(`${other.style || ''} ${other.faceName || ''}`) === italic);
    return twins.length > 1 && face.faceName ? face.faceName : label;
}

export function textProps({ value, onChange, fonts, path, sizeLimit = 'fontSize', label = 'Písmo' }) {
    const list = fonts || [];
    const names = fontNames(list);
    let current = value;
    const prop = (suffix) => (path ? { 'data-prop': `${path}.${suffix}` } : {});
    const emit = (patch) => {
        current = { ...current, ...patch };
        paint();
        if (onChange) onChange(patch);
    };

    const face = () => faceByFamily(list, current.family);
    const fontOptions = (withFallback) => [...(withFallback ? [{ value: FALLBACK, label: FALLBACK_LABEL }] : []), ...names.map((name) => ({ value: name, label: name }))];

    const family = selectField({
        label: 'Písmo',
        value: FALLBACK,
        options: fontOptions(true),
        attrs: prop('family'),
        onChange: (name) => {
            if (name === FALLBACK) {
                emit({ family: null });
                return;
            }
            const next = nearestFace(list, name, Number(current.weight) || 400);
            if (next) emit({ family: next.family });
        },
    });
    const cut = selectField({
        label: 'Řez',
        value: '',
        options: [],
        attrs: prop('weight'),
        onChange: (key) => {
            if (key.startsWith('w:')) {
                emit({ weight: Number(key.slice(2)) });
                return;
            }
            const next = faceByFamily(list, key);
            if (next) emit({ family: next.family, weight: next.weight });
        },
    });
    const size = limitField({ limit: sizeLimit, value: current.size, unit: 'pt', label: 'Velikost', attrs: prop('size'), onChange: (v) => emit({ size: v }) });

    function paint() {
        const f = face();
        // the fallback entry is offered only while it is in use (or the project has no font at all)
        family.setOptions(fontOptions(!f), f ? f.fontName : FALLBACK);
        if (f) {
            const faces = facesOf(list, f.fontName);
            cut.setOptions(
                faces.map((x) => ({ value: x.family, label: cutLabel(x, faces) })),
                f.family,
            );
        } else {
            const wanted = nearestWeight(FALLBACK_WEIGHTS, Math.min(700, Number(current.weight) || 400));
            cut.setOptions(
                FALLBACK_WEIGHTS.map((w) => ({ value: `w:${w}`, label: weightLabel(w) })),
                `w:${wanted}`,
            );
        }
        size.setValue(current.size);
    }

    const el = h('div', { class: 'cal-psec cal-text' }, label ? h('span', { class: 'lbl' }, label) : null, family, pair(cut, size));
    paint();
    el.setValue = (next) => {
        if (next === current) return;
        current = next;
        paint();
    };
    return el;
}
