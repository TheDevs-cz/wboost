<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
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
interface SocialNetworkTemplateVariantImageRendererInterface
{
    /**
     * Returns a streamed PNG Response. Designed to be returned directly from
     * a controller — do NOT call `sendContent()` server-side; use
     * `renderToBytes()` if you need the bytes in PHP.
     */
    public function render(
        SocialNetworkTemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
    ): Response;

    /**
     * Returns the rendered PNG as a string of bytes. Safe to base64-encode,
     * embed inline, hash for caching, or write to a file. Does not interact
     * with the HTTP response cycle.
     */
    public function renderToBytes(
        SocialNetworkTemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
    ): string;
}
