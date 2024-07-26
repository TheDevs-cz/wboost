<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler;

use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\AddImageColorsToProject;
use WBoost\Web\Message\UpdateProjectImages;
use WBoost\Web\Repository\ProjectRepository;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

        $logoHorizontalPath = $project->logoHorizontal;
        $logoVerticalPath = $project->logoVertical;
        $logoHorizontalWithClaimPath = $project->logoHorizontalWithClaim;
        $logoVerticalWithClaimPath = $project->logoVerticalWithClaim;
        $logoSymbolPath = $project->logoSymbol;

        if ($message->logoHorizontal !== null) {
            $logoHorizontalPath = $this->uploadImage($message->logoHorizontal, $message->projectId, 'logo-horizontal');
        }

        if ($message->logoVertical !== null) {
            $logoVerticalPath = $this->uploadImage($message->logoVertical, $message->projectId, 'logo-vertical');
        }

        if ($message->logoHorizontalWithClaim !== null) {
            $logoHorizontalWithClaimPath = $this->uploadImage($message->logoHorizontalWithClaim, $message->projectId, 'logo-horizontal-claim');
        }

        if ($message->logoVerticalWithClaim !== null) {
            $logoVerticalWithClaimPath = $this->uploadImage($message->logoVerticalWithClaim, $message->projectId, 'logo-vertical-claim');
        }

        if ($message->logoSymbol !== null) {
            $logoSymbolPath = $this->uploadImage($message->logoSymbol, $message->projectId, 'logo-symbol');
        }

        $project->updateImages(
            $logoHorizontalPath,
            $logoVerticalPath,
            $logoHorizontalWithClaimPath,
            $logoVerticalWithClaimPath,
            $logoSymbolPath,
        );
    }

    private function uploadImage(UploadedFile $image, string $projectId, string $imagePrefix): string
    {
        $timestamp = $this->clock->now()->getTimestamp();

        $extension = $image->guessExtension();
        $path = "projects/$projectId/$imagePrefix-$timestamp.$extension";

        // Stream is better because it is memory safe
        $stream = fopen($image->getPathname(), 'rb');
        $this->filesystem->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $this->bus->dispatch(
            new AddImageColorsToProject(
                $projectId,
                $path,
            ),
        );

        return $path;
    }
}
