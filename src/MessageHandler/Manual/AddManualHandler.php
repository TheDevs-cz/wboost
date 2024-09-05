<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(AddManual $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $now = $this->clock->now();
        $introImagePath = null;

        if ($message->introImage !== null) {
            $timestamp = $now->getTimestamp();

            $extension = $message->introImage->guessExtension();
            $introImagePath = "manuals/$message->manualId/intro-$timestamp.$extension";

            $fileContent = $message->introImage->getContent();
            $this->filesystem->write($introImagePath, $fileContent);
        }

        $manual = new Manual(
            $message->manualId,
            $project,
            $now,
            $message->type,
            $message->name,
            $introImagePath,
        );

        $this->manualRepository->add($manual);
    }
}
