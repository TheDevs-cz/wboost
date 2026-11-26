<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Project;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Project\MarkProjectSocialAccountNeedsReconnect;
use WBoost\Web\Repository\ProjectSocialAccountRepository;

#[AsMessageHandler]
readonly final class MarkProjectSocialAccountNeedsReconnectHandler
{
    public function __construct(
        private ProjectSocialAccountRepository $accountRepository,
    ) {
    }

    public function __invoke(MarkProjectSocialAccountNeedsReconnect $message): void
    {
        $this->accountRepository->get(Uuid::fromString($message->accountId))?->markNeedsReconnect();
    }
}
