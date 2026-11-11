<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\Calendar\CalendarAuthorKind;
use WBoost\Web\Value\Calendar\CalendarExportStatus;
use WBoost\Web\Value\Calendar\CalendarExportVariant;

/**
 * One PDF export of a calendar — an asynchronous job and, once it is done,
 * the file it produced (docs/architecture/calendars.md § "PDF export").
 *
 * The row is created `queued` by the request, turned `running` → `done` /
 * `failed` by {@see \WBoost\Web\Services\Calendar\CalendarPdfExporter} on the
 * Messenger consumer; the browser polls it. `calendarRevision` +
 * `rendererHash` say what the file was made from: a later request for the
 * same variant of an unchanged calendar is answered with this row.
 *
 * The file lives at `path` = `calendars/{calendarId}/exports/{random}.pdf` —
 * inside the calendar's directory, so it goes with the calendar.
 */
#[Entity]
#[Table(name: 'calendar_export')]
#[Index(name: 'idx_calendar_export_calendar_created', columns: ['calendar_id', 'created_at'])]
class CalendarExport
{
    public const int MAX_ERROR_LENGTH = 255;

    /**
     * A job that has not moved for this long is dead (the consumer was
     * restarted mid-export, or is not running): it reads as failed and a new
     * request starts a new one. A page takes about a second, so this is far
     * beyond a working export's silence.
     */
    public const int STALE_AFTER_SECONDS = 600;

    #[Column(type: 'string', length: 16, enumType: CalendarExportStatus::class)]
    public CalendarExportStatus $status = CalendarExportStatus::Queued;

    /** Pages finished so far / pages of the calendar (0 until the job has counted them). */
    #[Column]
    public int $progressDone = 0;

    #[Column]
    public int $progressTotal = 0;

    #[Column(length: 255, nullable: true)]
    public null|string $path = null;

    #[Column(type: Types::BIGINT, nullable: true)]
    public null|int $sizeBytes = null;

    /** A Czech sentence for the user; the technical cause goes to the log. */
    #[Column(length: 255, nullable: true)]
    public null|string $error = null;

    /** The heartbeat: every status / progress change. */
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $updatedAt;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $finishedAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Calendar $calendar,

        #[Immutable]
        #[Column(type: 'string', length: 16, enumType: CalendarExportVariant::class)]
        public CalendarExportVariant $variant,

        /** `calendar.revision` the file shows: the one at the request, corrected by the job to the one it rendered. */
        #[Column]
        public int $calendarRevision,

        /** Fingerprint of the page renderer ({@see \WBoost\Web\Services\Calendar\CalendarModuleInliner::fingerprint()}). */
        #[Immutable]
        #[Column(length: 32)]
        public string $rendererHash,

        #[Immutable]
        #[Column(type: 'string', length: 16, enumType: CalendarAuthorKind::class)]
        public CalendarAuthorKind $requestedByKind,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|User $requestedBy,

        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public function start(int $total, int $calendarRevision, DateTimeImmutable $now): void
    {
        $this->calendarRevision = $calendarRevision;
        $this->status = CalendarExportStatus::Running;
        $this->progressDone = 0;
        $this->progressTotal = $total;
        $this->updatedAt = $now;
    }

    public function advance(int $done, DateTimeImmutable $now): void
    {
        $this->progressDone = min($done, $this->progressTotal);
        $this->updatedAt = $now;
    }

    public function finish(string $path, int $sizeBytes, DateTimeImmutable $now): void
    {
        $this->status = CalendarExportStatus::Done;
        $this->progressDone = $this->progressTotal;
        $this->path = $path;
        $this->sizeBytes = $sizeBytes;
        $this->error = null;
        $this->updatedAt = $now;
        $this->finishedAt = $now;
    }

    public function fail(string $error, DateTimeImmutable $now): void
    {
        $this->status = CalendarExportStatus::Failed;
        $this->error = mb_substr($error, 0, self::MAX_ERROR_LENGTH);
        $this->updatedAt = $now;
        $this->finishedAt = $now;
    }

    /** Queued or running, but silent for too long. */
    public function isStale(DateTimeImmutable $now): bool
    {
        return !$this->status->isFinal()
            && $now->getTimestamp() - $this->updatedAt->getTimestamp() > self::STALE_AFTER_SECONDS;
    }

    /** Still worth waiting for. */
    public function isPending(DateTimeImmutable $now): bool
    {
        return !$this->status->isFinal() && !$this->isStale($now);
    }

    public function isDownloadable(): bool
    {
        return $this->status === CalendarExportStatus::Done && $this->path !== null;
    }
}
