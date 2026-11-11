// Shared property control — STUB of phase P3, owned by P5a.
//
// Contract (§D.2): `gradientEditor(options) → HTMLElement`; the element may carry `setValue(value)` like the
// widgets of `ui/dom.js`, so a panel can refresh it without rebuilding. P5a fixes the exact option
// names when it implements the control and records them in architecture §D.2; the working shape is
//   { value: Gradient, onChange(gradient), palette: string[], onStart?, onCommit? }
// A discrete control calls `onChange` once; a slider / colour drag calls `onStart` first,
// `onChange` while moving and `onCommit` at the end (one undo step, §D.6).

import { stubNote } from '../stub.js';

export function gradientEditor(options) {
    void options;
    return stubNote('P5a', 'Přechod');
}
