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
 * - No credentials (`INSTAGRAM_APP_ID` / `INSTAGRAM_APP_SECRET` empty) → off.
 * - `INSTAGRAM_ROLLOUT=admins` (default) → ROLE_ADMIN only. Until Meta's App
 *   Review approves the app, only Instagram accounts added as testers can
 *   authorise it anyway.
 * - `INSTAGRAM_ROLLOUT=everyone` → every signed-in user.
 */
readonly final class InstagramPublishing
{
    public function __construct(
        private Security $security,
        #[Autowire('%env(INSTAGRAM_APP_ID)%')]
        private string $appId,
        #[Autowire('%env(INSTAGRAM_APP_SECRET)%')]
        private string $appSecret,
        #[Autowire('%env(INSTAGRAM_ROLLOUT)%')]
        private string $rollout,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->appId !== '' && $this->appSecret !== '';
    }

    public function isAvailable(): bool
    {
        if (!$this->isConfigured() || !$this->security->getUser() instanceof User) {
            return false;
        }

        return $this->rollout === 'everyone' || $this->security->isGranted(User::ROLE_ADMIN);
    }
}
