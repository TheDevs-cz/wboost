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
 * then `F:<id>=<family>` per font mirror — each group sorted by inputId,
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

    const bytes = new TextEncoder().encode(parts.join("\n"));
    let hash = 5381;
    for (let i = 0; i < bytes.length; i += 1) {
        hash = (Math.imul(hash, 33) ^ bytes[i]) >>> 0;
    }

    return String(hash);
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
