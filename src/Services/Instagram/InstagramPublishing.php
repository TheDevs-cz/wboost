<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Instagram;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use WBoost\Web\Entity\User;

/**
 * Who gets the REAL Instagram connect + publish flow; everyone else keeps the
 * "coming soon" door (`_social_publish_fake_door.html.twig`).
 *
 * - No credentials (`INSTAGRAM_META_APP_ID` / `INSTAGRAM_META_APP_SECRET` /
 *   `INSTAGRAM_LOGIN_CONFIG_ID` empty) → off; in prod also off while
 *   `SOCIAL_TOKEN_ENCRYPTION_KEY` is empty or the committed dev key.
 * - `INSTAGRAM_ROLLOUT=admins` (default) → ROLE_ADMIN + ROLE_SOCIAL_BETA (Meta's reviewer, pilots). Until Meta's App
 *   Review approves the app, only Instagram accounts added as testers can
 *   authorise it anyway.
 * - `INSTAGRAM_ROLLOUT=everyone` → every signed-in user.
 */
readonly final class InstagramPublishing
{
    public function __construct(
        private Security $security,
        #[Autowire('%env(INSTAGRAM_META_APP_ID)%')]
        private string $appId,
        #[Autowire('%env(INSTAGRAM_META_APP_SECRET)%')]
        #[\SensitiveParameter]
        private string $appSecret,
        #[Autowire('%env(INSTAGRAM_LOGIN_CONFIG_ID)%')]
        private string $configId,
        #[Autowire('%env(INSTAGRAM_ROLLOUT)%')]
        private string $rollout,
        #[Autowire('%env(SOCIAL_TOKEN_ENCRYPTION_KEY)%')]
        #[\SensitiveParameter]
        private string $encryptionKey = '',
        #[Autowire('%kernel.environment%')]
        private string $environment = 'prod',
    ) {
    }

    /** The development key committed in `.env` — public, so never good enough in prod. */
    private const string DEV_ENCRYPTION_KEY = 'Oe9omNp9iaQgTR3NVOzC+CfqmgVnIgEUrd75mEIXOoA=';

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

        return $this->rollout === 'everyone'
            || $this->security->isGranted(User::ROLE_ADMIN)
            || $this->security->isGranted(User::ROLE_SOCIAL_BETA);
    }
}
