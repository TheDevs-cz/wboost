<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One gallery picture, as the compiler needs it: the three facts a Fabric image
 * object cannot be emitted without.
 *
 * - {@see $url} becomes the object's `src` — the public URL
 *   {@see \WBoost\Web\Services\UploaderHelper::getPublicPath()} produced, the
 *   same string the admin editor writes when a designer picks an image;
 * - {@see $path} becomes `assetPath`, without which
 *   {@see \WBoost\Web\Services\SocialNetwork\AssetInliner} cannot inline the
 *   picture and headless Chromium — which has no route to Minio — paints
 *   nothing (plan §4.2 invariant 9);
 * - {@see $width} / {@see $height} are the picture's NATURAL pixel size, which
 *   is what Fabric's `width`/`height` mean on an image object (the displayed
 *   size is that times `scaleX`/`scaleY`). Without them neither the cover fit
 *   of a background nor the contain fit of a decorative image can be computed.
 *
 * Both dimensions are nullable together: an SVG has no raster size and a file
 * whose header will not decode has no honest one. Callers that need a number
 * fall back the way {@see \WBoost\Web\Services\Editor\BackgroundLayer::buildObject()}
 * does — treat the frame itself as the natural size and scale by 1 — rather
 * than guessing a ratio.
 *
 * `#[Exclude]`: a value, never a service (the `src/Mcp/Design/` directory is
 * loaded into the container).
 */
#[Exclude]
readonly final class DesignAsset
{
    public function __construct(
        /** `FileUpload` UUID — the id the DSL, the export API and `list_gallery` all speak. */
        public string $id,
        /** Storage path inside the Minio bucket. */
        public string $path,
        /** Public URL of that path. */
        public string $url,
        /** Natural pixel width, or null when the bytes declare none (SVG, unreadable). */
        public null|int $width,
        /** Natural pixel height; null exactly when {@see $width} is. */
        public null|int $height,
    ) {
    }

    /**
     * Is there a real raster size to scale against?
     *
     * Guards the divisions in the compiler's fit math — a zero or absent
     * dimension must never reach a denominator.
     */
    public function hasNaturalSize(): bool
    {
        return $this->width !== null && $this->height !== null
            && $this->width > 0 && $this->height > 0;
    }
}
