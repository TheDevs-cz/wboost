<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Output format for a server-side Gotenberg render of a template variant.
 *
 * Deliberately NOT the same enum as {@see ImageFormat}: that one is bound to the
 * `/stahnout-logo/{manualId}/{logo}.{format}` route and carries SVG, so adding a
 * case there would immediately expose a public URL for a format that controller
 * cannot actually produce.
 *
 * **No JPEG case, by construction.** The renderer calls `omitBackground()` for
 * `BackgroundMode::Layer` variants and for background-less {@see CanvasSlice}
 * overlays, so transparency is a hard requirement on those paths and JPEG has no
 * alpha channel. Keeping JPEG unrepresentable makes that a type guarantee rather
 * than a comment someone can miss.
 *
 * **WebP is lossy and NOT tunable.** Gotenberg ignores the `quality` form field
 * for WebP — measured 2026-08-05, `quality=50` / `quality=85` / unset all return
 * byte-identical output; the gotenberg-bundle's own docblock says quality is
 * "jpeg only". So WebP means "Chromium's default lossy encode" (4:2:0 chroma
 * subsampling) and there is no knob to trade size for fidelity. That is fine for
 * on-screen previews and is why every EXPORT path stays {@see self::Png}.
 */
enum RenderImageFormat: string
{
    case Png = 'png';
    case Webp = 'webp';

    public function contentType(): string
    {
        return match ($this) {
            self::Png => 'image/png',
            self::Webp => 'image/webp',
        };
    }

    /**
     * Prefix for an inline `data:` URI, so the declared mime can never drift
     * from the bytes it is wrapping.
     */
    public function dataUriPrefix(): string
    {
        return sprintf('data:%s;base64,', $this->contentType());
    }
}
