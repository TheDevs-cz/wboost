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
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\NutritionalValues;

#[Entity]
class MealVariant
{
    /** @var Collection<int, Diet> */
    #[ManyToMany(targetEntity: Diet::class)]
    #[JoinTable(name: 'meal_variant_diet')]
    private Collection $diets;

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
        $this->diets = new ArrayCollection();
    }

    /**
     * @param array<Diet> $diets
     */
    public function editManual(
        string $name,
        array $diets,
        null|string $energyValue,
        null|string $fats,
        null|string $carbohydrates,
        null|string $proteins,
    ): void {
        $this->name = $name;
        $this->setDiets($diets);
        $this->referenceMeal = null;
        $this->energyValue = $energyValue;
        $this->fats = $fats;
        $this->carbohydrates = $carbohydrates;
        $this->proteins = $proteins;
    }

    public function editReference(Meal $referenceMeal): void
    {
        $this->diets->clear();
        $this->referenceMeal = $referenceMeal;
        $this->name = null;
        $this->energyValue = null;
        $this->fats = null;
        $this->carbohydrates = null;
        $this->proteins = null;
    }

    /**
     * @param array<Diet> $diets
     */
    public function setDiets(array $diets): void
    {
        $this->diets->clear();
        foreach ($diets as $diet) {
            $this->diets->add($diet);
        }
    }

    /**
     * @return array<Diet>
     */
    public function diets(): array
    {
        return $this->diets->toArray();
    }

    public function hasDiets(): bool
    {
        return !$this->diets->isEmpty();
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

    /**
     * @return array<Diet>
     */
    public function getEffectiveDiets(): array
    {
        if ($this->referenceMeal !== null) {
            return $this->referenceMeal->diets();
        }

        return $this->diets->toArray();
    }

    public function dietCodesLabel(): string
    {
        $diets = $this->getEffectiveDiets();

        if ($diets === []) {
            return '';
        }

        return implode(', ', array_map(
            static fn(Diet $diet) => $diet->codesLabel(),
            $diets,
        ));
    }

    public function getNutritionalValues(): NutritionalValues
    {
        return new NutritionalValues(
            energyValue: $this->getEffectiveEnergyValue(),
            fats: $this->getEffectiveFats(),
            carbohydrates: $this->getEffectiveCarbohydrates(),
            proteins: $this->getEffectiveProteins(),
        );
    }
}
