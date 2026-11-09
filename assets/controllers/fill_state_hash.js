/**
 * The single fill page's SETTLE contract, shared by variant_fill_overlay
 * (the render veil) and variant_text_echo (echo vs rest): which element the
 * server stamps a settle render on, and the hash that tells a settle matching
 * the mirrors "as they are NOW" from a stale one that raced the typing.
 *
 * Pure DOM reads, no imports — node-testable (the PHP parity check lives in
 * TemplateVariantExportControllerTest::testFillStateHashIsStableAndByteDomainCorrect).
 */

/**
 * djb2 over the canonical UTF-8 fill state of `root` (the fill form): one
 * `T:<id>=<text>` line per text mirror, then `H:<id>=<0|1>` per hide mirror,
 * then `F:<id>=<family>` per font mirror, then `S:<id>=<colour>` per shape
 * colour mirror, then `R:<rowId>=<count>` per repeatable-row count mirror,
 * then `O:<id>=<x>,<y>` per MOVED element (its two `data-offset-mirror`
 * fields; an unmoved element has no line) — each group sorted by id,
 * joined by "\n". The PHP twin is AbstractVariantFiller::fillStateHash() and
 * the two MUST stay byte-identical: the server stamps its value as
 * `data-state-hash` on the settle source element, and the client compares.
 */
export function fillStateHash(root) {
    const parts = [];

    const collect = (selector, attribute, prefix, read) => {
        const rows = [];
        root.querySelectorAll(selector).forEach((mirror) => {
            rows.push([mirror.getAttribute(attribute), read(mirror)]);
        });
        rows.sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0));
        rows.forEach(([id, value]) => parts.push(`${prefix}:${id}=${value}`));
    };

    collect("[data-text-mirror]", "data-text-mirror", "T", (mirror) => mirror.value);
    collect("[data-hide-mirror]", "data-hide-mirror", "H", (mirror) => (mirror.checked ? "1" : "0"));
    collect("[data-font-mirror]", "data-font-mirror", "F", (mirror) => mirror.value);
    collect("[data-shape-color-mirror]", "data-shape-color-mirror", "S", (mirror) => mirror.value);
    // Repeatable rows (container-rows P2): mirrors exist only for a design
    // that has rows, so every other state hashes as before. M2's `O:` lines
    // follow — the agreed order is T H F S R O.
    collect("[data-row-count-mirror]", "data-row-count-mirror", "R", (mirror) => mirror.value);

    // User-moved elements ("Uživatel může posunout", M2): two mirrors per
    // movable element (`data-offset-axis` x / y), ONE line per element that
    // is actually moved — every other state hashes as before.
    const offsets = new Map();
    root.querySelectorAll("[data-offset-mirror]").forEach((mirror) => {
        const id = mirror.getAttribute("data-offset-mirror");
        const entry = offsets.get(id) || { x: 0, y: 0 };
        entry[mirror.getAttribute("data-offset-axis") === "y" ? "y" : "x"] = offsetValue(mirror.value);
        offsets.set(id, entry);
    });
    [...offsets.keys()].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0)).forEach((id) => {
        const { x, y } = offsets.get(id);
        if (x !== 0 || y !== 0) parts.push(`O:${id}=${hashNumber(x)},${hashNumber(y)}`);
    });

    const bytes = new TextEncoder().encode(parts.join("\n"));
    let hash = 5381;
    for (let i = 0; i < bytes.length; i += 1) {
        hash = (Math.imul(hash, 33) ^ bytes[i]) >>> 0;
    }

    return String(hash);
}

/** Half-away-from-zero to 1/100 (≡ ElementMoveAllowance::round2, ≡ element_move.js). */
function round2(value) {
    if (typeof value !== "number" || !Number.isFinite(value)) return 0;
    const rounded = Math.floor(Math.abs(value) * 100 + 0.5) / 100;
    if (rounded === 0) return 0;
    return value < 0 ? -rounded : rounded;
}

/** A mirror's raw value as the server reads it: numeric → round2, else 0. */
function offsetValue(raw) {
    const text = String(raw ?? "").trim();
    if (text === "" || !/^[-+]?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?$/.test(text)) return 0;
    return round2(Number(text));
}

/**
 * An offset in the state hash — the PHP twin is
 * AbstractVariantFiller::hashNumber(): two decimals, trailing zeros and a
 * bare "." dropped ("12.5", "-3.33", "40").
 */
export function hashNumber(value) {
    let text = round2(value).toFixed(2);
    if (text.includes(".")) text = text.replace(/0+$/, "").replace(/\.$/, "");
    return text === "-0" || text === "" ? "0" : text;
}

/**
 * The Live-updated element a settle render lands on — `data-src` (the settle
 * bytes), `data-base-src` (the echo base) and `data-state-hash` (the fill
 * state it painted). The text branch renders the `previewSource` span, the
 * image branch the backdrop span; a form has exactly one of them.
 */
export function settleSourceFor(root) {
    return root.querySelector('[data-variant-fill-overlay-target="previewSource"]')
        || root.querySelector("#variant-backdrop-source");
}
