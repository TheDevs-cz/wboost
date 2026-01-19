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
class WeeklyMenuCourse
{
    /** @var Collection<int, WeeklyMenuCourseVariant> */
    #[Immutable]
    #[OneToMany(targetEntity: WeeklyMenuCourseVariant::class, mappedBy: 'course', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[OrderBy(['position' => 'ASC'])]
    private Collection $variants;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne(inversedBy: 'courses')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public WeeklyMenuDayMealType $dayMealType,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, options: ['default' => 0])]
        public int $position = 0,
    ) {
        $this->variants = new ArrayCollection();
    }

    public function addVariant(WeeklyMenuCourseVariant $variant): void
    {
        $this->variants->add($variant);
    }

    public function removeVariant(WeeklyMenuCourseVariant $variant): void
    {
        $this->variants->removeElement($variant);
    }

    /**
     * @return array<WeeklyMenuCourseVariant>
     */
    public function variants(): array
    {
        return $this->variants->toArray();
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }

    /**
     * Returns nutritional values from the first variant (for hierarchical aggregation).
     */
    public function getNutritionalValues(): NutritionalValues
    {
        $firstVariant = $this->variants->first();

        if ($firstVariant === false) {
            return new NutritionalValues();
        }

        return $firstVariant->getNutritionalValues();
    }

    /**
     * Returns min-max range across all variants (since variants are alternatives).
     * @return array{min: NutritionalValues, max: NutritionalValues}
     */
    public function getNutritionalValuesRange(): array
    {
        $variantValues = [];

        foreach ($this->variants as $variant) {
            $variantValues[] = $variant->getNutritionalValues();
        }

        return NutritionalValues::range($variantValues);
    }
}
