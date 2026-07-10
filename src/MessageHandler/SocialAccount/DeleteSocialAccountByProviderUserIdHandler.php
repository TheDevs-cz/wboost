<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialAccount;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\SocialAccount\DeleteSocialAccountByProviderUserId;
use WBoost\Web\Repository\SocialAccountRepository;

#[AsMessageHandler]
readonly final class DeleteSocialAccountByProviderUserIdHandler
{
    public function __construct(
        private SocialAccountRepository $socialAccountRepository,
    ) {
    }

    public function __invoke(DeleteSocialAccountByProviderUserId $message): void
    {
        $account = $this->socialAccountRepository->findByProviderUserId(
            $message->provider,
            $message->providerUserId,
        );

        // No-op when unknown: Meta may fire the callback for people who never
        // finished connecting, and deletion must be idempotent anyway.
        if ($account !== null) {
            $this->socialAccountRepository->remove($account);
        }
    }
}
