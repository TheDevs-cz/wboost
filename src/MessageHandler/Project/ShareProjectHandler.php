<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Project;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\Project\ShareProject;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\ProjectSharedMailer;
use WBoost\Web\Value\SharingLevel;

#[AsMessageHandler]
readonly final class ShareProjectHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private ProjectSharedMailer $projectSharedMailer,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws UserNotFound
     */
    public function __invoke(ShareProject $message): void
    {
        $project = $this->projectRepository->get(Uuid::fromString($message->projectId));
        $user = $this->userRepository->getById(Uuid::fromString($message->userId));

        $sharedBy = $message->sharedById !== null
            ? $this->userRepository->getById(Uuid::fromString($message->sharedById))
            : null;

        $isNewShare = $project->getUserSharingLevel($user) === null
            && !$user->id->equals($project->owner->id);

        $project->share($user, SharingLevel::from($message->level), $this->clock->now(), $sharedBy);

        // Only a fresh share for an active account gets the notice — a level
        // change is silent, and pending invitees get the invitation e-mail.
        if ($isNewShare && $user->confirmed) {
            $this->projectSharedMailer->sendShareNotification($project, $user, $sharedBy);
        }
    }
}
