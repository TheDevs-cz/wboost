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
 * The credit ledger — append-only, FK-less. A start writes `reserve` (−1);
 * the end of that try writes exactly one `settle` (0, the scan counts) or
 * `refund` (+1). `period` is the reserve's month in Europe/Prague and is
 * copied to its settle / refund, so a refund always lands in the month it
 * gives back to.
 *
 * The unique index makes a double reserve, settle or refund impossible;
 * "settle XOR refund" is enforced by the insert itself
 * ({@see \WBoost\Web\Services\AiScan\AiScanCredits::close()}).
 */
#[Entity]
#[Table(name: 'ai_credit_ledger')]
#[UniqueConstraint(name: 'uniq_ai_ledger_scan_try_reason', columns: ['scan_id', 'try', 'reason'])]
#[Index(name: 'idx_ai_ledger_project_period', columns: ['project_id', 'period'])]
#[Index(name: 'idx_ai_ledger_created_by', columns: ['created_by', 'created_at'])]
#[Index(name: 'idx_ai_ledger_project_created', columns: ['project_id', 'created_at'])]
class AiCreditLedgerEntry
{
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Column(type: UuidType::NAME)]
        public UuidInterface $projectId,

        /** Null for a grant / adjustment. */
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $scanId,

        #[Column(name: 'try', options: ['default' => 0])]
        public int $try,

        /** `YYYY-MM`, Europe/Prague. */
        #[Column(length: 7)]
        public string $period,

        #[Column]
        public int $delta,

        #[Column(length: 16)]
        public string $reason,

        #[Column(type: Types::BIGINT, options: ['default' => 0])]
        public int $costMicroUsd,

        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $createdBy,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
    }
}
