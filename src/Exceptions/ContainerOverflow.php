<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Raised on the STRICT render paths (API export) when a container's filled
 * text cannot fit within its max height even after reflow — the same contract
 * class as the maxLength 400, but measured in pixels of wrapped text rather
 * than characters.
 *
 * Detection happens inside the headless Gotenberg render (only Fabric can
 * measure wrapped text): the render template throws an uncaught
 * `Error("CONTAINER_OVERFLOW:{json}")`, Gotenberg (with failOnConsoleExceptions
 * enabled) turns that into an HTTP error whose body carries the exception
 * text, and {@see \WBoost\Web\Services\Editor\TemplateVariantImageRenderer}
 * parses the marker back out into this exception. The web fill/download paths
 * render leniently instead (the fill page blocks export client-side).
 */
#[WithHttpStatus(Response::HTTP_BAD_REQUEST)]
final class ContainerOverflow extends \Exception
{
    public function __construct(
        public readonly null|string $containerId,
        public readonly float $overflowPx,
    ) {
        parent::__construct(sprintf(
            'Container %s content overflows its max height by %.1f px.',
            $this->containerId ?? '(unknown)',
            $this->overflowPx,
        ));
    }

    /**
     * Parse the CONTAINER_OVERFLOW marker out of a Gotenberg error body.
     * Returns null when the error is not an overflow signal (a real render
     * failure must keep propagating as-is).
     */
    public static function tryFromGotenbergError(string $gotenbergErrorBody): null|self
    {
        if (!str_contains($gotenbergErrorBody, 'CONTAINER_OVERFLOW:')) {
            return null;
        }

        // Marker present but payload mangled (e.g. truncated body): still an
        // overflow — a detail-less 400 beats a 500 masquerading as a render
        // failure. The payload is a flat JSON object (no nested braces).
        if (preg_match('/CONTAINER_OVERFLOW:(\{[^}]*\})/', $gotenbergErrorBody, $matches) !== 1) {
            return new self(null, 0.0);
        }

        /** @var mixed $payload */
        $payload = json_decode($matches[1], true);
        if (!is_array($payload)) {
            return new self(null, 0.0);
        }

        $containerId = $payload['containerId'] ?? null;
        $overflowPx = $payload['overflowPx'] ?? null;

        return new self(
            is_string($containerId) ? $containerId : null,
            is_int($overflowPx) || is_float($overflowPx) ? (float) $overflowPx : 0.0,
        );
    }
}
