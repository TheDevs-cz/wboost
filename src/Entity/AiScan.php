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
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\AiScan\AiScanChannel;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanStatus;
use WBoost\Web\Value\AiScan\AiScanStopReason;

/**
 * One AI scan: the job and everything the screens read about it
 * (docs/architecture/ai-scan.md).
 *
 * **This entity is written by the request that creates it and by nothing else
 * through the ORM.** Every later change — claim, heartbeat, finish, cancel,
 * the reaper — is one conditional `UPDATE … WHERE id = ? AND status = ?` in
 * {@see \WBoost\Web\Services\AiScan\AiScanTransitions}, because two processes
 * (the web request and the scanner's call) race for the same row and only a
 * conditional update makes "exactly one of them wins" true. So a loaded
 * instance is a snapshot: re-read it ({@see \WBoost\Web\Repository\AiScanRepository::get()}
 * refreshes) and never `flush()` changes made on it.
 *
 * Files live in the PRIVATE bucket under
 * `ai-scan/{projectId}/{id}/{storageSecret}/…` — see
 * {@see \WBoost\Web\Services\AiScan\AiScanStorage}.
 */
#[Entity]
#[Table(name: 'ai_scan')]
#[UniqueConstraint(name: 'uniq_ai_scan_request', columns: ['requested_by_id', 'request_id'])]
#[Index(name: 'idx_ai_scan_queue', columns: ['status', 'waiting_since'])]
#[Index(name: 'idx_ai_scan_project_status', columns: ['project_id', 'status'])]
#[Index(name: 'idx_ai_scan_project_source', columns: ['project_id', 'source_sha256'])]
#[Index(name: 'idx_ai_scan_expires', columns: ['expires_at'])]
class AiScan
{
    #[Column(type: 'string', length: 16, enumType: AiScanStatus::class)]
    public AiScanStatus $status = AiScanStatus::Checking;

    /** Stage / note codes of architecture.md §5.6 — what the waiting screen shows. */
    #[Column(length: 16, nullable: true)]
    public null|string $stage = null;

    #[Column(length: 32, nullable: true)]
    public null|string $note = null;

    /** @var null|array<string, int> */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $noteParams = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $stageStartedAt = null;

    /** The correction round the builder is in (0 = the first build). */
    #[Column(options: ['default' => 0])]
    public int $round = 0;

    /**
     * The stages of the current try as the scanner went through them:
     * `{<stage>: {at, end?, note?, params?}}` (epoch seconds). Written only
     * by the transitions and the heartbeat — `docs/architecture/ai-scan.md`
     * § "Stage log".
     *
     * @var null|array<string, array<string, mixed>>
     */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $stageLog = null;

    /**
     * Where the current run found lines of text: `{width, height, boxes}`.
     *
     * @var null|array{width: int, height: int, boxes: list<list<int>>}
     */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $textBoxes = null;

    /** Grows with every stored candidate render: the screen reloads its picture when it changes. */
    #[Column(options: ['default' => 0])]
    public int $previewSeq = 0;

    /**
     * What happened, oldest first, at most {@see AiScanLimits::EVENTS_MAX}:
     * `{at, event, from, to, code?}`.
     *
     * @var list<array<string, mixed>>
     */
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $events = [];

    /**
     * The user's starts of this upload: 1, +1 per "Zkusit znovu" (at most
     * two) and +1 for "Zkusit přesto" (once). Also the ledger key.
     */
    #[Column(name: 'try', options: ['default' => 1])]
    public int $try = 1;

    /**
     * Intake claims so far — the `run` of the intake's calls and of its usage
     * row. Only ever grows (a released or retried intake gets a new number),
     * so no two intake attempts share a usage row.
     */
    #[Column(options: ['default' => 0])]
    public int $intakeTries = 0;

    /** Intake leases LOST in the current try (0–2): the second one stops the scan as unusable input. */
    #[Column(options: ['default' => 0])]
    public int $intakeLosses = 0;

    /** Continued after a photo-of-a-print stop. */
    #[Column(options: ['default' => false])]
    public bool $approximate = false;

    /** The automatic restart of this try is used up (0–1). */
    #[Column(options: ['default' => 0])]
    public int $autoRestart = 0;

    /** Builds the scanner handed back in the current try (rule 7) — bounded by {@see \WBoost\Web\Value\AiScan\AiScanLimits::MAX_BUILD_RELEASES}. */
    #[Column(options: ['default' => 0])]
    public int $releases = 0;

    /** Grows at every build claim — the key of the build's calls and usage row. */
    #[Column(options: ['default' => 0])]
    public int $run = 0;

    /** The last heartbeat applied; an older `seq` changes nothing. */
    #[Column(options: ['default' => 0])]
    public int $beatSeq = 0;

    /**
     * sha256 of the lease token of the LATEST claim. Kept after the lease is
     * lost, so that run's late `finish` can still be recognised.
     */
    #[Column(length: 64, nullable: true)]
    public null|string $leaseTokenHash = null;

    /** Null = nobody holds the scan. */
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $leaseExpiresAt = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $claimedAt = null;

    /** Since when the scan waits in `checking` / `queued` — what the queue timeout counts. */
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $waitingSince = null;

    /** Start of the current try. */
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $startedAt;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $finishedAt = null;

    /** When the files go (30 days after the end); null once they are gone or while the scan works. */
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $expiresAt = null;

    /** The stop reason of a `stopped` scan, the error code of a `failed` one. */
    #[Column(length: 32, nullable: true)]
    public null|string $errorCode = null;

    /** Failed tries that count against the picture ({@see \WBoost\Web\Value\AiScan\AiScanErrorCode::countsAgainstSource()}). */
    #[Column(options: ['default' => 0])]
    public int $failureCount = 0;

    /** @var null|array<string, mixed> */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $preCheck = null;

    /**
     * The current run's rendered candidates: `{n, seq, design, issues, width, height}`.
     *
     * @var list<array<string, mixed>>
     */
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $candidates = [];

    /** The number of the candidate the proposal was made from. */
    #[Column(nullable: true)]
    public null|int $keptCandidate = null;

    /**
     * The proposal: the kept candidate's design document plus what the scanner
     * measured on it (boxes, colours, line widths).
     *
     * @var null|array<string, mixed>
     */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $proposal = null;

    /** @var null|array<string, mixed> */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $report = null;

    /** @var null|array<string, mixed> */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $fixes = null;

    /** @var null|array<string, mixed> */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $pictures = null;

    /** @var null|array<string, mixed> */
    #[Column(name: 'restore', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $restore = null;

    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|Template $resultTemplate = null;

    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|TemplateVariant $resultVariant = null;

    /** The run stopped at a cap; the scanner wrote the report alone. */
    #[Column(options: ['default' => false])]
    public bool $unfinished = false;

    // Totals over every run of this scan (sums of its ai_usage_event rows).
    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $inputTokens = 0;

    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $outputTokens = 0;

    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $cacheReadTokens = 0;

    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $cacheWriteTokens = 0;

    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $costMicroUsd = 0;

    #[Column(options: ['default' => 0])]
    public int $turns = 0;

    #[Column(options: ['default' => 0])]
    public int $renders = 0;

    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $durationMs = 0;

    /**
     * Peak memory / CPU the scanner reported for the last run.
     *
     * @var null|array<string, mixed>
     */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $resources = null;

    #[Column(length: 64, nullable: true)]
    public null|string $model = null;

    #[Column(length: 32, nullable: true)]
    public null|string $definitionVersion = null;

    #[Column(length: 32, nullable: true)]
    public null|string $scannerVersion = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $seenAt = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $notifiedAt = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $remindedAt = null;

    #[Column(type: Types::SMALLINT, nullable: true)]
    public null|int $rating = null;

    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $ratingNote = null;

    /**
     * @param array<string, mixed> $sourceMeta `{contentType, ext, bytes, width, height, name}` — header facts only
     * @param array<string, mixed> $options
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        /** The browser's idempotency key: the same key of the same user returns this scan. */
        #[Immutable]
        #[Column(length: 64)]
        public string $requestId,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Project $project,

        #[Immutable]
        #[Column(type: 'string', length: 16, enumType: AiScanKind::class)]
        public AiScanKind $kind,

        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Calendar $calendar,

        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|User $requestedBy,

        /** Survives the user: usage and the admin table still say who asked. */
        #[Immutable]
        #[Column(length: 180)]
        public string $requesterEmail,

        #[Immutable]
        #[Column(type: 'string', length: 16, enumType: AiScanChannel::class)]
        public AiScanChannel $channel,

        /** 128 random bits (hex) — part of every storage key, in no URL, e-mail or log. */
        #[Immutable]
        #[Column(length: 32)]
        public string $storageSecret,

        #[Immutable]
        #[Column(length: 64)]
        public string $sourceSha256,

        #[Column(type: Types::JSON, options: ['jsonb' => true])]
        public array $sourceMeta,

        #[Immutable]
        #[Column(type: Types::JSON, options: ['jsonb' => true])]
        public array $options,

        #[Immutable]
        #[Column(length: 32, nullable: true)]
        public null|string $consentVersion,

        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
        $this->startedAt = $createdAt;
        $this->waitingSince = $createdAt;
    }

    public function stopReason(): null|AiScanStopReason
    {
        if ($this->status !== AiScanStatus::Stopped || $this->errorCode === null) {
            return null;
        }

        return AiScanStopReason::tryFrom($this->errorCode);
    }

    /** "Zkusit znovu" uses: every start after the first, minus the one "Zkusit přesto". */
    public function retriesUsed(): int
    {
        return max(0, $this->try - 1 - ($this->approximate ? 1 : 0));
    }

    public function retriesLeft(): int
    {
        return max(0, AiScanLimits::MAX_RETRIES - $this->retriesUsed());
    }

    public function canContinue(): bool
    {
        return !$this->approximate && $this->stopReason()?->isContinuable() === true;
    }

    /** Whether somebody holds the scan right now (the lease may still be overdue). */
    public function isClaimed(): bool
    {
        return $this->leaseExpiresAt !== null;
    }
}
