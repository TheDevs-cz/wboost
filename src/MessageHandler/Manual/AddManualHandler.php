<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Manual\AddManual;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Repository\ProjectRepository;

#[AsMessageHandler]
readonly final class AddManualHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ManualRepository $manualRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(AddManual $message): void
    {
        $project = $this->projectRepository->get($message->projectId);

        $manual = new Manual(
            $message->manualId,
            $project,
            $this->clock->now(),
            $message->type,
            $message->name,
        );

        $this->manualRepository->add($manual);
    }
}
