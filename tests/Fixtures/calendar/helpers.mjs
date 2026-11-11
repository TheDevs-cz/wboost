// Shared fixtures of the calendar suites (lives beside the harness, outside suites/, so the PHPUnit
// data provider does not take it for a suite).

export const ENV = { fonts: [], accent: '#C84B55', assets: {}, currentYear: 2026 };

export async function makeDoc(load, o = {}) {
    const { normalizeDocument } = await load('model/normalize.js');
    const { defaultLayout } = await load('model/layouts.js');
    const { bleedOf } = await load('model/defaults.js');
    const type = o.type || 'week';
    const preset = o.preset === undefined ? (type === 'fortnight' ? 'twoSided' : 'photoLeft') : o.preset;
    const base = normalizeDocument({ id: 'cal-1', revision: 1, name: o.name || 'Stolní kalendář 2027', year: o.year || 2027, type, page: o.page || {}, print: o.print || {}, features: o.features || {}, content: o.content || {} }, ENV);
    if (preset === null) return base;
    const layout = defaultLayout(preset, base.design.page, bleedOf(base.design.print), ENV);
    const styled = o.styles ? o.styles(layout) || layout : layout;
    return normalizeDocument({ ...base, design: { ...base.design, preset, layout: styled } }, ENV);
}

export async function layoutOpts(load, extra = {}) {
    const { createEstimatorMeasurer } = await load('render/measure.js');
    return { env: { assets: {}, fonts: [], ...(extra.env || {}) }, measurer: createEstimatorMeasurer(), ...extra, ...(extra.env ? { env: { assets: {}, fonts: [], ...extra.env } } : {}) };
}

export const edges = (b) => ({ l: b.x, r: b.x + b.w, t: b.y, b: b.y + b.h });

export function near(assert, actual, expected, message, tol = 1e-6) {
    assert.ok(Math.abs(actual - expected) <= tol, `${message || 'value'}: expected ${expected}, got ${actual}`);
}

export function boxNear(assert, actual, expected, message, tol = 1e-6) {
    for (const k of ['x', 'y', 'w', 'h']) near(assert, actual[k], expected[k], `${message || 'box'}.${k}`, tol);
}

export const copy = (v) => JSON.parse(JSON.stringify(v));
