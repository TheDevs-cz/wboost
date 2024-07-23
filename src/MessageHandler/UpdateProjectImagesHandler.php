<?php

declare(strict_types=1);

namespace BrandManuals\Web\MessageHandler;

use BrandManuals\Web\Exceptions\ProjectNotFound;
use BrandManuals\Web\Message\AddImageColorsToProject;
use BrandManuals\Web\Message\UpdateProjectImages;
use BrandManuals\Web\Repository\ProjectRepository;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class UpdateProjectImagesHandler
{
    public function __construct(
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private ProjectRepository $projectRepository,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(UpdateProjectImages $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $timestamp = $this->clock->now()->getTimestamp();

        $logoHorizontalPath = $project->logoHorizontal;

        if ($message->logoHorizontal !== null) {
            $extension = $message->logoHorizontal->guessExtension();
            $logoHorizontalPath = "projects/$message->projectId/logo-horizontal-$timestamp.$extension";

            // Stream is better because it is memory safe
            $stream = fopen($message->logoHorizontal->getPathname(), 'rb');
            $this->filesystem->writeStream($logoHorizontalPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->bus->dispatch(
                new AddImageColorsToProject(
                    $message->projectId,
                    $logoHorizontalPath,
                ),
            );
        }

        $project->updateImages(
            $logoHorizontalPath,
            null,
            null,
            null,
            null,
        );
    }
}
