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

/**
 * One line of a calendar's Historie: who (Grafik / Klient) did what, as a
 * ready Czech sentence composed by
 * {@see \WBoost\Web\Services\Calendar\DescribeCalendarChange}.
 */
#[Entity]
#[Table(name: 'calendar_history')]
#[Index(name: 'idx_calendar_history_calendar_created', columns: ['calendar_id', 'created_at'])]
class CalendarHistoryEntry
{
    public const int MAX_DESCRIPTION_LENGTH = 255;

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
        #[Column(type: 'string', length: 16, enumType: CalendarAuthorKind::class)]
        public CalendarAuthorKind $authorKind,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|User $user,

        #[Immutable]
        #[Column(length: 255)]
        public string $description,

        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
    }
}
