// Alignment controls (map §8.1). Owner: P5a; reused by P5b.
//
//   alignControl({ value: 'start' | 'middle' | 'end', onChange(value), label?, attrs? })
//       physical left | centre | right — icons, tooltips `Vlevo` / `Na střed` / `Vpravo`
//   valignControl({ value: 'top' | 'middle' | 'bottom', onChange(value), label?, attrs? })
//       `Nahoru` / `Na střed` / `Dolů` — the values are the `VAlign` of architecture §B.2
//   sideControl({ value, onChange, label, options: [{ value, label }], attrs? })
//       a full-width text segmented control (`Doplní klient | Doplním sám`, `Vyplnit | Přizpůsobit`)
//
// All return the `.seg` of `segmented()` (`ui/dom.js`): `button[data-value][aria-pressed]`, `setValue(value)`.
// `attrs` lands on the group element (`{ 'data-prop': 'cap.align' }`).

import { segmented } from '../dom.js';

export function alignControl({ value, onChange, label = 'Zarovnání', attrs }) {
    return segmented({
        label,
        value,
        onChange,
        attrs,
        options: [
            { value: 'start', icon: 'al-left', title: 'Vlevo' },
            { value: 'middle', icon: 'al-center', title: 'Na střed' },
            { value: 'end', icon: 'al-right', title: 'Vpravo' },
        ],
    });
}

export function valignControl({ value, onChange, label = 'Svislé zarovnání', attrs }) {
    return segmented({
        label,
        value,
        onChange,
        attrs,
        options: [
            { value: 'top', icon: 'alv-t', title: 'Nahoru' },
            { value: 'middle', icon: 'alv-m', title: 'Na střed' },
            { value: 'bottom', icon: 'alv-b', title: 'Dolů' },
        ],
    });
}

export function sideControl({ value, onChange, label, options, attrs }) {
    const el = segmented({ label, value, onChange, options, full: true, attrs });
    el.classList.add('cal-side');
    return el;
}
