<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Image;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Exceptions\FileDirectoryNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Image\CreateFileDirectory;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Repository\ProjectRepository;

#[AsMessageHandler]
readonly final class CreateFileDirectoryHandler
{
    public function __construct(
        private FileDirectoryRepository $fileDirectoryRepository,
        private ProjectRepository $projectRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws FileDirectoryNotFound
     */
    public function __invoke(CreateFileDirectory $message): void
    {
        $project = $this->projectRepository->get($message->projectId);

        $parent = $message->parentId !== null
            ? $this->fileDirectoryRepository->get($message->parentId)
            : null;

        $this->fileDirectoryRepository->add(
            new FileDirectory(
                $message->directoryId,
                $project,
                $message->source,
                $message->name,
                $parent,
                $this->clock->now(),
            ),
        );
    }
}
