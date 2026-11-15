<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers 413 when PHP has thrown the request body away.
 *
 * A body bigger than `post_max_size` is not an error in PHP: it SILENTLY
 * empties $_POST and $_FILES and runs the request anyway. Downstream that
 * reads as "Invalid CSRF token", a blank re-rendered form or a save that did
 * nothing. The only trace left is the Content-Length header, so this compares
 * it with the live ini value before the session, router, firewall, CSRF and
 * forms ever look at the empty request.
 *
 * Priority 512: AFTER SentryRequestIsolationSubscriber (1000), whose
 * terminate listener always pops the scope it pushed, and BEFORE the session
 * (128), router (32) and firewall (8).
 *
 * Programmatic clients get a JSON 413 (contract in
 * docs/architecture/gallery-and-uploads.md, "Request size limits"); browsers
 * get the Czech error page (templates/bundles/TwigBundle/Exception/error.html.twig).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final readonly class RejectOversizedRequestBodyListener
{
    private const array BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    /** Paths whose callers are programs, never a page in a browser tab. */
    private const array JSON_PATH_PREFIXES = ['/api', '/_mcp', '/internal/', '/_components'];

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!in_array($request->getMethod(), self::BODY_METHODS, true)) {
            return;
        }

        $maxBytes = RequestBodyLimit::postMaxSizeBytes();

        if ($maxBytes === 0) {
            return;
        }

        $contentLength = $request->headers->get('Content-Length');

        if ($contentLength === null || preg_match('/^\d+$/', $contentLength) !== 1 || (int) $contentLength <= $maxBytes) {
            return;
        }

        $message = RequestBodyLimit::tooLargeMessage($maxBytes);

        if ($this->wantsJson($request)) {
            $event->setResponse(new JsonResponse([
                'status' => 'error',
                'code' => 'request_too_large',
                'message' => $message,
                'maxBytes' => $maxBytes,
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE));

            return;
        }

        throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $message);
    }

    private function wantsJson(Request $request): bool
    {
        $path = $request->getPathInfo();

        foreach (self::JSON_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        // Live Components send `Accept: application/vnd.live-component+html`
        // together with `X-Requested-With: XMLHttpRequest` (and post to
        // /_components, matched above).
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        if (str_contains($accept, 'application/json') || str_contains($accept, '+json')) {
            return true;
        }

        return str_contains((string) $request->headers->get('Content-Type', ''), 'json');
    }
}
