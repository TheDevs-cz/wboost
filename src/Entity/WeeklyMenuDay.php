<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\NutritionalValues;

#[Entity]
class WeeklyMenuDay
{
    /** @var Collection<int, WeeklyMenuDayMealType> */
    #[Immutable]
    #[OneToMany(targetEntity: WeeklyMenuDayMealType::class, mappedBy: 'weeklyMenuDay', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[OrderBy(['position' => 'ASC'])]
    private Collection $mealTypes;

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
        $this->mealTypes = new ArrayCollection();
    }

    public function setDate(null|\DateTimeImmutable $date): void
    {
        $this->date = $date;
    }

    public function dayLabel(): string
    {
        return match ($this->dayOfWeek) {
            1 => 'Pondělí',
            2 => 'Úterý',
            3 => 'Středa',
            4 => 'Čtvrtek',
            5 => 'Pátek',
            6 => 'Sobota',
            7 => 'Neděle',
            default => '',
        };
    }

    public function dayLabelShort(): string
    {
        return match ($this->dayOfWeek) {
            1 => 'Po',
            2 => 'Út',
            3 => 'St',
            4 => 'Čt',
            5 => 'Pá',
            6 => 'So',
            7 => 'Ne',
            default => '',
        };
    }

    public function addMealType(WeeklyMenuDayMealType $mealType): void
    {
        $this->mealTypes->add($mealType);
    }

    public function removeMealType(WeeklyMenuDayMealType $mealType): void
    {
        $this->mealTypes->removeElement($mealType);
    }

    /**
     * @return array<WeeklyMenuDayMealType>
     */
    public function mealTypes(): array
    {
        $mealTypes = $this->mealTypes->toArray();
        usort($mealTypes, static fn(WeeklyMenuDayMealType $a, WeeklyMenuDayMealType $b) => $a->position <=> $b->position);

        return $mealTypes;
    }

    public function getNutritionalValues(): NutritionalValues
    {
        $values = new NutritionalValues();

        foreach ($this->mealTypes as $mealType) {
            $values = $values->add($mealType->getNutritionalValues());
        }

        return $values;
    }
}
