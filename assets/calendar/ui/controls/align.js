// Alignment controls — STUB of phase P3, owned by P5a.
//
// Contract (§D.2): `alignControl({ value: 'start' | 'middle' | 'end', onChange(value), attrs? })` and
// `valignControl({ value: 'start' | 'middle' | 'end', onChange(value), attrs? })` → HTMLElement
// (labels `Vlevo / Na střed / Vpravo`, `Nahoru / Na střed / Dolů`, map §8.1), with `setValue(value)`.

import { segmented } from '../dom.js';

export function alignControl({ value, onChange, attrs }) {
    return segmented({
        label: 'Zarovnání',
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

export function valignControl({ value, onChange, attrs }) {
    return segmented({
        label: 'Svislé zarovnání',
        value,
        onChange,
        attrs,
        options: [
            { value: 'start', icon: 'alv-t', title: 'Nahoru' },
            { value: 'middle', icon: 'alv-m', title: 'Na střed' },
            { value: 'end', icon: 'alv-b', title: 'Dolů' },
        ],
    });
}
