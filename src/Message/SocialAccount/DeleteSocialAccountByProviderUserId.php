<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialAccount;

use WBoost\Web\Value\SocialProvider;

/**
 * Meta data-deletion callback: the person asked Facebook to delete the data
 * our app holds about them, identified only by the provider-side user id.
 */
readonly final class DeleteSocialAccountByProviderUserId
{
    public function __construct(
        public SocialProvider $provider,
        public string $providerUserId,
    ) {
    }
}
