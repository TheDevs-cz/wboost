<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A login POST whose credential fields are missing (or are not strings at all)
 * is a FAILED LOGIN, not a malformed request.
 *
 * Symfony's FormLoginAuthenticator already treats an EMPTY `_username` that way
 * — `BadCredentialsException`, caught by the authenticator manager, redirected
 * back to /login with "Neplatné přihlašovací údaje". But a field that is absent
 * or arrives as an array reaches a different branch of the very same method and
 * throws `BadRequestHttpException` ("The key "_username" must be a string,
 * "NULL" given."), which is not an AuthenticationException: it escapes the
 * firewall, answers 400 through the error page and — since `sentry.php` only
 * ignores AccessDenied/NotFound — books a Sentry issue for every scanner that
 * POSTs to /login without our field names (Sentry 77905823).
 *
 * So the fields are normalized to `''` before the firewall runs (priority 9 vs
 * the Firewall listener's 8; RouterListener at 32 has already resolved
 * `_route`), and the request continues down Symfony's own empty-credentials
 * path. A missing field is an empty field — the same answer the user gets for
 * submitting the form blank.
 *
 * Reading goes through `->all()` on purpose: `InputBag::get()` throws
 * `BadRequestException` on a non-scalar value, which is the very shape being
 * repaired here.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final readonly class NormalizeLoginCredentialsListener
{
    /**
     * The `main` firewall's `form_login.check_path` (config/packages/security.php).
     * Matched by route name, the way HttpUtils::checkRequestPath() matches it
     * once routing has run — and the way FacebookAuthenticator matches its own.
     */
    private const string LOGIN_ROUTE = 'login';

    /**
     * `username_parameter` / `password_parameter`. Both are read from the BODY
     * only, because form_login runs with its default `post_only: true`.
     */
    private const array BODY_PARAMETERS = ['_username', '_password'];

    private const string CSRF_PARAMETER = '_csrf_token';

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($event->isMainRequest() === false || $request->isMethod('POST') === false) {
            return;
        }

        if ($request->attributes->get('_route') !== self::LOGIN_ROUTE) {
            return;
        }

        foreach (self::BODY_PARAMETERS as $parameter) {
            if (is_string($request->request->all()[$parameter] ?? null) === false) {
                $request->request->set($parameter, '');
            }
        }

        // The CSRF token is looked up in the query string BEFORE the body, so a
        // non-string one in the query shadows a perfectly good one in the form.
        // Only a value that is actually present is rewritten: writing an empty
        // token into the query bag would shadow the real token on every login.
        foreach ([$request->query, $request->request] as $bag) {
            self::emptyNonStringToken($bag);
        }
    }

    private static function emptyNonStringToken(ParameterBag $bag): void
    {
        if ($bag->has(self::CSRF_PARAMETER) === false) {
            return;
        }

        if (is_string($bag->all()[self::CSRF_PARAMETER]) === false) {
            $bag->set(self::CSRF_PARAMETER, '');
        }
    }
}
