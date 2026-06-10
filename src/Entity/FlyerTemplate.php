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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class FlyerTemplate
{
    /** @var Collection<int, FlyerTemplateVariant>  */
    #[Immutable]
    #[OneToMany(targetEntity: FlyerTemplateVariant::class, mappedBy: 'template', fetch: 'EAGER')]
    private Collection $variants;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public Project $project,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne(inversedBy: 'templates')]
        #[JoinColumn(onDelete: "SET NULL")]
        public null|FlyerCategory $category,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $createdAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $image,

        #[Column(options: ['default' => 0])]
        public int $position,
    ) {
        $this->variants = new ArrayCollection();
    }

    public function edit(
        null|FlyerCategory $category,
        string $name,
        null|string $imagePath,
    ): void
    {
        $this->category = $category;
        $this->name = $name;
        $this->image = $imagePath;
    }

    /**
     * @return array<FlyerTemplateVariant>
     */
    public function variants(): array
    {
        return $this->variants->toArray();
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }
}
