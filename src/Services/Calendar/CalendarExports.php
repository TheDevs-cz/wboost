<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarExport;
use WBoost\Web\Message\Calendar\RequestCalendarExport;
use WBoost\Web\Message\Calendar\RunCalendarExport;
use WBoost\Web\Repository\CalendarExportRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarExportStatus;
use WBoost\Web\Value\Calendar\CalendarExportVariant;
use WBoost\Web\Value\DownloadFileName;

/**
 * The PDF exports of a calendar as the three callers see them — the designer
 * routes, the client-link routes and the MCP tool
 * (docs/architecture/calendars.md § "PDF export"): ask for one, read where it
 * is, name the file.
 */
readonly final class CalendarExports
{
    public const string NO_LAYOUT = 'Kalendář ještě nemá rozvržení, není co exportovat.';
    public const string FAILED = 'PDF se nepodařilo připravit. Zkuste to prosím znovu.';
    public const string BUSY = 'Vykreslovací služba je teď vytížená. Zkuste to prosím za chvíli znovu.';
    public const string STALLED = 'Příprava PDF se zastavila. Zkuste to prosím znovu.';

    /**
     * Up to this size the download is a buffered response of the app; a
     * larger file is answered with a redirect to its storage URL (the key is
     * unguessable and the object carries its own download name) — a print
     * file of a full year is 150–500 MB, which no PHP response should hold.
     */
    public const int BUFFERED_DOWNLOAD_BYTES = 32_000_000;

    public function __construct(
        private MessageBusInterface $bus,
        #[Autowire(service: 'job_bus')]
        private MessageBusInterface $jobBus,
        private CalendarExportRepository $exportRepository,
        private CalendarModuleInliner $modules,
        private ProvideIdentity $provideIdentity,
        private UploaderHelper $uploaderHelper,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The export that already answers a request for this variant of the
     * calendar as it is saved now: a finished file, or a job on its way.
     * Null = a request would start a new render.
     */
    public function reusable(Calendar $calendar, CalendarExportVariant $variant): null|CalendarExport
    {
        $now = $this->clock->now();
        $hash = $this->modules->fingerprint();

        foreach ($this->exportRepository->forCalendar($calendar->id) as $export) {
            if ($export->variant !== $variant || $export->calendarRevision !== $calendar->revision || $export->rendererHash !== $hash) {
                continue;
            }

            if ($export->isDownloadable() || $export->isPending($now)) {
                return $export;
            }
        }

        return null;
    }

    /**
     * Asks for a PDF. Answers the export to poll — a new job, or the one
     * {@see reusable()} found. The request is written into the history either
     * way.
     */
    public function request(Calendar $calendar, CalendarExportVariant $variant, CalendarActor $actor): CalendarExport
    {
        $exportId = $this->provideIdentity->next();

        // The handler locks the calendar row: two requests at once end in ONE job.
        $this->bus->dispatch(new RequestCalendarExport($exportId, $calendar->id, $variant, $actor));

        $export = $this->reusable($calendar, $variant) ?? $this->exportRepository->get($exportId);

        if ($export->id->equals($exportId)) {
            $this->jobBus->dispatch(new RunCalendarExport($exportId));
        }

        return $export;
    }

    /**
     * @param callable(CalendarExport): string $statusUrl
     * @param callable(CalendarExport): string $downloadUrl
     *
     * @return array{id: string, variant: string, status: string, done: int, total: int, error: null|string, sizeBytes: null|int, fileName: string, urls: array{status: string, download: null|string}}
     */
    public function describe(CalendarExport $export, callable $statusUrl, callable $downloadUrl): array
    {
        $status = $export->status;
        $error = $export->error;

        if ($export->isStale($this->clock->now())) {
            $status = CalendarExportStatus::Failed;
            $error = self::STALLED;
        }

        return [
            'id' => $export->id->toString(),
            'variant' => $export->variant->value,
            'status' => $status->value,
            'done' => $export->progressDone,
            'total' => $export->progressTotal,
            'error' => $status === CalendarExportStatus::Failed ? ($error ?? self::FAILED) : null,
            'sizeBytes' => $export->sizeBytes,
            'fileName' => self::fileName($export->calendar, $export->variant)->name,
            'urls' => [
                'status' => $statusUrl($export),
                'download' => $export->isDownloadable() ? $downloadUrl($export) : null,
            ],
        ];
    }

    /** `Kalendář 2027 - tisk.pdf` */
    public static function fileName(Calendar $calendar, CalendarExportVariant $variant): DownloadFileName
    {
        return DownloadFileName::fromParts([$calendar->name, $variant->fileSuffix()], 'pdf', 'kalendar');
    }

    /** Whether the download goes through the app (buffered) or straight from the storage. */
    public function isBuffered(CalendarExport $export): bool
    {
        return ($export->sizeBytes ?? 0) <= self::BUFFERED_DOWNLOAD_BYTES;
    }

    public function storageUrl(CalendarExport $export): string
    {
        return $this->uploaderHelper->getPublicPath((string) $export->path);
    }
}
