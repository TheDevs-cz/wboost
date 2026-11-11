// Floating editors of step 5 (map §8.12 "Popover chrome") — STUB of phase P3, owned by P7a and used
// only by P7a's modules.
//
// Working shape:
//   `openPopover({ title, body: Node, at: { x, y }, confirmLabel?, onConfirm?(): boolean | void, onClose?() }): { el, close() }`
//   - `position: fixed` near `at` (viewport px), clamped to the viewport, draggable by its grip
//     through `assets/controllers/popover_drag.js` (`makeDraggable`)
//   - Enter in an input confirms, Esc / the close button discard; `onConfirm` returning `false`
//     keeps the popover open (empty event name or comment keeps focus and saves nothing)

export function openPopover(options) {
    void options;
    return { el: null, close() {} };
}
