<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A network a PROJECT can be connected to for direct publishing
 * (`project_social_account.platform`). Instagram today; a Facebook Page is
 * the planned second case — the table, the project page and the publish
 * modal are shaped for it, only its connect flow + publisher are missing
 * (docs/architecture/social-publishing.md § "Adding Facebook").
 *
 * Not to be confused with SocialProvider: that one is a USER's sign-in /
 * personal Facebook link.
 */
enum SocialPlatform: string
{
    case Instagram = 'instagram';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
        };
    }
}
