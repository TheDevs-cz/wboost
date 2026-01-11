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
class WeeklyMenuMealVariant
{
    /** @var Collection<int, WeeklyMenuMealVariantDietVersion> */
    #[Immutable]
    #[OneToMany(targetEntity: WeeklyMenuMealVariantDietVersion::class, mappedBy: 'variant', fetch: 'EAGER', cascade: ['persist'])]
    #[OrderBy(['sortOrder' => 'ASC'])]
    private Collection $dietVersions;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne(inversedBy: 'variants')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public WeeklyMenuMeal $meal,

        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $variantNumber,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $name = null,

        #[Column(type: Types::SMALLINT)]
        public int $sortOrder = 0,
    ) {
        $this->dietVersions = new ArrayCollection();
    }

    public function edit(null|string $name): void
    {
        $this->name = $name;
    }

    public function addDietVersion(WeeklyMenuMealVariantDietVersion $dietVersion): void
    {
        $this->dietVersions->add($dietVersion);
    }

    public function removeDietVersion(WeeklyMenuMealVariantDietVersion $dietVersion): void
    {
        $this->dietVersions->removeElement($dietVersion);
    }

    /**
     * @return array<WeeklyMenuMealVariantDietVersion>
     */
    public function dietVersions(): array
    {
        return $this->dietVersions->toArray();
    }

    public function canAddDietVersion(): bool
    {
        return $this->dietVersions->count() < 2;
    }

    public function canRemoveDietVersion(): bool
    {
        return $this->dietVersions->count() > 1;
    }

    public function nextSortOrder(): int
    {
        $max = 0;

        foreach ($this->dietVersions as $dietVersion) {
            if ($dietVersion->sortOrder > $max) {
                $max = $dietVersion->sortOrder;
            }
        }

        return $max + 1;
    }
}
