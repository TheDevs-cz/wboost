/**
 * Pure size / position math for the admin editor's "Velikost a poloha"
 * section (image popover, canvas-image-properties). Node-testable: no Fabric,
 * no DOM — the controller adapts a Fabric object to the plain shapes below.
 *
 * The numbers the designer sees are the object's UNROTATED displayed box —
 * the same convention as the fill page's image-adjust panel
 * (image_placement.js `placementToBox`):
 *
 *   width  = natural width  × |scaleX|
 *   height = natural height × |scaleY|
 *   x, y   = that box's top-left = centre − size / 2   (trim origin)
 *
 * in the variant's unit (px, or mm / cm at 300 DPI — `pxPerUnit`).
 *
 * The size slider's 100 % is "Přizpůsobit" — the picture contained in the
 * design area (the trim), exactly as 100 % on the fill page is the picture
 * contained in its frame. In the editor the "frame" a picture fits into is
 * the canvas itself. Logos and full-bleed photos live orders of magnitude
 * apart on the same canvas, so the slider is LOGARITHMIC between
 * SIZE_PERCENT_MIN and `sizePercentMax`.
 */

export { pxPerUnit, unitDecimals, roundTo } from './image_placement.js';

/** Smallest slider value: 1 % of "fit to canvas" (a small logo on A4 is ~5 %). */
export const SIZE_PERCENT_MIN = 1;

/** Slider resolution — the range input runs 0…SLIDER_STEPS. */
export const SLIDER_STEPS = 1000;

/** Scale that contains `natural` inside `area` (both {width, height}). */
export function containFactor(natural, area) {
    const w = natural.width > 0 ? natural.width : 1;
    const h = natural.height > 0 ? natural.height : 1;
    const factor = Math.min(area.width / w, area.height / h);

    return factor > 0 ? factor : 1;
}

/** Scale that covers `area` with `natural`. */
export function coverFactor(natural, area) {
    const w = natural.width > 0 ? natural.width : 1;
    const h = natural.height > 0 ? natural.height : 1;
    const factor = Math.max(area.width / w, area.height / h);

    return factor > 0 ? factor : 1;
}

/** Displayed width (px) as a percentage of "fit to the design area". */
export function sizePercent(displayedWidth, natural, design) {
    const fitWidth = (natural.width > 0 ? natural.width : 1) * containFactor(natural, design);

    return (displayedWidth / fitWidth) * 100;
}

/** Slider ceiling: 400 %, but at least 2× cover (a panorama on a tall canvas)
 *  and never below the current size, so any existing design is reachable. */
export function sizePercentMax(natural, design, currentPercent = 0) {
    const coverPercent = (coverFactor(natural, design) / containFactor(natural, design)) * 100;

    return Math.max(400, Math.ceil(coverPercent * 2), Math.ceil(currentPercent));
}

/** Slider floor: SIZE_PERCENT_MIN, lowered when the current size is smaller. */
export function sizePercentMin(currentPercent = Infinity) {
    return Math.max(0.01, Math.min(SIZE_PERCENT_MIN, currentPercent));
}

/** Log-scale slider position (0…SLIDER_STEPS) → percent. */
export function sliderToPercent(position, min, max) {
    const t = Math.min(1, Math.max(0, position / SLIDER_STEPS));

    return Math.exp(Math.log(min) + t * (Math.log(max) - Math.log(min)));
}

/** Percent → log-scale slider position (0…SLIDER_STEPS). */
export function percentToSlider(percent, min, max) {
    if (!(percent > 0)) return 0;
    const t = (Math.log(percent) - Math.log(min)) / (Math.log(max) - Math.log(min));

    return Math.round(Math.min(1, Math.max(0, t)) * SLIDER_STEPS);
}

/** Percent label: whole numbers, one decimal below 10 % (logos live there). */
export function formatPercent(percent) {
    return percent < 10 ? `${(Math.round(percent * 10) / 10).toLocaleString('cs-CZ')} %` : `${Math.round(percent)} %`;
}

/**
 * Plain geometry of an object → its unrotated box in canvas px.
 * `geometry` = {width, height, scaleX, scaleY, centerX, centerY, angle}.
 */
export function boxOf(geometry) {
    const width = geometry.width * Math.abs(geometry.scaleX || 1);
    const height = geometry.height * Math.abs(geometry.scaleY || 1);

    return {
        width,
        height,
        x: geometry.centerX - width / 2,
        y: geometry.centerY - height / 2,
        angle: geometry.angle || 0,
    };
}

/**
 * A typed width or height (canvas px). Anchored at the box's TOP-LEFT so the
 * X / Y fields next to it keep their values (Figma convention); with the
 * aspect lock on the other side follows the box's CURRENT ratio (a stretched
 * image stays exactly as stretched). Returns {scaleX, scaleY, centerX, centerY}.
 */
export function resizeBox(geometry, axis, value, lockAspect) {
    const box = boxOf(geometry);
    const sx = Math.abs(geometry.scaleX || 1);
    const sy = Math.abs(geometry.scaleY || 1);
    let scaleX = sx;
    let scaleY = sy;

    if (axis === 'width') {
        scaleX = value / (geometry.width || 1);
        if (lockAspect) scaleY = sy * (scaleX / sx);
    } else {
        scaleY = value / (geometry.height || 1);
        if (lockAspect) scaleX = sx * (scaleY / sy);
    }

    const width = geometry.width * scaleX;
    const height = geometry.height * scaleY;

    return { scaleX, scaleY, centerX: box.x + width / 2, centerY: box.y + height / 2 };
}

/** Uniform zoom about the CENTRE (slider, Vyplnit / Přizpůsobit): every
 *  scale is multiplied by `factor`, the centre stays where it is. */
export function zoomBox(geometry, factor) {
    return {
        scaleX: Math.abs(geometry.scaleX || 1) * factor,
        scaleY: Math.abs(geometry.scaleY || 1) * factor,
        centerX: geometry.centerX,
        centerY: geometry.centerY,
    };
}

/** A typed X / Y (canvas px): the box's top-left moves there, size kept. */
export function moveBox(geometry, axis, value) {
    const box = boxOf(geometry);

    return axis === 'x'
        ? { centerX: value + box.width / 2, centerY: geometry.centerY }
        : { centerX: geometry.centerX, centerY: value + box.height / 2 };
}

/** Rotation input → Fabric angle, normalised into (-180, 180]. */
export function normalizeAngle(degrees) {
    let angle = degrees % 360;
    if (angle > 180) angle -= 360;
    if (angle <= -180) angle += 360;

    return angle;
}
