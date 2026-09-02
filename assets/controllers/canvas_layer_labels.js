/**
 * Row labels for the layers panel ("Vrstvy"), computed for a WHOLE canvas
 * stack at once.
 *
 * A named object shows its name. An unnamed one falls back to a TYPE label
 * that is NUMBERED per type in stack order — bottom of the stack = 1 — so
 * two unnamed images read "Obrázek 1" / "Obrázek 2" instead of two identical
 * "Obrázek" rows (which was the whole complaint: a canvas with a handful of
 * decorative pictures was an undifferentiated list). The number is derived at
 * display time and NEVER persisted: a blank name stays blank in the canvas
 * JSON, so nothing leaks into `imageInputs[].name`, the fill page tags or the
 * API. Numbering bottom-up means a newly added object (Fabric appends on top)
 * takes the next free number and leaves the existing ones alone; deleting or
 * restacking below an object does renumber it — the trade-off for keeping the
 * canvas document untouched.
 *
 * Pure and dependency-free on purpose (no Fabric, no Stimulus) so the
 * numbering can be exercised under plain node.
 *
 * @param {Array<{name?: string|null, fallback: string, numbered?: boolean, qualifier?: string|null}>} entries
 *   One entry per object IN STACK ORDER (index 0 first).
 *   - `name`: the object's own name; a non-blank one wins outright.
 *   - `fallback`: the type label used when the name is blank.
 *   - `numbered`: whether the fallback takes a per-fallback ordinal. Off for
 *     labels that are unique by construction (the single "Pozadí") or already
 *     specific (a text's first line).
 *   - `qualifier`: optional suffix placed AFTER the number ("Obrázek 2
 *     (placeholder)"); the counter is keyed on the bare fallback, so plain
 *     images and placeholders share one "Obrázek" sequence.
 * @returns {string[]} labels, index-aligned with `entries`.
 */
export function labelLayers(entries) {
    const counters = new Map();

    return entries.map((entry) => {
        const name = (entry.name || '').trim();
        if (name !== '') return name;

        const fallback = entry.fallback;
        if (!entry.numbered) return fallback;

        const ordinal = (counters.get(fallback) || 0) + 1;
        counters.set(fallback, ordinal);

        const qualifier = (entry.qualifier || '').trim();

        return qualifier !== '' ? `${fallback} ${ordinal} ${qualifier}` : `${fallback} ${ordinal}`;
    });
}
