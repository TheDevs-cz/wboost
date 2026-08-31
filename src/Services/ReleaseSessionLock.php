<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Symfony\Component\HttpFoundation\Request;

/**
 * Write + close the request's session BEFORE long render work starts.
 *
 * Sessions are stored via PdoSessionHandler, which holds a `SELECT … FOR
 * UPDATE` row lock from the first session read until the session is written
 * at the end of the request. Any request that renders through Gotenberg
 * therefore holds that lock for SECONDS — and every other request of the
 * same user (the group fill page fires one preview POST per dimension in
 * parallel; the fill page fires 2-3 renders per edit) queues behind it.
 * Under FrankenPHP the queue wait counts against max_execution_time
 * (wall-clock), so the pile-up ends in MaxExecutionTimeError fatals — the
 * Sentry WEB-2C events die literally inside PdoSessionHandler's SELECT.
 *
 * Releasing the lock is safe on these routes because everything they need
 * the session FOR (authentication, the voter check) has already happened,
 * and nothing after the render writes session state — they answer with
 * image bytes, JSON, or a plain page. If later code does touch the session
 * again, Symfony transparently restarts it (a fresh, short-lived lock),
 * so this is an optimization boundary, not a correctness one.
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
