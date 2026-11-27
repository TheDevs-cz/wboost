<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * Graph 10 / 200-299 on a project Page call — the Page token lacks pages_manage_posts or the person can't create content on the Page.
 */
final class FacebookPagePermissionMissing extends MetaApiError
{
    public function userMessage(): string
    {
        return 'Facebook nepovolil publikování na stránku. Propojte stránku znovu a povolte všechna oprávnění.';
    }
}
