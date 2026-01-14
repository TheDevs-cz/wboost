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

#[Entity]
class WeeklyMenuCourseVariant
{
    /** @var Collection<int, WeeklyMenuCourseVariantMeal> */
    #[Immutable]
    #[OneToMany(targetEntity: WeeklyMenuCourseVariantMeal::class, mappedBy: 'courseVariant', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[OrderBy(['position' => 'ASC'])]
    private Collection $meals;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne(inversedBy: 'variants')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public WeeklyMenuCourse $course,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $name = null,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, options: ['default' => 0])]
        public int $position = 0,
    ) {
        $this->meals = new ArrayCollection();
    }

    public function edit(null|string $name): void
    {
        $this->name = $name;
    }

    public function addMeal(WeeklyMenuCourseVariantMeal $meal): void
    {
        $this->meals->add($meal);
    }

    public function removeMeal(WeeklyMenuCourseVariantMeal $meal): void
    {
        $this->meals->removeElement($meal);
    }

    /**
     * @return array<WeeklyMenuCourseVariantMeal>
     */
    public function meals(): array
    {
        return $this->meals->toArray();
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }
}
