/**
 * JS twin of AssetInliner::ensureSvgIntrinsicSize() (PHP): design-tool SVGs
 * (Illustrator, Figma, …) often carry only a `viewBox`, no width/height. Such
 * an <img> has no definite intrinsic size, and what the browser makes of it
 * varies (headless Chrome: ~150 × 150; the editor stored the viewBox size).
 * Fabric then draws with a source rectangle in one size and a bitmap in the
 * other: on the fill page a stand-in QR code filled 42 % of its frame.
 *
 * Injecting width/height from the viewBox makes the intrinsic size the
 * viewBox — what the server render does before Gotenberg sees the file. SVGs
 * that already declare a width or height, or have no usable viewBox, are
 * returned unchanged.
 */
export function ensureSvgIntrinsicSize(svg) {
    return svg.replace(/<svg\b[^>]*>/i, (tag) => {
        if (/\swidth\s*=/i.test(tag) || /\sheight\s*=/i.test(tag)) return tag;

        const viewBox = tag.match(/\sviewBox\s*=\s*(["'])([^"']*)\1/i);
        if (!viewBox) return tag;

        const parts = viewBox[2].trim().split(/[\s,]+/);
        if (parts.length !== 4) return tag;

        const width = Number(parts[2]);
        const height = Number(parts[3]);
        if (!(width > 0) || !(height > 0)) return tag;

        return tag.replace(/^<svg\b/i, `<svg width="${parts[2]}" height="${parts[3]}"`);
    });
}

/** Whether a URL names an SVG file (by extension; query / hash ignored). */
export function isSvgUrl(url) {
    return typeof url === "string" && /\.svg(?:[?#]|$)/i.test(url);
}

/**
 * The URL to load an image from so its intrinsic size is deterministic: an
 * SVG is fetched and handed back as a data URI with width/height pinned;
 * anything else (and an SVG whose fetch fails) is the URL itself.
 */
export async function intrinsicSizedImageUrl(url) {
    if (!isSvgUrl(url)) return url;
    try {
        const response = await fetch(url, { mode: "cors", credentials: "omit" });
        if (!response.ok) return url;
        const text = await response.text();
        return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(ensureSvgIntrinsicSize(text))}`;
    } catch (error) {
        return url;
    }
}
