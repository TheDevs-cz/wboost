<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Calendar;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\CalendarAsset;
use WBoost\Web\Exceptions\CalendarAssetNotFound;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Message\Calendar\CopyCalendarAsset;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\Calendar\CalendarHistoryWriter;

#[AsMessageHandler]
readonly final class CopyCalendarAssetHandler
{
    public function __construct(
        private CalendarRepository $calendarRepository,
        private CalendarAssetRepository $assetRepository,
        private CalendarHistoryWriter $history,
        private Filesystem $filesystem,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CalendarNotFound
     * @throws CalendarAssetNotFound when the source is gone or belongs to another project
     * @throws FilesystemException
     */
    public function __invoke(CopyCalendarAsset $message): void
    {
        $target = $this->calendarRepository->get($message->targetCalendarId);
        $source = $this->assetRepository->get($message->sourceAssetId);

        // Files never cross projects.
        if (!$source->calendar->project->id->equals($target->project->id)) {
            throw new CalendarAssetNotFound();
        }

        $base = sprintf('calendars/%s/%s', $target->id->toString(), $message->newAssetId->toString());
        $path = sprintf('%s.%s', $base, pathinfo($source->path, PATHINFO_EXTENSION));
        $this->filesystem->copy($source->path, $path);

        $previewPath = null;

        if ($source->previewPath !== null) {
            $previewPath = sprintf('%s-preview.%s', $base, pathinfo($source->previewPath, PATHINFO_EXTENSION));
            $this->filesystem->copy($source->previewPath, $previewPath);
        }

        $this->assetRepository->add(new CalendarAsset(
            $message->newAssetId,
            $target,
            $source->kind,
            $path,
            $previewPath,
            $source->mimeType,
            $source->originalName,
            $source->width,
            $source->height,
            $source->description,
            $source->sizeBytes,
            $message->actor->kind,
            $this->history->user($message->actor),
            $this->clock->now(),
        ));
    }
}
