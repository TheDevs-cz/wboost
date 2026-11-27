<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Instagram;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Instagram\DeleteInstagramConnections;
use WBoost\Web\Repository\ProjectSocialAccountRepository;
use WBoost\Web\Value\SocialPlatform;

/**
 * Meta's deauthorize / data-deletion callbacks of the ONE Meta app: drops the
 * matching project connections of BOTH platforms (Instagram + Facebook Page).
 */
#[AsMessageHandler]
readonly final class DeleteInstagramConnectionsHandler
{
    public function __construct(
        private ProjectSocialAccountRepository $accountRepository,
    ) {
    }

    public function __invoke(DeleteInstagramConnections $message): void
    {
        foreach (SocialPlatform::cases() as $platform) {
            foreach ($this->accountRepository->findByExternalId($platform, $message->instagramUserId) as $account) {
                $this->accountRepository->remove($account);
            }
        }
    }
}
