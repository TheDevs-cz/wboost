<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

/**
 * Store an already-rendered thumbnail for a variant and point the row at it.
 *
 * ## Why this is a message of its own
 *
 * `EditTemplateVariantCanvasEditor` takes the thumbnail as a
 * `previewImageDataUri` because its only caller until now was a BROWSER: the
 * admin editor captures `canvas.toDataURL()` client-side and posts it along
 * with the canvas. A server-side writer (the MCP `set_design` tool) has no
 * browser, so it passes `''` — which that handler reads as *"keep whatever
 * thumbnail is already stored"* — and produces the picture itself.
 *
 * Folding "render it server-side" into the canvas handler was the alternative
 * and would have been worse in two ways: it would put a synchronous Gotenberg
 * call inside the hot path every admin canvas save takes (which already ships
 * its own thumbnail and needs none), and it would make the canvas write depend
 * on the renderer being up. Splitting it keeps the canvas write exactly as
 * cheap and as reliable as it was, and makes thumbnail persistence a thing that
 * can be dispatched — and tested — on its own.
 *
 * The message carries BYTES rather than a variant id alone on purpose: the
 * caller has already rendered the picture (it returns it to the agent), and a
 * handler that re-rendered would double the Gotenberg cost of every write and
 * could hand back a thumbnail of a different render than the one the caller
 * showed.
 */
readonly final class StoreTemplateVariantPreviewImage
{
    /**
     * The one place the thumbnail storage key is spelled.
     *
     * `EditTemplateVariantCanvasHandler` writes the same key for the browser
     * path; both call this, because two `sprintf()`s of one key is a pair
     * waiting to drift — and if they ever did, a variant would carry two
     * thumbnails and the row would point at the stale one.
     */
    public static function pathFor(UuidInterface $variantId): string
    {
        return sprintf('custom-templates/preview/%s.png', $variantId->toString());
    }

    /**
     * @param string $imageBytes A rendered PNG. The key it lands under ends in
     *        `.png` and the app labels it as such, so the bytes must really be
     *        one — see {@see \WBoost\Web\Value\RenderImageFormat} for why every
     *        export-shaped path states its format explicitly rather than
     *        inheriting a default.
     */
    public function __construct(
        public UuidInterface $variantId,
        public string $imageBytes,
    ) {
    }
}
