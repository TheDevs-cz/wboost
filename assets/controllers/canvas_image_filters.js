import { filters as FabricFilters } from "fabric";

/**
 * "Styl vrstvy" › Úpravy obrázku — Photoshop-like adjustments on an image,
 * carried by Fabric's NATIVE `filters` array (serialized by Fabric, enlivened
 * by `loadFromJSON` on every surface).
 *
 * UI units (and the MCP DSL's `adjust` block, `Value/ImageAdjustments`):
 *   brightness  −100…100  → Brightness.brightness = v / 100
 *   contrast    −100…100  → Contrast.contrast     = v / 100
 *   saturation  −100…100  → Saturation.saturation = v / 100
 *   hue         −180…180° → HueRotation.rotation  = v / 180
 *   grayscale   bool      → Grayscale (luminosity)
 *   blur        0…100     → Blur.blur             = v / 400 (a share of the picture)
 *
 * Only non-neutral adjustments are stored, always in this canonical order, so
 * the array is byte-stable for the same settings. The headless render forces
 * Fabric's Canvas2D filter backend (no GPU there, no texture-size cap); the
 * editor and the fill page use Fabric's default backend for a live preview —
 * the export is the truth, as with every other preview.
 */

export const ADJUSTMENTS = [
    { key: 'brightness', type: 'Brightness', prop: 'brightness', min: -100, max: 100, scale: 100 },
    { key: 'contrast', type: 'Contrast', prop: 'contrast', min: -100, max: 100, scale: 100 },
    { key: 'saturation', type: 'Saturation', prop: 'saturation', min: -100, max: 100, scale: 100 },
    { key: 'hue', type: 'HueRotation', prop: 'rotation', min: -180, max: 180, scale: 180 },
    { key: 'blur', type: 'Blur', prop: 'blur', min: 0, max: 100, scale: 400 },
];

export function neutralAdjustments() {
    return { brightness: 0, contrast: 0, saturation: 0, hue: 0, grayscale: false, blur: 0 };
}

/** The UI model of an image's filters (unknown filters are ignored here and
 *  kept by {@see applyAdjustments}). */
export function adjustmentsOf(obj) {
    const model = neutralAdjustments();
    plainFilters(obj).forEach((filter) => {
        const spec = ADJUSTMENTS.find((entry) => entry.type === filter.type);
        if (spec) {
            model[spec.key] = Math.round((Number(filter[spec.prop]) || 0) * spec.scale);
        } else if (filter.type === 'Grayscale') {
            model.grayscale = true;
        }
    });
    return model;
}

/** Plain `{type, …}` objects of an object's filters (Fabric instances or
 *  already-plain entries alike). */
export function plainFilters(obj) {
    const list = obj && Array.isArray(obj.filters) ? obj.filters : [];
    return list.filter(Boolean).map((filter) => (typeof filter.toObject === 'function' ? filter.toObject() : { ...filter }));
}

/** The canonical plain filter list for a UI model. */
export function adjustmentsToPlain(model) {
    const out = [];
    ADJUSTMENTS.slice(0, 4).forEach((spec) => {
        const value = clamp(Number(model[spec.key]) || 0, spec.min, spec.max);
        if (value !== 0) out.push({ type: spec.type, [spec.prop]: round4(value / spec.scale) });
    });
    if (model.grayscale) out.push({ type: 'Grayscale', mode: 'luminosity' });
    const blur = clamp(Number(model.blur) || 0, 0, 100);
    if (blur !== 0) out.push({ type: 'Blur', blur: round4(blur / 400) });
    return out;
}

/** Fabric filter instances for a plain list (unknown types dropped). */
export function instantiateFilters(plainList) {
    return (Array.isArray(plainList) ? plainList : []).map((plain) => {
        const FilterClass = plain && FabricFilters[plain.type];
        if (!FilterClass) return null;
        const { type, ...options } = plain;
        return new FilterClass(options);
    }).filter(Boolean);
}

/** Set an image's adjustments (keeping any filter this UI does not word)
 *  and re-run Fabric's filter pipeline. */
export function applyAdjustments(obj, model) {
    const known = new Set([...ADJUSTMENTS.map((spec) => spec.type), 'Grayscale']);
    const foreign = plainFilters(obj).filter((filter) => !known.has(filter.type));
    setPlainFilters(obj, [...foreign, ...adjustmentsToPlain(model)]);
}

/** Replace an image's filters with a plain list and re-apply them. */
export function setPlainFilters(obj, plainList) {
    if (!obj || typeof obj.applyFilters !== 'function') return;
    const next = instantiateFilters(plainList);
    if (next.length === 0 && (!Array.isArray(obj.filters) || obj.filters.length === 0)) return;
    obj.filters = next;
    try {
        obj.applyFilters();
    } catch (error) {
        // A tainted (cross-origin, no CORS) picture cannot be filtered in the
        // browser; the export still applies the stored filters server-side.
        console.warn('Úpravy obrázku nelze v náhledu použít:', error);
    }
    obj.dirty = true;
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function round4(value) {
    return Math.round(value * 10000) / 10000;
}
