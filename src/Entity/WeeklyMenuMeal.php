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
use WBoost\Web\Value\WeeklyMenuMealType;

#[Entity]
class WeeklyMenuMeal
{
    /** @var Collection<int, WeeklyMenuMealVariant> */
    #[Immutable]
    #[OneToMany(targetEntity: WeeklyMenuMealVariant::class, mappedBy: 'meal', fetch: 'EAGER', cascade: ['persist'])]
    #[OrderBy(['sortOrder' => 'ASC'])]
    private Collection $variants;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne(inversedBy: 'meals')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public WeeklyMenuDay $menuDay,

        #[Immutable]
        #[Column(enumType: WeeklyMenuMealType::class)]
        public WeeklyMenuMealType $type,

        #[Column(type: Types::SMALLINT)]
        public int $sortOrder,
    ) {
        $this->variants = new ArrayCollection();
    }

    public function addVariant(WeeklyMenuMealVariant $variant): void
    {
        $this->variants->add($variant);
    }

    public function removeVariant(WeeklyMenuMealVariant $variant): void
    {
        $this->variants->removeElement($variant);
    }

    /**
     * @return array<WeeklyMenuMealVariant>
     */
    public function variants(): array
    {
        return $this->variants->toArray();
    }

    public function canAddVariant(): bool
    {
        return $this->variants->count() < 3;
    }

    public function canRemoveVariant(): bool
    {
        return $this->variants->count() > 1;
    }

    public function nextVariantNumber(): int
    {
        $max = 0;

        foreach ($this->variants as $variant) {
            if ($variant->variantNumber > $max) {
                $max = $variant->variantNumber;
            }
        }

        return $max + 1;
    }

    public function nextSortOrder(): int
    {
        $max = 0;

        foreach ($this->variants as $variant) {
            if ($variant->sortOrder > $max) {
                $max = $variant->sortOrder;
            }
        }

        return $max + 1;
    }
}
