// Shared property control — STUB of phase P3, owned by P5a.
//
// Contract (§D.2): `uploadTile(options) → HTMLElement`; the element may carry `setValue(value)` like the
// widgets of `ui/dom.js`, so a panel can refresh it without rebuilding. P5a fixes the exact option
// names when it implements the control and records them in architecture §D.2; the working shape is
//   { asset: AssetInfo | null, kind: 'photo' | 'logo' | 'icon' | 'qr' | 'image' | 'page', accept?, label, ctx, onChange(assetId | null) }
// A discrete control calls `onChange` once; a slider / colour drag calls `onStart` first,
// `onChange` while moving and `onCommit` at the end (one undo step, §D.6).

import { stubNote } from '../stub.js';

export function uploadTile(options) {
    void options;
    return stubNote('P5a', 'Nahrát');
}
