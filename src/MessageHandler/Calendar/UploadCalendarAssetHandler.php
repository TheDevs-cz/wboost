<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Calendar;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\CalendarAsset;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Message\Calendar\UploadCalendarAsset;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\Calendar\CalendarHistoryWriter;

#[AsMessageHandler]
readonly final class UploadCalendarAssetHandler
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
     * @throws InvalidCalendarChange
     * @throws FilesystemException
     */
    public function __invoke(UploadCalendarAsset $message): void
    {
        $calendar = $this->calendarRepository->get($message->calendarId);
        $prefix = sprintf('calendars/%s/%s', $calendar->id->toString(), $message->assetId->toString());

        foreach ([$message->path, $message->previewPath] as $path) {
            if ($path !== null && (!str_starts_with($path, $prefix) || str_contains($path, '..'))) {
                throw new InvalidCalendarChange('Soubor musí být uložen u svého kalendáře.');
            }
        }

        $this->filesystem->write($message->path, $message->contents);

        if ($message->previewPath !== null && $message->previewContents !== null) {
            $this->filesystem->write($message->previewPath, $message->previewContents);
        }

        $this->assetRepository->add(new CalendarAsset(
            $message->assetId,
            $calendar,
            $message->kind,
            $message->path,
            $message->previewContents !== null ? $message->previewPath : null,
            $message->mimeType,
            mb_substr($message->originalName, 0, 255),
            $message->width,
            $message->height,
            $message->description !== null ? mb_substr($message->description, 0, 255) : null,
            strlen($message->contents),
            $message->actor->kind,
            $this->history->user($message->actor),
            $this->clock->now(),
        ));
    }
}
