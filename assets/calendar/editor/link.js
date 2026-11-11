// Propojit prvky (architecture §E.4; handoff §7 is authoritative).

import { K } from '../model/defaults.js';

/**
 * Followers of a resize of `key` from `from` to `to`. Everything derives from `baseBoxes` (the boxes
 * at the start of the gesture), so dragging back restores every follower exactly.
 * @returns all boxes, `key` set to `to`
 */
export function applyLink(baseBoxes, key, from, to, visibleKeys, page) {
    const TOL = K.LINK_TOL;
    const onPageEdge = { x: (v) => v <= TOL || v >= page.width - TOL, y: (v) => v <= TOL || v >= page.height - TOL };
    const edges = [
        [from.x, to.x, 'x'],
        [from.x + from.w, to.x + to.w, 'x'],
        [from.y, to.y, 'y'],
        [from.y + from.h, to.y + to.h, 'y'],
    ].filter(([a, z, axis]) => Math.abs(a - z) >= 0.005 && !onPageEdge[axis](a));

    const result = {};
    for (const [name, box] of Object.entries(baseBoxes)) result[name] = { ...box };
    result[key] = { ...to };
    if (!visibleKeys.includes(key)) return result;

    for (const name of visibleKeys) {
        if (name === key || !baseBoxes[name]) continue;
        const base = baseBoxes[name];
        const o = { ...base };
        for (const [a, z, axis] of edges) {
            const pos = axis === 'x' ? 'x' : 'y';
            const size = axis === 'x' ? 'w' : 'h';
            const min = axis === 'x' ? K.MIN_W : K.MIN_H;
            if (Math.abs(base[pos] - a) <= TOL) {
                const far = o[pos] + o[size];
                o[pos] = Math.min(z, far - min);
                o[size] = far - o[pos];
            } else if (Math.abs(base[pos] + base[size] - a) <= TOL) {
                o[size] = Math.max(min, z - o[pos]);
            }
        }
        result[name] = o;
    }
    return result;
}
