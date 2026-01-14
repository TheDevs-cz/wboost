<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class WeeklyMenuDay
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne(inversedBy: 'days')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public WeeklyMenu $weeklyMenu,

        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $dayOfWeek,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        public null|\DateTimeImmutable $date = null,
    ) {
    }

    public function setDate(null|\DateTimeImmutable $date): void
    {
        $this->date = $date;
    }

    public function dayLabel(): string
    {
        return match ($this->dayOfWeek) {
            1 => 'Pondeli',
            2 => 'Utery',
            3 => 'Streda',
            4 => 'Ctvrtek',
            5 => 'Patek',
            6 => 'Sobota',
            7 => 'Nedele',
            default => '',
        };
    }

    public function dayLabelShort(): string
    {
        return match ($this->dayOfWeek) {
            1 => 'Po',
            2 => 'Ut',
            3 => 'St',
            4 => 'Ct',
            5 => 'Pa',
            6 => 'So',
            7 => 'Ne',
            default => '',
        };
    }
}
