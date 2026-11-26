<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Project;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Project\DisconnectProjectSocialAccount;
use WBoost\Web\Repository\ProjectSocialAccountRepository;
use WBoost\Web\Value\SocialPlatform;

/**
 * Drops the stored connection (and with it the token). The permission stays
 * granted on the network's side until the person removes WBoost there
 * (Instagram → Settings → Apps and websites), which reaches us as the
 * deauthorize callback.
 */
#[AsMessageHandler]
readonly final class DisconnectProjectSocialAccountHandler
{
    public function __construct(
        private ProjectSocialAccountRepository $accountRepository,
    ) {
    }

    public function __invoke(DisconnectProjectSocialAccount $message): void
    {
        $account = $this->accountRepository->findForProject(
            Uuid::fromString($message->projectId),
            SocialPlatform::from($message->platform),
        );

        if ($account !== null) {
            $this->accountRepository->remove($account);
        }
    }
}
