<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A network a PROJECT can be connected to for direct publishing
 * (`project_social_account.platform`): an Instagram professional account or a
 * Facebook Page, both via Facebook Login for Business of the same Meta app
 * (docs/architecture/social-publishing.md).
 *
 * Not to be confused with SocialProvider: that one is a USER's sign-in /
 * personal Facebook link.
 */
enum SocialPlatform: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
        };
    }
}
