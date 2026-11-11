// Store bindings of the property controls (architecture §D.6 "Properties"). Owner: P5a; reused by P5b.
//
// Every control of `ui/controls/*` speaks the same three callbacks:
//     onChange(value)   a new value
//     onStart()         optional — a continuous gesture begins (slider drag)
//     onCommit()        optional — the gesture ended
// A discrete control calls only `onChange` (→ one `store.change`, one undo step). A slider calls
// `onStart`, then `onChange` for every movement (→ `store.updateLive`, the page redraws) and
// `onCommit` at the end (→ `store.commitChange`, ONE undo step for the whole drag).
//
// `styleBinding(store, 'month.size')` returns exactly these callbacks for one path in
// `layout.styles`, so a panel writes
//     const b = styleBinding(store, 'cal.bg.opacity');
//     sliderRow({ label: 'Krytí', limit: 'percent', unit: '%', value: b.get(), ...b })
// `b.attrs` is `{ 'data-prop': path }` — the e2e hook of §D.10.

/** Value at a dotted path (`'photo.border.width'`, `'bg.gradient.stops.0.color'`); `undefined` when missing. */
export function getPath(object, path) {
    let node = object;
    for (const key of String(path).split('.')) {
        if (node === null || node === undefined) return undefined;
        node = node[key];
    }
    return node;
}

/** Copy of `object` with the value at `path` replaced; only the objects along the path are copied. */
export function setPath(object, path, value) {
    const keys = Array.isArray(path) ? path : String(path).split('.');
    const [head, ...rest] = keys;
    const base = Array.isArray(object) ? object.slice() : { ...(object || {}) };
    base[head] = rest.length ? setPath(base[head], rest, value) : value;
    return base;
}

/** `layout` with `styles.<path>` set. */
export function setStyle(layout, path, value) {
    return { ...layout, styles: setPath(layout.styles, path, value) };
}

/** `layout` with the object at `styles.<path>` shallow-merged with `patch`. */
export function patchStyle(layout, path, patch) {
    return setStyle(layout, path, { ...getPath(layout.styles, path), ...patch });
}

/** Current `layout.styles` of the store (null without a layout). */
export function stylesOf(store) {
    const layout = store.getState().doc.design.layout;
    return layout ? layout.styles : null;
}

/**
 * The callbacks of one style path.
 *
 * @param store  the calendar store
 * @param path   dotted path inside `layout.styles`
 * @param write  optional `(layout, value) => layout` replacing the plain "set the path" (use it when
 *               one control writes several fields, e.g. the Čísla slider)
 * @returns `{ get(), set(value), patch(object), onStart(), onChange(value), onCommit(), attrs }`
 *          `set` = one undo step regardless of a running gesture; `patch` merges into the object at the path
 */
export function styleBinding(store, path, write) {
    let live = false;
    const apply = write || ((layout, value) => setStyle(layout, path, value));
    return {
        attrs: { 'data-prop': path },
        get: () => getPath(stylesOf(store), path),
        set: (value) => store.change((layout) => apply(layout, value)),
        patch: (patch) => store.change((layout) => patchStyle(layout, path, patch)),
        onStart() {
            live = true;
            store.beginChange();
        },
        onChange(value) {
            if (live) store.updateLive((layout) => apply(layout, value));
            else store.change((layout) => apply(layout, value));
        },
        onCommit() {
            if (!live) return;
            live = false;
            store.commitChange();
        },
    };
}

/**
 * Keeps controls fresh without rebuilding a panel. `track(read, apply)` remembers the last value
 * `read()` returned and calls `apply(value)` only when it changed (identity — the store replaces
 * every touched object, so an untouched style object keeps its identity during a box drag).
 *
 * @returns `{ track(read, apply), refresh(), clear() }`
 */
export function createRefresher() {
    let entries = [];
    return {
        track(read, apply) {
            const entry = { read, apply, last: read() };
            entries.push(entry);
            return entry;
        },
        refresh() {
            for (const entry of entries.slice()) {
                const next = entry.read();
                if (next === entry.last) continue;
                entry.last = next;
                entry.apply(next);
            }
        },
        clear() {
            entries = [];
        },
    };
}
