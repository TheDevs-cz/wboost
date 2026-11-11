// Shared month grid of the content tools (map §8.11 "Date picker"). STUB of phase P3, owned by P6b
// — its only consumers are P6b's own tools, so P6b may refine the options; keep the shape.
//
// Contract: `mountDatePicker(root, options): { setMonth(year, month), refresh(), destroy() }`
//   options.year / options.month   month shown first (month is 1-based)
//   options.min / options.max      ISO limits (`dateLimits(year)` of model/content.js)
//   options.isMarked(iso): boolean days drawn as selected
//   options.onPick(iso)            a day was clicked
//   options.counts?(year, month): number   badge of the month strip, when the strip is wanted

import { stubNote } from '../stub.js';

export function mountDatePicker(root, options) {
    void options;
    root.replaceChildren(stubNote('P6b', 'Výběr dne'));
    return {
        setMonth() {},
        refresh() {},
        destroy() {
            root.replaceChildren();
        },
    };
}
