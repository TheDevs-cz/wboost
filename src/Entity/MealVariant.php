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
class MealVariant
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne(inversedBy: 'variants')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Meal $meal,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Diet $diet = null,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, options: ['default' => 0])]
        public int $position = 0,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Meal $referenceMeal = null,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
        public null|string $energyValue = null,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
        public null|string $fats = null,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
        public null|string $carbohydrates = null,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
        public null|string $proteins = null,
    ) {
    }

    public function editManual(
        string $name,
        Diet $diet,
        null|string $energyValue,
        null|string $fats,
        null|string $carbohydrates,
        null|string $proteins,
    ): void {
        $this->name = $name;
        $this->diet = $diet;
        $this->referenceMeal = null;
        $this->energyValue = $energyValue;
        $this->fats = $fats;
        $this->carbohydrates = $carbohydrates;
        $this->proteins = $proteins;
    }

    public function editReference(Meal $referenceMeal): void
    {
        $this->diet = null;
        $this->referenceMeal = $referenceMeal;
        $this->name = null;
        $this->energyValue = null;
        $this->fats = null;
        $this->carbohydrates = null;
        $this->proteins = null;
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }

    public function isReferenceMode(): bool
    {
        return $this->referenceMeal !== null;
    }

    public function getDisplayName(): string
    {
        if ($this->referenceMeal !== null) {
            return $this->referenceMeal->name;
        }

        return $this->name ?? '';
    }

    public function getEffectiveEnergyValue(): null|string
    {
        if ($this->referenceMeal !== null) {
            return $this->referenceMeal->energyValue;
        }

        return $this->energyValue;
    }

    public function getEffectiveFats(): null|string
    {
        if ($this->referenceMeal !== null) {
            return $this->referenceMeal->fats;
        }

        return $this->fats;
    }

    public function getEffectiveCarbohydrates(): null|string
    {
        if ($this->referenceMeal !== null) {
            return $this->referenceMeal->carbohydrates;
        }

        return $this->carbohydrates;
    }

    public function getEffectiveProteins(): null|string
    {
        if ($this->referenceMeal !== null) {
            return $this->referenceMeal->proteins;
        }

        return $this->proteins;
    }

    public function getEffectiveDiet(): null|Diet
    {
        if ($this->referenceMeal !== null) {
            return $this->referenceMeal->diet;
        }

        return $this->diet;
    }

    public function dietCodesLabel(): string
    {
        $diet = $this->getEffectiveDiet();

        return $diet?->codesLabel() ?? '';
    }
}
