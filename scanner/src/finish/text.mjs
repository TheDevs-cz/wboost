// Text comparison helpers for the finish stage.
export const normalise = (s) => String(s ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');

export function levenshtein(a, b) {
    if (a === b) return 0;
    if (!a.length) return b.length;
    if (!b.length) return a.length;
    let prev = Array.from({ length: b.length + 1 }, (_, i) => i);
    for (let i = 1; i <= a.length; i++) {
        const cur = [i];
        for (let j = 1; j <= b.length; j++) cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
        prev = cur;
    }
    return prev[b.length];
}

/** 0…1: 1 = the same words (case, diacritics, spacing and punctuation ignored). */
export function similarity(a, b) {
    const x = normalise(a), y = normalise(b);
    if (!x.length && !y.length) return 1;
    if (!x.length || !y.length) return 0;
    return 1 - levenshtein(x, y) / Math.max(x.length, y.length);
}

/** Similarity of `line` to the best-matching window of `text` (an OCR line may hold only part of a text line). */
export function containment(line, text) {
    const x = normalise(line), y = normalise(text);
    if (!x.length || !y.length) return 0;
    if (y.includes(x) || x.includes(y)) return Math.min(x.length, y.length) / Math.max(1, Math.min(x.length, y.length));
    return similarity(line, text);
}

export const family = (face) => normalise(String(face ?? '').replace(/\(.*$/, ''));
