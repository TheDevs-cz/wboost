<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialAccount;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\SocialAccount\DisconnectFacebookAccount;
use WBoost\Web\Repository\SocialAccountRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Value\SocialProvider;

#[AsMessageHandler]
readonly final class DisconnectFacebookAccountHandler
{
    public function __construct(
        private SocialAccountRepository $socialAccountRepository,
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @throws UserNotFound
     */
    public function __invoke(DisconnectFacebookAccount $message): void
    {
        $user = $this->userRepository->getById(Uuid::fromString($message->userId));
        $account = $this->socialAccountRepository->findForUser($user, SocialProvider::Facebook);

        if ($account !== null) {
            $this->socialAccountRepository->remove($account);
        }
    }
}
