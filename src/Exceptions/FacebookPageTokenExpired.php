<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * The project's Facebook Page token is invalid (Graph 190, removed app, undecryptable) — the connection is flagged for reconnect.
 */
final class FacebookPageTokenExpired extends MetaApiError
{
    public function userMessage(): string
    {
        return 'Propojení s facebookovou stránkou vypršelo nebo bylo zrušeno. Propojte stránku v projektu znovu.';
    }
}
