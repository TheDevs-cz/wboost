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
class WeeklyMenuDayMealType
{
    /** @var Collection<int, WeeklyMenuCourse> */
    #[Immutable]
    #[OneToMany(targetEntity: WeeklyMenuCourse::class, mappedBy: 'dayMealType', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[OrderBy(['position' => 'ASC'])]
    private Collection $courses;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne(inversedBy: 'mealTypes')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public WeeklyMenuDay $weeklyMenuDay,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public WeeklyMenuMealType $mealType,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, options: ['default' => 0])]
        public int $position = 0,
    ) {
        $this->courses = new ArrayCollection();
    }

    public function addCourse(WeeklyMenuCourse $course): void
    {
        $this->courses->add($course);
    }

    public function removeCourse(WeeklyMenuCourse $course): void
    {
        $this->courses->removeElement($course);
    }

    /**
     * @return array<WeeklyMenuCourse>
     */
    public function courses(): array
    {
        return $this->courses->toArray();
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }
}
