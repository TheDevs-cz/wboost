/**
 * Pure image-placement math — the browser mirror of the server's
 * `Services\SocialNetwork\ImagePlacement`. Both sides must agree exactly:
 * whatever this draws over a preview is what the next server render will bake
 * into the PNG, so any divergence shows up as the picture visibly jumping when
 * the fresh render lands.
 *
 * The contract, identical to PHP:
 *   contain    = min(frame.width / naturalWidth, frame.height / naturalHeight)
 *   finalScale = contain * scale
 *   centre     = frame centre + offset
 *   rotation   = degrees, clockwise, about that centre
 *
 * The pan may be expressed in canvas px (`offsetX`/`offsetY`) or as a FRACTION
 * of the frame (`offsetXRatio`/`offsetYRatio`). The ratio form is what makes a
 * single placement portable across dimensions — the same crop intent in a
 * 1080×1080 and a 1080×1920, whose frames differ — and is what the group fill
 * page stores. A non-null ratio wins for its axis.
 */

/** A placement with everything at its neutral value: contained, centred, upright. */
export const NEUTRAL_PLACEMENT = Object.freeze({
    scale: 1,
    offsetXRatio: 0,
    offsetYRatio: 0,
    rotation: 0,
});

export function containScale(frame, naturalWidth, naturalHeight) {
    const width = naturalWidth > 0 ? naturalWidth : 1;
    const height = naturalHeight > 0 ? naturalHeight : 1;
    const contain = Math.min(frame.width / width, frame.height / height);

    return contain > 0 ? contain : 1;
}

/**
 * Cover twin of `containScale` — the least scale that fills the whole frame.
 * Background slots use it with the frame = the canvas rect.
 */
export function coverScale(frame, naturalWidth, naturalHeight) {
    const width = naturalWidth > 0 ? naturalWidth : 1;
    const height = naturalHeight > 0 ? naturalHeight : 1;
    const cover = Math.max(frame.width / width, frame.height / height);

    return cover > 0 ? cover : 1;
}

/** Resolve a pan expressed as a fraction of a frame edge into canvas px. */
export function offsetFromRatio(ratio, frameSize) {
    return ratio * frameSize;
}

/**
 * The picture's box in CANVAS pixels for one dimension: its displayed size and
 * the centre it is drawn around. `natural` is the image's intrinsic size.
 */
export function placementGeometry(frame, natural, placement) {
    const contain = containScale(frame, natural.width, natural.height);
    const finalScale = contain * (placement.scale ?? 1);

    const offsetX = placement.offsetXRatio != null
        ? offsetFromRatio(placement.offsetXRatio, frame.width)
        : (placement.offsetX ?? 0);
    const offsetY = placement.offsetYRatio != null
        ? offsetFromRatio(placement.offsetYRatio, frame.height)
        : (placement.offsetY ?? 0);

    return {
        width: (natural.width > 0 ? natural.width : 1) * finalScale,
        height: (natural.height > 0 ? natural.height : 1) * finalScale,
        // Centre relative to the frame's top-left corner — the frame IS the
        // clipping window, so the ghost positions inside it.
        centerX: frame.width / 2 + offsetX,
        centerY: frame.height / 2 + offsetY,
        rotation: placement.rotation ?? 0,
    };
}

/**
 * Background-slot geometry: cover fit anchored to the frame's TOP-LEFT
 * (overflow crops bottom-right), no user transform. The browser mirror of the
 * server's `ImagePlacement::computeCover` — the two must agree exactly.
 */
export function coverGeometry(frame, natural) {
    const scale = coverScale(frame, natural.width, natural.height);
    const width = (natural.width > 0 ? natural.width : 1) * scale;
    const height = (natural.height > 0 ? natural.height : 1) * scale;

    return {
        width,
        height,
        // Same centre-based shape as `placementGeometry` so ghost rendering
        // code can treat both fits uniformly (centre of the displayed image,
        // relative to the frame's top-left corner).
        centerX: width / 2,
        centerY: height / 2,
        rotation: 0,
    };
}

/**
 * The same geometry expressed as inline CSS for a ghost `<img>` inside a
 * frame-sized, overflow-hidden box, at a display scale `k`
 * (= rendered preview width / variant width).
 */
export function ghostStyle(frame, natural, placement, k) {
    const geometry = placementGeometry(frame, natural, placement);

    return {
        width: `${geometry.width * k}px`,
        height: `${geometry.height * k}px`,
        left: `${geometry.centerX * k}px`,
        top: `${geometry.centerY * k}px`,
        transform: `translate(-50%, -50%) rotate(${geometry.rotation}deg)`,
    };
}

/** The frame's own box in display px, relative to the preview's top-left. */
export function frameBox(frame, k) {
    return {
        left: frame.x * k,
        top: frame.y * k,
        width: frame.width * k,
        height: frame.height * k,
    };
}

export function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

// --- Image-adjust toolbar (both fill pages) ---------------------------------
//
// The toolbar edits the SAME placement the drag does (`scale` × contain,
// pan from the frame centre, degrees) — it only shows it in friendlier
// terms: a zoom slider where 100 % = "Přizpůsobit" (contain), and the
// picture's width / height / top-left X / Y in the variant's own unit
// (mm / cm for print, px for screen formats), measured from the CANVAS
// origin on the unrotated box. Nothing new is stored — every number maps
// back to the four placement fields through the functions below.

/** Lowest zoom the toolbar / handles allow: a quarter of "Přizpůsobit", so a
 *  picture CAN be shrunk below contain (the frame then shows gaps). */
export const ADJUST_SCALE_MIN = 0.25;

/** Highest zoom: 4× contain, but never less than 2× cover — a panorama in a
 *  tall frame needs a big multiple of contain just to fill it. */
export function adjustScaleMax(frame, natural) {
    const cover = coverScale(frame, natural.width, natural.height) / containScale(frame, natural.width, natural.height);

    return Math.max(4, Math.ceil(cover * 2 * 4) / 4);
}

/** The placement `scale` at which the picture covers the frame ("Vyplnit"). */
export function coverRelativeScale(frame, natural) {
    return coverScale(frame, natural.width, natural.height) / containScale(frame, natural.width, natural.height);
}

/** Canvas px per one unit of the variant's unit (300 DPI for physical). */
export function pxPerUnit(unit) {
    if (unit === 'mm') return 300 / 25.4;
    if (unit === 'cm') return 3000 / 25.4;
    return 1;
}

/** Display decimals for a unit: px whole, mm one, cm two. */
export function unitDecimals(unit) {
    if (unit === 'mm') return 1;
    if (unit === 'cm') return 2;
    return 0;
}

export function roundTo(value, decimals) {
    const factor = 10 ** decimals;

    return Math.round(value * factor) / factor;
}

/**
 * Placement (centre offset in canvas px + scale) → the toolbar's box in
 * the variant's unit: {width, height, x, y} with x/y = the unrotated
 * picture's top-left corner relative to the canvas origin.
 */
export function placementToBox(frame, natural, scale, offsetX, offsetY, unit) {
    const k = pxPerUnit(unit);
    const contain = containScale(frame, natural.width, natural.height);
    const width = (natural.width > 0 ? natural.width : 1) * contain * scale;
    const height = (natural.height > 0 ? natural.height : 1) * contain * scale;
    const centerX = frame.x + frame.width / 2 + offsetX;
    const centerY = frame.y + frame.height / 2 + offsetY;

    return {
        width: width / k,
        height: height / k,
        x: (centerX - width / 2) / k,
        y: (centerY - height / 2) / k,
    };
}

/** Inverse of `placementToBox` for a typed width (aspect locked; the CENTRE
 *  stays put, like every other resize): the new placement `scale`. */
export function scaleForWidth(frame, natural, widthInUnit, unit) {
    const contain = containScale(frame, natural.width, natural.height);
    const width = (natural.width > 0 ? natural.width : 1) * contain;

    return (widthInUnit * pxPerUnit(unit)) / width;
}

export function scaleForHeight(frame, natural, heightInUnit, unit) {
    const contain = containScale(frame, natural.width, natural.height);
    const height = (natural.height > 0 ? natural.height : 1) * contain;

    return (heightInUnit * pxPerUnit(unit)) / height;
}

/** Inverse of `placementToBox` for a typed top-left X / Y: the centre offset
 *  (canvas px) along that axis, at the current scale. */
export function offsetForX(frame, natural, scale, xInUnit, unit) {
    const contain = containScale(frame, natural.width, natural.height);
    const width = (natural.width > 0 ? natural.width : 1) * contain * scale;

    return xInUnit * pxPerUnit(unit) + width / 2 - (frame.x + frame.width / 2);
}

export function offsetForY(frame, natural, scale, yInUnit, unit) {
    const contain = containScale(frame, natural.width, natural.height);
    const height = (natural.height > 0 ? natural.height : 1) * contain * scale;

    return yInUnit * pxPerUnit(unit) + height / 2 - (frame.y + frame.height / 2);
}
