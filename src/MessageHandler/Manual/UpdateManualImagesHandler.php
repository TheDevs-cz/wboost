<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\UpdateManualImages;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\DetectImageColors;
use WBoost\Web\Value\Logo;
use WBoost\Web\Value\SvgImage;

#[AsMessageHandler]
readonly final class UpdateManualImagesHandler
{
    public function __construct(
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private ManualRepository $manualRepository,
        private DetectImageColors $detectImageColors,
        private TagAwareCacheInterface $cache,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(UpdateManualImages $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);

        $logoHorizontal = $manual->logo->horizontal;
        $logoVertical = $manual->logo->vertical;
        $logoHorizontalWithClaim = $manual->logo->horizontalWithClaim;
        $logoVerticalWithClaim = $manual->logo->verticalWithClaim;
        $logoSymbol = $manual->logo->symbol;

        if ($message->logoHorizontal !== null) {
            $logoHorizontal = $this->uploadImage($manual, $message->logoHorizontal, 'logo-horizontal');
        }

        if ($message->logoVertical !== null) {
            $logoVertical = $this->uploadImage($manual, $message->logoVertical, 'logo-vertical');
        }

        if ($message->logoHorizontalWithClaim !== null) {
            $logoHorizontalWithClaim = $this->uploadImage($manual, $message->logoHorizontalWithClaim, 'logo-horizontal-claim');
        }

        if ($message->logoVerticalWithClaim !== null) {
            $logoVerticalWithClaim = $this->uploadImage($manual, $message->logoVerticalWithClaim, 'logo-vertical-claim');
        }

        if ($message->logoSymbol !== null) {
            $logoSymbol = $this->uploadImage($manual, $message->logoSymbol, 'logo-symbol');
        }

        $logoHorizontal?->updateDimensionsInfo($message->logoHorizontalWidthInfo, $message->logoHorizontalHeightInfo);
        $logoVertical?->updateDimensionsInfo($message->logoVerticalWidthInfo, $message->logoVerticalHeightInfo);
        $logoHorizontalWithClaim?->updateDimensionsInfo($message->logoHorizontalWithClaimWidthInfo, $message->logoHorizontalWithClaimHeightInfo);
        $logoVerticalWithClaim?->updateDimensionsInfo($message->logoVerticalWithClaimWidthInfo, $message->logoVerticalWithClaimHeightInfo);
        $logoSymbol?->updateDimensionsInfo($message->logoSymbolWidthInfo, $message->logoSymbolHeightInfo);

        $manual->editLogo(
            new Logo(
                horizontal: $logoHorizontal,
                vertical: $logoVertical,
                horizontalWithClaim: $logoHorizontalWithClaim,
                verticalWithClaim: $logoVerticalWithClaim,
                symbol: $logoSymbol,
            )
        );
    }

    private function uploadImage(
        Manual $manual,
        UploadedFile $image,
        string $imagePrefix,
    ): SvgImage
    {
        $timestamp = $this->clock->now()->getTimestamp();

        $extension = $image->guessExtension();
        $path = "manuals/$manual->id/$imagePrefix-$timestamp.$extension";

        $fileContent = $image->getContent();
        $this->filesystem->write($path, $fileContent);
        $detectedColors = $this->detectImageColors->fromSvg($fileContent);

        // Invalidate cached logo images for this manual
        $cacheTag = 'manual_' . $manual->id;
        $this->cache->invalidateTags([$cacheTag]);

        return new SvgImage(
            filePath: $path,
            detectedColors: $detectedColors,
        );
    }
}
