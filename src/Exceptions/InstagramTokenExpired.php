<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * Instagram error 190 (or an undecryptable stored token): the project's
 * Instagram connection is expired / revoked. The connection gets flagged
 * `needsReconnect`; someone who manages the project reconnects it.
 */
final class InstagramTokenExpired extends MetaApiError
{
    public function userMessage(): string
    {
        return 'Propojení s Instagramem vypršelo nebo bylo zrušeno. Správce projektu ho musí propojit znovu.';
    }
}
