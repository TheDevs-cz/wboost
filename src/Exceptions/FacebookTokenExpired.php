<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * Graph error 190: the stored access token is expired, revoked, or otherwise
 * invalid. The account gets flagged `needsReconnect` and the user is asked to
 * re-connect Facebook from their profile.
 */
final class FacebookTokenExpired extends MetaApiError
{
    public function userMessage(): string
    {
        return 'Připojení k Facebooku vypršelo. Znovu jej propojte ve svém profilu.';
    }
}
