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
