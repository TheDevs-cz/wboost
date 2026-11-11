// ISO date arithmetic (UTC based — never local time). Months are 1-based, weekday 0 = Monday … 6 = Sunday.

const DAY_MS = 86400000;
const pad2 = (n) => String(n).padStart(2, '0');

export function iso(y, m, d) {
    return `${String(y).padStart(4, '0')}-${pad2(m)}-${pad2(d)}`;
}

export function parts(isoDate) {
    return { y: Number(isoDate.slice(0, 4)), m: Number(isoDate.slice(5, 7)), d: Number(isoDate.slice(8, 10)) };
}

function toDays(isoDate) {
    const p = parts(isoDate);
    return Math.round(Date.UTC(p.y, p.m - 1, p.d) / DAY_MS);
}

function fromDays(days) {
    const dt = new Date(days * DAY_MS);
    return iso(dt.getUTCFullYear(), dt.getUTCMonth() + 1, dt.getUTCDate());
}

export function daysInMonth(y, m) {
    return new Date(Date.UTC(y, m, 0)).getUTCDate();
}

export function isValid(isoDate) {
    if (typeof isoDate !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(isoDate)) return false;
    const p = parts(isoDate);
    return p.m >= 1 && p.m <= 12 && p.d >= 1 && p.d <= daysInMonth(p.y, p.m);
}

export function addDays(isoDate, n) {
    return fromDays(toDays(isoDate) + n);
}

/** Days from `a` to `b` (positive when `b` is later). */
export function diffDays(a, b) {
    return toDays(b) - toDays(a);
}

export function weekday(isoDate) {
    // 1970-01-01 was a Thursday (3)
    return (((toDays(isoDate) + 3) % 7) + 7) % 7;
}

export function mondayOf(isoDate) {
    return addDays(isoDate, -weekday(isoDate));
}

export function isoWeek(isoDate) {
    const thursday = addDays(mondayOf(isoDate), 3);
    const year = parts(thursday).y;
    const firstMonday = mondayOf(iso(year, 1, 4));
    return { year, week: 1 + Math.round(diffDays(firstMonday, mondayOf(isoDate)) / 7) };
}

/** Easter Sunday (Meeus / Jones / Butcher), Gregorian. */
export function easterSunday(year) {
    const a = year % 19;
    const b = Math.floor(year / 100);
    const c = year % 100;
    const d = Math.floor(b / 4);
    const e = b % 4;
    const f = Math.floor((b + 8) / 25);
    const g = Math.floor((b - f + 1) / 3);
    const h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4);
    const k = c % 4;
    const l = (32 + 2 * e + 2 * i - h - k) % 7;
    const m = Math.floor((a + 11 * h + 22 * l) / 451);
    const month = Math.floor((h + l - 7 * m + 114) / 31);
    const day = ((h + l - 7 * m + 114) % 31) + 1;
    return iso(year, month, day);
}

export function compare(a, b) {
    return a < b ? -1 : a > b ? 1 : 0;
}
