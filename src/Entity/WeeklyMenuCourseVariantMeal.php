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
class WeeklyMenuCourseVariantMeal
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne(inversedBy: 'meals')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public WeeklyMenuCourseVariant $courseVariant,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Meal $meal,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, options: ['default' => 0])]
        public int $position = 0,
    ) {
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }
}
