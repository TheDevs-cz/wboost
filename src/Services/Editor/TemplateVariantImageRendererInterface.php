<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Renders a canvas template variant (social network or custom template — both carry the
 * same canvas / inputs / imageInputs / dimension shape) to a PNG.
 *
 * Two entry points for the same Gotenberg pipeline:
 *
 *  - `render()` returns a streamed Response intended to flow straight to the
 *    browser as the body of an HTTP response (download / API export).
 *  - `renderToBytes()` returns the raw PNG bytes for server-side consumption
 *    (live preview as a base64 data: URI, future caching). Crucially this
 *    path does NOT call `flush()` or `echo` — it uses the Gotenberg bundle's
 *    `InMemoryProcessor` to drain the chunked response into a string.
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
     * Returns a BUFFERED PNG Response (Content-Type: image/png), ready to be
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
     * @throws \WBoost\Web\Exceptions\ContainerOverflow
     */
    public function render(
        SocialNetworkTemplateVariant|CustomTemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
        bool $strictContainerOverflow = false,
    ): Response;

    /**
     * Returns the rendered PNG as a string of bytes. Safe to base64-encode,
     * embed inline, hash for caching, or write to a file. Does not interact
     * with the HTTP response cycle.
     *
     * @throws \WBoost\Web\Exceptions\ContainerOverflow
     */
    public function renderToBytes(
        SocialNetworkTemplateVariant|CustomTemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
        bool $strictContainerOverflow = false,
    ): string;
}
