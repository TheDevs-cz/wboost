<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * Instagram error 10 / 200-299: the token lacks the publishing permission
 * (unchecked in the consent screen, or the app is not approved for it yet).
 */
final class InstagramPermissionMissing extends MetaApiError
{
    public function userMessage(): string
    {
        return 'Instagram nepovolil publikování. Propojte účet znovu a povolte všechna oprávnění.';
    }
}
