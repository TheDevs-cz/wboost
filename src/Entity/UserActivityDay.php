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
 * Per-user, per-day activity counter: one row per (user, calendar day) whose
 * {@see $hits} counts throttled activity pings (at most one per minute per user
 * — see {@see \WBoost\Web\Services\Usage\UserActivityListener}). It is the
 * time-series behind the admin activity report.
 *
 * Deliberately FK-less (like {@see ExportEvent}) so schema:validate stays in
 * sync and the analytics table never constrains user deletion; the read model
 * inner-joins {@see User} instead, so rows of a since-deleted user simply drop
 * out. It is written with a native UPSERT and read with raw SQL — never
 * hydrated by the ORM; this mapping exists only so doctrine:schema:create
 * builds the table for tests.
 */
#[Entity]
#[Table(name: 'user_activity_day')]
#[UniqueConstraint(name: 'uniq_user_activity_day_user_day', columns: ['user_id', 'day'])]
#[Index(name: 'idx_user_activity_day_day', columns: ['day'])]
class UserActivityDay
{
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        #[Column(type: UuidType::NAME)]
        readonly public UuidInterface $userId,

        #[Column(type: Types::DATE_IMMUTABLE)]
        readonly public DateTimeImmutable $day,

        #[Column(type: Types::INTEGER, options: ['default' => 0])]
        public int $hits = 0,
    ) {
    }
}
