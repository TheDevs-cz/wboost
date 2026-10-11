<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Project;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\ProjectModuleChange;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\Project\DisableProjectModule;
use WBoost\Web\Repository\ProjectModuleChangeRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class DisableProjectModuleHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
        private ProjectModuleChangeRepository $changeRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws UserNotFound
     */
    public function __invoke(DisableProjectModule $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $user = $this->userRepository->getById($message->userId);

        if (!$project->disableModule($message->module)) {
            return;
        }

        $this->changeRepository->add(new ProjectModuleChange(
            $this->provideIdentity->next(),
            $project,
            $message->module,
            false,
            $this->clock->now(),
            $user,
        ));
    }
}
