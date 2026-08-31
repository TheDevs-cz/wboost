<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Symfony\Component\HttpFoundation\Request;

/**
 * Write + close the request's session BEFORE long render work starts — the
 * Symfony-documented courtesy for long-running requests.
 *
 * Session LOCKING is off app-wide (PdoSessionHandler LOCK_NONE, see
 * config/services.php — the transactional row lock used to serialize every
 * request of one user behind seconds-long Gotenberg renders, the Sentry
 * WEB-2C fatals). What closing early still buys on a render route: the
 * request writes its session back within milliseconds of auth instead of
 * holding an in-memory copy through 25-150s of render work and clobbering
 * whatever was written meanwhile (a logout in another tab must not be
 * resurrected by a finishing render).
 *
 * Safe on these routes because everything they need the session FOR
 * (authentication, the voter check) has already happened, and nothing after
 * the render writes session state — they answer with image bytes, JSON, or
 * a plain page. If later code does touch the session again, Symfony
 * transparently restarts it, so this is an optimization boundary, not a
 * correctness one.
 */
final class ReleaseSessionLock
{
    public function release(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        if ($session->isStarted()) {
            $session->save();
        }
    }
}
