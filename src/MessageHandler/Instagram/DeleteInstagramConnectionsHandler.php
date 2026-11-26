<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Instagram;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Instagram\DeleteInstagramConnections;
use WBoost\Web\Repository\ProjectSocialAccountRepository;
use WBoost\Web\Value\SocialPlatform;

#[AsMessageHandler]
readonly final class DeleteInstagramConnectionsHandler
{
    public function __construct(
        private ProjectSocialAccountRepository $accountRepository,
    ) {
    }

    public function __invoke(DeleteInstagramConnections $message): void
    {
        foreach ($this->accountRepository->findByExternalId(SocialPlatform::Instagram, $message->instagramUserId) as $account) {
            $this->accountRepository->remove($account);
        }
    }
}
