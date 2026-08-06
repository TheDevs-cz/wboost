<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What the user just clicked on the consent screen, carried across the ONE
 * redirect back into `/api/authorize`
 * ({@see \WBoost\Web\Services\OAuth2\ConsentSession}).
 *
 * Both cases are load-bearing:
 *
 * - `Denied` is the only way the authorization endpoint can learn about a
 *   refusal at all. Without it the redirect back would simply find no stored
 *   approval and render the consent screen again — an endless loop instead of
 *   the RFC-correct `access_denied` the client is waiting for.
 * - `Approved` is a one-shot safety valve. The persisted approval is what
 *   normally satisfies the next pass, so this case is redundant in the happy
 *   path — but it guarantees FORWARD PROGRESS: if the stored approval somehow
 *   failed to cover the request, the user's click still resolves this one
 *   authorization instead of bouncing them back to a screen they just answered.
 *
 * The value is consumed on read and bound to the client + scope set it was
 * given for, so neither case can be replayed against a different request.
 */
enum ConsentDecision: string
{
    case Approved = 'approved';

    case Denied = 'denied';
}
