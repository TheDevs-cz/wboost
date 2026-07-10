<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * External social-network identity provider a user account can be linked to.
 * Instagram deliberately has no case: Meta's Instagram login never returns an
 * e-mail address, so Instagram is reachable only THROUGH the Facebook
 * connection (Instagram professional account linked to a Facebook Page).
 */
enum SocialProvider: string
{
    case Facebook = 'facebook';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
        };
    }
}
