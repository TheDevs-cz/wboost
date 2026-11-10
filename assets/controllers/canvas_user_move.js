/**
 * "Uživatel může posunout" — editor-side helpers shared by the element
 * popovers (canvas_user_move_controller.js) and the container ⚙ popover
 * (canvas_container_controller.js).
 *
 * The allowance itself (canonical shape, per-direction bounds, group scaling)
 * is the classic script assets/editor/element_move.js (`window.WBoostElementMove`,
 * PHP twin ElementMoveAllowance). This module only adds the editor's unit
 * handling: values are TYPED and SHOWN in the variant's unit (px, or mm / cm
 * at 300 DPI — the image geometry fields' convention) and STORED as canvas px.
 */
import { pxPerUnit, roundTo, unitDecimals } from './image_placement.js';

export const DIRECTIONS = ['maxUp', 'maxDown', 'maxLeft', 'maxRight'];
export const AXIS_OF = { maxUp: 'vertical', maxDown: 'vertical', maxLeft: 'horizontal', maxRight: 'horizontal' };

function core() {
    return window.WBoostElementMove || null;
}

/** Canonical allowance or null (= fixed). */
export function normalizeAllowance(raw) {
    const c = core();
    return c ? c.normalize(raw) : null;
}

export function normalizeUnit(unit) {
    return unit === 'mm' || unit === 'cm' ? unit : 'px';
}

/** Stored canvas px → the field's display string ('' = unlimited). */
export function displayMax(px, unit) {
    if (typeof px !== 'number' || !isFinite(px)) return '';
    // px keep their 1/100 (a typed 25,5 px must read back as 25.5, not 26);
    // mm / cm use the geometry fields' 1 / 2 decimals.
    return String(roundTo(px / pxPerUnit(unit), unit === 'px' ? 2 : unitDecimals(unit)));
}

/**
 * A typed field → canvas px. `null` = empty (unlimited), `undefined` = not a
 * number / negative (the caller refuses it). Czech decimal comma accepted.
 */
export function parseMax(raw, unit) {
    const text = String(raw ?? '').trim();
    if (text === '') return null;
    const value = parseFloat(text.replace(',', '.'));
    if (!Number.isFinite(value) || value < 0) return undefined;
    const c = core();
    const px = value * pxPerUnit(unit);
    return c ? c.round2(px) : Math.round(px * 100) / 100;
}

/** "15 mm" / "∞" — a band cap / hint label for one direction. */
export function formatLimit(px, unit) {
    if (px === null || px === undefined) return '∞';
    return `${displayMax(px, unit).replace('.', ',')} ${unit}`;
}

/**
 * Next allowance after switching an axis. Switching ON takes the maxima the
 * designer left in the (disabled) fields, so an accidental off/on keeps them.
 */
export function withAxis(current, axis, on, fieldMaxima = {}) {
    const next = {
        vertical: false, horizontal: false,
        maxUp: null, maxDown: null, maxLeft: null, maxRight: null,
        ...(normalizeAllowance(current) || {}),
    };
    next[axis] = on;
    if (on) {
        DIRECTIONS.filter((dir) => AXIS_OF[dir] === axis).forEach((dir) => {
            if (fieldMaxima[dir] !== undefined) next[dir] = fieldMaxima[dir];
        });
    }
    return normalizeAllowance(next);
}

/** Next allowance after a typed maximum (px or null). */
export function withMax(current, dir, px) {
    const allowance = normalizeAllowance(current);
    if (!allowance) return null;
    return normalizeAllowance({ ...allowance, [dir]: px });
}

/**
 * The range band: the element's designed bounding box swept by the allowed
 * offsets — {left, top, right, bottom} in canvas px, plus the bounds.
 */
export function bandRect(allowance, frame, canvasWidth, canvasHeight) {
    const c = core();
    if (!c || !allowance) return null;
    const b = c.bounds(allowance, frame, canvasWidth, canvasHeight);
    return {
        left: frame.left + b.minX,
        top: frame.top + b.minY,
        right: frame.left + frame.width + b.maxX,
        bottom: frame.top + frame.height + b.maxY,
        bounds: b,
    };
}

/**
 * The ⚙ popover block for a TOP-LEVEL container ("Uživatel může posunout
 * celý kontejner") — the same fields as the element popovers, built in DOM
 * because the container popover itself is.
 *
 * @param {Object} container the live definition (read for the initial state)
 * @param {Object} options
 * @param {string} options.unit       the variant's unit
 * @param {string} options.idPrefix   unique id prefix for label/for pairs
 * @param {Function} options.onChange (nextAllowance|null) => void — commit
 */
export function buildContainerMoveSection(container, { unit, idPrefix, onChange }) {
    unit = normalizeUnit(unit);
    const wrap = document.createElement('div');
    wrap.className = 'ed-umove ed-umove--container';

    const heading = document.createElement('div');
    heading.className = 'form-label small mb-1 fw-semibold';
    heading.textContent = 'Uživatel může posunout celý kontejner';
    wrap.appendChild(heading);

    const fields = {};
    const toggles = {};
    const limitRows = {};

    const sync = () => {
        const allowance = normalizeAllowance(container.userMove);
        ['vertical', 'horizontal'].forEach((axis) => {
            const on = Boolean(allowance && allowance[axis]);
            toggles[axis].checked = on;
            limitRows[axis].classList.toggle('is-off', !on);
        });
        DIRECTIONS.forEach((dir) => {
            const input = fields[dir];
            input.disabled = !(allowance && allowance[AXIS_OF[dir]]);
            input.classList.remove('is-invalid');
            if (document.activeElement === input) return;
            if (allowance && allowance[AXIS_OF[dir]]) input.value = displayMax(allowance[dir], unit);
        });
    };

    const fieldMaxima = (axis) => {
        const out = {};
        DIRECTIONS.filter((dir) => AXIS_OF[dir] === axis).forEach((dir) => {
            const px = parseMax(fields[dir].value, unit);
            if (px !== undefined) out[dir] = px;
        });
        return out;
    };

    const axisRow = (axis, label, dirs) => {
        const row = document.createElement('div');
        row.className = 'ed-umove__axis';
        const check = document.createElement('div');
        check.className = 'form-check form-switch mb-0';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.setAttribute('role', 'switch');
        input.className = 'form-check-input';
        input.id = `${idPrefix}-${axis}`;
        input.dataset.axis = axis;
        const lab = document.createElement('label');
        lab.className = 'form-check-label small';
        lab.htmlFor = input.id;
        lab.textContent = label;
        check.appendChild(input);
        check.appendChild(lab);
        row.appendChild(check);
        toggles[axis] = input;

        const limits = document.createElement('div');
        limits.className = 'ed-umove__limits';
        dirs.forEach(([dir, arrow, aria]) => {
            const field = document.createElement('label');
            field.className = 'ed-geo__field';
            const key = document.createElement('span');
            key.className = 'ed-geo__key';
            key.setAttribute('aria-hidden', 'true');
            key.textContent = arrow;
            const text = document.createElement('input');
            text.type = 'text';
            text.inputMode = 'decimal';
            text.className = 'form-control form-control-sm';
            text.placeholder = 'kamkoli';
            text.setAttribute('aria-label', aria);
            text.dataset.dir = dir;
            const unitEl = document.createElement('span');
            unitEl.className = 'ed-geo__unit';
            unitEl.textContent = unit;
            field.appendChild(key);
            field.appendChild(text);
            field.appendChild(unitEl);
            limits.appendChild(field);
            fields[dir] = text;

            text.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    text.blur();
                }
            });
            text.addEventListener('change', () => {
                const px = parseMax(text.value, unit);
                if (px === undefined) {
                    text.classList.add('is-invalid');
                    return;
                }
                container.userMove = withMax(container.userMove, dir, px) || undefined;
                onChange(container.userMove || null);
                sync();
            });
        });
        row.appendChild(limits);
        limitRows[axis] = limits;

        input.addEventListener('change', () => {
            container.userMove = withAxis(container.userMove, axis, input.checked, fieldMaxima(axis)) || undefined;
            onChange(container.userMove || null);
            sync();
        });
        return row;
    };

    wrap.appendChild(axisRow('vertical', '↕ Svisle', [['maxUp', '↑', 'Maximální posun nahoru'], ['maxDown', '↓', 'Maximální posun dolů']]));
    wrap.appendChild(axisRow('horizontal', '↔ Vodorovně', [['maxLeft', '←', 'Maximální posun doleva'], ['maxRight', '→', 'Maximální posun doprava']]));

    sync();
    return wrap;
}
