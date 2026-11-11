// Snapping (architecture §E.3).

function best(candidates, targets, threshold) {
    let found = null;
    for (const value of candidates) {
        for (const target of targets) {
            const distance = Math.abs(target - value);
            if (distance <= threshold && (found === null || distance < found.distance - 1e-9)) found = { distance, delta: target - value, target };
        }
    }
    return found;
}

export function snapTargets(baseBoxes, movingKey, visibleKeys, page, bleedMm) {
    const B = Number(bleedMm) || 0;
    const W = page.width;
    const H = page.height;
    const xs = [0, W / 2, W];
    const ys = [0, page.bandHeight, (page.bandHeight + H) / 2, H];
    if (B > 0) {
        xs.push(-B, W + B);
        ys.push(-B, H + B);
    }
    for (const key of visibleKeys) {
        if (key === movingKey || !baseBoxes[key]) continue;
        const b = baseBoxes[key];
        xs.push(b.x, b.x + b.w);
        ys.push(b.y, b.y + b.h);
    }
    return { xs, ys };
}

export function snapMove(box, targets, thresholdMm) {
    const guides = [];
    const out = { ...box };
    const sx = best([box.x, box.x + box.w / 2, box.x + box.w], targets.xs, thresholdMm);
    if (sx) {
        out.x = box.x + sx.delta;
        guides.push({ axis: 'x', pos: sx.target });
    }
    const sy = best([box.y, box.y + box.h / 2, box.y + box.h], targets.ys, thresholdMm);
    if (sy) {
        out.y = box.y + sy.delta;
        guides.push({ axis: 'y', pos: sy.target });
    }
    return { box: out, guides };
}

/** Only the dragged edges snap; the opposite edge stays. */
export function snapResize(box, handle, targets, thresholdMm) {
    const guides = [];
    const out = { ...box };
    if (handle.includes('w')) {
        const s = best([box.x], targets.xs, thresholdMm);
        if (s) {
            out.x = s.target;
            out.w = box.x + box.w - s.target;
            guides.push({ axis: 'x', pos: s.target });
        }
    } else if (handle.includes('e')) {
        const s = best([box.x + box.w], targets.xs, thresholdMm);
        if (s) {
            out.w = s.target - box.x;
            guides.push({ axis: 'x', pos: s.target });
        }
    }
    if (handle.includes('n')) {
        const s = best([box.y], targets.ys, thresholdMm);
        if (s) {
            out.y = s.target;
            out.h = box.y + box.h - s.target;
            guides.push({ axis: 'y', pos: s.target });
        }
    } else if (handle.includes('s')) {
        const s = best([box.y + box.h], targets.ys, thresholdMm);
        if (s) {
            out.h = s.target - box.y;
            guides.push({ axis: 'y', pos: s.target });
        }
    }
    return { box: out, guides };
}
