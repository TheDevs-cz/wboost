<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * What one step of one run cost: **one row per `(scan, run, step)`**, upserted
 * from the scanner's heartbeats (the figures are cumulative) and closed with an
 * outcome when the run ends — by finish, release, a lost lease, a cancel or
 * the purge. A run that was killed therefore still has a row, with the last
 * heartbeat's figures.
 *
 * FK-less and denormalised on purpose, like {@see ExportEvent}: spend must
 * stay readable after the scan, the project or the user is gone. Written only
 * by {@see \WBoost\Web\Services\AiScan\RecordAiUsage} (raw SQL upsert); this
 * class is the schema.
 */
#[Entity]
#[Table(name: 'ai_usage_event')]
#[UniqueConstraint(name: 'uniq_ai_usage_scan_run_step', columns: ['scan_id', 'run', 'step'])]
#[Index(name: 'idx_ai_usage_occurred', columns: ['occurred_at'])]
#[Index(name: 'idx_ai_usage_project', columns: ['project_id', 'occurred_at'])]
#[Index(name: 'idx_ai_usage_lease', columns: ['scan_id', 'lease_token_hash'])]
class AiUsageEvent
{
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $closedAt = null;

    #[Column(length: 32, options: ['default' => 'anthropic'])]
    public string $provider = 'anthropic';

    #[Column(length: 64, options: ['default' => ''])]
    public string $model = '';

    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $inputTokens = 0;

    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $outputTokens = 0;

    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $cacheReadTokens = 0;

    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $cacheWriteTokens = 0;

    /** The part of the cache writes made with the 1-hour lifetime (priced apart). */
    #[Column(name: 'cache_write_1h_tokens', type: Types::BIGINT, options: ['default' => 0])]
    public int $cacheWrite1hTokens = 0;

    /**
     * sha256 of the lease token of the claim that opened this row. A call of
     * a run the app has since replaced proves itself with it, so its usage is
     * still recorded here — and nowhere else.
     */
    #[Column(length: 64, nullable: true)]
    public null|string $leaseTokenHash = null;

    /** What the app charges this step with: the price table when it knows the model, else the engine's own figure. */
    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $costMicroUsd = 0;

    #[Column(length: 32, nullable: true)]
    public null|string $priceVersion = null;

    /** What the engine itself reported. */
    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $engineCostMicroUsd = 0;

    #[Column(length: 24, nullable: true)]
    public null|string $outcome = null;

    #[Column(length: 32, nullable: true)]
    public null|string $errorCode = null;

    #[Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $durationMs = 0;

    #[Column(options: ['default' => 0])]
    public int $turns = 0;

    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Column(type: UuidType::NAME)]
        public UuidInterface $scanId,

        #[Column]
        public int $run,

        #[Column(length: 16)]
        public string $step,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $occurredAt,

        #[Column(type: UuidType::NAME)]
        public UuidInterface $projectId,

        #[Column]
        public string $projectName,

        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $ownerId,

        #[Column(length: 180)]
        public string $ownerEmail,

        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $userId,

        #[Column(length: 180)]
        public string $userEmail,

        #[Column(length: 16)]
        public string $channel,

        #[Column(length: 16)]
        public string $kind,
    ) {
    }
}
