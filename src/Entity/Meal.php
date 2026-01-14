<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
use WBoost\Web\Value\WeeklyMenuMealType;

#[Entity]
class Meal
{
    /** @var Collection<int, MealVariant> */
    #[Immutable]
    #[OneToMany(targetEntity: MealVariant::class, mappedBy: 'meal', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[OrderBy(['position' => 'ASC'])]
    private Collection $variants;

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
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Diet $diet = null,
    ) {
        $this->variants = new ArrayCollection();
    }

    public function edit(
        WeeklyMenuMealType $mealType,
        DishType $dishType,
        string $name,
        string $internalName,
        null|Diet $diet,
    ): void {
        $this->mealType = $mealType;
        $this->dishType = $dishType;
        $this->name = $name;
        $this->internalName = $internalName;
        $this->diet = $diet;
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
        if ($this->diet === null) {
            return '';
        }

        return $this->diet->codesLabel();
    }
}
