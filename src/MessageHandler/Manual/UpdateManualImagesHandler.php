<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\UpdateManualImages;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\DetectImageColors;

#[AsMessageHandler]
readonly final class UpdateManualImagesHandler
{
    public function __construct(
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private ManualRepository $manualRepository,
        private DetectImageColors $detectImageColors,
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
            $logoHorizontalPath = $this->uploadImage($manual, $message->logoHorizontal, 'logo-horizontal');
        }

        if ($message->logoVertical !== null) {
            $logoVerticalPath = $this->uploadImage($manual, $message->logoVertical, 'logo-vertical');
        }

        if ($message->logoHorizontalWithClaim !== null) {
            $logoHorizontalWithClaimPath = $this->uploadImage($manual, $message->logoHorizontalWithClaim, 'logo-horizontal-claim');
        }

        if ($message->logoVerticalWithClaim !== null) {
            $logoVerticalWithClaimPath = $this->uploadImage($manual, $message->logoVerticalWithClaim, 'logo-vertical-claim');
        }

        if ($message->logoSymbol !== null) {
            $logoSymbolPath = $this->uploadImage($manual, $message->logoSymbol, 'logo-symbol');
        }

        $manual->updateImages(
            $logoHorizontalPath,
            $logoVerticalPath,
            $logoHorizontalWithClaimPath,
            $logoVerticalWithClaimPath,
            $logoSymbolPath,
        );
    }

    private function uploadImage(Manual $manual, UploadedFile $image, string $imagePrefix): string
    {
        $timestamp = $this->clock->now()->getTimestamp();

        $extension = $image->guessExtension();
        $path = "manuals/$manual->id/$imagePrefix-$timestamp.$extension";

        $fileContent = $image->getContent();
        $this->filesystem->write($path, $fileContent);

        $colors = $this->detectImageColors->fromSvg($fileContent);

        $manual->addColors($colors);

        return $path;
    }
}
