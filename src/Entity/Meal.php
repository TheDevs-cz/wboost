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
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\NutritionalValues;
use WBoost\Web\Value\WeeklyMenuMealType;

#[Entity]
class Meal
{
    /** @var Collection<int, MealVariant> */
    #[Immutable]
    #[OneToMany(targetEntity: MealVariant::class, mappedBy: 'meal', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[OrderBy(['position' => 'ASC'])]
    private Collection $variants;

    /** @var Collection<int, Diet> */
    #[ManyToMany(targetEntity: Diet::class)]
    #[JoinTable(name: 'meal_diet')]
    private Collection $diets;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Project $project,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public WeeklyMenuMealType $mealType,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public DishType $dishType,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $internalName,

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
        $this->variants = new ArrayCollection();
        $this->diets = new ArrayCollection();
    }

    /**
     * @param array<Diet> $diets
     */
    public function edit(
        WeeklyMenuMealType $mealType,
        DishType $dishType,
        string $name,
        string $internalName,
        array $diets,
        null|string $energyValue,
        null|string $fats,
        null|string $carbohydrates,
        null|string $proteins,
    ): void {
        $this->mealType = $mealType;
        $this->dishType = $dishType;
        $this->name = $name;
        $this->internalName = $internalName;
        $this->setDiets($diets);
        $this->energyValue = $energyValue;
        $this->fats = $fats;
        $this->carbohydrates = $carbohydrates;
        $this->proteins = $proteins;
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

    public function addVariant(MealVariant $variant): void
    {
        $this->variants->add($variant);
    }

    public function removeVariant(MealVariant $variant): void
    {
        $this->variants->removeElement($variant);
    }

    /**
     * @return array<MealVariant>
     */
    public function variants(): array
    {
        return $this->variants->toArray();
    }

    public function hasVariants(): bool
    {
        return $this->variants->count() > 0;
    }

    public function dietCodesLabel(): string
    {
        if ($this->diets->isEmpty()) {
            return '';
        }

        return implode(', ', array_map(
            static fn(Diet $diet) => $diet->codesLabel(),
            $this->diets->toArray(),
        ));
    }

    public function getNutritionalValues(): NutritionalValues
    {
        return new NutritionalValues(
            energyValue: $this->energyValue,
            fats: $this->fats,
            carbohydrates: $this->carbohydrates,
            proteins: $this->proteins,
        );
    }
}
