/**
 * Photoshop's "Režim prolnutí" as the canvas can draw it.
 *
 * The carrier is Fabric's NATIVE `globalCompositeOperation` — serialized in
 * the canvas JSON by Fabric itself, so every surface that enlivens the JSON
 * (editor, headless render, fill page) paints it with no further teaching.
 *
 * Only the 16 modes a 2D canvas draws natively are offered (Jan, 2026-11-21
 * — the same 16 the calendar offers, docs/calendar/effects-plan.md §4):
 * Rozpustit, Lineárně ztmavit / zesvětlit, Tmavší / Světlejší barva, Jasné /
 * Lineární / Bodové světlo, Tvrdé míchání, Odečíst and Rozdělit have no
 * canvas operator and are not listed. Groups and order follow Photoshop.
 *
 * Mirrored by `src/Value/CanvasBlendMode.php` (same values, same order) —
 * `CanvasBlendModeTest` parses this file to keep the two in step.
 */
export const BLEND_MODE_GROUPS = [
    [
        ['source-over', 'Normální'],
    ],
    [
        ['darken', 'Ztmavit'],
        ['multiply', 'Násobit'],
        ['color-burn', 'Ztmavit barvy'],
    ],
    [
        ['lighten', 'Zesvětlit'],
        ['screen', 'Závoj'],
        ['color-dodge', 'Zesvětlit barvy'],
    ],
    [
        ['overlay', 'Překrýt'],
        ['soft-light', 'Měkké světlo'],
        ['hard-light', 'Tvrdé světlo'],
    ],
    [
        ['difference', 'Rozdíl'],
        ['exclusion', 'Vyloučit'],
    ],
    [
        ['hue', 'Odstín'],
        ['saturation', 'Sytost'],
        ['color', 'Barva'],
        ['luminosity', 'Světlost'],
    ],
];

export const NORMAL_BLEND_MODE = 'source-over';

const KNOWN = new Set(BLEND_MODE_GROUPS.flat().map(([value]) => value));

/** The object's blend mode, normalised: anything unknown reads as Normální. */
export function blendModeOf(obj) {
    const value = obj && typeof obj.globalCompositeOperation === 'string' ? obj.globalCompositeOperation : NORMAL_BLEND_MODE;
    return KNOWN.has(value) ? value : NORMAL_BLEND_MODE;
}

export function isKnownBlendMode(value) {
    return KNOWN.has(value);
}
