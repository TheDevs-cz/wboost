<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\CanvasSlice;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Renders a canvas template variant to an image.
 *
 * Two entry points for the same Gotenberg pipeline:
 *
 *  - `render()` returns a streamed Response intended to flow straight to the
 *    browser as the body of an HTTP response (download / API export).
 *  - `renderToBytes()` returns the raw image bytes for server-side consumption
 *    (live preview as a base64 data: URI, future caching). Crucially this
 *    path does NOT call `flush()` or `echo` — it uses the Gotenberg bundle's
 *    `InMemoryProcessor` to drain the chunked response into a string.
 *
 * **Format defaults to PNG on BOTH methods and must stay that way.**
 * `renderToBytes()` is not an "internal preview" method — it also feeds the
 * group ZIP export and the Meta (Facebook/Instagram) publish path, where the
 * bytes are handed to third parties under an `image/png` label. Only callers
 * that render for the screen opt in to {@see RenderImageFormat::Webp}; every
 * export/download/API/publish path leaves the default alone so it stays
 * lossless. See {@see RenderImageFormat} for why WebP has no quality knob.
 *
 * Why this matters: the bundle's StreamedResponse callback `flush()`es output
 * to the SAPI on every chunk. Calling `sendContent()` on that response
 * server-side (e.g. inside a Twig render to capture bytes) commits the
 * response headers prematurely, so the actual outer HTTP response cannot
 * set its own Content-Type / cookies / etc. The fallout is silent in unit
 * tests but devastating in FrankenPHP worker mode: the browser receives
 * a header-less response and content-sniffs it, rendering the page in
 * unpredictable ways.
 */
interface TemplateVariantImageRendererInterface
{
    /**
     * Returns a BUFFERED image Response whose Content-Type is derived from
     * `$format` (so the header can never drift from the bytes), ready to be
     * returned directly from a controller. Buffered rather than streamed on
     * purpose: a flushing StreamedResponse corrupts the next request under
     * FrankenPHP's resident PHP process ("headers already sent"). Use
     * `renderToBytes()` if you only need the raw bytes in PHP.
     *
     * `$strictContainerOverflow` selects the container-overflow policy: true
     * (API export) makes the render fail with
     * {@see \WBoost\Web\Exceptions\ContainerOverflow} when a container's
     * filled text cannot fit its max height; false (web fill preview /
     * download) renders the overflowing state as-is so the user can see it —
     * the fill page blocks export client-side.
     *
     * `$slice` restricts the render to a z-range of the object stack (the fill
     * page's layered preview, {@see CanvasSlice}); null renders everything.
     *
     * `$format` defaults to lossless PNG — see the class docblock before
     * changing a caller to WebP.
     *
     * `$transparentTextInputIds` renders the bound textboxes of those inputs at
     * opacity 0 while keeping their exact layout influence — the "base" the
     * fill page's client-side text echo paints over. Empty (the default) means
     * a normal render.
     *
     * @param list<string> $transparentTextInputIds
     *
     * @throws \WBoost\Web\Exceptions\ContainerOverflow
     * @throws \WBoost\Web\Exceptions\TemplateRenderUnavailable when the renderer is overloaded / unreachable
     */
    public function render(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
        bool $strictContainerOverflow = false,
        null|CanvasSlice $slice = null,
        RenderImageFormat $format = RenderImageFormat::Png,
        array $transparentTextInputIds = [],
    ): Response;

    /**
     * Returns the rendered image as a string of bytes in `$format` (PNG unless
     * a caller opts in). Safe to base64-encode, embed inline, hash for caching,
     * or write to a file. Does not interact with the HTTP response cycle.
     *
     * Callers that hand these bytes to anything outside this app — the ZIP
     * export, the Meta publish path — must leave `$format` at its PNG default;
     * see the class docblock.
     *
     * @param list<string> $transparentTextInputIds see {@see render()}
     *
     * @throws \WBoost\Web\Exceptions\ContainerOverflow
     * @throws \WBoost\Web\Exceptions\TemplateRenderUnavailable when the renderer is overloaded / unreachable
     */
    public function renderToBytes(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
        bool $strictContainerOverflow = false,
        null|CanvasSlice $slice = null,
        RenderImageFormat $format = RenderImageFormat::Png,
        array $transparentTextInputIds = [],
    ): string;
}
