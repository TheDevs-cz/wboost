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
class WeeklyMenu
{
    /** @var Collection<int, WeeklyMenuDay> */
    #[Immutable]
    #[OneToMany(targetEntity: WeeklyMenuDay::class, mappedBy: 'weeklyMenu', fetch: 'EAGER', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[OrderBy(['dayOfWeek' => 'ASC'])]
    private Collection $days;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Project $project,

        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATE_IMMUTABLE)]
        public \DateTimeImmutable $validFrom,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATE_IMMUTABLE)]
        public \DateTimeImmutable $validTo,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|\DateTimeImmutable $updatedAt = null,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $createdBy = null,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $approvedBy = null,
    ) {
        $this->days = new ArrayCollection();
    }

    public function edit(
        string $name,
        \DateTimeImmutable $validFrom,
        \DateTimeImmutable $validTo,
        null|string $createdBy = null,
        null|string $approvedBy = null,
    ): void {
        $this->name = $name;
        $this->validFrom = $validFrom;
        $this->validTo = $validTo;
        $this->createdBy = $createdBy;
        $this->approvedBy = $approvedBy;
        $this->markUpdated();
    }

    public function markUpdated(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function addDay(WeeklyMenuDay $day): void
    {
        $this->days->add($day);
    }

    public function removeDay(WeeklyMenuDay $day): void
    {
        $this->days->removeElement($day);
    }

    /**
     * @return array<WeeklyMenuDay>
     */
    public function days(): array
    {
        return $this->days->toArray();
    }

    public function day(int $dayOfWeek): null|WeeklyMenuDay
    {
        foreach ($this->days as $day) {
            if ($day->dayOfWeek === $dayOfWeek) {
                return $day;
            }
        }

        return null;
    }

    public function getNutritionalValues(): NutritionalValues
    {
        $values = new NutritionalValues();

        foreach ($this->days as $day) {
            $values = $values->add($day->getNutritionalValues());
        }

        return $values;
    }
}
