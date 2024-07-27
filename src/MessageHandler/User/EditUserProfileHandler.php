<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\User\EditUserProfile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Repository\UserRepository;

#[AsMessageHandler]
readonly final class EditUserProfileHandler
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @throws UserNotFound
     */
    public function __invoke(EditUserProfile $message): void
    {
        $user = $this->userRepository->get($message->userEmail);

        $user->editProfile(
            $message->name,
        );
    }
}
