// Zoom (architecture §E.13).

import { K } from '../model/defaults.js';

/** Fit scale in px per mm. */
export function fitScale(stageW, stageH, page, bleedMm) {
    const B = Number(bleedMm) || 0;
    const fit = Math.min((stageW - 64) / (page.width + 2 * B), (stageH - 120) / (page.height + 2 * B));
    return Math.min(K.FIT_MAX, Math.max(K.FIT_MIN, fit));
}

export function zoomIn(z) {
    const next = K.ZOOM_STEPS.find((s) => s > z + 1e-9);
    return next === undefined ? K.ZOOM_STEPS[K.ZOOM_STEPS.length - 1] : next;
}

export function zoomOut(z) {
    const smaller = K.ZOOM_STEPS.filter((s) => s < z - 1e-9);
    return smaller.length ? smaller[smaller.length - 1] : K.ZOOM_STEPS[0];
}
