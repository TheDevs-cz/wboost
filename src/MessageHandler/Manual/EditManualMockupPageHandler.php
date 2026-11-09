<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualMockupPageNotFound;
use WBoost\Web\Message\Manual\EditManualMockupPage;
use WBoost\Web\Repository\ManualMockupPageRepository;
use WBoost\Web\Services\Manual\MockupPageSlotImages;
use WBoost\Web\Services\Manual\StoreMockupPageDownload;

#[AsMessageHandler]
readonly final class EditManualMockupPageHandler
{
    public function __construct(
        private ManualMockupPageRepository $manualMockupPageRepository,
        private ClockInterface $clock,
        private MockupPageSlotImages $slotImages,
        private StoreMockupPageDownload $storeMockupPageDownload,
    ) {
    }

    /**
     * @throws ManualMockupPageNotFound
     * @throws FilesystemException
     */
    public function __invoke(EditManualMockupPage $message): void
    {
        $page = $this->manualMockupPageRepository->get($message->pageId);
        $timestamp = $this->clock->now()->getTimestamp();
        $manualId = $page->manual->id;
        $images = [];

        foreach ($message->images as $index => $image) {
            // Upload > gallery pick > remove flag > keep: the new choice for a
            // slot always wins over the flag set by clicking "remove" first.
            $new = $this->slotImages->resolve(
                $page->manual,
                $page->id,
                $message->name,
                $index,
                $image,
                $message->galleryImages[$index] ?? null,
                $timestamp,
            );

            if ($new !== null) {
                $images[] = $new;
                continue;
            }

            $keepExisting = ($message->removeImages[$index] ?? false) === false;
            $images[] = $keepExisting ? ($page->images[$index] ?? null) : null;
        }

        // A new upload always wins over the remove flag, mirroring the images
        // above: the flag was set by clicking "remove", the upload came after.
        if ($message->downloadFile !== null) {
            $downloadFile = ($this->storeMockupPageDownload)(
                $message->downloadFile,
                $manualId,
                $page->id,
                'page',
                $timestamp,
            );
        } else {
            $downloadFile = $message->removeDownloadFile ? null : $page->downloadFile;
        }

        $imageDownloads = [];

        foreach ($message->imageDownloads as $index => $download) {
            if ($download === null) {
                $keepExisting = ($message->removeImageDownloads[$index] ?? false) === false;

                $imageDownloads[] = $keepExisting ? ($page->imageDownloads[$index] ?? null) : null;
                continue;
            }

            $imageDownloads[] = ($this->storeMockupPageDownload)(
                $download,
                $manualId,
                $page->id,
                'image-' . ($index + 1),
                $timestamp,
            );
        }

        $page->edit(
            $message->name,
            $images,
            $downloadFile,
            $imageDownloads,
        );
    }
}
