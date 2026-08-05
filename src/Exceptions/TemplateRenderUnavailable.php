<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * The image renderer (Gotenberg + headless Chromium) did not answer within the
 * time the request can give it — it is overloaded or down, not fed bad input.
 *
 * This is deliberately its own failure mode rather than a generic 500: the
 * render is a synchronous dependency of user-facing pages (fill preview,
 * download, API export), and the honest answer to "the renderer is busy" is
 * "try again", not "something went wrong". 503 also tells API consumers and
 * uptime probes exactly that.
 *
 * The 2026-08-05 incident is why the timeout exists at all: with no bound on
 * the HTTP call, a slow render consumed PHP's whole max_execution_time and died
 * as a FATAL, holding the session lock for the full 30s and dragging every
 * other request of that user down with it.
 */
#[WithHttpStatus(Response::HTTP_SERVICE_UNAVAILABLE)]
final class TemplateRenderUnavailable extends \RuntimeException
{
    public static function timedOut(\Throwable $previous): self
    {
        return new self('The image renderer did not respond in time.', 0, $previous);
    }
}
