// The image rule (architecture §4.5, provider limits E27): every image given to the model is
// re-encoded, long edge ≤ 2576 px and ≤ 3 MB. The provider would otherwise resize silently and the
// model's pixels would stop being the scanner's pixels.
import sharp from 'sharp';

export const DEFAULT_IMAGE_RULE = Object.freeze({ longEdgePx: 2576, maxBytes: 3_000_000 });

const isRaster = (v) => v && typeof v === 'object' && !Buffer.isBuffer(v) && v.data && v.width && v.height;

/**
 * → {data: Buffer, mimeType, width, height, scale} where `scale` = shown px ÷ original px (1 when not resized).
 * Encoding ladder: PNG → lossless WebP → WebP q92 … q60 → smaller picture, until it fits `maxBytes`.
 */
export async function prepareImage(input, rule = DEFAULT_IMAGE_RULE) {
    const open = () => (isRaster(input)
        ? sharp(input.data, { raw: { width: input.width, height: input.height, channels: input.channels ?? 3 } })
        : sharp(input, { limitInputPixels: 40_000_000 }));
    const meta = isRaster(input) ? { width: input.width, height: input.height } : await open().metadata();
    let scale = Math.min(1, rule.longEdgePx / Math.max(meta.width, meta.height));
    for (let attempt = 0; attempt < 8; attempt++) {
        const w = Math.max(1, Math.round(meta.width * scale)), h = Math.max(1, Math.round(meta.height * scale));
        const base = () => { const s = open().flatten({ background: '#ffffff' }); return scale < 1 ? s.resize(w, h, { fit: 'fill', kernel: 'lanczos3' }) : s; };
        const tries = [
            ['image/png', () => base().png({ compressionLevel: 9 }).toBuffer()],
            ['image/webp', () => base().webp({ lossless: true }).toBuffer()],
            ...[92, 84, 72, 60].map((q) => ['image/webp', () => base().webp({ quality: q }).toBuffer()]),
        ];
        for (const [mimeType, encode] of tries) {
            const data = await encode();
            if (data.length <= rule.maxBytes) return { data, mimeType, width: w, height: h, scale };
        }
        scale *= 0.8;
    }
    throw new Error('image could not be brought under the size limit');
}
