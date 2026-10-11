/**
 * Shared by every SortableJS list (templates, categories, colours, fonts,
 * mockup pages, weekly menu, editor layers, fill "Všechna pole" panel).
 *
 * All of them drag in `forceFallback` mode, where the clone following the
 * pointer has `pointer-events: none` — so mid-drag the cursor would be
 * whatever sits underneath (a text caret over an input, a pointer over a
 * button). `withMoveCursor` wraps the options' onStart/onEnd to flag the
 * document for the duration of the drag; app.css then shows `move`
 * everywhere (`html.is-sort-dragging`).
 */
export function withMoveCursor(options) {
    const { onStart, onEnd } = options;

    return {
        ...options,
        onStart(event) {
            document.documentElement.classList.add("is-sort-dragging");
            if (onStart) onStart.call(this, event);
        },
        onEnd(event) {
            document.documentElement.classList.remove("is-sort-dragging");
            if (onEnd) onEnd.call(this, event);
        },
    };
}
