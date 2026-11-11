// Undo / redo stacks of layout snapshots (architecture §D.4). Pure.
//
// A snapshot is an opaque string (the store uses JSON of `{ layout, captions, preset }`).
// `undo(current)` / `redo(current)` take the CURRENT snapshot, park it on the opposite stack and
// return the snapshot to restore — or `null` when there is nothing to restore.

export function createUndo(limit) {
    const max = Math.max(1, Number(limit) || 1);
    let past = [];
    let future = [];

    return {
        /** Remembers the state BEFORE a change; a new change clears redo. */
        push(snapshot) {
            past.push(snapshot);
            if (past.length > max) past = past.slice(past.length - max);
            future = [];
        },
        undo(current) {
            if (!past.length) return null;
            const snapshot = past.pop();
            future.push(current);
            return snapshot;
        },
        redo(current) {
            if (!future.length) return null;
            const snapshot = future.pop();
            past.push(current);
            return snapshot;
        },
        canUndo() {
            return past.length > 0;
        },
        canRedo() {
            return future.length > 0;
        },
        clear() {
            past = [];
            future = [];
        },
    };
}
