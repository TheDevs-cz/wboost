<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Filesystem\Filesystem as LocalFilesystem;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarExport;
use WBoost\Web\Exceptions\CalendarExportNeedsMoreSpace;
use WBoost\Web\Exceptions\CalendarExportNotFound;
use WBoost\Web\Exceptions\CalendarModelRefused;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Repository\CalendarExportRepository;

/**
 * Runs one PDF export job from its `calendar_export` row to a stored file
 * (docs/architecture/calendars.md § "PDF export"):
 *
 * produce the file ({@see CalendarPdfProducerInterface}) → store it under
 * `calendars/{calendarId}/exports/` → mark the row done → prune old exports.
 *
 * Runs on the Messenger consumer, OUTSIDE a transaction: every status and
 * progress change is flushed at once, because the browser polls the row.
 * It never throws: whatever goes wrong becomes a `failed` row with a Czech
 * sentence for the user and a log entry for us.
 */
readonly final class CalendarPdfExporter
{
    /** Exports kept per calendar (rows and files); older ones are removed after a successful export. */
    public const int KEEP = 6;

    public function __construct(
        private CalendarExportRepository $exportRepository,
        private CalendarPdfProducerInterface $producer,
        private Filesystem $filesystem,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function run(UuidInterface $exportId): void
    {
        try {
            $export = $this->exportRepository->get($exportId);
        } catch (CalendarExportNotFound) {
            // the calendar (and its exports) was deleted while the job waited
            return;
        }

        if ($export->status->isFinal()) {
            return;
        }

        $directory = sprintf('%s/wboost-calendar-export-%s', sys_get_temp_dir(), $export->id->toString());
        $local = new LocalFilesystem();

        try {
            $local->mkdir($directory);
            $this->export($export, $directory);
        } catch (\Throwable $failure) {
            $this->logger->error('Calendar PDF export failed.', [
                'exportId' => $export->id->toString(),
                'calendarId' => $export->calendar->id->toString(),
                'variant' => $export->variant->value,
                'exception' => $failure,
            ]);

            $this->fail($export, match (true) {
                $failure instanceof TemplateRenderUnavailable => CalendarExports::BUSY,
                $failure instanceof CalendarExportNeedsMoreSpace => CalendarExports::NO_SPACE,
                $failure instanceof CalendarModelRefused && $failure->reason === 'no_layout' => CalendarExports::NO_LAYOUT,
                default => CalendarExports::FAILED,
            });
        } finally {
            try {
                $local->remove($directory);
            } catch (\Throwable) {
                // a leftover work directory is not worth failing for
            }
        }
    }

    private function export(CalendarExport $export, string $directory): void
    {
        $calendar = $export->calendar;

        $file = $this->producer->produce($calendar, $export->variant, $directory, function (int $done, int $total) use ($export, $calendar): void {
            if ($done === 0) {
                // The job renders what is saved NOW; the row says which revision that is.
                $export->start($total, $calendar->revision, $this->clock->now());
            } else {
                $export->advance($done, $this->clock->now());
            }

            $this->entityManager->flush();
        });

        // The upload of a few hundred MB takes its time: one more heartbeat before it.
        $export->advance($export->progressTotal, $this->clock->now());
        $this->entityManager->flush();

        $path = sprintf('calendars/%s/exports/%s.pdf', $calendar->id->toString(), bin2hex(random_bytes(20)));
        $stream = fopen($file, 'rb');

        if ($stream === false) {
            throw new \RuntimeException('The calendar PDF cannot be read.');
        }

        try {
            $this->filesystem->writeStream($path, $stream, [
                // what a download straight from the storage is called (large files — CalendarExports::isBuffered())
                'ContentType' => 'application/pdf',
                'ContentDisposition' => CalendarExports::fileName($calendar, $export->variant)->disposition(),
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $export->finish($path, (int) filesize($file), $this->clock->now());
        $this->entityManager->flush();

        $this->logger->info('Calendar PDF export: stored.', [
            'exportId' => $export->id->toString(),
            'variant' => $export->variant->value,
            'bytes' => $export->sizeBytes,
            'seconds' => $export->finishedAt !== null ? $export->finishedAt->getTimestamp() - $export->createdAt->getTimestamp() : null,
            // of the whole worker process so far — what `messenger:consume --memory-limit` is compared with
            'peakMemoryMb' => (int) round(memory_get_peak_usage(true) / 1_000_000),
        ]);

        $this->prune($calendar);
    }

    private function fail(CalendarExport $export, string $message): void
    {
        try {
            if (!$this->entityManager->isOpen()) {
                return;
            }

            $export->fail($message, $this->clock->now());
            $this->entityManager->flush();
        } catch (\Throwable $error) {
            // the row then reads as stalled after CalendarExport::STALE_AFTER_SECONDS
            $this->logger->error('Calendar PDF export: the failure could not be recorded.', ['exception' => $error]);
        }
    }

    /**
     * Keeps the newest {@see KEEP} exports of the calendar; a job that is
     * still on its way is never removed.
     */
    private function prune(Calendar $calendar): void
    {
        $now = $this->clock->now();
        $paths = [];

        foreach (array_slice($this->exportRepository->forCalendar($calendar->id), self::KEEP) as $old) {
            if ($old->isPending($now)) {
                continue;
            }

            if ($old->path !== null) {
                $paths[] = $old->path;
            }

            $this->exportRepository->remove($old);
        }

        // rows first: a file without a row is an orphan the storage report shows,
        // a row without its file would be a broken download
        $this->entityManager->flush();

        foreach ($paths as $path) {
            try {
                $this->filesystem->delete($path);
            } catch (FilesystemException $error) {
                $this->logger->error('Failed to delete a pruned calendar export.', ['path' => $path, 'exception' => $error]);
            }
        }
    }
}
