<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * Graph error 10 / 200-299 / 803: the token works but lacks a permission (the
 * user unchecked a scope in the consent dialog, or the app lacks review
 * approval for it). Fixed by re-connecting and granting everything.
 */
final class FacebookPermissionMissing extends MetaApiError
{
    public function userMessage(): string
    {
        return 'Chybí oprávnění k publikování. Odpojte a znovu propojte facebookový účet a povolte všechna oprávnění.';
    }
}
