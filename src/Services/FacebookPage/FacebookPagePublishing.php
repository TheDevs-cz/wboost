<?php

declare(strict_types=1);

namespace WBoost\Web\Services\FacebookPage;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use WBoost\Web\Entity\User;

/**
 * Who gets the REAL project Facebook Page connect + publish flow.
 *
 * - Off without `INSTAGRAM_META_APP_ID` / `INSTAGRAM_META_APP_SECRET` (the one
 *   Meta app) or `FACEBOOK_PAGES_LOGIN_CONFIG_ID`; in prod also off while
 *   `SOCIAL_TOKEN_ENCRYPTION_KEY` is empty or the committed dev key.
 * - `FACEBOOK_PAGES_ROLLOUT=admins` (default) → ROLE_ADMIN only;
 *   `everyone` → every signed-in user (after Meta's App Review).
 *
 * Outside the gate the fill page keeps the older personal Facebook link
 * (user profile → own Pages) where that is connected.
 */
readonly final class FacebookPagePublishing
{
    /** The development key committed in `.env` — public, so never good enough in prod. */
    private const string DEV_ENCRYPTION_KEY = 'Oe9omNp9iaQgTR3NVOzC+CfqmgVnIgEUrd75mEIXOoA=';

    public function __construct(
        private Security $security,
        #[Autowire('%env(INSTAGRAM_META_APP_ID)%')]
        private string $appId,
        #[Autowire('%env(INSTAGRAM_META_APP_SECRET)%')]
        #[\SensitiveParameter]
        private string $appSecret,
        #[Autowire('%env(FACEBOOK_PAGES_LOGIN_CONFIG_ID)%')]
        private string $configId,
        #[Autowire('%env(FACEBOOK_PAGES_ROLLOUT)%')]
        private string $rollout,
        #[Autowire('%env(SOCIAL_TOKEN_ENCRYPTION_KEY)%')]
        #[\SensitiveParameter]
        private string $encryptionKey = '',
        #[Autowire('%kernel.environment%')]
        private string $environment = 'prod',
    ) {
    }

    public function isConfigured(): bool
    {
        if ($this->appId === '' || $this->appSecret === '' || $this->configId === '') {
            return false;
        }

        if ($this->environment === 'prod') {
            return $this->encryptionKey !== '' && $this->encryptionKey !== self::DEV_ENCRYPTION_KEY;
        }

        return true;
    }

    public function isAvailable(): bool
    {
        if (!$this->isConfigured() || !$this->security->getUser() instanceof User) {
            return false;
        }

        return $this->rollout === 'everyone' || $this->security->isGranted(User::ROLE_ADMIN);
    }
}
