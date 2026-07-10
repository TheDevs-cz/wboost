<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialAccount;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\SocialAccount\MarkSocialAccountNeedsReconnect;
use WBoost\Web\Repository\SocialAccountRepository;

#[AsMessageHandler]
readonly final class MarkSocialAccountNeedsReconnectHandler
{
    public function __construct(
        private SocialAccountRepository $socialAccountRepository,
    ) {
    }

    public function __invoke(MarkSocialAccountNeedsReconnect $message): void
    {
        $account = $this->socialAccountRepository->getById(Uuid::fromString($message->socialAccountId));

        $account?->markNeedsReconnect();
    }
}
