<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\AddImageColorsToManual;
use WBoost\Web\Message\Manual\UpdateManualImages;
use WBoost\Web\Repository\ManualRepository;

#[AsMessageHandler]
readonly final class UpdateManualImagesHandler
{
    public function __construct(
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private ManualRepository $manualRepository,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(UpdateManualImages $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);

        $logoHorizontalPath = $manual->logoHorizontal;
        $logoVerticalPath = $manual->logoVertical;
        $logoHorizontalWithClaimPath = $manual->logoHorizontalWithClaim;
        $logoVerticalWithClaimPath = $manual->logoVerticalWithClaim;
        $logoSymbolPath = $manual->logoSymbol;

        if ($message->logoHorizontal !== null) {
            $logoHorizontalPath = $this->uploadImage($message->logoHorizontal, $message->manualId, 'logo-horizontal');
        }

        if ($message->logoVertical !== null) {
            $logoVerticalPath = $this->uploadImage($message->logoVertical, $message->manualId, 'logo-vertical');
        }

        if ($message->logoHorizontalWithClaim !== null) {
            $logoHorizontalWithClaimPath = $this->uploadImage($message->logoHorizontalWithClaim, $message->manualId, 'logo-horizontal-claim');
        }

        if ($message->logoVerticalWithClaim !== null) {
            $logoVerticalWithClaimPath = $this->uploadImage($message->logoVerticalWithClaim, $message->manualId, 'logo-vertical-claim');
        }

        if ($message->logoSymbol !== null) {
            $logoSymbolPath = $this->uploadImage($message->logoSymbol, $message->manualId, 'logo-symbol');
        }

        $manual->updateImages(
            $logoHorizontalPath,
            $logoVerticalPath,
            $logoHorizontalWithClaimPath,
            $logoVerticalWithClaimPath,
            $logoSymbolPath,
        );
    }

    private function uploadImage(UploadedFile $image, UuidInterface $manualId, string $imagePrefix): string
    {
        $timestamp = $this->clock->now()->getTimestamp();

        $extension = $image->guessExtension();
        $path = "manuals/$manualId/$imagePrefix-$timestamp.$extension";

        // Stream is better because it is memory safe
        $stream = fopen($image->getPathname(), 'rb');
        $this->filesystem->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $this->bus->dispatch(
            new AddImageColorsToManual(
                $manualId,
                $path,
            ),
        );

        return $path;
    }
}
