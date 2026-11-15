<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tells the browser that a native form post has been answered.
 *
 * The site-wide form guard (assets/submit_guard.js) makes a form's button
 * busy while it posts. A post that NAVIGATES needs nothing — the page goes
 * away. A post answered with a DOWNLOAD (exports, ZIPs, PDFs) leaves the page
 * where it is, and the browser has no event for "the download arrived". So
 * the guard sends a random `_submit_token`, and this listener echoes it on
 * whatever the server answers as the cookie `wbsubmit_<token>` — readable by
 * the page (not HttpOnly; it carries nothing but its own name), two minutes
 * long — which the guard polls for to give the button back.
 *
 * Only a token of the guard's shape is echoed (lowercase base36, 8–32
 * characters), so a request cannot name arbitrary cookies.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class SubmitTokenCookieListener
{
    public const string FIELD = '_submit_token';
    public const string COOKIE_PREFIX = 'wbsubmit_';

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->isMethod('POST')) {
            return;
        }

        $token = $request->request->get(self::FIELD);

        if (!is_string($token) || preg_match('/^[a-z0-9]{8,32}$/', $token) !== 1) {
            return;
        }

        $event->getResponse()->headers->setCookie(
            Cookie::create(self::COOKIE_PREFIX . $token, '1')
                ->withExpires(time() + 120)
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(false)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );
    }
}
