// The calendar model, run on the server (docs/architecture/calendars.md § "Server-side model").
//
// The browser owns generation and defaults (`assets/calendar/**`). A calendar changed WITHOUT a
// browser — the MCP tools — must still go through those very rules, so PHP
// (`Services/Calendar/CalendarModelBridge`) pipes a calendar document into this script, the
// script drives the pure store (`state/store.js`) exactly as the workspace does, and answers the
// save payload the workspace would have POSTed. PHP then validates and stores that payload
// through the one write path (`SaveCalendar`). Nothing is generated or defaulted in PHP.
//
// stdin : { root, calendar, assets, env: { fonts, palette, currentYear }, commands: [...],
//           pages?: bool, warnings?: bool, view?: { bleed?, marks? } }
// stdout: { ok: true, payload: { design?, content? } | null, notes: [...], design, pages?,
//           warnings?, view? }
//       | { ok: false, code, message }
//
// Commands (all designer actions of the store):
//   { op: 'configure', name?, year?, type?, features?: {…}, print?: {…}, linkElements? }
//   { op: 'preset', preset, reset? }
//   { op: 'layout', boxes?: {…}, order?: [...], styles?: {…} }
//   { op: 'replace_layout', layout, assets? }
//   { op: 'step', step }
//
// Text is measured with the deterministic estimator (there are no fonts here): the auto-grow of
// Měsíc / Číslo týdne is therefore an estimate until the calendar is next edited in a browser.

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

class Refusal extends Error {
    constructor(code, message) {
        super(message);
        this.code = code;
    }
}

const refuse = (code, message) => {
    throw new Refusal(code, message);
};

const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const clone = (v) => (v === undefined ? undefined : JSON.parse(JSON.stringify(v)));
const quote = (list) => list.map((v) => `"${v}"`).join(', ');
const COLOR = /^#[0-9a-fA-F]{6}$/;

/** Style keys whose value is one of a few words. `null` = "automatic" where the default is null. */
const ENUMS = {
    align: ['start', 'middle', 'end'],
    numAlign: ['start', 'middle', 'end'],
    dayAlign: ['start', 'middle', 'end'],
    valign: ['top', 'middle', 'bottom'],
    numberSide: ['start', 'end'],
    fit: ['contain', 'cover'],
    source: ['client', 'self'],
    form: ['a', 'b', 'c'],
    dayFormat: ['full', 'short'],
    dayPosition: ['side', 'above'],
    iconGrid: ['4', '2x2', '1+2', '2+1'],
    style: ['solid', 'dash', 'dot'],
    position: ['text', 'sep'],
    bgMode: ['alt', 'all', 'none'],
    mode: ['four', 'one'],
    type: ['linear', 'radial'],
};

/** Style maps whose keys are data (ids, part names), not schema — `model/normalize.js` OPEN_MAPS. */
const OPEN_MAPS = ['cal.iconCells', 'waste.kinds', 'offsets'];
const ICON_CATEGORIES = ['waste', 'moon', 'ev', 'time', 'season'];

async function main(input) {
    const [major, minor] = process.versions.node.split('.').map(Number);
    if (major < 22 || (major === 22 && minor < 7)) {
        return { ok: false, code: 'node_too_old', message: `node ${process.versions.node} is older than 22.7` };
    }

    const load = (path) => import(pathToFileURL(join(input.root, 'assets/calendar', path)).href);
    const { K, bleedOf, roundMm } = await load('model/defaults.js');
    const { DEFAULT_ORDER, HIDEABLE_BOXES, elementOfBox } = await load('model/elements.js');
    const { defaultLayout, defaultStyles, presetsForType } = await load('model/layouts.js');
    const { normalizeDocument, normalizeLayout } = await load('model/normalize.js');
    const { buildPages } = await load('generate/pages.js');
    const { createEstimatorMeasurer } = await load('render/measure.js');
    const { pageViewBox } = await load('render/page.js');
    const { allWarnings } = await load('render/warnings.js');
    const { clampBoxesToBounds } = await load('editor/bleed.js');
    const { roundBox } = await load('editor/geometry.js');
    const { createStore } = await load('state/store.js');
    const { buildPayload } = await load('state/payload.js');

    const palette = Array.isArray(input.env && input.env.palette) ? input.env.palette : [];
    const env = {
        mode: 'designer',
        fonts: Array.isArray(input.env && input.env.fonts) ? input.env.fonts : [],
        palette,
        // the workspace controller's rule: first project colour that is not pure white / black
        accent: palette.find((c) => c !== '#FFFFFF' && c !== '#000000') || null,
        currentYear: Number(input.env && input.env.currentYear) || 0,
        assets: isObject(input.assets) ? clone(input.assets) : {},
        siblings: [],
        urls: {},
    };
    const measurer = createEstimatorMeasurer();
    // Nothing is ever sent from here: the payload is read off the state and handed to PHP.
    const api = { save: () => Promise.reject(new Error('The server-side model does not save.')) };
    const store = createStore({ doc: normalizeDocument(input.calendar, env), env, api, measurer });
    const design = () => store.getState().doc.design;
    const notes = [];
    const families = env.fonts.map((f) => f.family);

    // ---- configure ------------------------------------------------------------------------

    function configure(cmd) {
        if (cmd.type !== undefined && cmd.type !== null) {
            if (cmd.type !== 'week' && cmd.type !== 'fortnight') refuse('invalid_type', `type must be "week" (7 days per page) or "fortnight" (14 days per page), not ${JSON.stringify(cmd.type)}.`);
            if (cmd.type !== design().type) {
                const hadLayout = Boolean(design().layout);
                store.changeType(cmd.type);
                if (hadLayout) notes.push(`The type changed: element positions were reset to the default ${design().preset} layout of the new type (styles kept).`);
            }
        }
        if (cmd.year !== undefined && cmd.year !== null) {
            if (!Number.isInteger(cmd.year) || cmd.year < 2000 || cmd.year > 2100) refuse('invalid_year', `year must be a whole number between 2000 and 2100, not ${JSON.stringify(cmd.year)}.`);
            if (cmd.year !== design().year) {
                const from = design().year;
                const { dropped } = store.changeYear(cmd.year);
                notes.push(`The year changed from ${from} to ${cmd.year}: every dated content item moved with it and manual moon edits were cleared.`);
                if (dropped > 0) notes.push(`${dropped} item(s) dated 29 February were dropped — ${cmd.year} has no such day.`);
            }
        }
        if (cmd.print !== undefined && cmd.print !== null) {
            if (!isObject(cmd.print)) refuse('invalid_print', 'print must be an object {bleed?, bleedMm?, marks?}.');
            for (const key of Object.keys(cmd.print)) {
                if (!['bleed', 'bleedMm', 'marks'].includes(key)) refuse('invalid_print', `print.${key} is not a print setting. Settings: bleed (bool), bleedMm (0–10), marks (bool).`);
            }
            for (const key of ['bleed', 'marks']) {
                if (cmd.print[key] !== undefined && typeof cmd.print[key] !== 'boolean') refuse('invalid_print', `print.${key} must be true or false.`);
            }
            if (cmd.print.bleedMm !== undefined && (typeof cmd.print.bleedMm !== 'number' || !Number.isFinite(cmd.print.bleedMm) || cmd.print.bleedMm < 0 || cmd.print.bleedMm > 10)) {
                refuse('invalid_print', 'print.bleedMm must be a number from 0 to 10 (mm per side).');
            }
            // the switch first: switching the bleed ON resets its width to the default
            if (cmd.print.bleed !== undefined) store.setPrint({ bleed: cmd.print.bleed });
            const rest = {};
            if (cmd.print.bleedMm !== undefined) rest.bleedMm = cmd.print.bleedMm;
            if (cmd.print.marks !== undefined) rest.marks = cmd.print.marks;
            if (Object.keys(rest).length) store.setPrint(rest);
            if (cmd.print.bleedMm !== undefined && design().print.bleedMm !== cmd.print.bleedMm) notes.push(`print.bleedMm: ${cmd.print.bleedMm} → ${design().print.bleedMm} (0.5 mm steps).`);
        }
        if (cmd.features !== undefined && cmd.features !== null) {
            if (!isObject(cmd.features)) refuse('unknown_feature', 'features must be an object of switches, e.g. {"events": true, "waste": true}.');
            const known = Object.keys(design().features);
            for (const [key, on] of Object.entries(cmd.features)) {
                if (!known.includes(key)) refuse('unknown_feature', `"${key}" is not a feature switch. Switches: ${known.join(', ')}.`);
                if (typeof on !== 'boolean') refuse('unknown_feature', `features.${key} must be true or false.`);
            }
            for (const [key, on] of Object.entries(cmd.features)) store.setFeature(key, on);
        }
        if (cmd.name !== undefined && cmd.name !== null) {
            const name = typeof cmd.name === 'string' ? cmd.name.replace(/\s+/g, ' ').trim() : '';
            if (name === '') refuse('invalid_name', 'name must be a non-empty text.');
            if ([...name].length > 60) refuse('invalid_name', 'name is too long — at most 60 characters.');
            store.setDesign({ name });
        }
        if (cmd.linkElements !== undefined && cmd.linkElements !== null) {
            if (typeof cmd.linkElements !== 'boolean') refuse('invalid_design', 'linkElements must be true or false.');
            store.setDesign({ linkElements: cmd.linkElements });
        }
    }

    // ---- preset ---------------------------------------------------------------------------

    function preset(cmd) {
        const allowed = presetsForType(design().type);
        if (typeof cmd.preset !== 'string' || !['photoLeft', 'photoRight', 'twoSided'].includes(cmd.preset)) {
            refuse('unknown_preset', `preset must be one of ${quote(allowed)} for this ${design().type} calendar, not ${JSON.stringify(cmd.preset)}.`);
        }
        if (!allowed.includes(cmd.preset)) {
            refuse('preset_type_mismatch', `The preset "${cmd.preset}" does not fit a ${design().type} calendar — it takes ${quote(allowed)}. A 7-day calendar ("week") has photoLeft / photoRight, a 14-day one ("fortnight") only twoSided; change the type first (manage_calendar "configure").`);
        }
        const d = design();
        if (d.layout && cmd.reset === true) {
            // back to the untouched default: the default of the current preset, then (7 days) the mirror
            store.replaceLayout(defaultLayout(d.preset || allowed[0], d.page, bleedOf(d.print), env), {});
            notes.push('The layout was reset to the preset\'s default — every style and position change is gone.');
        } else if (d.layout && d.preset === cmd.preset) {
            notes.push(`The calendar already has the "${cmd.preset}" layout — nothing changed. Pass reset: true to throw the current styling away and start from the default.`);
        } else if (d.layout) {
            notes.push(`The existing layout was mirrored to "${cmd.preset}" — styles and custom positions are kept, as on the web. Pass reset: true for the untouched default instead.`);
        }
        store.choosePreset(cmd.preset);
    }

    // ---- layout patch ---------------------------------------------------------------------

    function leaf(path, key, value, current, fallback) {
        if (key === 'family') {
            if (value === null) return null;
            if (typeof value !== 'string' || !families.includes(value)) {
                refuse('unknown_font', `styles.${path}: "${value}" is not a font face of this project. Use one of get_calendar fonts[].family exactly (${families.length ? quote(families.slice(0, 12)) + (families.length > 12 ? ', …' : '') : 'the project has no fonts — only null, the built-in face, is available'}), or null.`);
            }
            return value;
        }
        if (/color$/i.test(key)) {
            if (value === null) {
                if (fallback !== null && fallback !== undefined) refuse('invalid_color', `styles.${path} cannot be null — give a colour as "#RRGGBB".`);
                return null;
            }
            if (typeof value !== 'string' || !COLOR.test(value)) refuse('invalid_color', `styles.${path} must be a colour written "#RRGGBB" (six hex digits), not ${JSON.stringify(value)}.`);
            return value.toUpperCase();
        }
        if (value === null) {
            if (fallback !== null && fallback !== undefined) refuse('invalid_style_value', `styles.${path} cannot be null (it is a ${Array.isArray(fallback) ? 'list' : typeof fallback}).`);
            return null;
        }
        if (ENUMS[key] && typeof current !== 'boolean' && typeof current !== 'number' && !isObject(current)) {
            if (!ENUMS[key].includes(value)) refuse('invalid_style_value', `styles.${path} must be one of ${quote(ENUMS[key])}${fallback === null ? ' or null (automatic)' : ''}, not ${JSON.stringify(value)}.`);
            return value;
        }
        const expected = fallback !== null && fallback !== undefined ? fallback : current;
        if (expected !== null && expected !== undefined) {
            const want = Array.isArray(expected) ? 'list' : typeof expected;
            const got = Array.isArray(value) ? 'list' : typeof value;
            if (want !== got) refuse('invalid_style_value', `styles.${path} must be a ${want === 'object' ? 'JSON object' : want}, not a ${got === 'object' ? 'JSON object' : got}.`);
        }
        if (typeof value === 'number' && !Number.isFinite(value)) refuse('invalid_style_value', `styles.${path} must be a finite number.`);
        if (key === 'order' && path === 'icons.order') {
            for (const category of value) {
                if (!ICON_CATEGORIES.includes(category)) refuse('invalid_style_value', `styles.icons.order may hold only ${quote(ICON_CATEGORIES)}, not ${JSON.stringify(category)}.`);
            }
            if (new Set(value).size !== value.length) refuse('invalid_style_value', 'styles.icons.order must not repeat a category.');
        }
        return checkNested(path, clone(value));
    }

    /**
     * A value that is stored whole (a list of gradient stops, an open-map entry): its colours and
     * fonts are checked like any other, and the checked copy (colours upper case) is returned.
     */
    function checkNested(path, value) {
        if (Array.isArray(value)) return value.map((member, index) => checkNested(`${path}[${index}]`, member));
        if (!isObject(value)) return value;
        const out = {};
        for (const [key, member] of Object.entries(value)) {
            if (key === 'family' || /color$/i.test(key)) out[key] = member === null ? null : leaf(`${path}.${key}`, key, member, null, null);
            else out[key] = checkNested(`${path}.${key}`, member);
        }
        return out;
    }

    // A JSON object that travelled through PHP arrives as a LIST when it was empty or keyed 0…n
    // (`iconCells["4"] = {"0": …}`): read such a list as the map it was.
    const asMap = (value) => (Array.isArray(value) ? Object.fromEntries(value.entries()) : value);

    function mergeStyles(current, patch, defaults, path) {
        if (Array.isArray(patch) && (patch.length === 0 || OPEN_MAPS.includes(path))) patch = asMap(patch);
        if (!isObject(patch)) refuse('invalid_style_value', `${path ? `styles.${path}` : 'styles'} must be a JSON object.`);
        const open = OPEN_MAPS.includes(path);
        const out = { ...current };
        for (const [key, value] of Object.entries(patch)) {
            const next = path ? `${path}.${key}` : key;
            if (open) {
                // a data-keyed map: null removes the entry, anything else replaces / merges it
                if (value === null) {
                    delete out[key];
                    continue;
                }
                const entry = asMap(value);
                if (!isObject(entry)) refuse('invalid_style_value', `styles.${next} must be a JSON object or null (to remove it).`);
                const checked = checkNested(next, clone(entry));
                out[key] = isObject(out[key]) ? { ...out[key], ...checked } : checked;
                continue;
            }
            if (!Object.prototype.hasOwnProperty.call(current, key)) {
                refuse('unknown_style_key', `styles.${next} is not a style key. Keys of ${path ? `styles.${path}` : 'styles'}: ${Object.keys(current).join(', ')}. (get_calendar layout.styles shows the whole tree.)`);
            }
            const cur = current[key];
            const fallback = isObject(defaults) ? defaults[key] : undefined;
            if (isObject(cur) && Array.isArray(value) && (value.length === 0 || OPEN_MAPS.includes(next))) {
                out[key] = mergeStyles(cur, asMap(value), isObject(fallback) ? fallback : cur, next);
            } else if (isObject(cur) && isObject(value)) {
                out[key] = mergeStyles(cur, value, isObject(fallback) ? fallback : cur, next);
            } else if (isObject(cur) && value !== null) {
                refuse('invalid_style_value', `styles.${next} is a group of settings — send a JSON object with the keys to change (${Object.keys(cur).join(', ')}).`);
            } else if (isObject(cur) && value === null && fallback !== null) {
                refuse('invalid_style_value', `styles.${next} is a group of settings and cannot be null.`);
            } else {
                out[key] = leaf(next, key, value, cur, fallback);
            }
        }
        return out;
    }

    function mergeBoxes(current, patch) {
        if (Array.isArray(patch) && patch.length === 0) return current;
        if (!isObject(patch)) refuse('invalid_box', 'boxes must be an object {"<element>": {x?, y?, w?, h?, hidden?}}.');
        const out = { ...current };
        for (const [key, value] of Object.entries(patch)) {
            if (!Object.prototype.hasOwnProperty.call(current, key)) {
                refuse('unknown_box', `"${key}" is not an element box of this calendar. Boxes: ${Object.keys(current).join(', ')}.`);
            }
            if (!isObject(value)) refuse('invalid_box', `boxes.${key} must be an object {x?, y?, w?, h?, hidden?}.`);
            const next = { ...current[key] };
            for (const [field, v] of Object.entries(value)) {
                if (field === 'hidden') {
                    if (typeof v !== 'boolean') refuse('invalid_box', `boxes.${key}.hidden must be true or false.`);
                    if (!HIDEABLE_BOXES.includes(key)) {
                        refuse('not_hideable', key === 'cap'
                            ? 'The caption is shown or hidden by the feature switch: manage_calendar "configure" with features {"captions": false}.'
                            : `The element "${key}" cannot be hidden. Only ${HIDEABLE_BOXES.join(', ')} take hidden${HIDEABLE_BOXES.includes(elementOfBox(key)) ? ` — hide "${elementOfBox(key)}", its second frame follows` : ''}.`);
                    }
                    if (v) next.hidden = true;
                    else delete next.hidden;
                    continue;
                }
                if (!['x', 'y', 'w', 'h'].includes(field)) refuse('invalid_box', `boxes.${key}.${field} is not a box field. Fields: x, y, w, h (mm), hidden.`);
                if (typeof v !== 'number' || !Number.isFinite(v)) refuse('invalid_box', `boxes.${key}.${field} must be a number (mm).`);
                next[field] = v;
            }
            if (next.w < K.MIN_W) {
                notes.push(`boxes.${key}.w: ${next.w} → ${K.MIN_W} (the minimum width).`);
                next.w = K.MIN_W;
            }
            if (next.h < K.MIN_H) {
                notes.push(`boxes.${key}.h: ${next.h} → ${K.MIN_H} (the minimum height).`);
                next.h = K.MIN_H;
            }
            out[key] = roundBox(next);
        }
        return out;
    }

    function noteDifferences(requested, actual, path) {
        if (isObject(requested)) {
            for (const [key, value] of Object.entries(requested)) {
                if (value === null || !isObject(actual)) continue;
                noteDifferences(value, actual[key], path ? `${path}.${key}` : key);
            }
            return;
        }
        if (typeof requested === 'number' && typeof actual === 'number' && Math.abs(requested - actual) > 1e-9) {
            notes.push(`${path}: ${requested} → ${actual} (brought into the allowed range / step).`);
        }
    }

    function layout(cmd) {
        const d = design();
        if (!d.layout) refuse('no_layout', 'The calendar has no layout yet. Choose one first: set_calendar_layout with preset "photoLeft", "photoRight" (7 days) or "twoSided" (14 days).');
        let boxes = d.layout.boxes;
        let order = d.layout.order;
        let styles = d.layout.styles;
        if (cmd.boxes !== undefined && cmd.boxes !== null) boxes = mergeBoxes(boxes, cmd.boxes);
        if (cmd.order !== undefined && cmd.order !== null) {
            const valid = Array.isArray(cmd.order)
                && cmd.order.length === DEFAULT_ORDER.length
                && new Set(cmd.order).size === cmd.order.length
                && cmd.order.every((key) => DEFAULT_ORDER.includes(key));
            if (!valid) refuse('invalid_order', `order must list every element exactly once, top layer first: ${DEFAULT_ORDER.join(', ')} (the background is always at the bottom and is not listed).`);
            order = cmd.order.slice();
        }
        if (cmd.styles !== undefined && cmd.styles !== null) styles = mergeStyles(styles, cmd.styles, defaultStyles(env), '');
        const bleed = bleedOf(d.print);
        store.change(() => {
            const normalised = normalizeLayout({ v: 1, boxes, order, styles }, d.type, d.page, env);
            return { ...normalised, boxes: clampBoxesToBounds(normalised.boxes, d.page, bleed) };
        });
        const stored = design().layout;
        if (isObject(cmd.styles)) noteDifferences(cmd.styles, stored.styles, 'styles');
        if (isObject(cmd.boxes)) {
            for (const [key, value] of Object.entries(cmd.boxes)) {
                for (const field of ['x', 'y', 'w', 'h']) {
                    if (typeof value[field] !== 'number') continue;
                    const wanted = roundMm(Math.max(value[field], field === 'w' ? K.MIN_W : field === 'h' ? K.MIN_H : -Infinity));
                    if (Math.abs(wanted - stored.boxes[key][field]) > 1e-9) {
                        notes.push(`boxes.${key}.${field}: ${value[field]} → ${stored.boxes[key][field]} (kept inside the page${bleed ? ' + bleed' : ''}, or grown to hold its text).`);
                    }
                }
            }
        }
    }

    function replaceLayout(cmd) {
        if (!isObject(cmd.layout)) refuse('invalid_design', 'There is no layout to apply.');
        store.replaceLayout(cmd.layout, isObject(cmd.assets) ? cmd.assets : {});
        if (design().step < 3) store.setStep(3);
    }

    function step(cmd) {
        if (!design().layout && cmd.step >= 3) refuse('no_layout', 'The calendar has no layout yet. Choose one first: set_calendar_layout with a preset.');
        store.setStep(cmd.step);
    }

    const commands = Array.isArray(input.commands) ? input.commands : [];
    for (const cmd of commands) {
        if (cmd.op === 'configure') configure(cmd);
        else if (cmd.op === 'preset') preset(cmd);
        else if (cmd.op === 'layout') layout(cmd);
        else if (cmd.op === 'replace_layout') replaceLayout(cmd);
        else if (cmd.op === 'step') step(cmd);
        else refuse('model_error', `Unknown model command "${cmd.op}".`);
    }

    const state = store.getState();
    const { payload } = buildPayload(state, {});
    const doc = state.doc;
    const out = {
        ok: true,
        payload: Object.keys(payload).length ? payload : null,
        notes,
        design: doc.design,
    };

    const pages = input.pages || input.warnings ? buildPages(doc) : null;
    if (input.pages) {
        out.pages = pages.map((page) => {
            const row = { index: page.index, key: page.key, kind: page.kind, title: page.title, subtitle: page.subtitle || '' };
            if (page.kind === 'week') {
                row.from = page.week.days[0];
                row.to = page.week.days[page.week.days.length - 1];
            }
            return row;
        });
    }
    if (input.warnings) {
        out.warnings = [];
        const opts = { env: { assets: env.assets, fonts: env.fonts }, measurer };
        for (const [index, list] of allWarnings(doc, pages, opts)) {
            for (const warning of list) out.warnings.push({ page: pages[index].key, kind: warning.kind, message: warning.message });
        }
    }
    if (isObject(input.view)) {
        out.view = pageViewBox(doc.design, { bleed: input.view.bleed === true, marks: input.view.marks === true });
    }
    return out;
}

let result;
try {
    result = await main(JSON.parse(readFileSync(0, 'utf8')));
} catch (error) {
    result = error instanceof Refusal
        ? { ok: false, code: error.code, message: error.message }
        : { ok: false, code: 'model_error', message: String((error && error.stack) || error) };
}
process.stdout.write(JSON.stringify(result));
