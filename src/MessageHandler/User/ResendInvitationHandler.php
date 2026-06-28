<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\User\ResendInvitation;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\InvitationMailer;

#[AsMessageHandler]
readonly final class ResendInvitationHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private InvitationMailer $invitationMailer,
    ) {
    }

    /**
     * @throws UserNotFound
     */
    public function __invoke(ResendInvitation $message): void
    {
        $user = $this->userRepository->getById(Uuid::fromString($message->userId));

        // Only pending invitees can be re-invited; an already-active account has
        // nothing to resend.
        if ($user->confirmed) {
            return;
        }

        $this->invitationMailer->sendInvitation($user);
    }
}
