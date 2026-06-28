<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\UserAlreadyRegistered;
use WBoost\Web\Message\User\InviteUser;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\InvitationMailer;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\SharingLevel;

#[AsMessageHandler]
readonly final class InviteUserHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ProjectRepository $projectRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private InvitationMailer $invitationMailer,
    ) {
    }

    /**
     * @throws UserAlreadyRegistered
     * @throws ProjectNotFound
     */
    public function __invoke(InviteUser $message): void
    {
        $existing = $this->userRepository->findByEmailOrNull($message->email);

        if ($existing !== null && $existing->confirmed) {
            throw new UserAlreadyRegistered();
        }

        // A pending (unconfirmed) user is re-invited: reuse the row, refresh its
        // metadata + pre-shares and re-mint the token. A brand-new user starts as
        // unconfirmed with an empty password (can't authenticate until activation).
        if ($existing !== null) {
            $user = $existing;
        } else {
            $user = new User($this->provideIdentity->next(), $message->email, $this->clock->now(), confirmed: false);
            $this->userRepository->save($user);
        }

        $user->changeRoles($message->roles);
        $user->editProfile($message->name);

        $invitedBy = $this->userRepository->getById(Uuid::fromString($message->invitedById));

        foreach ($message->projectIds as $projectId) {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
            $project->share($user, SharingLevel::Read, $this->clock->now(), $invitedBy);
        }

        $this->invitationMailer->sendInvitation($user);
    }
}
